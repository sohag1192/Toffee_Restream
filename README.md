# 📺 Toffee Live TV Restream & Web Player (PHP Edition)

<p align="center">
  <a href="https://github.com/sohag1192/Toffee_Restream/releases/latest">
    <img src="https://img.shields.io/github/v/release/sohag1192/Toffee_Restream?style=flat-square&logo=github&color=blue" alt="Latest Release">
  </a>
  <img src="https://img.shields.io/badge/PHP-8.1+-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/Composer-Ready-885630?style=flat-square&logo=composer&logoColor=white" alt="Composer">
  <img src="https://img.shields.io/badge/CI-GitHub%20Actions-2088FF?style=flat-square&logo=githubactions&logoColor=white" alt="CI">
  <img src="https://img.shields.io/badge/AES--128-Decrypted-brightgreen?style=flat-square" alt="AES-128">
</p>

<p align="center">
  <a href="https://github.com/sohag1192/Toffee_Restream/releases/latest/download/Toffee_Restream_PHP.zip">
    <img src="https://img.shields.io/badge/Download-Latest%20Release%20ZIP-2ea44f?style=for-the-badge&logo=github&logoColor=white" alt="Download Latest Release ZIP">
  </a>
</p>

High-performance, standalone **PHP-based** Restream Proxy, Auto-Decryption Engine, and Modern Web Player for Toffee BD Live TV channels.

---

## 📥 Quick Download (Ready to Deploy)

Download the pre-packaged deployment zip containing everything you need to run on localhost or upload to Apache / cPanel / Shared Hosting:

| Package | Direct Download Link | Releases Page |
| :--- | :--- | :--- |
| 📦 **Toffee_Restream_PHP.zip** | [⬇️ **Download Latest Release (.zip)**](https://github.com/sohag1192/Toffee_Restream/releases/latest/download/Toffee_Restream_PHP.zip) | [🏷️ View All Releases & Tags](https://github.com/sohag1192/Toffee_Restream/releases) |

---

## ⚡ Features

- **🐘 Pure PHP Architecture**: 100% PHP with zero Python dependencies.
- **⚡ In-Memory RAM Caching**: High-performance segment caching using `/dev/shm` or temporary memory.
- **✨ Modern Web Player Interface**:
  - Deep space OLED dark theme with glassmorphism and ambient backlighting.
  - **Quality Selector**: Switch between Auto, 1080p, 720p, 480p, etc. directly in the player.
  - **Theater Mode & PiP**: Expand player full-width (`T`) or watch in floating Picture-in-Picture window (`P`).
  - **Favorites & Recents**: Bookmark favorite channels (`⭐`) with persistent local storage.
  - **View Modes**: Switch between Grid Cards and Compact List mode.
  - **Animated Equalizer**: Live audio wave visualizer when stream is playing.
- **🔄 Live Playlist Updater**:
  - Dedicated `/api/refresh` REST endpoint to fetch fresh tokens from upstream.
  - Multi-source playlist merging with auto-fallback across 3 upstream repositories.
- **🔐 AES-128 Key Decryption Proxy**:
  - Automatically proxies AES-128 encryption keys with proper CORS headers so browsers and players can decrypt live feeds seamlessly.
- **📺 Smart TV & VLC Ready**:
  - Generates proxied M3U playlist link at `http://<IP>:8081/playlist.m3u` compatible with VLC, TiviMate, OTT Navigator, IPTV Smarters, and Smart TVs.
- **🚀 Automated Versioning & Releases**:
  - GitHub Actions automatically calculates the next version tag, packages `Toffee_Restream_PHP.zip`, and creates a GitHub Release on every push to `main`.

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
php -S 0.0.0.0:8081 index.php
```

### Linux / macOS
```bash
php -S 0.0.0.0:8081 index.php
```

### Apache / Nginx / cPanel
Upload the extracted files from `Toffee_Restream_PHP.zip` to your web root directory (e.g. `public_html`). The included `.htaccess` file handles URL routing and CORS headers automatically.

---

## 🧪 Testing & CI

Run syntax checks and tests with Composer:
```bash
composer lint
composer test
```

---

## 🌐 Endpoints

- **Web Player**: `http://localhost:8081`
- **M3U Playlist (VLC / TiviMate / Smart TV)**: `http://localhost:8081/playlist.m3u`
- **Channels API**: `http://localhost:8081/api/channels`
- **Refresh Playlist API**: `http://localhost:8081/api/refresh`
- **Single Channel Stream**: `http://localhost:8081/channel/{id}.m3u8`
- **Health Check**: `http://localhost:8081/health`
