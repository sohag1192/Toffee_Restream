"""
Toffee Live Restream Proxy Server
High-performance HTTP server & reverse proxy with AES-128 key proxying and CORS support.
"""

import os
import sys
import ssl
import json
import socket
import urllib.parse
import urllib.request
from http.server import HTTPServer, BaseHTTPRequestHandler
from socketserver import ThreadingMixIn
from typing import Optional, Dict

from playlist_manager import PlaylistManager, Channel

SSL_CONTEXT = ssl.create_default_context()
SSL_CONTEXT.check_hostname = False
SSL_CONTEXT.verify_mode = ssl.CERT_NONE


class ThreadedHTTPServer(ThreadingMixIn, HTTPServer):
    daemon_threads = True
    allow_reuse_address = True


class RestreamHandler(BaseHTTPRequestHandler):
    playlist_mgr: PlaylistManager = None
    server_host: str = "localhost"
    server_port: int = 8081

    def log_message(self, format, *args):
        status_code = args[1] if len(args) > 1 else ""
        if status_code not in ("200", "206"):
            sys.stderr.write(f"[{self.log_date_time_string()}] {self.address_string()} - {format % args}\n")

    def send_cors_headers(self):
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, HEAD, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "*")
        self.send_header("Access-Control-Expose-Headers", "Content-Length, Content-Range")

    def do_OPTIONS(self):
        self.send_response(204)
        self.send_cors_headers()
        self.end_headers()

    def do_GET(self):
        parsed = urllib.parse.urlparse(self.path)
        path = parsed.path
        query = urllib.parse.parse_qs(parsed.query)

        if path in ("/", "/index.html", "/player"):
            self.serve_static_file("index.html", "text/html; charset=utf-8")
            return

        if path.startswith("/static/"):
            filename = path[8:]
            ctype = "text/plain"
            if filename.endswith(".css"):
                ctype = "text/css; charset=utf-8"
            elif filename.endswith(".js"):
                ctype = "application/javascript; charset=utf-8"
            self.serve_static_file(filename, ctype)
            return

        if path == "/api/channels":
            self.handle_api_channels(query)
            return

        if path in ("/api/refresh", "/api/playlist/refresh"):
            self.handle_api_refresh()
            return

        if path == "/api/groups":
            self.handle_api_groups()
            return

        if path in ("/playlist.m3u", "/channels.m3u"):
            self.handle_proxied_playlist(query)
            return

        if path.startswith("/channel/") and path.endswith(".m3u8"):
            cid_str = path[9:-5]
            try:
                cid = int(cid_str)
                self.handle_channel_stream(cid)
            except ValueError:
                self.send_error(400, "Invalid channel ID")
            return

        if path == "/proxy/m3u8":
            self.handle_proxy_m3u8(query)
            return

        if path == "/proxy/ts":
            self.handle_proxy_ts(query)
            return

        if path == "/proxy/key":
            self.handle_proxy_key(query)
            return

        if path == "/health":
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_cors_headers()
            self.end_headers()
            self.wfile.write(b'{"status":"healthy","service":"toffee_proxy"}')
            return

        self.send_error(404, "Not Found")

    def get_base_url(self) -> str:
        host_header = self.headers.get("Host")
        if host_header:
            return f"http://{host_header}"
        return f"http://{self.server_host}:{self.server_port}"

    def serve_static_file(self, filename: str, content_type: str):
        static_dir = os.path.join(os.path.dirname(os.path.abspath(__file__)), "static")
        safe_path = os.path.abspath(os.path.join(static_dir, filename))
        if not safe_path.startswith(static_dir) or not os.path.isfile(safe_path):
            self.send_error(404, "File Not Found")
            return
        try:
            with open(safe_path, "rb") as f:
                data = f.read()
            self.send_response(200)
            self.send_header("Content-Type", content_type)
            self.send_header("Content-Length", str(len(data)))
            self.send_cors_headers()
            self.end_headers()
            self.wfile.write(data)
        except Exception as e:
            self.send_error(500, f"Error: {e}")

    def handle_api_channels(self, query: Dict):
        grp = query.get("group", [""])[0]
        q = query.get("q", [""])[0]
        force_refresh = query.get("refresh", ["0"])[0] in ("1", "true")
        if force_refresh:
            self.playlist_mgr.load_playlist(force_refresh=True)

        channels = self.playlist_mgr.search_channels(query=q, group=grp)
        base_url = self.get_base_url()
        data = []
        for ch in channels:
            d = ch.to_dict()
            d["stream_url"] = f"{base_url}/channel/{ch.cid}.m3u8"
            data.append(d)
        payload = json.dumps({
            "total": len(data),
            "updated_at": getattr(self.playlist_mgr, "last_updated_str", ""),
            "group_counts": getattr(self.playlist_mgr, "group_counts", {}),
            "channels": data
        }).encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_cors_headers()
        self.end_headers()
        self.wfile.write(payload)

    def handle_api_refresh(self):
        try:
            channels = self.playlist_mgr.load_playlist(force_refresh=True)
            payload = json.dumps({
                "status": "success",
                "message": f"Successfully updated {len(channels)} channels",
                "total": len(channels),
                "updated_at": getattr(self.playlist_mgr, "last_updated_str", "")
            }).encode("utf-8")
            self.send_response(200)
        except Exception as e:
            payload = json.dumps({
                "status": "error",
                "message": f"Failed to refresh playlist: {e}"
            }).encode("utf-8")
            self.send_response(500)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_cors_headers()
        self.end_headers()
        self.wfile.write(payload)

    def handle_api_groups(self):
        groups = ["All"] + self.playlist_mgr.groups
        payload = json.dumps(groups).encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_cors_headers()
        self.end_headers()
        self.wfile.write(payload)

    def handle_proxied_playlist(self, query: Dict):
        grp = query.get("group", [""])[0]
        base_url = self.get_base_url()
        m3u = self.playlist_mgr.generate_proxied_m3u(base_url, filter_group=grp)
        payload = m3u.encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "application/vnd.apple.mpegurl; charset=utf-8")
        self.send_header("Content-Disposition", 'attachment; filename="toffee_playlist.m3u"')
        self.send_cors_headers()
        self.end_headers()
        self.wfile.write(payload)

    def handle_channel_stream(self, cid: int):
        ch = self.playlist_mgr.get_channel(cid)
        if not ch:
            self.send_error(404, "Channel not found")
            return
        self.rewrite_and_serve_manifest(ch.url, ch)

    def handle_proxy_m3u8(self, query: Dict):
        target_url = query.get("url", [""])[0]
        cid_str = query.get("cid", ["0"])[0]
        if not target_url:
            self.send_error(400, "Missing target url")
            return
        channel = self.playlist_mgr.get_channel(int(cid_str)) if cid_str.isdigit() else None
        self.rewrite_and_serve_manifest(target_url, channel)

    def rewrite_and_serve_manifest(self, manifest_url: str, channel: Optional[Channel]):
        headers = channel.headers if channel else {}
        candidate_urls = [manifest_url]
        if channel and hasattr(channel, "backup_urls") and channel.backup_urls:
            candidate_urls.extend([u for u in channel.backup_urls if u != manifest_url])

        content = None
        used_url = manifest_url
        last_error = None

        for u in candidate_urls:
            try:
                req = urllib.request.Request(u, headers=headers)
                with urllib.request.urlopen(req, timeout=12, context=SSL_CONTEXT) as resp:
                    content = resp.read().decode("utf-8", errors="ignore")
                    used_url = u
                    break
            except Exception as e:
                last_error = e

        if content is None:
            self.send_error(502, f"Manifest fetch failed: {last_error}")
            return

        base_url = self.get_base_url()
        cid = channel.cid if channel else 0
        rewritten = self._rewrite_hls_content(content, used_url, base_url, cid)
        payload = rewritten.encode("utf-8")

        self.send_response(200)
        self.send_header("Content-Type", "application/vnd.apple.mpegurl; charset=utf-8")
        self.send_header("Cache-Control", "no-cache, no-store, must-revalidate")
        self.send_cors_headers()
        self.end_headers()
        self.wfile.write(payload)

    def _rewrite_hls_content(self, m3u8_text: str, current_manifest_url: str, base_proxy_url: str, cid: int) -> str:
        import re
        lines = m3u8_text.splitlines()
        output = []
        last_tag = ""

        for line in lines:
            trimmed = line.strip()
            if not trimmed:
                output.append(line)
                continue

            if trimmed.startswith("#"):
                # Rewrite AES-128 key URI
                if trimmed.startswith("#EXT-X-KEY:") and 'URI="' in trimmed:
                    def repl_key(m):
                        orig = m.group(1)
                        abs_uri = urllib.parse.urljoin(current_manifest_url, orig)
                        enc = urllib.parse.quote(abs_uri, safe="")
                        return f'URI="{base_proxy_url}/proxy/key?url={enc}&cid={cid}"'
                    trimmed = re.sub(r'URI="([^"]+)"', repl_key, trimmed)

                output.append(trimmed)
                last_tag = trimmed.split(":", 1)[0]
                continue

            # URI line
            abs_url = urllib.parse.urljoin(current_manifest_url, trimmed)
            enc_url = urllib.parse.quote(abs_url, safe="")

            if last_tag in ("#EXT-X-STREAM-INF", "#EXT-X-I-FRAME-STREAM-INF") or ".m3u8" in abs_url.lower():
                output.append(f"{base_proxy_url}/proxy/m3u8?url={enc_url}&cid={cid}")
            else:
                output.append(f"{base_proxy_url}/proxy/ts?url={enc_url}&cid={cid}")
            last_tag = ""

        return "\n".join(output)

    def handle_proxy_ts(self, query: Dict):
        target_url = query.get("url", [""])[0]
        cid_str = query.get("cid", ["0"])[0]
        if not target_url:
            self.send_error(400, "Missing target url")
            return

        channel = self.playlist_mgr.get_channel(int(cid_str)) if cid_str.isdigit() else None
        headers = dict(channel.headers) if channel else {}

        client_range = self.headers.get("Range")
        if client_range:
            headers["Range"] = client_range

        try:
            req = urllib.request.Request(target_url, headers=headers)
            with urllib.request.urlopen(req, timeout=15, context=SSL_CONTEXT) as resp:
                self.send_response(resp.status)
                self.send_header("Content-Type", resp.headers.get("Content-Type", "video/mp2t"))
                if resp.headers.get("Content-Length"):
                    self.send_header("Content-Length", resp.headers.get("Content-Length"))
                if resp.headers.get("Content-Range"):
                    self.send_header("Content-Range", resp.headers.get("Content-Range"))
                self.send_header("Accept-Ranges", "bytes")
                self.send_cors_headers()
                self.end_headers()

                buf_size = 65536
                while True:
                    chunk = resp.read(buf_size)
                    if not chunk:
                        break
                    self.wfile.write(chunk)
        except (BrokenPipeError, ConnectionResetError):
            pass
        except Exception as e:
            try:
                self.send_error(502, f"Segment proxy failed: {e}")
            except Exception:
                pass

    def handle_proxy_key(self, query: Dict):
        target_url = query.get("url", [""])[0]
        cid_str = query.get("cid", ["0"])[0]
        channel = self.playlist_mgr.get_channel(int(cid_str)) if cid_str.isdigit() else None
        headers = channel.headers if channel else {}

        try:
            req = urllib.request.Request(target_url, headers=headers)
            with urllib.request.urlopen(req, timeout=10, context=SSL_CONTEXT) as resp:
                data = resp.read()
                self.send_response(200)
                self.send_header("Content-Type", "application/octet-stream")
                self.send_header("Content-Length", str(len(data)))
                self.send_cors_headers()
                self.end_headers()
                self.wfile.write(data)
        except Exception as e:
            self.send_error(502, f"Key proxy failed: {e}")


def get_local_ip() -> str:
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.connect(("8.8.8.8", 80))
        ip = s.getsockname()[0]
        s.close()
        return ip
    except Exception:
        return "127.0.0.1"


def run_server(port: int = 8081, playlist_url: str = None, host: str = "0.0.0.0"):
    pm = PlaylistManager(source_url=playlist_url) if playlist_url else PlaylistManager()
    print("=" * 60)
    print("          TOFFEE LIVE RESTREAM & WEB PLAYER")
    print("=" * 60)
    pm.load_playlist()

    local_ip = get_local_ip()
    RestreamHandler.playlist_mgr = pm
    RestreamHandler.server_host = local_ip
    RestreamHandler.server_port = port

    httpd = ThreadedHTTPServer((host, port), RestreamHandler)
    print("-" * 60)
    print(f"[*] Port:             {port}")
    print(f"[*] Web Player:       http://localhost:{port}")
    print(f"[*] Network Player:   http://{local_ip}:{port}")
    print(f"[*] Proxied M3U:      http://{local_ip}:{port}/playlist.m3u")
    print("-" * 60)
    print("Press Ctrl+C to stop.")

    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        print("\nStopping...")
        httpd.shutdown()
        httpd.server_close()


if __name__ == "__main__":
    port = 8081
    if len(sys.argv) > 1 and sys.argv[1].isdigit():
        port = int(sys.argv[1])
    run_server(port=port)
