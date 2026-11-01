"""
Toffee Live Playlist Manager
Fetches, merges, and parses multi-source Toffee Live M3U playlists.
"""

import os
import re
import time
import datetime
import urllib.request
from typing import Dict, List, Optional, Any

DEFAULT_PLAYLIST_SOURCES = [
    "https://raw.githubusercontent.com/sm-monirulislam/Toffee-Auto-Update/refs/heads/main/toffee_playlist.m3u",
    "https://raw.githubusercontent.com/srhady/toffee-bd/refs/heads/main/toffee_playlist.m3u",
    "https://raw.githubusercontent.com/kgkaku/Toffee-Auto-Update-Playlist/refs/heads/main/universal.m3u",
]
DEFAULT_PLAYLIST_URL = DEFAULT_PLAYLIST_SOURCES[0]
RAW_M3U_CACHE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "toffee_raw.m3u")
DEFAULT_UA = "Toffee (Linux;Android 14) AndroidXMedia3/1.1.1/64100410/4d2ec9b8c3502383"


class Channel:
    def __init__(self, cid: int, name: str, url: str, group: str = "General", 
                 logo: str = "", tvg_id: str = "", headers: Optional[Dict[str, str]] = None,
                 backup_urls: Optional[List[str]] = None):
        self.cid = cid
        self.name = name
        self.url = url
        self.group = group or "General"
        self.logo = logo
        self.tvg_id = tvg_id
        self.headers = headers or {"User-Agent": DEFAULT_UA}
        self.backup_urls = backup_urls or []

    def to_dict(self) -> Dict[str, Any]:
        return {
            "id": self.cid,
            "name": self.name,
            "url": self.url,
            "group": self.group,
            "logo": self.logo,
            "tvg_id": self.tvg_id,
            "headers": self.headers
        }


class PlaylistManager:
    def __init__(self, source_url: Optional[str] = None, cache_ttl_seconds: int = 1800):
        self.sources: List[str] = [source_url] if source_url else DEFAULT_PLAYLIST_SOURCES
        self.source_url = self.sources[0]
        self.cache_ttl_seconds = cache_ttl_seconds
        self.channels: List[Channel] = []
        self.channel_map: Dict[int, Channel] = {}
        self.groups: List[str] = []
        self.group_counts: Dict[str, int] = {}
        self.last_fetch_time: float = 0.0
        self.last_updated_str: str = "Never"

    def load_playlist(self, force_refresh: bool = False) -> List[Channel]:
        now = time.time()
        if self.channels and not force_refresh and (now - self.last_fetch_time < self.cache_ttl_seconds):
            return self.channels

        merged_raw = ""
        fetch_success = False
        all_downloaded = []

        for src in self.sources:
            try:
                print(f"[ToffeeManager] Fetching playlist from {src} ...")
                req = urllib.request.Request(src, headers={"User-Agent": DEFAULT_UA})
                with urllib.request.urlopen(req, timeout=12) as resp:
                    text = resp.read().decode("utf-8", errors="ignore")
                    if text and "#EXTINF" in text:
                        all_downloaded.append(text)
                        print(f"[ToffeeManager] Downloaded {len(text)} bytes from {src}")
            except Exception as e:
                print(f"[ToffeeManager] Fetch failed for {src}: {e}")

        if all_downloaded:
            fetch_success = True
            merged_raw = "\n".join(all_downloaded)
            try:
                with open(RAW_M3U_CACHE, "w", encoding="utf-8") as f:
                    f.write(merged_raw)
            except Exception as e:
                print(f"[ToffeeManager] Failed to write cache: {e}")
        elif os.path.exists(RAW_M3U_CACHE):
            print("[ToffeeManager] Using locally cached playlist.")
            try:
                with open(RAW_M3U_CACHE, "r", encoding="utf-8") as f:
                    merged_raw = f.read()
            except Exception as e:
                print(f"[ToffeeManager] Cache read error: {e}")

        if not merged_raw:
            if self.channels:
                return self.channels
            raise RuntimeError("Unable to load any Toffee playlist sources.")

        self.channels = self._parse_and_merge_m3u(merged_raw)
        self.channel_map = {ch.cid: ch for ch in self.channels}
        
        # Calculate group counts
        group_counts: Dict[str, int] = {}
        for ch in self.channels:
            grp = ch.group or "General"
            group_counts[grp] = group_counts.get(grp, 0) + 1
        self.group_counts = group_counts
        self.groups = sorted(list(group_counts.keys()))

        self.last_fetch_time = time.time()
        self.last_updated_str = datetime.datetime.now().strftime("%Y-%m-%d %I:%M:%S %p")
        print(f"[ToffeeManager] Ready: {len(self.channels)} channels across {len(self.groups)} categories.")
        return self.channels

    def _normalize_name(self, name: str) -> str:
        s = name.lower()
        s = re.sub(r'\s+', ' ', s)
        s = re.sub(r'[\(\)\[\]\-_]', ' ', s)
        return s.strip()

    def _parse_and_merge_m3u(self, content: str) -> List[Channel]:
        lines = content.splitlines()
        channels: List[Channel] = []
        seen_keys: Dict[str, Channel] = {}
        curr: Dict[str, Any] = {}
        cid = 1

        for line in lines:
            line = line.strip()
            if not line:
                continue

            if line.startswith("#EXTINF:"):
                curr = {"headers": {"User-Agent": DEFAULT_UA}}
                comma_idx = line.rfind(",")
                if comma_idx != -1:
                    curr["name"] = line[comma_idx + 1:].strip()
                m = re.search(r'group-title="([^"]*)"', line)
                if m:
                    curr["group"] = m.group(1).strip()
                m = re.search(r'tvg-logo="([^"]*)"', line)
                if m:
                    curr["logo"] = m.group(1).strip()
                m = re.search(r'tvg-id="([^"]*)"', line)
                if m:
                    curr["tvg_id"] = m.group(1).strip()

            elif not line.startswith("#"):
                if curr and not curr.get("url") and line.startswith("http"):
                    name = curr.get("name", "").strip()
                    url = line

                    # Filter invalid / test channels
                    low_name = name.lower()
                    if not name or low_name.startswith("test srt") or "test stream" in low_name:
                        curr = {}
                        continue

                    norm_key = self._normalize_name(name)
                    if not norm_key and curr.get("tvg_id"):
                        norm_key = curr["tvg_id"].lower()

                    if norm_key in seen_keys:
                        # Existing channel: add as backup URL if different
                        existing = seen_keys[norm_key]
                        if url != existing.url and url not in existing.backup_urls:
                            existing.backup_urls.append(url)
                        if not existing.logo and curr.get("logo"):
                            existing.logo = curr["logo"]
                    else:
                        ch = Channel(
                            cid=cid,
                            name=name,
                            url=url,
                            group=curr.get("group", "General"),
                            logo=curr.get("logo", ""),
                            tvg_id=curr.get("tvg_id", ""),
                            headers=curr.get("headers")
                        )
                        channels.append(ch)
                        seen_keys[norm_key] = ch
                        cid += 1

                    curr = {}

        return channels

    def get_channel(self, cid: int) -> Optional[Channel]:
        if not self.channels:
            self.load_playlist()
        return self.channel_map.get(cid)

    def search_channels(self, query: str = "", group: str = "") -> List[Channel]:
        if not self.channels:
            self.load_playlist()
        res = self.channels
        if group and group != "All":
            res = [c for c in res if c.group.lower() == group.lower()]
        if query:
            q = query.lower().strip()
            # check #id query
            if q.startswith("#") and q[1:].isdigit():
                target_id = int(q[1:])
                return [c for c in res if c.cid == target_id]
            res = [c for c in res if q in c.name.lower() or q in c.group.lower() or str(c.cid) == q]
        return res

    def generate_proxied_m3u(self, base_server_url: str, filter_group: str = "") -> str:
        if not self.channels:
            self.load_playlist()
        res = self.channels
        if filter_group and filter_group != "All":
            res = [c for c in res if c.group.lower() == filter_group.lower()]

        lines = ["#EXTM3U"]
        for ch in res:
            logo = f' tvg-logo="{ch.logo}"' if ch.logo else ""
            tid = f' tvg-id="{ch.tvg_id}"' if ch.tvg_id else ""
            grp = f' group-title="{ch.group}"' if ch.group else ""
            lines.append(f'#EXTINF:-1{tid}{logo}{grp},{ch.name}')
            lines.append(f"{base_server_url.rstrip('/')}/channel/{ch.cid}.m3u8")
        return "\n".join(lines) + "\n"
