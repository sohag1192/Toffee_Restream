# Toffee Restream

PHP-based Toffee live-TV web player and HLS restream proxy. It combines
multiple M3U playlists, serves a browser player, and provides a proxied M3U
playlist for VLC and other IPTV players.

## Features

- **Multi-source channel list:** combines these playlists:
  - `https://raw.githubusercontent.com/sm-monirulislam/Toffee-Auto-Update/refs/heads/main/toffee_playlist.m3u`
  - `https://raw.githubusercontent.com/srhady/toffee-bd/refs/heads/main/toffee_playlist.m3u`
  - `https://raw.githubusercontent.com/kgkaku/Toffee-Auto-Update-Playlist/refs/heads/main/universal.m3u`
- **Channel merging:** merges matching channels by `tvg-id` or normalized name
  and retains source alternatives behind a stable internal channel ID.
- **Web player:** browser-based HLS playback with channel search, group filters,
  channel logos, now-playing status, and copyable playlist/stream links.
- **VLC/IPTV playlist:** exports a proxied M3U playlist. Stream links use
  1-based list positions, such as `/channel/1.m3u8`; previous stable-ID links
  remain supported.
- **HLS proxy:** rewrites master/media manifests and proxies media segments and
  AES-128 keys while preserving upstream query tokens and required headers.
- **Token-expiry recovery:** when an upstream manifest, segment, or key returns
  HTTP 401/403, refreshes playlist sources and tries the same channel's
  refreshed source, followed by alternate sources. For VLC and other external
  players, a recovered segment/key is returned in the original request so the
  player does not have to reload its stream.
- **Shared segment cache:** caches successful GET media segments for 20 seconds,
  coalesces concurrent requests for the same segment, and reports
  `X-Proxy-Cache: MISS`, `HIT`, or `BYPASS`. The cache is capped at 128 MiB;
  segments larger than 16 MiB and byte-range requests bypass it. It uses
  `/dev/shm` when available, otherwise the system temporary directory. Whether
  that directory is RAM-backed depends on the host.
- **Filtered channels:** channels whose names start with `EPL Channel` or
  `TEST SRT` are omitted from both the web player and exported M3U playlist.
- **Playlist resilience:** playlist sources are cached for 60 seconds; a
  previously cached source can be used if that source is temporarily
  unavailable.
- **Protected storage:** Apache denies direct access to `storage/`; directory
  listings are disabled.

## Requirements

- PHP 8.1 or newer
- PHP cURL extension
- Outbound HTTPS access to the playlist and stream hosts
- Writable `storage/` directory beside `index.php`
- Apache `mod_rewrite` and `.htaccess` support when using Apache hosting

## Run locally

From the project directory, start PHP's development server:

```powershell
php -S 127.0.0.1:8081
```

Open `http://127.0.0.1:8081/` in a browser. In VLC on the same computer, open:

```text
http://127.0.0.1:8081/playlist.m3u
```

`127.0.0.1` refers to the device running VLC. To use VLC on a different device,
run the server on a network-accessible interface and use the host computer's
LAN IP address instead.

## Deploy to PHP hosting

Upload the project with `index.php`, `playlist_manager.php`, `.htaccess`, and
the `static/` directory to the website document root. Ensure PHP can create and
write `storage/`. The application page is rendered by `index.php`; a separate
`index.html` is not required.

If PHP cannot verify an upstream TLS certificate, configure
`TOFFEE_CA_BUNDLE` to point to a readable CA certificate bundle. Keep TLS
verification enabled.

## Configuration

- `TOFFEE_PLAYLIST_URL`: adds or prioritizes a custom primary playlist while
  retaining the three built-in sources as alternatives.
- `TOFFEE_CA_BUNDLE`: optional absolute path to a readable CA certificate
  bundle for outbound HTTPS verification.

## Endpoints

| Endpoint | Purpose |
| --- | --- |
| `/` | Web player |
| `/api/channels` | Channel data and copyable stream URLs |
| `/api/groups` | Available channel groups |
| `/playlist.m3u` | Full proxied M3U playlist |
| `/channels.m3u` | Alias for `/playlist.m3u` |
| `/channel/1.m3u8` | Stream for the first channel in the current merged list |
| `/health` | Proxy health check |

The proxy's `/proxy/m3u8`, `/proxy/ts`, and `/proxy/key` routes are used by
rewritten manifests and are intended to be followed through the generated
playlist/stream URLs.

## Notes

- Channel-position aliases can change if the merged playlist order changes.
  Existing stable-ID stream URLs continue to resolve to their original channel.
- Automatic external-player recovery occurs on upstream HTTP 401/403 responses.
  Recovery requires an equivalent current segment/key in the refreshed or
  alternate source; if none is available, the proxy reports a failure.
- The segment cache is local to one host/filesystem and is not shared between
  separate hosting nodes.
