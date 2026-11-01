<?php
declare(strict_types=1);

error_reporting(0);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

if (!defined('DEFAULT_PLAYLIST_URL')) {
    define('DEFAULT_PLAYLIST_URL', 'https://raw.githubusercontent.com/srhady/toffee-bd/refs/heads/main/toffee_playlist.m3u');
}
if (!defined('ADDITIONAL_PLAYLIST_URLS')) {
    define('ADDITIONAL_PLAYLIST_URLS', [
        'https://raw.githubusercontent.com/sm-monirulislam/Toffee-Auto-Update/refs/heads/main/toffee_playlist.m3u',
        'https://raw.githubusercontent.com/kgkaku/Toffee-Auto-Update-Playlist/refs/heads/main/universal.m3u',
    ]);
}
if (!defined('DEFAULT_USER_AGENT')) {
    define('DEFAULT_USER_AGENT', 'Toffee (Linux;Android 14) AndroidXMedia3/1.1.1/64100410/4d2ec9b8c3502383');
}

if (!function_exists('toffeeCaBundlePath')) {
    function toffeeCaBundlePath(): ?string
    {
        $caBundle = getenv('TOFFEE_CA_BUNDLE');
        if ($caBundle !== false && $caBundle !== '' && @is_file($caBundle) && @is_readable($caBundle)) {
            return $caBundle;
        }
        $iniCa = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');
        if ($iniCa !== false && $iniCa !== '' && @is_file($iniCa) && @is_readable($iniCa)) {
            return $iniCa;
        }
        return null;
    }
}

if (!function_exists('applyToffeeCurlSslOptions')) {
    function applyToffeeCurlSslOptions($curl): void
    {
        $caBundle = toffeeCaBundlePath();
        if ($caBundle !== null) {
            curl_setopt($curl, CURLOPT_CAINFO, $caBundle);
        }
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
    }
}

if (!class_exists('PlaylistManager')) {
    final class PlaylistManager
    {
        private array $sourceUrls;
        private string $cacheDirectory;
        private int $cacheTtlSeconds;
        private ?array $channels = null;
        private ?array $channelMap = null;
        private bool $usingStaleCache = false;

        public function __construct(?string $sourceUrl = null, int $cacheTtlSeconds = 60)
        {
            $configuredUrl = getenv('TOFFEE_PLAYLIST_URL');
            $primaryUrl = $sourceUrl ?? ($configuredUrl !== false && $configuredUrl !== ''
                ? $configuredUrl
                : DEFAULT_PLAYLIST_URL);
            $this->sourceUrls = array_values(array_unique(array_merge(
                [$primaryUrl],
                [DEFAULT_PLAYLIST_URL],
                ADDITIONAL_PLAYLIST_URLS
            )));
            $this->cacheTtlSeconds = $cacheTtlSeconds;

            $primaryDir = __DIR__ . DIRECTORY_SEPARATOR . 'storage';
            if (!@is_dir($primaryDir) && !@mkdir($primaryDir, 0777, true) && !@is_dir($primaryDir)) {
                $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'toffee_storage';
                if (!@is_dir($tempDir)) {
                    @mkdir($tempDir, 0777, true);
                }
                $this->cacheDirectory = @is_dir($tempDir) && @is_writable($tempDir) ? $tempDir : $primaryDir;
            } else {
                $this->cacheDirectory = $primaryDir;
            }
        }

        public function channels(): array
        {
            if ($this->channels !== null) {
                return $this->channels;
            }

            $this->channels = $this->loadAndMergeSources(false);
            if ($this->channels === []) {
                throw new RuntimeException('No valid channels found in the configured playlist sources.');
            }
            $this->channelMap = [];
            foreach ($this->channels as $channel) {
                $this->channelMap[$channel['id']] = $channel;
            }
            return $this->channels;
        }

        public function refreshPlaylist(): array
        {
            $this->usingStaleCache = false;
            $channels = $this->loadAndMergeSources(true);
            if ($channels === []) {
                throw new RuntimeException('No valid channels found in the configured playlist sources.');
            }
            $this->channels = $channels;
            $this->channelMap = [];
            foreach ($channels as $channel) {
                $this->channelMap[$channel['id']] = $channel;
            }
            return $channels;
        }

        public function sourceUrls(): array
        {
            return $this->sourceUrls;
        }

        public function isUsingStaleCache(): bool
        {
            return $this->usingStaleCache;
        }

        public function groups(): array
        {
            $groups = [];
            foreach ($this->channels() as $channel) {
                if ($channel['group'] !== '') {
                    $groups[$channel['group']] = true;
                }
            }
            $groups = array_keys($groups);
            sort($groups, SORT_STRING);
            return $groups;
        }

        public function getChannel(int $id): ?array
        {
            $this->channels();
            return $this->channelMap[$id] ?? null;
        }

        public function getChannelByPosition(int $position): ?array
        {
            if ($position < 1) {
                return null;
            }
            return $this->channels()[$position - 1] ?? null;
        }

        public function getChannelByNameOrSlug(string $identifier): ?array
        {
            $identifier = trim(urldecode($identifier));
            $clean = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '', $identifier)));
            if ($clean === '') {
                return null;
            }
            foreach ($this->channels() as $channel) {
                $nameClean = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '', $channel['name'])));
                if ($nameClean === $clean) {
                    return $channel;
                }
                if (!empty($channel['tvg_name'])) {
                    $tvgNameClean = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '', $channel['tvg_name'])));
                    if ($tvgNameClean === $clean) {
                        return $channel;
                    }
                }
                if (!empty($channel['tvg_id'])) {
                    $idClean = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '', $channel['tvg_id'])));
                    if ($idClean === $clean) {
                        return $channel;
                    }
                }
            }
            return null;
        }

        public function channelPosition(int $id): ?int
        {
            foreach ($this->channels() as $index => $channel) {
                if ($channel['id'] === $id) {
                    return $index + 1;
                }
            }
            return null;
        }

        public function searchChannels(string $query = '', string $group = ''): array
        {
            $query = strtolower(trim($query));
            $group = strtolower(trim($group));
            $result = [];

            foreach ($this->channels() as $channel) {
                if ($group !== '' && $group !== 'all' && strtolower($channel['group']) !== $group) {
                    continue;
                }
                if ($query !== '' && !str_contains(strtolower($channel['name']), $query)
                    && !str_contains(strtolower($channel['group']), $query)) {
                    continue;
                }
                $result[] = $channel;
            }
            return $result;
        }

        public function generateProxiedM3u(string $baseUrl, string $filterGroup = ''): string
        {
            $lines = ['#EXTM3U x-tvg-url="https://raw.githubusercontent.com/srhady/toffee-bd/refs/heads/main/toffee_epg.xml"'];
            $group = strtolower(trim($filterGroup));

            foreach ($this->channels() as $index => $channel) {
                if ($group !== '' && $group !== 'all' && strtolower($channel['group']) !== $group) {
                    continue;
                }

                $channelName = trim((string) ($channel['name'] ?? ''));
                if ($channelName === '') {
                    $channelName = 'Toffee Channel ' . ($index + 1);
                }

                $tvgId = !empty($channel['tvg_id']) ? $channel['tvg_id'] : $channelName;
                $tvgName = !empty($channel['tvg_name']) ? $channel['tvg_name'] : $channelName;
                $logo = !empty($channel['logo']) ? $channel['logo'] : '';
                $groupTitle = !empty($channel['group']) ? $channel['group'] : 'General';

                $attributes = ' tvg-id="' . $this->escapeM3uAttribute($tvgId) . '"';
                $attributes .= ' tvg-name="' . $this->escapeM3uAttribute($tvgName) . '"';
                if ($logo !== '') {
                    $attributes .= ' tvg-logo="' . $this->escapeM3uAttribute($logo) . '"';
                }
                $attributes .= ' group-title="' . $this->escapeM3uAttribute($groupTitle) . '"';

                $lines[] = '#EXTINF:-1' . $attributes . ',' . $channelName;
                $lines[] = rtrim($baseUrl, '/') . '/channel/' . ($index + 1) . '.m3u8';
            }

            return implode("\n", $lines) . "\n";
        }

        private function loadAndMergeSources(bool $forceRefresh): array
        {
            $merged = [];
            $identityIndexes = [];
            $loadedSources = 0;

            foreach ($this->sourceUrls as $sourceUrl) {
                $cacheFile = $this->cacheFileFor($sourceUrl);
                $rawText = null;
                $cacheIsFresh = @is_file($cacheFile)
                    && (time() - (int) @filemtime($cacheFile) < $this->cacheTtlSeconds);

                if (!$forceRefresh && $cacheIsFresh) {
                    $cached = @file_get_contents($cacheFile);
                    if ($cached !== false && $cached !== '') {
                        $parsedCache = $this->parseM3u($cached, $sourceUrl);
                        if ($parsedCache !== []) {
                            $rawText = $cached;
                        }
                    }
                }

                if ($rawText === null) {
                    try {
                        $rawText = $this->fetchPlaylist($sourceUrl);
                        $parsed = $this->parseM3u($rawText, $sourceUrl);
                        if ($parsed === []) {
                            throw new RuntimeException('Playlist contains no valid channel entries.');
                        }
                        $this->writeCache($cacheFile, $rawText);
                    } catch (Throwable $error) {
                        $cached = @is_file($cacheFile) ? @file_get_contents($cacheFile) : false;
                        $parsed = $cached !== false && $cached !== ''
                            ? $this->parseM3u($cached, $sourceUrl)
                            : [];
                        if ($parsed === []) {
                            error_log('[ToffeeManager] Playlist source unavailable (' . $sourceUrl . '): ' . $error->getMessage());
                            continue;
                        }
                        error_log('[ToffeeManager] Using cached playlist for ' . $sourceUrl . ': ' . $error->getMessage());
                        $rawText = $cached;
                        $this->usingStaleCache = true;
                    }
                } else {
                    $parsed = $this->parseM3u($rawText, $sourceUrl);
                }

                $loadedSources++;
                foreach ($parsed as $channel) {
                    $identityKeys = $this->channelIdentityKeys($channel);
                    $identity = null;
                    foreach ($identityKeys as $identityKey) {
                        if (isset($identityIndexes[$identityKey])) {
                            $identity = $identityKey;
                            break;
                        }
                    }

                    if ($identity === null) {
                        $index = count($merged);
                        $identity = $identityKeys[0];
                        $channel['_identity'] = $identity;
                        $merged[] = $channel;
                    } else {
                        $index = $identityIndexes[$identity];
                        $existingSourceUrls = array_column($merged[$index]['_sources'], 'source_url');
                        if (!in_array($sourceUrl, $existingSourceUrls, true)) {
                            $merged[$index]['_sources'][] = $channel['_sources'][0];
                        }
                    }

                    foreach ($identityKeys as $identityKey) {
                        $identityIndexes[$identityKey] = $index;
                    }
                }
            }

            if ($loadedSources === 0) {
                $fallbackPaths = [
                    dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'toffee_raw.m3u',
                    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'toffee_raw.m3u',
                    __DIR__ . DIRECTORY_SEPARATOR . 'toffee_raw.m3u',
                ];
                foreach ($fallbackPaths as $fp) {
                    if (@is_file($fp) && @is_readable($fp)) {
                        $rawText = @file_get_contents($fp);
                        if ($rawText !== false && $rawText !== '') {
                            $parsed = $this->parseM3u($rawText, 'local_raw');
                            if ($parsed !== []) {
                                error_log('[ToffeeManager] Successfully loaded local toffee_raw.m3u fallback.');
                                $merged = $parsed;
                                $loadedSources++;
                                $this->usingStaleCache = true;
                                break;
                            }
                        }
                    }
                }
            }

            if ($loadedSources === 0) {
                throw new RuntimeException('Unable to load any playlist source. Check outbound HTTPS and PHP cURL/SSL configuration.');
            }

            $filteredChannels = [];
            foreach ($merged as $channel) {
                if (!$this->isEplChannel($channel) && !$this->isTestSrtChannel($channel)) {
                    $filteredChannels[] = $channel;
                }
            }
            $merged = $filteredChannels;
            $usedIds = [];
            foreach ($merged as &$channel) {
                $id = hexdec(substr(hash('sha256', $channel['_identity'] ?? $channel['name']), 0, 7));
                while (isset($usedIds[$id])) {
                    $id++;
                }
                $usedIds[$id] = true;
                $channel['id'] = $id;
                unset($channel['_identity']);
            }
            unset($channel);

            return $merged;
        }

        private function isEplChannel(array $channel): bool
        {
            return preg_match('/^epl\\s+channel\\b/i', trim($channel['name'])) === 1;
        }

        private function isTestSrtChannel(array $channel): bool
        {
            return preg_match('/^test\\s+srt\\b/i', trim($channel['name'])) === 1;
        }

        private function channelIdentityKeys(array $channel): array
        {
            $name = preg_replace('/[^\\p{L}\\p{N}]+/u', ' ', trim($channel['name']));
            $keys = ['name:' . strtolower(trim($name ?? $channel['name']))];
            if ($channel['tvg_id'] !== '') {
                $keys[] = 'tvg:' . strtolower(trim($channel['tvg_id']));
            }
            return $keys;
        }

        private function cacheFileFor(string $sourceUrl): string
        {
            return $this->cacheDirectory . DIRECTORY_SEPARATOR . 'playlist_' . hash('sha256', $sourceUrl) . '.m3u';
        }

        private function fetchPlaylist(string $sourceUrl): string
        {
            $scheme = strtolower((string) parse_url($sourceUrl, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) {
                throw new RuntimeException('Playlist source must use HTTP or HTTPS.');
            }

            $curl = curl_init($sourceUrl);
            if ($curl === false) {
                throw new RuntimeException('Unable to initialize cURL.');
            }

            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_USERAGENT => DEFAULT_USER_AGENT,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ];
            curl_setopt_array($curl, $options);
            applyToffeeCurlSslOptions($curl);

            $content = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);

            if ($content === false || $status < 200 || $status >= 300) {
                throw new RuntimeException($error !== '' ? $error : 'Playlist server returned HTTP ' . $status . '.');
            }
            if (trim((string) $content) === '') {
                throw new RuntimeException('Playlist source returned an empty response.');
            }
            return (string) $content;
        }

        private function writeCache(string $cacheFile, string $content): void
        {
            $directory = $this->cacheDirectory;
            if (!@is_dir($directory) && !@mkdir($directory, 0777, true) && !@is_dir($directory)) {
                error_log('[ToffeeManager] Could not create playlist cache directory: ' . $directory);
                return;
            }

            $temporaryFile = $cacheFile . '.' . bin2hex(random_bytes(6)) . '.tmp';
            if (@file_put_contents($temporaryFile, $content, LOCK_EX) === false) {
                error_log('[ToffeeManager] Could not write playlist cache file.');
                return;
            }
            if (@is_file($cacheFile)) {
                @unlink($cacheFile);
            }
            if (!@rename($temporaryFile, $cacheFile)) {
                @unlink($temporaryFile);
                error_log('[ToffeeManager] Could not replace playlist cache file.');
            }
        }

        private function parseM3u(string $content, string $sourceUrl): array
        {
            $channels = [];
            $current = null;
            $id = 1;

            foreach (preg_split('/\\r\\n|\\r|\\n/', $content) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                if (str_starts_with($line, '#EXTINF:')) {
                    $comma = strrpos($line, ',');
                    $name = $comma === false ? '' : trim(substr($line, $comma + 1));
                    $current = [
                        'id' => $id,
                        'name' => $name !== '' ? $name : 'Toffee CH ' . $id,
                        'url' => '',
                        'group' => 'General',
                        'logo' => '',
                        'tvg_id' => '',
                        'tvg_name' => '',
                        'headers' => ['User-Agent' => DEFAULT_USER_AGENT],
                        '_sources' => [],
                    ];
                    foreach (['group-title' => 'group', 'tvg-logo' => 'logo', 'tvg-id' => 'tvg_id', 'tvg-name' => 'tvg_name'] as $attribute => $field) {
                        if (preg_match('/' . preg_quote($attribute, '/') . '=\"([^\"]*)\"/i', $line, $matches)) {
                            $current[$field] = trim($matches[1]);
                        }
                    }
                    if ($current['group'] === '') {
                        $current['group'] = 'General';
                    }
                    continue;
                }

                if ($line[0] === '#' || $current === null || $current['url'] !== '') {
                    continue;
                }

                $current['url'] = $line;
                $current['_sources'][] = [
                    'source_url' => $sourceUrl,
                    'url' => $line,
                    'headers' => $current['headers'],
                ];
                $channels[] = $current;
                $id++;
                $current = null;
            }

            return $channels;
        }

        private function escapeM3uAttribute(string $value): string
        {
            return str_replace(['\\\\', '\"', "\r", "\n"], ['\\\\', '\\"', '', ''], $value);
        }
    }
}
