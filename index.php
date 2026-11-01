<?php
declare(strict_types=1);

error_reporting(0);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

if (php_sapi_name() === 'cli-server') {
    $rawReqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $diskFile = __DIR__ . $rawReqPath;
    if ($rawReqPath !== '/' && is_file($diskFile) && !str_ends_with($diskFile, '.php')) {
        return false;
    }
}

/**
 * ============================================================================
 * TOFFEE LIVE RESTREAM & WEB PLAYER - FULL STANDALONE PHP EDITION (WITH RAM CACHE)
 * ============================================================================
 */

const DEFAULT_PLAYLIST_URL = 'https://raw.githubusercontent.com/srhady/toffee-bd/refs/heads/main/toffee_playlist.m3u';
const ADDITIONAL_PLAYLIST_URLS = [
    'https://raw.githubusercontent.com/sm-monirulislam/Toffee-Auto-Update/refs/heads/main/toffee_playlist.m3u',
    'https://raw.githubusercontent.com/kgkaku/Toffee-Auto-Update-Playlist/refs/heads/main/universal.m3u',
];
const DEFAULT_USER_AGENT = 'Toffee (Linux;Android 14) AndroidXMedia3/1.1.1/64100410/4d2ec9b8c3502383';

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

function applyToffeeCurlSslOptions($curl): void
{
    $caBundle = toffeeCaBundlePath();
    if ($caBundle !== null) {
        curl_setopt($curl, CURLOPT_CAINFO, $caBundle);
    }
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
}

function sendCorsHeaders(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
    header('Access-Control-Allow-Headers: *');
    header('Access-Control-Expose-Headers: Content-Length, Content-Range, X-Proxy-Cache, X-Stream-Recovery');
}

function sendJson(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    sendCorsHeaders();
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function applicationBasePath(): string
{
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $directory = str_replace('\\', '/', dirname($scriptName));
    return ($directory === '/' || $directory === '.') ? '' : rtrim($directory, '/');
}

function requestBaseUrl(): string
{
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    return $scheme . '://' . $host . applicationBasePath();
}

function appPath(string $requestPath): string
{
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $scriptBasename = basename($scriptName);

    if (!empty($_SERVER['PATH_INFO'])) {
        $path = '/' . ltrim($_SERVER['PATH_INFO'], '/');
        return $path === '' ? '/' : $path;
    }

    $basePath = applicationBasePath();
    if ($basePath !== '' && str_starts_with($requestPath, $basePath)) {
        $requestPath = substr($requestPath, strlen($basePath));
    }

    if (in_array(strtolower($scriptBasename), ['index.php', 'toffee.php'], true)) {
        if (str_starts_with($requestPath, '/' . $scriptBasename)) {
            $requestPath = substr($requestPath, strlen('/' . $scriptBasename));
        }
    } elseif (str_starts_with($requestPath, '/index.php')) {
        $requestPath = substr($requestPath, strlen('/index.php'));
    } elseif (str_starts_with($requestPath, '/toffee.php')) {
        $requestPath = substr($requestPath, strlen('/toffee.php'));
    }

    $requestPath = '/' . ltrim($requestPath, '/');
    return $requestPath === '' ? '/' : $requestPath;
}

function resolveUrl(string $baseUrl, string $reference): string
{
    if (preg_match('~^https?://~i', $reference)) {
        return $reference;
    }
    $base = parse_url($baseUrl);
    if ($base === false || empty($base['scheme']) || empty($base['host'])) {
        throw new RuntimeException('Invalid upstream manifest URL.');
    }
    $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');

    if (str_starts_with($reference, '//')) {
        return $base['scheme'] . ':' . $reference;
    }

    $referenceParts = parse_url($reference);
    if ($referenceParts === false) {
        throw new RuntimeException('Invalid URL in upstream manifest.');
    }
    $referencePath = $referenceParts['path'] ?? '';
    if ($referencePath === '') {
        $path = $base['path'] ?? '/';
    } elseif (str_starts_with($referencePath, '/')) {
        $path = $referencePath;
    } else {
        $path = rtrim(dirname($base['path'] ?? '/'), '/') . '/' . $referencePath;
    }

    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
        } else {
            $segments[] = $segment;
        }
    }
    $path = '/' . implode('/', $segments);
    $query = array_key_exists('query', $referenceParts)
        ? '?' . $referenceParts['query']
        : (($referencePath === '' && isset($base['query'])) ? '?' . $base['query'] : '');
    return $origin . $path . $query;
}

function upstreamHeaders(?array $channel = null): array
{
    $headers = [];
    $ua = $channel['headers']['User-Agent'] ?? DEFAULT_USER_AGENT;
    $headers[] = 'User-Agent: ' . $ua;
    if (isset($channel['headers'])) {
        foreach ($channel['headers'] as $name => $value) {
            if (strcasecmp($name, 'User-Agent') !== 0) {
                $headers[] = $name . ': ' . $value;
            }
        }
    }
    $headers[] = 'Accept: */*';
    if (!empty($_SERVER['HTTP_RANGE'])) {
        $headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
    }
    return $headers;
}

function validateUpstreamUrl(string $url): void
{
    $parts = parse_url($url);
    if ($parts === false || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        throw new RuntimeException('Upstream URL must be an HTTP(S) URL without embedded credentials.');
    }
}

function fetchManifest(string $url, ?array $channel = null): string
{
    validateUpstreamUrl($url);
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Unable to initialize cURL.');
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => upstreamHeaders($channel),
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    applyToffeeCurlSslOptions($curl);

    $content = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);

    if ($content === false || $status < 200 || $status >= 300) {
        $detail = $status > 0 ? 'HTTP ' . $status : $error;
        throw new RuntimeException('Upstream manifest request failed: ' . $detail . '.');
    }
    return (string) $content;
}

function rewriteManifest(
    string $content,
    string $manifestUrl,
    string $baseUrl,
    int $channelId,
    int $sourceIndex,
    int $variantIndex
): string
{
    $output = [];
    $lastTag = '';
    $nextVariantIndex = 0;
    $pendingVariantIndex = null;
    $nextSequence = 0;
    $hasSequence = false;
    foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            $output[] = $line;
            continue;
        }
        if (str_starts_with($trimmed, '#')) {
            $tag = explode(':', $trimmed, 2)[0];
            if ($tag === '#EXT-X-STREAM-INF') {
                $pendingVariantIndex = $nextVariantIndex++;
            } elseif ($tag === '#EXT-X-MEDIA-SEQUENCE' && preg_match('/^#EXT-X-MEDIA-SEQUENCE:(\d+)/i', $trimmed, $matches)) {
                $nextSequence = (int) $matches[1];
                $hasSequence = true;
            }
            $attributeProxyPath = match ($tag) {
                '#EXT-X-KEY' => '/proxy/key',
                '#EXT-X-MAP' => '/proxy/ts',
                '#EXT-X-MEDIA', '#EXT-X-I-FRAME-STREAM-INF' => '/proxy/m3u8',
                default => null,
            };
            if ($attributeProxyPath !== null && str_contains($trimmed, 'URI="')) {
                $trimmed = preg_replace_callback('/URI="([^"]+)"/i', static function (array $matches) use ($manifestUrl, $baseUrl, $channelId, $sourceIndex, $variantIndex, $attributeProxyPath): string {
                    $target = resolveUrl($manifestUrl, $matches[1]);
                    return 'URI="' . $baseUrl . $attributeProxyPath . '?' . http_build_query(
                        ['url' => $target, 'cid' => $channelId, 'src' => $sourceIndex, 'variant' => $variantIndex],
                        '',
                        '&',
                        PHP_QUERY_RFC3986
                    ) . '"';
                }, $trimmed);
            }
            $output[] = $trimmed;
            $lastTag = $tag;
            continue;
        }

        $absoluteUrl = resolveUrl($manifestUrl, $trimmed);
        $isManifest = in_array($lastTag, ['#EXT-X-STREAM-INF', '#EXT-X-I-FRAME-STREAM-INF'], true)
            || stripos($absoluteUrl, '.m3u8') !== false;
        $proxyPath = $isManifest ? '/proxy/m3u8' : '/proxy/ts';
        $requestVariantIndex = $pendingVariantIndex ?? $variantIndex;
        $query = ['url' => $absoluteUrl, 'cid' => $channelId, 'src' => $sourceIndex, 'variant' => $requestVariantIndex];
        if (!$isManifest) {
            if ($hasSequence) {
                $query['seq'] = $nextSequence++;
            }
            if (preg_match('/^#EXTINF:([0-9.]+)/i', $lastTag, $durationMatch)) {
                $query['dur'] = $durationMatch[1];
            }
        }
        $output[] = $baseUrl . $proxyPath . '?' . http_build_query(
            $query,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
        $pendingVariantIndex = null;
        $lastTag = '';
    }
    return implode("\n", $output) . "\n";
}

function sendManifest(
    string $content,
    string $manifestUrl,
    ?array $channel,
    string $baseUrl,
    int $sourceIndex,
    int $variantIndex
): never
{
    $rewritten = rewriteManifest(
        $content,
        $manifestUrl,
        $baseUrl,
        $channel['id'] ?? 0,
        $sourceIndex,
        $variantIndex
    );
    header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    sendCorsHeaders();
    echo $rewritten;
    exit;
}

function manifestVariantUrls(string $content, string $manifestUrl): array
{
    $variants = [];
    $awaitingVariantUri = false;
    foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }
        if (str_starts_with($trimmed, '#')) {
            $awaitingVariantUri = str_starts_with($trimmed, '#EXT-X-STREAM-INF:');
            continue;
        }
        if ($awaitingVariantUri) {
            $variants[] = resolveUrl($manifestUrl, $trimmed);
            $awaitingVariantUri = false;
        }
    }
    return $variants;
}

function fetchCurrentMediaManifest(array $candidate, int $variantIndex): array
{
    $url = $candidate['url'];
    $content = fetchManifest($url, $candidate);
    for ($depth = 0; $depth < 3; $depth++) {
        $variants = manifestVariantUrls($content, $url);
        if ($variants === []) {
            return ['content' => $content, 'url' => $url];
        }
        $selectedIndex = min(max($variantIndex, 0), count($variants) - 1);
        $url = $variants[$selectedIndex];
        $content = fetchManifest($url, $candidate);
    }
    if (manifestVariantUrls($content, $url) !== []) {
        throw new RuntimeException('Upstream manifest nesting exceeds the supported depth.');
    }
    return ['content' => $content, 'url' => $url];
}

function findCurrentMediaManifest(array $channel, int $preferredSourceIndex, int $variantIndex): ?array
{
    $candidates = $channel['_sources'] ?? [[
        'url' => $channel['url'],
        'headers' => $channel['headers'],
    ]];
    $candidateIndexes = array_values(array_unique(array_merge(
        [$preferredSourceIndex],
        array_keys($candidates)
    )));
    foreach ($candidateIndexes as $candidateIndex) {
        if (!isset($candidates[$candidateIndex])) {
            continue;
        }
        try {
            $manifest = fetchCurrentMediaManifest($candidates[$candidateIndex], $variantIndex);
            $manifest['source_index'] = $candidateIndex;
            return $manifest;
        } catch (Throwable $error) {
            error_log('[ToffeeProxy] Refreshed source manifest failed for channel ' . $channel['id']
                . ' source ' . $candidateIndex . ': ' . $error->getMessage());
        }
    }
    return null;
}

function manifestRecoveryResources(string $content, string $manifestUrl, string $contentType): array
{
    $resources = [];
    $sequence = 0;
    $duration = null;
    foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
        $trimmed = trim($line);
        if (preg_match('/^#EXT-X-MEDIA-SEQUENCE:(\d+)/i', $trimmed, $matches)) {
            $sequence = (int) $matches[1];
            continue;
        }
        if ($contentType === 'video/mp2t' && preg_match('/^#EXTINF:([0-9.]+)/i', $trimmed, $matches)) {
            $duration = (float) $matches[1];
            continue;
        }
        if (preg_match('/^#EXT-X-(?:KEY|MAP):.*URI="([^"]+)"/i', $trimmed, $matches)) {
            $resources[] = ['url' => resolveUrl($manifestUrl, $matches[1]), 'sequence' => null, 'duration' => null];
            continue;
        }
        if ($trimmed === '' || $trimmed[0] === '#' || $contentType !== 'video/mp2t') {
            continue;
        }
        $resources[] = ['url' => resolveUrl($manifestUrl, $trimmed), 'sequence' => $sequence++, 'duration' => $duration];
        $duration = null;
    }
    return $resources;
}

function recoveryResourceUrl(
    string $content,
    string $manifestUrl,
    string $failedUrl,
    string $contentType,
    ?int $sequence,
    ?float $duration
): ?string
{
    $resources = manifestRecoveryResources($content, $manifestUrl, $contentType);
    $failedPath = parse_url($failedUrl, PHP_URL_PATH);
    foreach ($resources as $resource) {
        if ($failedPath !== false
            && parse_url($resource['url'], PHP_URL_PATH) === $failedPath) {
            return $resource['url'];
        }
    }
    if ($contentType === 'video/mp2t' && $sequence !== null) {
        foreach ($resources as $resource) {
            if ($resource['sequence'] !== $sequence) {
                continue;
            }
            if ($duration !== null && $resource['duration'] !== null
                && abs($resource['duration'] - $duration) > 0.75) {
                continue;
            }
            return $resource['url'];
        }
    }
    return null;
}

function recoveryResourceCandidates(
    array $channel,
    int $preferredSourceIndex,
    int $variantIndex,
    string $failedUrl,
    string $contentType,
    ?int $sequence,
    ?float $duration
): array
{
    $candidates = $channel['_sources'] ?? [[
        'url' => $channel['url'],
        'headers' => $channel['headers'],
    ]];
    $candidateIndexes = array_values(array_unique(array_merge(
        [$preferredSourceIndex],
        array_keys($candidates)
    )));
    $resources = [];
    foreach ($candidateIndexes as $candidateIndex) {
        if (!isset($candidates[$candidateIndex])) {
            continue;
        }
        try {
            $manifest = fetchCurrentMediaManifest($candidates[$candidateIndex], $variantIndex);
            $replacementUrl = recoveryResourceUrl(
                $manifest['content'],
                $manifest['url'],
                $failedUrl,
                $contentType,
                $sequence,
                $duration
            );
            if ($replacementUrl !== null) {
                $resources[] = [
                    'url' => $replacementUrl,
                    'source_index' => $candidateIndex,
                    'headers' => $candidates[$candidateIndex]['headers'] ?? [],
                ];
            }
        } catch (Throwable $error) {
            error_log('[ToffeeProxy] Recovery manifest failed for channel ' . $channel['id']
                . ' source ' . $candidateIndex . ': ' . $error->getMessage());
        }
    }
    return null !== $resources ? $resources : [];
}

function fetchRecoveryResource(string $url, array $headers): ?array
{
    $curl = curl_init($url);
    if ($curl === false) {
        return null;
    }
    $responseHeaders = [];
    $bodySize = 0;
    $bodyLimit = 16 * 1024 * 1024;
    curl_setopt_array($curl, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => upstreamHeaders($headers),
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_FAILONERROR => false,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseHeaders, &$bodySize, $bodyLimit): int {
            $chunkSize = strlen($chunk);
            if ($bodySize + $chunkSize > $bodyLimit) {
                return 0;
            }
            $responseHeaders['_body'] = ($responseHeaders['_body'] ?? '') . $chunk;
            $bodySize += $chunkSize;
            return $chunkSize;
        },
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
            $length = strlen($line);
            if (preg_match('~^HTTP/\S+\s+\d{3}~i', $line)) {
                $responseHeaders = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
            return $length;
        },
    ]);
    applyToffeeCurlSslOptions($curl);

    $result = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);

    $body = $responseHeaders['_body'] ?? '';
    unset($responseHeaders['_body']);
    if ($result === false || $status < 200 || $status >= 300 || $body === '') {
        if ($error !== '') {
            error_log('[ToffeeProxy] Refreshed media fetch failed: ' . $error);
        }
        return null;
    }
    return [
        'status' => $status,
        'content_type' => $responseHeaders['content-type'] ?? 'application/octet-stream',
        'body' => $body,
    ];
}

function serveChannelManifest(array $channel, string $baseUrl, PlaylistManager $manager): never
{
    $candidates = $channel['_sources'] ?? [[
        'url' => $channel['url'],
        'headers' => $channel['headers'],
    ]];
    $needsRefresh = false;
    $lastError = null;

    for ($attempt = 0; $attempt < 2; $attempt++) {
        foreach ($candidates as $sourceIndex => $candidate) {
            try {
                $content = fetchManifest($candidate['url'], $candidate);
                sendManifest($content, $candidate['url'], $channel, $baseUrl, $sourceIndex, 0);
            } catch (Throwable $error) {
                $lastError = $error;
                if (preg_match('/HTTP (?:401|403)\b/', $error->getMessage())) {
                    $needsRefresh = true;
                }
            }
        }

        if ($attempt === 0 && $needsRefresh) {
            try {
                $manager->refreshPlaylist();
                $channel = $manager->getChannel((int) $channel['id']) ?? $channel;
                $candidates = $channel['_sources'] ?? $candidates;
                $needsRefresh = false;
                continue;
            } catch (Throwable $error) {
                $lastError = $error;
            }
        }
        break;
    }

    error_log('[ToffeeProxy] All stream sources failed for channel ' . $channel['id'] . ': '
        . ($lastError?->getMessage() ?? 'No source manifest available.'));
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    sendCorsHeaders();
    echo 'All stream sources are currently unavailable.';
    exit;
}

function serveManifest(
    string $url,
    ?array $channel,
    string $baseUrl,
    PlaylistManager $manager,
    int $sourceIndex,
    int $variantIndex
): never
{
    try {
        $content = fetchManifest($url, $channel);
        sendManifest($content, $url, $channel, $baseUrl, $sourceIndex, $variantIndex);
    } catch (Throwable $error) {
        if ($channel !== null && preg_match('/HTTP (?:401|403)\b/', $error->getMessage())) {
            try {
                $manager->refreshPlaylist();
                $freshChannel = $manager->getChannel((int) $channel['id']) ?? $channel;
                $freshManifest = findCurrentMediaManifest($freshChannel, $sourceIndex, $variantIndex);
                if ($freshManifest !== null) {
                    sendManifest(
                        $freshManifest['content'],
                        $freshManifest['url'],
                        $freshChannel,
                        $baseUrl,
                        $freshManifest['source_index'],
                        $variantIndex
                    );
                }
            } catch (Throwable $refreshError) {
                error_log('[ToffeeProxy] Failed to refresh expired stream token: ' . $refreshError->getMessage());
            }
        }
        error_log('[ToffeeProxy] Manifest fetch failed: ' . $error->getMessage());
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        sendCorsHeaders();
        echo 'Manifest fetch failed.';
        exit;
    }
}

function segmentCacheDirectory(): ?string
{
    static $resolved = false;
    if ($resolved !== false) {
        return $resolved;
    }

    $openBaseDir = ini_get('open_basedir');

    // 1. Try Linux /dev/shm only if open_basedir allows it or is empty
    if (empty($openBaseDir) || str_contains((string)$openBaseDir, '/dev/shm')) {
        if (@is_dir('/dev/shm') && @is_writable('/dev/shm')) {
            $dir = '/dev/shm/toffee_segment_cache';
            if (@is_dir($dir) || @mkdir($dir, 0777, true)) {
                if (@is_writable($dir)) {
                    return $resolved = $dir;
                }
            }
        }
    }

    // 2. Try sys_get_temp_dir() (/tmp is almost always permitted in open_basedir)
    $tempRoot = sys_get_temp_dir();
    if (@is_dir($tempRoot) && @is_writable($tempRoot)) {
        $dir = rtrim($tempRoot, '/\\') . DIRECTORY_SEPARATOR . 'toffee_segment_cache';
        if (@is_dir($dir) || @mkdir($dir, 0777, true)) {
            if (@is_writable($dir)) {
                return $resolved = $dir;
            }
        }
    }

    // 3. Fallback to local storage inside project
    $localDir = __DIR__ . DIRECTORY_SEPARATOR . 'toffee' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'segment_cache';
    if (@is_dir($localDir) || @mkdir($localDir, 0777, true)) {
        if (@is_writable($localDir)) {
            return $resolved = $localDir;
        }
    }

    return $resolved = null;
}

function cachedSegmentMetadata(string $cacheFile, string $metadataFile, int $ttl): ?array
{
    @clearstatcache(true, $cacheFile);
    @clearstatcache(true, $metadataFile);
    if (!@is_file($cacheFile) || !@is_file($metadataFile) || @filemtime($cacheFile) < time() - $ttl) {
        return null;
    }
    $metadata = json_decode((string) @file_get_contents($metadataFile), true);
    return is_array($metadata) && isset($metadata['status'], $metadata['content_type'])
        ? $metadata
        : null;
}

function serveSegmentFile(string $file, array $metadata, string $cacheStatus, bool $deleteAfterRead = false): never
{
    http_response_code((int) $metadata['status']);
    header('Content-Type: ' . $metadata['content_type']);
    header('Content-Length: ' . (string) filesize($file));
    foreach (['content-range' => 'Content-Range', 'accept-ranges' => 'Accept-Ranges'] as $key => $headerName) {
        if (!empty($metadata[$key])) {
            header($headerName . ': ' . $metadata[$key]);
        }
    }
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Proxy-Cache: ' . $cacheStatus);
    sendCorsHeaders();
    readfile($file);
    if ($deleteAfterRead) {
        @unlink($file);
    }
    exit;
}

function trimSegmentCache(string $directory, int $incomingBytes): void
{
    $maxCacheBytes = 128 * 1024 * 1024;
    $now = time();
    $entries = [];
    $totalBytes = 0;

    foreach (glob($directory . DIRECTORY_SEPARATOR . '*.bin') ?: [] as $file) {
        $size = (int) @filesize($file);
        $metadataFile = substr($file, 0, -4) . '.json';
        $mtime = (int) @filemtime($file);

        if ($mtime < $now - 60) {
            @unlink($file);
            @unlink($metadataFile);
            continue;
        }
        $entries[] = ['file' => $file, 'metadata' => $metadataFile, 'size' => $size, 'mtime' => $mtime];
        $totalBytes += $size;
    }

    if ($totalBytes + $incomingBytes > $maxCacheBytes) {
        usort($entries, static fn(array $left, array $right): int => $left['mtime'] <=> $right['mtime']);
        foreach ($entries as $entry) {
            if ($totalBytes + $incomingBytes <= $maxCacheBytes) {
                break;
            }
            @unlink($entry['file']);
            @unlink($entry['metadata']);
            $totalBytes -= $entry['size'];
        }
    }
}

function proxyBinary(
    string $url,
    ?array $channel,
    string $defaultContentType,
    PlaylistManager $manager
): never
{
    try {
        validateUpstreamUrl($url);
    } catch (RuntimeException $error) {
        http_response_code(400);
        exit($error->getMessage());
    }
    @set_time_limit(0);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if ($defaultContentType === 'application/octet-stream') {
        $curl = curl_init($url);
        if ($curl === false) {
            http_response_code(502);
            exit('Unable to initialize cURL.');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => upstreamHeaders($channel),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        applyToffeeCurlSslOptions($curl);

        $keyData = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        if (($keyData === false || $status === 401 || $status === 403) && $channel !== null) {
            try {
                $manager->refreshPlaylist();
                $freshChannel = $manager->getChannel((int) $channel['id']) ?? $channel;
                $sourceIndex = filter_var($_GET['src'] ?? 0, FILTER_VALIDATE_INT);
                $sourceIndex = $sourceIndex === false ? 0 : max(0, $sourceIndex);
                $variantIndex = filter_var($_GET['variant'] ?? 0, FILTER_VALIDATE_INT);
                $variantIndex = $variantIndex === false ? 0 : max(0, $variantIndex);
                $replacements = recoveryResourceCandidates($freshChannel, $sourceIndex, $variantIndex, $url, $defaultContentType, null, null);
                foreach ($replacements as $replacement) {
                    $recovered = fetchRecoveryResource($replacement['url'], $replacement['headers']);
                    if ($recovered !== null) {
                        http_response_code(200);
                        header('Content-Type: application/octet-stream');
                        header('Content-Length: ' . (string) strlen($recovered['body']));
                        header('Cache-Control: no-cache, no-store, must-revalidate');
                        header('X-Stream-Recovery: refreshed-key');
                        sendCorsHeaders();
                        echo $recovered['body'];
                        exit;
                    }
                }
            } catch (Throwable $e) {
                error_log('[ToffeeProxy] Key recovery error: ' . $e->getMessage());
            }
        }

        if ($keyData === false || $status < 200 || $status >= 300) {
            http_response_code(502);
            header('Content-Type: text/plain; charset=utf-8');
            sendCorsHeaders();
            exit('Key proxy failed.');
        }

        http_response_code(200);
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . (string) strlen((string)$keyData));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        sendCorsHeaders();
        echo $keyData;
        exit;
    }

    $cacheEligible = (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET')
        && empty($_SERVER['HTTP_RANGE']);
    $cacheDirectory = $cacheEligible ? segmentCacheDirectory() : null;
    $cacheTtl = 25;

    if ($cacheDirectory !== null) {
        $cacheKey = hash('sha256', $url);
        $cacheFile = $cacheDirectory . DIRECTORY_SEPARATOR . $cacheKey . '.bin';
        $cacheMetaFile = $cacheDirectory . DIRECTORY_SEPARATOR . $cacheKey . '.json';

        $meta = cachedSegmentMetadata($cacheFile, $cacheMetaFile, $cacheTtl);
        if ($meta !== null && @is_file($cacheFile)) {
            serveSegmentFile($cacheFile, $meta, 'HIT');
        }
    }

    $cacheTempFile = ($cacheDirectory !== null) ? @tempnam($cacheDirectory, 'seg_') : null;
    $cacheTempHandle = is_string($cacheTempFile) ? @fopen($cacheTempFile, 'wb') : null;

    $curl = curl_init($url);
    if ($curl === false) {
        if (is_resource($cacheTempHandle)) {
            fclose($cacheTempHandle);
        }
        if (is_string($cacheTempFile) && @is_file($cacheTempFile)) {
            @unlink($cacheTempFile);
        }
        http_response_code(502);
        exit('Unable to initialize cURL.');
    }

    $responseStatus = 200;
    $responseHeaders = [];
    $headersSent = false;

    $headerCallback = static function ($handle, string $line) use (&$responseStatus, &$responseHeaders): int {
        $length = strlen($line);
        if (preg_match('~^HTTP/\S+\s+(\d{3})~i', $line, $matches)) {
            $responseStatus = (int) $matches[1];
            $responseHeaders = [];
        } elseif (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $responseHeaders[strtolower(trim($name))] = trim($value);
        }
        return $length;
    };

    $writeCallback = static function ($handle, string $chunk) use (
        &$headersSent, &$responseStatus, &$responseHeaders, $defaultContentType, &$cacheTempHandle
    ): int {
        $chunkLen = strlen($chunk);

        if ($responseStatus < 200 || $responseStatus >= 300) {
            return $chunkLen;
        }

        if (!$headersSent) {
            http_response_code($responseStatus);
            header('Content-Type: ' . ($responseHeaders['content-type'] ?? $defaultContentType));
            if (!empty($responseHeaders['content-length'])) {
                header('Content-Length: ' . $responseHeaders['content-length']);
            }
            if (!empty($responseHeaders['content-range'])) {
                header('Content-Range: ' . $responseHeaders['content-range']);
            }
            if (!empty($responseHeaders['accept-ranges'])) {
                header('Accept-Ranges: ' . $responseHeaders['accept-ranges']);
            }
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('X-Proxy-Cache: MISS');
            header('X-Accel-Buffering: no');
            sendCorsHeaders();
            $headersSent = true;
        }

        echo $chunk;
        flush();

        if (is_resource($cacheTempHandle)) {
            @fwrite($cacheTempHandle, $chunk);
        }

        return $chunkLen;
    };

    curl_setopt_array($curl, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => upstreamHeaders($channel),
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HEADERFUNCTION => $headerCallback,
        CURLOPT_WRITEFUNCTION => $writeCallback,
    ]);
    applyToffeeCurlSslOptions($curl);

    $result = curl_exec($curl);
    $error = curl_error($curl);

    if (is_resource($cacheTempHandle)) {
        fclose($cacheTempHandle);
        $cacheTempHandle = null;
    }

    if ($result !== false && $responseStatus >= 200 && $responseStatus < 300
        && is_string($cacheTempFile) && @is_file($cacheTempFile)
        && isset($cacheFile, $cacheMetaFile, $cacheDirectory)) {
        $size = (int) @filesize($cacheTempFile);
        if ($size > 0 && $size <= 16 * 1024 * 1024) {
            $metadata = [
                'status' => $responseStatus,
                'content_type' => $responseHeaders['content-type'] ?? $defaultContentType,
                'content_length' => (string) $size,
                'accept-ranges' => 'bytes',
            ];
            @file_put_contents($cacheMetaFile, json_encode($metadata), LOCK_EX);
            if (@is_file($cacheFile)) {
                @unlink($cacheFile);
            }
            @rename($cacheTempFile, $cacheFile);
            trimSegmentCache((string) $cacheDirectory, $size);
        } else {
            @unlink($cacheTempFile);
        }
    } elseif (is_string($cacheTempFile) && @is_file($cacheTempFile)) {
        @unlink($cacheTempFile);
    }

    if (!$headersSent && in_array($responseStatus, [401, 403], true) && $channel !== null) {
        $sourceIndex = filter_var($_GET['src'] ?? 0, FILTER_VALIDATE_INT);
        $sourceIndex = $sourceIndex === false ? 0 : max(0, $sourceIndex);
        $variantIndex = filter_var($_GET['variant'] ?? 0, FILTER_VALIDATE_INT);
        $variantIndex = $variantIndex === false ? 0 : max(0, $variantIndex);
        $sequence = filter_var($_GET['seq'] ?? null, FILTER_VALIDATE_INT);
        $duration = is_numeric($_GET['dur'] ?? null) ? (float) $_GET['dur'] : null;

        try {
            $manager->refreshPlaylist();
            $freshChannel = $manager->getChannel((int) $channel['id']) ?? $channel;
            $replacements = recoveryResourceCandidates(
                $freshChannel,
                $sourceIndex,
                $variantIndex,
                $url,
                $defaultContentType,
                $sequence === false ? null : $sequence,
                $duration
            );
            foreach ($replacements as $replacement) {
                $response = fetchRecoveryResource($replacement['url'], $replacement['headers']);
                if ($response === null) {
                    continue;
                }
                http_response_code($response['status']);
                header('Content-Type: ' . $response['content_type']);
                header('Content-Length: ' . (string) strlen($response['body']));
                header('Cache-Control: no-store, no-cache, must-revalidate');
                header('X-Proxy-Cache: BYPASS');
                header('X-Stream-Recovery: refreshed-source');
                sendCorsHeaders();
                echo $response['body'];
                exit;
            }
        } catch (Throwable $e) {
            error_log('[ToffeeProxy] Segment recovery error: ' . $e->getMessage());
        }
    }

    if (!$headersSent) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        sendCorsHeaders();
        echo 'Upstream media request failed: ' . ($error !== '' ? $error : 'HTTP ' . $responseStatus);
    }
    exit;
}

function channelFromRequest(PlaylistManager $manager): ?array
{
    $rawId = $_GET['cid'] ?? '';
    if (!is_string($rawId) && !is_int($rawId)) {
        return null;
    }
    return ctype_digit((string) $rawId) ? $manager->getChannel((int) $rawId) : null;
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

            $primaryDir = __DIR__ . DIRECTORY_SEPARATOR . 'toffee' . DIRECTORY_SEPARATOR . 'storage';
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
                    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'toffee_raw.m3u',
                    __DIR__ . DIRECTORY_SEPARATOR . 'toffee' . DIRECTORY_SEPARATOR . 'toffee_raw.m3u',
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

sendCorsHeaders();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD, OPTIONS');
    http_response_code(405);
    exit('Method not allowed.');
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = appPath($requestPath);
$baseUrl = requestBaseUrl();

// Route query parameter fallbacks (enables execution even without mod_rewrite)
if ($path === '/' || $path === '') {
    if (isset($_GET['proxy'])) {
        $path = '/proxy/' . $_GET['proxy'];
    } elseif (isset($_GET['api'])) {
        $path = '/api/' . $_GET['api'];
    } elseif (isset($_GET['channel'])) {
        $chVal = trim((string) $_GET['channel']);
        if (!str_ends_with(strtolower($chVal), '.m3u8')) {
            $chVal .= '.m3u8';
        }
        $path = '/channel/' . $chVal;
    } elseif (isset($_GET['playlist']) || isset($_GET['m3u']) || isset($_GET['m3u8']) || (isset($_GET['output']) && in_array(strtolower((string) $_GET['output']), ['m3u', 'm3u8'], true))) {
        $path = '/playlist.m3u8';
    }
}

$manager = new PlaylistManager();

// Static assets handler for style.css
if (in_array($path, ['/toffee/style.css', '/static/style.css'], true)) {
    header('Content-Type: text/css; charset=utf-8');
    header('Cache-Control: public, max-age=86400');
    sendCorsHeaders();
    $diskCss = __DIR__ . '/static/style.css';
    if (is_file($diskCss) && is_readable($diskCss)) {
        readfile($diskCss);
        exit;
    }
    echo <<<'EMBEDDED_CSS'
:root {
  --bg-primary: #0b0f19;
  --bg-secondary: #131b2e;
  --bg-card: #1a233d;
  --bg-card-hover: #222d4f;
  --text-main: #f8fafc;
  --text-muted: #94a3b8;
  --accent: #3b82f6;
  --accent-hover: #2563eb;
  --accent-glow: rgba(59, 130, 246, 0.25);
  --green-live: #10b981;
  --border: #2e3856;
  --radius-lg: 14px;
  --radius-md: 10px;
  --radius-sm: 6px;
}

* {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}

body {
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  background-color: var(--bg-primary);
  color: var(--text-main);
  line-height: 1.5;
  min-height: 100vh;
}

.app-container {
  max-width: 1500px;
  margin: 0 auto;
  padding: 18px 24px 40px;
}

/* Header */
.header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 20px;
  background: var(--bg-secondary);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  margin-bottom: 22px;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
}

.brand {
  display: flex;
  align-items: center;
  gap: 12px;
}

.live-dot {
  width: 12px;
  height: 12px;
  background-color: var(--green-live);
  border-radius: 50%;
  box-shadow: 0 0 10px var(--green-live);
  animation: pulse 2s infinite;
}

@keyframes pulse {
  0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
  70% { transform: scale(1.1); box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
  100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}

.brand h1 {
  font-size: 1.35rem;
  font-weight: 700;
  letter-spacing: -0.5px;
}

.brand h1 span {
  color: var(--accent);
}

.badge {
  background: rgba(59, 130, 246, 0.15);
  color: #60a5fa;
  font-size: 0.75rem;
  font-weight: 600;
  padding: 4px 10px;
  border-radius: 20px;
  border: 1px solid rgba(59, 130, 246, 0.3);
}

.header-actions {
  display: flex;
  gap: 10px;
}

/* Buttons */
.btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 9px 16px;
  border-radius: var(--radius-md);
  font-size: 0.88rem;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
  transition: all 0.2s ease;
  border: none;
}

.btn-primary {
  background: var(--accent);
  color: #fff;
  box-shadow: 0 2px 10px var(--accent-glow);
}

.btn-primary:hover {
  background: var(--accent-hover);
  transform: translateY(-1px);
}

.btn-secondary {
  background: var(--bg-card);
  color: var(--text-main);
  border: 1px solid var(--border);
}

.btn-secondary:hover {
  background: var(--bg-card-hover);
}

.btn-outline {
  background: transparent;
  color: #93c5fd;
  border: 1px solid #3b82f6;
  padding: 6px 12px;
  font-size: 0.8rem;
}

.btn-outline:hover {
  background: rgba(59, 130, 246, 0.15);
}

.btn-sm {
  padding: 6px 12px;
  font-size: 0.8rem;
}

/* Layout */
.main-layout {
  display: grid;
  grid-template-columns: 44% 56%;
  gap: 24px;
}

@media (max-width: 1050px) {
  .main-layout {
    grid-template-columns: 1fr;
  }
}

/* Player Section */
.player-section {
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.player-wrapper {
  position: relative;
  width: 100%;
  aspect-ratio: 16 / 9;
  background: #000;
  border-radius: var(--radius-lg);
  overflow: hidden;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
  border: 1px solid var(--border);
}

.player-wrapper video {
  width: 100%;
  height: 100%;
  display: block;
  object-fit: contain;
}

.player-placeholder {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  color: var(--text-muted);
  background: rgba(11, 15, 25, 0.95);
  z-index: 2;
  transition: opacity 0.3s ease;
}

.player-placeholder svg {
  color: #475569;
}

.player-placeholder p {
  font-size: 0.95rem;
  font-weight: 500;
}

.now-playing-card {
  display: flex;
  align-items: center;
  gap: 16px;
  background: var(--bg-secondary);
  border: 1px solid var(--border);
  padding: 14px 18px;
  border-radius: var(--radius-md);
}

.channel-logo {
  width: 52px;
  height: 52px;
  border-radius: var(--radius-sm);
  object-fit: cover;
  background: var(--bg-card);
  border: 1px solid var(--border);
}

.now-playing-info {
  flex: 1;
}

.now-playing-info h2 {
  font-size: 1.1rem;
  font-weight: 600;
  margin-bottom: 4px;
}

.meta-tags {
  display: flex;
  gap: 8px;
}

.category-tag {
  background: #1e293b;
  color: #cbd5e1;
  font-size: 0.72rem;
  padding: 2px 8px;
  border-radius: 4px;
  font-weight: 500;
}

.status-tag {
  background: rgba(16, 185, 129, 0.15);
  color: #34d399;
  font-size: 0.72rem;
  padding: 2px 8px;
  border-radius: 4px;
  font-weight: 500;
}

.now-playing-actions {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.network-info-box {
  background: var(--bg-secondary);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  padding: 16px;
}

.info-item {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-bottom: 8px;
}

.info-label {
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.info-item code {
  background: #090d16;
  border: 1px solid #24304f;
  padding: 8px 12px;
  border-radius: var(--radius-sm);
  color: #38bdf8;
  font-family: monospace;
  font-size: 0.88rem;
  word-break: break-all;
}

.info-hint {
  font-size: 0.8rem;
  color: var(--text-muted);
  line-height: 1.4;
}

/* Channels Section */
.channels-section {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-height: 500px;
}

.channels-controls {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.search-box {
  display: flex;
  align-items: center;
  gap: 10px;
  background: var(--bg-secondary);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  padding: 10px 14px;
}

.search-box svg {
  color: var(--text-muted);
  flex-shrink: 0;
}

.search-box input {
  background: transparent;
  border: none;
  outline: none;
  color: var(--text-main);
  font-size: 0.95rem;
  width: 100%;
}

.search-box input::placeholder {
  color: #64748b;
}

.groups-filter {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  padding-bottom: 4px;
}

.groups-filter::-webkit-scrollbar {
  height: 4px;
}

.groups-filter::-webkit-scrollbar-thumb {
  background: var(--border);
  border-radius: 4px;
}

.pill-btn {
  background: var(--bg-secondary);
  color: var(--text-muted);
  border: 1px solid var(--border);
  padding: 6px 14px;
  border-radius: 20px;
  font-size: 0.82rem;
  font-weight: 500;
  cursor: pointer;
  white-space: nowrap;
  transition: all 0.2s;
}

.pill-btn:hover {
  background: var(--bg-card);
  color: var(--text-main);
}

.pill-btn.active {
  background: var(--accent);
  color: #fff;
  border-color: var(--accent);
  box-shadow: 0 2px 8px var(--accent-glow);
}

/* Channels Grid */
.channels-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 12px;
  max-height: 700px;
  overflow-y: auto;
  padding-right: 4px;
}

.channels-grid::-webkit-scrollbar {
  width: 6px;
}

.channels-grid::-webkit-scrollbar-thumb {
  background: var(--border);
  border-radius: 4px;
}

.channel-card {
  display: flex;
  flex-direction: column;
  background: var(--bg-secondary);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  padding: 12px;
  cursor: pointer;
  transition: all 0.2s ease;
  position: relative;
}

.channel-card:hover {
  background: var(--bg-card);
  transform: translateY(-2px);
  border-color: #3b82f6;
  box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
}

.channel-card.active {
  border-color: #3b82f6;
  background: #182342;
  box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.4);
}

.card-top {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 8px;
}

.card-logo {
  width: 44px;
  height: 44px;
  border-radius: var(--radius-sm);
  object-fit: cover;
  background: #0b0f19;
  border: 1px solid var(--border);
  flex-shrink: 0;
}

.card-info {
  overflow: hidden;
}

.card-title {
  font-size: 0.92rem;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  color: var(--text-main);
}

.card-group {
  font-size: 0.72rem;
  color: var(--text-muted);
  text-transform: capitalize;
}

.card-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: auto;
  padding-top: 8px;
  border-top: 1px solid rgba(255, 255, 255, 0.05);
}

.card-channel-id {
  font-size: 0.7rem;
  color: #64748b;
  font-family: monospace;
}

.play-badge {
  font-size: 0.75rem;
  font-weight: 600;
  color: #60a5fa;
  display: flex;
  align-items: center;
  gap: 4px;
}

/* Loading & Empty States */
.loading-state, .empty-state {
  grid-column: 1 / -1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 60px 20px;
  color: var(--text-muted);
  gap: 14px;
}

.spinner {
  width: 36px;
  height: 36px;
  border: 3px solid rgba(59, 130, 246, 0.2);
  border-top-color: var(--accent);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

/* Toast */
.toast {
  position: fixed;
  bottom: 24px;
  right: 24px;
  background: #1e293b;
  color: #f8fafc;
  border: 1px solid #3b82f6;
  padding: 10px 18px;
  border-radius: var(--radius-md);
  font-size: 0.88rem;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4);
  opacity: 0;
  transform: translateY(20px);
  transition: all 0.3s ease;
  pointer-events: none;
  z-index: 100;
}

.toast.show {
  opacity: 1;
  transform: translateY(0);
}

EMBEDDED_CSS;
    exit;
}

// Static assets handler for app.js
if (in_array($path, ['/toffee/app.js', '/static/app.js'], true)) {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: public, max-age=86400');
    sendCorsHeaders();
    $diskJs = __DIR__ . '/static/app.js';
    if (is_file($diskJs) && is_readable($diskJs)) {
        readfile($diskJs);
        exit;
    }
    echo <<<'EMBEDDED_JS'
let allChannels = [];
let channelDisplayNumbers = new Map();
let selectedGroup = "All";
let hlsInstance = null;
let currentChannel = null;
let lastTokenRefreshAt = 0;

const videoPlayer = document.getElementById("videoPlayer");
const playerPlaceholder = document.getElementById("playerPlaceholder");
const channelsGrid = document.getElementById("channelsGrid");
const searchInput = document.getElementById("searchInput");
const groupsFilterContainer = document.getElementById("groupsFilterContainer");
const channelCountBadge = document.getElementById("channelCountBadge");
const playlistUrlText = document.getElementById("playlistUrlText");
const copyM3uBtn = document.getElementById("copyM3uBtn");
const copyStreamUrlBtn = document.getElementById("copyStreamUrlBtn");
const nowPlayingCard = document.getElementById("nowPlayingCard");
const nowPlayingLogo = document.getElementById("nowPlayingLogo");
const nowPlayingTitle = document.getElementById("nowPlayingTitle");
const nowPlayingGroup = document.getElementById("nowPlayingGroup");
const nowPlayingStatus = document.getElementById("nowPlayingStatus");
const toast = document.getElementById("toast");

// Initialize on page load
document.addEventListener("DOMContentLoaded", () => {
  const m3uUrl = new URL("playlist.m3u", document.baseURI).toString();
  if (playlistUrlText) {
    playlistUrlText.innerText = m3uUrl;
  }

  loadGroups();
  loadChannels();

  // Search filter
  if (searchInput) {
    searchInput.addEventListener("input", () => {
      filterAndRenderChannels();
    });
  }

  // Copy M3U button
  if (copyM3uBtn) {
    copyM3uBtn.addEventListener("click", () => {
      copyToClipboard(m3uUrl, "Full M3U Playlist URL copied!");
    });
  }

  // Copy Stream URL button
  if (copyStreamUrlBtn) {
    copyStreamUrlBtn.addEventListener("click", () => {
      if (currentChannel) {
        copyToClipboard(currentChannel.stream_url, `Stream URL for "${currentChannel.name}" copied!`);
      }
    });
  }
});

function showToast(message) {
  if (!toast) return;
  toast.innerText = message;
  toast.classList.add("show");
  setTimeout(() => {
    toast.classList.remove("show");
  }, 2500);
}

function copyToClipboard(text, successMsg) {
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(() => {
      showToast(successMsg);
    }).catch(() => {
      fallbackCopy(text, successMsg);
    });
  } else {
    fallbackCopy(text, successMsg);
  }
}

function fallbackCopy(text, successMsg) {
  const input = document.createElement("input");
  input.value = text;
  document.body.appendChild(input);
  input.select();
  document.execCommand("copy");
  document.body.removeChild(input);
  showToast(successMsg);
}

async function loadGroups() {
  try {
    let res = await fetch("api/groups");
    if (!res.ok) {
      res = await fetch("?api=groups");
    }
    if (!res.ok) {
      throw new Error(`Groups request failed with HTTP ${res.status}`);
    }
    const groups = await res.json();
    renderGroupPills(groups);
  } catch (err) {
    console.error("Failed to load groups:", err);
  }
}

function renderGroupPills(groups) {
  if (!groupsFilterContainer) return;
  groupsFilterContainer.innerHTML = "";
  groups.forEach((grp) => {
    const btn = document.createElement("button");
    btn.className = `pill-btn ${grp === selectedGroup ? "active" : ""}`;
    btn.innerText = grp;
    btn.addEventListener("click", () => {
      selectedGroup = grp;
      document.querySelectorAll(".pill-btn").forEach((b) => b.classList.remove("active"));
      btn.classList.add("active");
      filterAndRenderChannels();
    });
    groupsFilterContainer.appendChild(btn);
  });
}

async function loadChannels() {
  try {
    let res = await fetch("api/channels");
    if (!res.ok) {
      res = await fetch("?api=channels");
    }
    const data = await res.json();
    if (!res.ok) {
      throw new Error(data.error || `Channels request failed with HTTP ${res.status}`);
    }
    if (!Array.isArray(data.channels)) {
      throw new Error("The server returned an invalid channels response.");
    }
    allChannels = data.channels;
    channelDisplayNumbers = new Map(allChannels.map((channel, index) => [channel.id, index + 1]));
    if (channelCountBadge) {
      channelCountBadge.innerText = `${allChannels.length} Channels${data.stale_cache ? " (cached)" : ""}`;
    }
    filterAndRenderChannels();
  } catch (err) {
    console.error("Failed to load channels:", err);
    if (channelCountBadge) {
      channelCountBadge.innerText = "Playlist unavailable";
    }
    if (channelsGrid) {
      channelsGrid.innerHTML = `<div class="empty-state"><p></p></div>`;
      channelsGrid.querySelector("p").textContent = err.message;
    }
  }
}

function filterAndRenderChannels() {
  if (!channelsGrid) return;
  const query = searchInput ? searchInput.value.trim().toLowerCase() : "";
  const filtered = allChannels.filter((ch) => {
    const matchGroup = selectedGroup === "All" || ch.group.toLowerCase() === selectedGroup.toLowerCase();
    const matchQuery = !query || ch.name.toLowerCase().includes(query) || ch.group.toLowerCase().includes(query);
    return matchGroup && matchQuery;
  });

  if (filtered.length === 0) {
    channelsGrid.innerHTML = `
      <div class="empty-state">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
        <p>No channels found matching "${searchInput ? searchInput.value : ""}"</p>
      </div>`;
    return;
  }

  channelsGrid.innerHTML = "";
  filtered.forEach((ch) => {
    const card = document.createElement("div");
    card.className = `channel-card ${currentChannel && currentChannel.id === ch.id ? "active" : ""}`;
    
    const fallbackLogo = `data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='44' height='44' viewBox='0 0 24 24' fill='none' stroke='%23475569' stroke-width='2'><rect x='2' y='7' width='20' height='15' rx='2' ry='2'></rect><polyline points='17 2 12 7 7 2'></polyline></svg>`;
    const logoSrc = ch.logo || fallbackLogo;

    card.innerHTML = `
      <div class="card-top">
        <img class="card-logo" src="${logoSrc}" alt="${ch.name}" loading="lazy" onerror="this.src='${fallbackLogo}'">
        <div class="card-info">
          <div class="card-title" title="${ch.name}">${ch.name}</div>
          <div class="card-group">${ch.group}</div>
        </div>
      </div>
      <div class="card-footer">
        <span class="card-channel-id">CH #${channelDisplayNumbers.get(ch.id)}</span>
        <span class="play-badge">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
          Play
        </span>
      </div>
    `;

    card.addEventListener("click", () => {
      playChannel(ch);
    });

    channelsGrid.appendChild(card);
  });
}

function playChannel(channel) {
  currentChannel = channel;

  // Highlight active card
  document.querySelectorAll(".channel-card").forEach((c) => c.classList.remove("active"));
  
  // Update Now Playing UI
  if (playerPlaceholder) playerPlaceholder.style.display = "none";
  if (nowPlayingCard) nowPlayingCard.style.display = "flex";
  if (nowPlayingTitle) nowPlayingTitle.innerText = channel.name;
  if (nowPlayingGroup) nowPlayingGroup.innerText = channel.group;
  if (nowPlayingStatus) nowPlayingStatus.innerText = "Buffering...";
  if (nowPlayingLogo) nowPlayingLogo.src = channel.logo || "";

  // Destroy previous HLS instance
  if (hlsInstance) {
    hlsInstance.destroy();
    hlsInstance = null;
  }

  const streamUrl = channel.stream_url;
  console.log(`[Player] Loading stream: ${streamUrl}`);

  if (Hls.isSupported()) {
    hlsInstance = new Hls({
      enableWorker: true,
      lowLatencyMode: true,
      backBufferLength: 60,
      manifestLoadingTimeOut: 15000,
      levelLoadingTimeOut: 15000,
    });

    hlsInstance.loadSource(streamUrl);
    hlsInstance.attachMedia(videoPlayer);

    hlsInstance.on(Hls.Events.MANIFEST_PARSED, () => {
      if (nowPlayingStatus) nowPlayingStatus.innerText = "Live";
      if (videoPlayer) {
        videoPlayer.play().catch((e) => {
          console.warn("Autoplay was prevented by browser:", e);
          if (nowPlayingStatus) nowPlayingStatus.innerText = "Click play";
        });
      }
    });

    hlsInstance.on(Hls.Events.ERROR, (event, data) => {
      console.warn("HLS Event Error:", data);
      if (data.fatal) {
        const responseCode = data.response && data.response.code;
        if ([401, 403, 410].includes(responseCode) && Date.now() - lastTokenRefreshAt > 10000) {
          lastTokenRefreshAt = Date.now();
          if (nowPlayingStatus) nowPlayingStatus.innerText = "Refreshing stream...";
          setTimeout(() => playChannel(channel), 500);
          return;
        }
        switch (data.type) {
          case Hls.ErrorTypes.NETWORK_ERROR:
            if (nowPlayingStatus) nowPlayingStatus.innerText = "Network Retry...";
            hlsInstance.startLoad();
            break;
          case Hls.ErrorTypes.MEDIA_ERROR:
            if (nowPlayingStatus) nowPlayingStatus.innerText = "Recovering...";
            hlsInstance.recoverMediaError();
            break;
          default:
            if (nowPlayingStatus) nowPlayingStatus.innerText = "Stream Offline / Error";
            hlsInstance.destroy();
            break;
        }
      }
    });
  } else if (videoPlayer && videoPlayer.canPlayType("application/vnd.apple.mpegurl")) {
    // Native Safari / iOS HLS
    videoPlayer.src = streamUrl;
    videoPlayer.addEventListener("loadedmetadata", () => {
      if (nowPlayingStatus) nowPlayingStatus.innerText = "Live";
      videoPlayer.play();
    });
  } else {
    if (nowPlayingStatus) nowPlayingStatus.innerText = "HLS not supported in browser";
    alert("HLS playback is not natively supported in this browser. Please use Chrome/Firefox/Edge or VLC.");
  }
}

EMBEDDED_JS;
    exit;
}

try {
    if (in_array($path, ['/', '/index.html', '/player', '/player/'], true)) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        $baseHref = htmlspecialchars(rtrim($baseUrl, '/') . '/', ENT_QUOTES, 'UTF-8');
        $diskHtml = __DIR__ . '/static/index.html';
        if (is_file($diskHtml) && is_readable($diskHtml)) {
            echo file_get_contents($diskHtml);
            exit;
        }
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <base href="<?= $baseHref ?>">
  <title>Toffee Live TV Web Player</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
  <style>
:root {
  --bg-primary: #0b0f19;
  --bg-secondary: #131b2e;
  --bg-card: #1a233d;
  --bg-card-hover: #222d4f;
  --text-main: #f8fafc;
  --text-muted: #94a3b8;
  --accent: #3b82f6;
  --accent-hover: #2563eb;
  --accent-glow: rgba(59, 130, 246, 0.25);
  --green-live: #10b981;
  --border: #2e3856;
  --radius-lg: 14px;
  --radius-md: 10px;
  --radius-sm: 6px;
}

* {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}

body {
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  background-color: var(--bg-primary);
  color: var(--text-main);
  line-height: 1.5;
  min-height: 100vh;
}

.app-container {
  max-width: 1500px;
  margin: 0 auto;
  padding: 18px 24px 40px;
}

/* Header */
.header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 20px;
  background: var(--bg-secondary);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  margin-bottom: 22px;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
}

.brand {
  display: flex;
  align-items: center;
  gap: 12px;
}

.live-dot {
  width: 12px;
  height: 12px;
  background-color: var(--green-live);
  border-radius: 50%;
  box-shadow: 0 0 10px var(--green-live);
  animation: pulse 2s infinite;
}

@keyframes pulse {
  0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
  70% { transform: scale(1.1); box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
  100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}

.brand h1 {
  font-size: 1.35rem;
  font-weight: 700;
  letter-spacing: -0.5px;
}

.brand h1 span {
  color: var(--accent);
}

.badge {
  background: rgba(59, 130, 246, 0.15);
  color: #60a5fa;
  font-size: 0.75rem;
  font-weight: 600;
  padding: 4px 10px;
  border-radius: 20px;
  border: 1px solid rgba(59, 130, 246, 0.3);
}

.header-actions {
  display: flex;
  gap: 10px;
}

/* Buttons */
.btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 9px 16px;
  border-radius: var(--radius-md);
  font-size: 0.88rem;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
  transition: all 0.2s ease;
  border: none;
}

.btn-primary {
  background: var(--accent);
  color: #fff;
  box-shadow: 0 2px 10px var(--accent-glow);
}

.btn-primary:hover {
  background: var(--accent-hover);
  transform: translateY(-1px);
}

.btn-secondary {
  background: var(--bg-card);
  color: var(--text-main);
  border: 1px solid var(--border);
}

.btn-secondary:hover {
  background: var(--bg-card-hover);
}

.btn-outline {
  background: transparent;
  color: #93c5fd;
  border: 1px solid #3b82f6;
  padding: 6px 12px;
  font-size: 0.8rem;
}

.btn-outline:hover {
  background: rgba(59, 130, 246, 0.15);
}

.btn-sm {
  padding: 6px 12px;
  font-size: 0.8rem;
}

/* Layout */
.main-layout {
  display: grid;
  grid-template-columns: 44% 56%;
  gap: 24px;
}

@media (max-width: 1050px) {
  .main-layout {
    grid-template-columns: 1fr;
  }
}

/* Player Section */
.player-section {
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.player-wrapper {
  position: relative;
  width: 100%;
  aspect-ratio: 16 / 9;
  background: #000;
  border-radius: var(--radius-lg);
  overflow: hidden;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
  border: 1px solid var(--border);
}

.player-wrapper video {
  width: 100%;
  height: 100%;
  display: block;
  object-fit: contain;
}

.player-placeholder {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  color: var(--text-muted);
  background: rgba(11, 15, 25, 0.95);
  z-index: 2;
  transition: opacity 0.3s ease;
}

.player-placeholder svg {
  color: #475569;
}

.player-placeholder p {
  font-size: 0.95rem;
  font-weight: 500;
}

.now-playing-card {
  display: flex;
  align-items: center;
  gap: 16px;
  background: var(--bg-secondary);
  border: 1px solid var(--border);
  padding: 14px 18px;
  border-radius: var(--radius-md);
}

.channel-logo {
  width: 52px;
  height: 52px;
  border-radius: var(--radius-sm);
  object-fit: cover;
  background: var(--bg-card);
  border: 1px solid var(--border);
}

.now-playing-info {
  flex: 1;
}

.now-playing-info h2 {
  font-size: 1.1rem;
  font-weight: 600;
  margin-bottom: 4px;
}

.meta-tags {
  display: flex;
  gap: 8px;
}

.category-tag {
  background: #1e293b;
  color: #cbd5e1;
  font-size: 0.72rem;
  padding: 2px 8px;
  border-radius: 4px;
  font-weight: 500;
}

.status-tag {
  background: rgba(16, 185, 129, 0.15);
  color: #34d399;
  font-size: 0.72rem;
  padding: 2px 8px;
  border-radius: 4px;
  font-weight: 500;
}

.now-playing-actions {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.network-info-box {
  background: var(--bg-secondary);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  padding: 16px;
}

.info-item {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-bottom: 8px;
}

.info-label {
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.info-item code {
  background: #090d16;
  border: 1px solid #24304f;
  padding: 8px 12px;
  border-radius: var(--radius-sm);
  color: #38bdf8;
  font-family: monospace;
  font-size: 0.88rem;
  word-break: break-all;
}

.info-hint {
  font-size: 0.8rem;
  color: var(--text-muted);
  line-height: 1.4;
}

/* Channels Section */
.channels-section {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-height: 500px;
}

.channels-controls {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.search-box {
  display: flex;
  align-items: center;
  gap: 10px;
  background: var(--bg-secondary);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  padding: 10px 14px;
}

.search-box svg {
  color: var(--text-muted);
  flex-shrink: 0;
}

.search-box input {
  background: transparent;
  border: none;
  outline: none;
  color: var(--text-main);
  font-size: 0.95rem;
  width: 100%;
}

.search-box input::placeholder {
  color: #64748b;
}

.groups-filter {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  padding-bottom: 4px;
}

.groups-filter::-webkit-scrollbar {
  height: 4px;
}

.groups-filter::-webkit-scrollbar-thumb {
  background: var(--border);
  border-radius: 4px;
}

.pill-btn {
  background: var(--bg-secondary);
  color: var(--text-muted);
  border: 1px solid var(--border);
  padding: 6px 14px;
  border-radius: 20px;
  font-size: 0.82rem;
  font-weight: 500;
  cursor: pointer;
  white-space: nowrap;
  transition: all 0.2s;
}

.pill-btn:hover {
  background: var(--bg-card);
  color: var(--text-main);
}

.pill-btn.active {
  background: var(--accent);
  color: #fff;
  border-color: var(--accent);
  box-shadow: 0 2px 8px var(--accent-glow);
}

/* Channels Grid */
.channels-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 12px;
  max-height: 700px;
  overflow-y: auto;
  padding-right: 4px;
}

.channels-grid::-webkit-scrollbar {
  width: 6px;
}

.channels-grid::-webkit-scrollbar-thumb {
  background: var(--border);
  border-radius: 4px;
}

.channel-card {
  display: flex;
  flex-direction: column;
  background: var(--bg-secondary);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  padding: 12px;
  cursor: pointer;
  transition: all 0.2s ease;
  position: relative;
}

.channel-card:hover {
  background: var(--bg-card);
  transform: translateY(-2px);
  border-color: #3b82f6;
  box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
}

.channel-card.active {
  border-color: #3b82f6;
  background: #182342;
  box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.4);
}

.card-top {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 8px;
}

.card-logo {
  width: 44px;
  height: 44px;
  border-radius: var(--radius-sm);
  object-fit: cover;
  background: #0b0f19;
  border: 1px solid var(--border);
  flex-shrink: 0;
}

.card-info {
  overflow: hidden;
}

.card-title {
  font-size: 0.92rem;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  color: var(--text-main);
}

.card-group {
  font-size: 0.72rem;
  color: var(--text-muted);
  text-transform: capitalize;
}

.card-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: auto;
  padding-top: 8px;
  border-top: 1px solid rgba(255, 255, 255, 0.05);
}

.card-channel-id {
  font-size: 0.7rem;
  color: #64748b;
  font-family: monospace;
}

.play-badge {
  font-size: 0.75rem;
  font-weight: 600;
  color: #60a5fa;
  display: flex;
  align-items: center;
  gap: 4px;
}

/* Loading & Empty States */
.loading-state, .empty-state {
  grid-column: 1 / -1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 60px 20px;
  color: var(--text-muted);
  gap: 14px;
}

.spinner {
  width: 36px;
  height: 36px;
  border: 3px solid rgba(59, 130, 246, 0.2);
  border-top-color: var(--accent);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

/* Toast */
.toast {
  position: fixed;
  bottom: 24px;
  right: 24px;
  background: #1e293b;
  color: #f8fafc;
  border: 1px solid #3b82f6;
  padding: 10px 18px;
  border-radius: var(--radius-md);
  font-size: 0.88rem;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4);
  opacity: 0;
  transform: translateY(20px);
  transition: all 0.3s ease;
  pointer-events: none;
  z-index: 100;
}

.toast.show {
  opacity: 1;
  transform: translateY(0);
}

  </style>
</head>
<body>
  <div class="app-container">
    <header class="header">
      <div class="brand">
        <span class="live-dot"></span>
        <h1>Toffee <span>Live TV</span></h1>
        <span class="badge" id="channelCountBadge">Loading...</span>
      </div>
    </header>

    <main class="main-layout">
      <section class="player-section">
        <div class="player-wrapper">
          <video id="videoPlayer" controls autoplay playsinline></video>
          <div id="playerPlaceholder" class="player-placeholder">
            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
            <p>Select a channel below to start watching</p>
          </div>
        </div>

        <div class="now-playing-card" id="nowPlayingCard" style="display: none;">
          <img id="nowPlayingLogo" src="" alt="Channel Logo" class="channel-logo" onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'40\' height=\'40\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%2364748b\' stroke-width=\'2\'><rect x=\'2\' y=\'7\' width=\'20\' height=\'15\' rx=\'2\' ry=\'2\'></rect><polyline points=\'17 2 12 7 7 2\'></polyline></svg>'">
          <div class="now-playing-info">
            <h2 id="nowPlayingTitle">Channel Name</h2>
            <div class="meta-tags">
              <span id="nowPlayingGroup" class="category-tag">Category</span>
              <span id="nowPlayingStatus" class="status-tag">Ready</span>
            </div>
          </div>
          <div class="now-playing-actions">
            <button id="copyStreamUrlBtn" class="btn btn-sm btn-outline">Copy Stream URL</button>
          </div>
        </div>

        <div class="network-info-box">
          <div class="info-item">
            <span class="info-label">Smart TV / VLC Playlist URL:</span>
            <code id="playlistUrlText"></code>
            <button id="copyM3uBtn" class="btn btn-sm btn-outline">Copy Playlist URL</button>
          </div>
          <p class="info-hint">Load this URL into VLC, TiviMate, Smart TV, or IPTV Smarters. All streams are automatically proxied with required HTTP headers!</p>
        </div>
      </section>

      <section class="channels-section">
        <div class="channels-controls">
          <div class="search-box">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input type="text" id="searchInput" placeholder="Search 400+ channels (e.g. Star Sports, Cricket, Sony)...">
          </div>
          <div class="groups-filter" id="groupsFilterContainer"></div>
        </div>

        <div class="channels-grid" id="channelsGrid">
          <div class="loading-state">
            <div class="spinner"></div>
            <p>Loading channels from playlist...</p>
          </div>
        </div>
      </section>
    </main>

    <div id="toast" class="toast">Link copied to clipboard!</div>
  </div>
  <script>
let allChannels = [];
let channelDisplayNumbers = new Map();
let selectedGroup = "All";
let hlsInstance = null;
let currentChannel = null;
let lastTokenRefreshAt = 0;

const videoPlayer = document.getElementById("videoPlayer");
const playerPlaceholder = document.getElementById("playerPlaceholder");
const channelsGrid = document.getElementById("channelsGrid");
const searchInput = document.getElementById("searchInput");
const groupsFilterContainer = document.getElementById("groupsFilterContainer");
const channelCountBadge = document.getElementById("channelCountBadge");
const playlistUrlText = document.getElementById("playlistUrlText");
const copyM3uBtn = document.getElementById("copyM3uBtn");
const copyStreamUrlBtn = document.getElementById("copyStreamUrlBtn");
const nowPlayingCard = document.getElementById("nowPlayingCard");
const nowPlayingLogo = document.getElementById("nowPlayingLogo");
const nowPlayingTitle = document.getElementById("nowPlayingTitle");
const nowPlayingGroup = document.getElementById("nowPlayingGroup");
const nowPlayingStatus = document.getElementById("nowPlayingStatus");
const toast = document.getElementById("toast");

// Initialize on page load
document.addEventListener("DOMContentLoaded", () => {
  const m3uUrl = new URL("playlist.m3u", document.baseURI).toString();
  if (playlistUrlText) {
    playlistUrlText.innerText = m3uUrl;
  }

  loadGroups();
  loadChannels();

  // Search filter
  if (searchInput) {
    searchInput.addEventListener("input", () => {
      filterAndRenderChannels();
    });
  }

  // Copy M3U button
  if (copyM3uBtn) {
    copyM3uBtn.addEventListener("click", () => {
      copyToClipboard(m3uUrl, "Full M3U Playlist URL copied!");
    });
  }

  // Copy Stream URL button
  if (copyStreamUrlBtn) {
    copyStreamUrlBtn.addEventListener("click", () => {
      if (currentChannel) {
        copyToClipboard(currentChannel.stream_url, `Stream URL for "${currentChannel.name}" copied!`);
      }
    });
  }
});

function showToast(message) {
  if (!toast) return;
  toast.innerText = message;
  toast.classList.add("show");
  setTimeout(() => {
    toast.classList.remove("show");
  }, 2500);
}

function copyToClipboard(text, successMsg) {
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(() => {
      showToast(successMsg);
    }).catch(() => {
      fallbackCopy(text, successMsg);
    });
  } else {
    fallbackCopy(text, successMsg);
  }
}

function fallbackCopy(text, successMsg) {
  const input = document.createElement("input");
  input.value = text;
  document.body.appendChild(input);
  input.select();
  document.execCommand("copy");
  document.body.removeChild(input);
  showToast(successMsg);
}

async function loadGroups() {
  try {
    let res = await fetch("api/groups");
    if (!res.ok) {
      res = await fetch("?api=groups");
    }
    if (!res.ok) {
      throw new Error(`Groups request failed with HTTP ${res.status}`);
    }
    const groups = await res.json();
    renderGroupPills(groups);
  } catch (err) {
    console.error("Failed to load groups:", err);
  }
}

function renderGroupPills(groups) {
  if (!groupsFilterContainer) return;
  groupsFilterContainer.innerHTML = "";
  groups.forEach((grp) => {
    const btn = document.createElement("button");
    btn.className = `pill-btn ${grp === selectedGroup ? "active" : ""}`;
    btn.innerText = grp;
    btn.addEventListener("click", () => {
      selectedGroup = grp;
      document.querySelectorAll(".pill-btn").forEach((b) => b.classList.remove("active"));
      btn.classList.add("active");
      filterAndRenderChannels();
    });
    groupsFilterContainer.appendChild(btn);
  });
}

async function loadChannels() {
  try {
    let res = await fetch("api/channels");
    if (!res.ok) {
      res = await fetch("?api=channels");
    }
    const data = await res.json();
    if (!res.ok) {
      throw new Error(data.error || `Channels request failed with HTTP ${res.status}`);
    }
    if (!Array.isArray(data.channels)) {
      throw new Error("The server returned an invalid channels response.");
    }
    allChannels = data.channels;
    channelDisplayNumbers = new Map(allChannels.map((channel, index) => [channel.id, index + 1]));
    if (channelCountBadge) {
      channelCountBadge.innerText = `${allChannels.length} Channels${data.stale_cache ? " (cached)" : ""}`;
    }
    filterAndRenderChannels();
  } catch (err) {
    console.error("Failed to load channels:", err);
    if (channelCountBadge) {
      channelCountBadge.innerText = "Playlist unavailable";
    }
    if (channelsGrid) {
      channelsGrid.innerHTML = `<div class="empty-state"><p></p></div>`;
      channelsGrid.querySelector("p").textContent = err.message;
    }
  }
}

function filterAndRenderChannels() {
  if (!channelsGrid) return;
  const query = searchInput ? searchInput.value.trim().toLowerCase() : "";
  const filtered = allChannels.filter((ch) => {
    const matchGroup = selectedGroup === "All" || ch.group.toLowerCase() === selectedGroup.toLowerCase();
    const matchQuery = !query || ch.name.toLowerCase().includes(query) || ch.group.toLowerCase().includes(query);
    return matchGroup && matchQuery;
  });

  if (filtered.length === 0) {
    channelsGrid.innerHTML = `
      <div class="empty-state">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
        <p>No channels found matching "${searchInput ? searchInput.value : ""}"</p>
      </div>`;
    return;
  }

  channelsGrid.innerHTML = "";
  filtered.forEach((ch) => {
    const card = document.createElement("div");
    card.className = `channel-card ${currentChannel && currentChannel.id === ch.id ? "active" : ""}`;
    
    const fallbackLogo = `data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='44' height='44' viewBox='0 0 24 24' fill='none' stroke='%23475569' stroke-width='2'><rect x='2' y='7' width='20' height='15' rx='2' ry='2'></rect><polyline points='17 2 12 7 7 2'></polyline></svg>`;
    const logoSrc = ch.logo || fallbackLogo;

    card.innerHTML = `
      <div class="card-top">
        <img class="card-logo" src="${logoSrc}" alt="${ch.name}" loading="lazy" onerror="this.src='${fallbackLogo}'">
        <div class="card-info">
          <div class="card-title" title="${ch.name}">${ch.name}</div>
          <div class="card-group">${ch.group}</div>
        </div>
      </div>
      <div class="card-footer">
        <span class="card-channel-id">CH #${channelDisplayNumbers.get(ch.id)}</span>
        <span class="play-badge">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
          Play
        </span>
      </div>
    `;

    card.addEventListener("click", () => {
      playChannel(ch);
    });

    channelsGrid.appendChild(card);
  });
}

function playChannel(channel) {
  currentChannel = channel;

  // Highlight active card
  document.querySelectorAll(".channel-card").forEach((c) => c.classList.remove("active"));
  
  // Update Now Playing UI
  if (playerPlaceholder) playerPlaceholder.style.display = "none";
  if (nowPlayingCard) nowPlayingCard.style.display = "flex";
  if (nowPlayingTitle) nowPlayingTitle.innerText = channel.name;
  if (nowPlayingGroup) nowPlayingGroup.innerText = channel.group;
  if (nowPlayingStatus) nowPlayingStatus.innerText = "Buffering...";
  if (nowPlayingLogo) nowPlayingLogo.src = channel.logo || "";

  // Destroy previous HLS instance
  if (hlsInstance) {
    hlsInstance.destroy();
    hlsInstance = null;
  }

  const streamUrl = channel.stream_url;
  console.log(`[Player] Loading stream: ${streamUrl}`);

  if (Hls.isSupported()) {
    hlsInstance = new Hls({
      enableWorker: true,
      lowLatencyMode: true,
      backBufferLength: 60,
      manifestLoadingTimeOut: 15000,
      levelLoadingTimeOut: 15000,
    });

    hlsInstance.loadSource(streamUrl);
    hlsInstance.attachMedia(videoPlayer);

    hlsInstance.on(Hls.Events.MANIFEST_PARSED, () => {
      if (nowPlayingStatus) nowPlayingStatus.innerText = "Live";
      if (videoPlayer) {
        videoPlayer.play().catch((e) => {
          console.warn("Autoplay was prevented by browser:", e);
          if (nowPlayingStatus) nowPlayingStatus.innerText = "Click play";
        });
      }
    });

    hlsInstance.on(Hls.Events.ERROR, (event, data) => {
      console.warn("HLS Event Error:", data);
      if (data.fatal) {
        const responseCode = data.response && data.response.code;
        if ([401, 403, 410].includes(responseCode) && Date.now() - lastTokenRefreshAt > 10000) {
          lastTokenRefreshAt = Date.now();
          if (nowPlayingStatus) nowPlayingStatus.innerText = "Refreshing stream...";
          setTimeout(() => playChannel(channel), 500);
          return;
        }
        switch (data.type) {
          case Hls.ErrorTypes.NETWORK_ERROR:
            if (nowPlayingStatus) nowPlayingStatus.innerText = "Network Retry...";
            hlsInstance.startLoad();
            break;
          case Hls.ErrorTypes.MEDIA_ERROR:
            if (nowPlayingStatus) nowPlayingStatus.innerText = "Recovering...";
            hlsInstance.recoverMediaError();
            break;
          default:
            if (nowPlayingStatus) nowPlayingStatus.innerText = "Stream Offline / Error";
            hlsInstance.destroy();
            break;
        }
      }
    });
  } else if (videoPlayer && videoPlayer.canPlayType("application/vnd.apple.mpegurl")) {
    // Native Safari / iOS HLS
    videoPlayer.src = streamUrl;
    videoPlayer.addEventListener("loadedmetadata", () => {
      if (nowPlayingStatus) nowPlayingStatus.innerText = "Live";
      videoPlayer.play();
    });
  } else {
    if (nowPlayingStatus) nowPlayingStatus.innerText = "HLS not supported in browser";
    alert("HLS playback is not natively supported in this browser. Please use Chrome/Firefox/Edge or VLC.");
  }
}

  </script>
</body>
</html>
        <?php
        exit;
    }

    if ($path === '/api/refresh') {
        try {
            $channels = $manager->refreshPlaylist();
            sendJson(['status' => 'success', 'message' => 'Playlist refreshed successfully', 'total' => count($channels)]);
        } catch (Throwable $e) {
            sendJson(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    if ($path === '/api/channels') {
        $channels = $manager->searchChannels(
            is_string($_GET['q'] ?? '') ? (string) ($_GET['q'] ?? '') : '',
            is_string($_GET['group'] ?? '') ? (string) ($_GET['group'] ?? '') : ''
        );
        foreach ($channels as &$channel) {
            $position = $manager->channelPosition((int) $channel['id']);
            $channel['stream_url'] = $baseUrl . '/channel/' . $position . '.m3u8';
            unset($channel['_sources'], $channel['_identity']);
        }
        unset($channel);
        sendJson(['total' => count($channels), 'channels' => $channels, 'stale_cache' => $manager->isUsingStaleCache()]);
    }

    if ($path === '/api/groups') {
        sendJson(array_merge(['All'], $manager->groups()));
    }

    $playlistPaths = [
        '/playlist.m3u',
        '/playlist.m3u8',
        '/channels.m3u',
        '/channels.m3u8',
        '/toffee.m3u',
        '/toffee.m3u8',
        '/live.m3u',
        '/live.m3u8',
        '/m3u',
        '/m3u8',
    ];
    if (in_array($path, $playlistPaths, true)) {
        $playlist = $manager->generateProxiedM3u(
            $baseUrl,
            is_string($_GET['group'] ?? '') ? (string) ($_GET['group'] ?? '') : ''
        );
        header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
        header('Content-Disposition: inline; filename="toffee_playlist.m3u8"');
        header('Cache-Control: public, max-age=60');
        sendCorsHeaders();
        echo $playlist;
        exit;
    }

    if (preg_match('~^/(?:channel|live|stream)/([^/]+?)(?:\.m3u8)?$~i', $path, $matches)) {
        $identifier = $matches[1];
        $channel = null;
        if (ctype_digit($identifier)) {
            $channel = $manager->getChannelByPosition((int) $identifier);
            if ($channel === null) {
                $channel = $manager->getChannel((int) $identifier);
            }
        } else {
            $channel = $manager->getChannelByNameOrSlug($identifier);
        }
        if ($channel === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            sendCorsHeaders();
            exit('Channel not found.');
        }
        serveChannelManifest($channel, $baseUrl, $manager);
    }

    if (in_array($path, ['/proxy/m3u8', '/proxy/ts', '/proxy/key'], true)) {
        $targetUrl = $_GET['url'] ?? '';
        if (!is_string($targetUrl) || $targetUrl === '') {
            http_response_code(400);
            exit('Missing target URL.');
        }
        $channel = channelFromRequest($manager);
        if ($path === '/proxy/m3u8') {
            $sourceIndex = filter_var($_GET['src'] ?? 0, FILTER_VALIDATE_INT);
            $variantIndex = filter_var($_GET['variant'] ?? 0, FILTER_VALIDATE_INT);
            serveManifest(
                $targetUrl,
                $channel,
                $baseUrl,
                $manager,
                $sourceIndex === false ? 0 : max(0, $sourceIndex),
                $variantIndex === false ? 0 : max(0, $variantIndex)
            );
        }
        proxyBinary(
            $targetUrl,
            $channel,
            $path === '/proxy/key' ? 'application/octet-stream' : 'video/mp2t',
            $manager
        );
    }

    if ($path === '/health') {
        sendJson(['status' => 'healthy', 'service' => 'toffee_proxy']);
    }

    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found.');
} catch (Throwable $error) {
    error_log('[ToffeeApp] Request failed: ' . $error->getMessage());
    sendJson([
        'error' => 'Playlist unavailable. ' . $error->getMessage(),
    ], 502);
}
