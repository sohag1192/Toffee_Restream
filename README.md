# 📺 Toffee Live TV Restream & Modern Web Player

<p align="center">
  <img src="https://img.shields.io/badge/Python-3.8+-3776AB?style=flat-square&logo=python&logoColor=white" alt="Python">
  <img src="https://img.shields.io/badge/AES--128-Decrypted-brightgreen?style=flat-square" alt="AES-128">
  <a href="https://hits.seeyoufarm.com"><img src="https://hits.seeyoufarm.com/api/count/incr/badge.svg?url=https%3A%2F%2Fgithub.com%2Fsrhady%2Ftoffee-restream&count_bg=%23E11D48&title_bg=%231E293B&icon=&icon_color=%23E7E7E7&title=Views&edge_flat=true" alt="Hit Counter"/></a>
  <img src="https://api.visitorbadge.io/api/visitors?pageUrl=https%3A%2F%2Fgithub.com%2Fsrhady%2Ftoffee-restream&label=VISITORS&countColor=%23e11d48&style=flat-square" alt="Visitors"/>
</p>

High-performance dedicated Restream Proxy, Auto-Decryption Engine, and Modern Dark-Theme Web Player for Toffee BD Live TV channels.

---

## ⚡ Features & Updates

- **✨ Modern Web Player Interface**:
  - Deep space OLED dark theme with glassmorphism and ambient backlighting.
  - **Dynamic Quality Switcher**: Select between Auto, 1080p, 720p, 480p, etc. directly inside the player.
  - **Theater Mode & PiP**: Expand player full-width (`T`) or watch in floating Picture-in-Picture window (`P`).
  - **Favorites & Recents**: Bookmark favorite channels (`⭐`) with persistent local storage.
  - **View Modes**: Switch between responsive Grid Cards and Compact List mode.
  - **Animated Equalizer**: Live audio wave visualizer when stream is playing.
  - **Volume & Audio Memory**: Preserves volume and mute settings across sessions.
- **🔄 Live Playlist Updater**:
  - 1-Click **"Update Playlist"** button directly on the webpage to fetch fresh tokens from upstream.
  - Added dedicated `/api/refresh` REST endpoint.
- **🛡️ Multi-Source Playlist Merging & Fallback**:
  - Merges channels and token streams across 3 reliable upstream repositories.
  - Automatic stream retry & token recovery on HTTP 401/403/410.
- **🔐 AES-128 Key Decryption Proxy**:
  - Transparently proxies AES-128 encryption keys with proper CORS headers so browsers and players can decrypt live feeds seamlessly.
- **📺 Smart TV & VLC Ready**:
  - Generates proxied M3U playlist link at `http://<IP>:8081/playlist.m3u` compatible with VLC, TiviMate, OTT Navigator, IPTV Smarters, and Smart TVs.

---

## ⌨️ Web Player Keyboard Shortcuts

| Shortcut | Action |
| --- | --- |
| `Space` or `K` | Play / Pause video |
| `F` | Toggle Fullscreen |
| `T` | Toggle Theater Mode |
| `M` | Toggle Mute / Unmute |
| `P` | Toggle Picture-in-Picture |
| `R` | Resync / Reload current stream |
| `/` | Instant search channels |
| `Esc` | Clear search / Close modal dialogs |

---

## 🚀 How to Run

### Windows
Double-click `start_server.bat` or run in terminal:
```powershell
python run_server.py --port 8081
```

### Linux / Ubuntu
```bash
chmod +x install.sh
./install.sh
```

---

## 🌐 Endpoints

- **Web Player**: `http://localhost:8081`
- **M3U Playlist (VLC / TiviMate / Smart TV)**: `http://localhost:8081/playlist.m3u`
- **Channels API**: `http://localhost:8081/api/channels`
- **Refresh Playlist API**: `http://localhost:8081/api/refresh`
- **Single Channel Stream**: `http://localhost:8081/channel/{id}.m3u8`
- **Health Check**: `http://localhost:8081/health`
