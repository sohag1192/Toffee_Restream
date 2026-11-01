/**
 * ============================================================================
 * Toffee Live TV Web Player — Modern Interactive Engine
 * ============================================================================
 */

(function () {
  "use strict";

  // Application State
  let allChannels = [];
  let groupCounts = {};
  let selectedGroup = "All";
  let hlsInstance = null;
  let currentChannel = null;
  let lastTokenRefreshAt = 0;
  let isTheaterMode = false;
  let viewMode = localStorage.getItem("toffee_view_mode") || "grid";

  // Persistent storage
  let favorites = new Set();
  try {
    const savedFavs = JSON.parse(localStorage.getItem("toffee_favorites") || "[]");
    favorites = new Set(savedFavs);
  } catch (e) {
    favorites = new Set();
  }

  let recentChannelIds = [];
  try {
    recentChannelIds = JSON.parse(localStorage.getItem("toffee_recents") || "[]");
  } catch (e) {
    recentChannelIds = [];
  }

  // DOM Elements
  const appContainer = document.getElementById("appContainer");
  const mainLayout = document.getElementById("mainLayout");
  const playerWrapper = document.getElementById("playerWrapper");
  const videoPlayer = document.getElementById("videoPlayer");
  const playerPlaceholder = document.getElementById("playerPlaceholder");
  const videoSpinner = document.getElementById("videoSpinner");
  const spinnerText = document.getElementById("spinnerText");
  const autoplayBanner = document.getElementById("autoplayBanner");
  const unmuteBtn = document.getElementById("unmuteBtn");

  // Controls Elements
  const playPauseBtn = document.getElementById("playPauseBtn");
  const playIcon = document.getElementById("playIcon");
  const pauseIcon = document.getElementById("pauseIcon");
  const muteBtn = document.getElementById("muteBtn");
  const volumeHighIcon = document.getElementById("volumeHighIcon");
  const volumeMutedIcon = document.getElementById("volumeMutedIcon");
  const volumeSlider = document.getElementById("volumeSlider");
  const qualityBtn = document.getElementById("qualityBtn");
  const qualityText = document.getElementById("qualityText");
  const qualityMenu = document.getElementById("qualityMenu");
  const resyncBtn = document.getElementById("resyncBtn");
  const theaterBtn = document.getElementById("theaterBtn");
  const pipBtn = document.getElementById("pipBtn");
  const fullscreenBtn = document.getElementById("fullscreenBtn");
  const fullscreenEnterIcon = document.getElementById("fullscreenEnterIcon");
  const fullscreenExitIcon = document.getElementById("fullscreenExitIcon");

  // Now Playing Elements
  const nowPlayingCard = document.getElementById("nowPlayingCard");
  const nowPlayingLogo = document.getElementById("nowPlayingLogo");
  const nowPlayingTitle = document.getElementById("nowPlayingTitle");
  const nowPlayingGroup = document.getElementById("nowPlayingGroup");
  const nowPlayingChannelId = document.getElementById("nowPlayingChannelId");
  const nowPlayingStatus = document.getElementById("nowPlayingStatus");
  const soundEqualizer = document.getElementById("soundEqualizer");
  const toggleFavoriteNowPlayingBtn = document.getElementById("toggleFavoriteNowPlayingBtn");
  const copyStreamUrlBtn = document.getElementById("copyStreamUrlBtn");
  const vlcLinkBtn = document.getElementById("vlcLinkBtn");

  // Channel Explorer Elements
  const channelsGrid = document.getElementById("channelsGrid");
  const searchInput = document.getElementById("searchInput");
  const clearSearchBtn = document.getElementById("clearSearchBtn");
  const groupsFilterContainer = document.getElementById("groupsFilterContainer");
  const filterScrollLeft = document.getElementById("filterScrollLeft");
  const filterScrollRight = document.getElementById("filterScrollRight");
  const gridViewBtn = document.getElementById("gridViewBtn");
  const listViewBtn = document.getElementById("listViewBtn");

  // Header & Modal Elements
  const channelCountBadge = document.getElementById("channelCountBadge");
  const lastUpdatedBadge = document.getElementById("lastUpdatedBadge");
  const refreshPlaylistBtn = document.getElementById("refreshPlaylistBtn");
  const copyM3uBtn = document.getElementById("copyM3uBtn");
  const downloadM3uBtn = document.getElementById("downloadM3uBtn");
  const openGuideBtn = document.getElementById("openGuideBtn");
  const guideModal = document.getElementById("guideModal");
  const closeGuideBtn = document.getElementById("closeGuideBtn");
  const guideCloseBtn2 = document.getElementById("guideCloseBtn2");
  const playlistUrlText = document.getElementById("playlistUrlText");
  const copyBoxM3uBtn = document.getElementById("copyBoxM3uBtn");
  const toast = document.getElementById("toast");
  const toastIcon = document.getElementById("toastIcon");
  const toastMessage = document.getElementById("toastMessage");

  // Initialize
  document.addEventListener("DOMContentLoaded", () => {
    initApp();
  });

  function initApp() {
    // Determine dynamic base URLs
    const origin = window.location.origin;
    const m3uUrl = `${origin}/playlist.m3u`;
    if (playlistUrlText) playlistUrlText.innerText = m3uUrl;
    if (downloadM3uBtn) downloadM3uBtn.href = m3uUrl;

    document.querySelectorAll(".guide-m3u-url").forEach(el => {
      el.innerText = m3uUrl;
    });

    // Restore volume
    const savedVol = localStorage.getItem("toffee_volume");
    if (savedVol !== null && videoPlayer) {
      videoPlayer.volume = parseFloat(savedVol);
      if (volumeSlider) volumeSlider.value = savedVol;
      updateVolumeIcons(videoPlayer.volume === 0);
    }

    // Restore view mode
    setViewMode(viewMode);

    // Register event listeners
    setupEventListeners(m3uUrl);
    setupKeyboardShortcuts();

    // Fetch initial channels and groups
    loadChannels();
  }

  function setupEventListeners(m3uUrl) {
    // Header Actions
    if (copyM3uBtn) {
      copyM3uBtn.addEventListener("click", () => {
        copyToClipboard(m3uUrl, "Full M3U Playlist URL copied to clipboard!");
      });
    }

    if (copyBoxM3uBtn) {
      copyBoxM3uBtn.addEventListener("click", () => {
        copyToClipboard(m3uUrl, "Playlist URL copied!");
      });
    }

    if (refreshPlaylistBtn) {
      refreshPlaylistBtn.addEventListener("click", () => {
        triggerRefreshPlaylist();
      });
    }

    // Setup Guide Modal
    if (openGuideBtn && guideModal) {
      openGuideBtn.addEventListener("click", () => {
        guideModal.style.display = "flex";
      });
    }
    if (closeGuideBtn) {
      closeGuideBtn.addEventListener("click", () => {
        guideModal.style.display = "none";
      });
    }
    if (guideCloseBtn2) {
      guideCloseBtn2.addEventListener("click", () => {
        guideModal.style.display = "none";
      });
    }
    if (guideModal) {
      guideModal.addEventListener("click", (e) => {
        if (e.target === guideModal) guideModal.style.display = "none";
      });
    }
    document.querySelectorAll(".copy-guide-url").forEach(btn => {
      btn.addEventListener("click", () => {
        copyToClipboard(m3uUrl, "VLC Network URL copied!");
      });
    });

    // Search bar
    if (searchInput) {
      searchInput.addEventListener("input", () => {
        if (clearSearchBtn) {
          clearSearchBtn.style.display = searchInput.value ? "flex" : "none";
        }
        filterAndRenderChannels();
      });
    }
    if (clearSearchBtn) {
      clearSearchBtn.addEventListener("click", () => {
        searchInput.value = "";
        clearSearchBtn.style.display = "none";
        searchInput.focus();
        filterAndRenderChannels();
      });
    }

    // View toggles
    if (gridViewBtn) {
      gridViewBtn.addEventListener("click", () => setViewMode("grid"));
    }
    if (listViewBtn) {
      listViewBtn.addEventListener("click", () => setViewMode("list"));
    }

    // Filter bar horizontal scroll arrows
    if (filterScrollLeft && groupsFilterContainer) {
      filterScrollLeft.addEventListener("click", () => {
        groupsFilterContainer.scrollBy({ left: -200, behavior: "smooth" });
      });
    }
    if (filterScrollRight && groupsFilterContainer) {
      filterScrollRight.addEventListener("click", () => {
        groupsFilterContainer.scrollBy({ left: 200, behavior: "smooth" });
      });
    }

    // Now Playing Actions
    if (copyStreamUrlBtn) {
      copyStreamUrlBtn.addEventListener("click", () => {
        if (currentChannel) {
          copyToClipboard(currentChannel.stream_url, `Stream URL for "${currentChannel.name}" copied!`);
        }
      });
    }

    if (toggleFavoriteNowPlayingBtn) {
      toggleFavoriteNowPlayingBtn.addEventListener("click", () => {
        if (currentChannel) {
          toggleFavorite(currentChannel.id);
          updateFavoriteButtonState(currentChannel.id);
        }
      });
    }

    // Video Controls
    if (playPauseBtn && videoPlayer) {
      playPauseBtn.addEventListener("click", togglePlayPause);
    }

    if (videoPlayer) {
      videoPlayer.addEventListener("play", () => {
        if (playIcon) playIcon.style.display = "none";
        if (pauseIcon) pauseIcon.style.display = "block";
        if (soundEqualizer) soundEqualizer.style.display = "flex";
        if (playerWrapper) playerWrapper.classList.add("playing");
      });

      videoPlayer.addEventListener("pause", () => {
        if (playIcon) playIcon.style.display = "block";
        if (pauseIcon) pauseIcon.style.display = "none";
        if (soundEqualizer) soundEqualizer.style.display = "none";
        if (playerWrapper) playerWrapper.classList.remove("playing");
      });

      videoPlayer.addEventListener("volumechange", () => {
        if (volumeSlider) volumeSlider.value = videoPlayer.volume;
        updateVolumeIcons(videoPlayer.muted || videoPlayer.volume === 0);
        localStorage.setItem("toffee_volume", videoPlayer.volume);
      });
    }

    if (muteBtn && videoPlayer) {
      muteBtn.addEventListener("click", () => {
        videoPlayer.muted = !videoPlayer.muted;
        updateVolumeIcons(videoPlayer.muted);
      });
    }

    if (volumeSlider && videoPlayer) {
      volumeSlider.addEventListener("input", (e) => {
        videoPlayer.volume = parseFloat(e.target.value);
        videoPlayer.muted = (videoPlayer.volume === 0);
        updateVolumeIcons(videoPlayer.muted);
      });
    }

    if (resyncBtn) {
      resyncBtn.addEventListener("click", () => {
        if (currentChannel) {
          showToast(`Resyncing stream: ${currentChannel.name}`);
          playChannel(currentChannel);
        }
      });
    }

    if (theaterBtn) {
      theaterBtn.addEventListener("click", toggleTheaterMode);
    }

    if (pipBtn && videoPlayer) {
      pipBtn.addEventListener("click", async () => {
        try {
          if (document.pictureInPictureElement) {
            await document.exitPictureInPicture();
          } else if (videoPlayer.requestPictureInPicture) {
            await videoPlayer.requestPictureInPicture();
          }
        } catch (err) {
          console.warn("PiP Error:", err);
        }
      });
    }

    if (fullscreenBtn && playerWrapper) {
      fullscreenBtn.addEventListener("click", toggleFullscreen);
    }

    document.addEventListener("fullscreenchange", () => {
      const isFull = !!document.fullscreenElement;
      if (fullscreenEnterIcon) fullscreenEnterIcon.style.display = isFull ? "none" : "block";
      if (fullscreenExitIcon) fullscreenExitIcon.style.display = isFull ? "block" : "none";
    });

    // Quality Menu Toggle
    if (qualityBtn && qualityMenu) {
      qualityBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        qualityMenu.style.display = qualityMenu.style.display === "none" ? "block" : "none";
      });
    }
    document.addEventListener("click", () => {
      if (qualityMenu) qualityMenu.style.display = "none";
    });

    // Unmute Banner
    if (unmuteBtn && videoPlayer) {
      unmuteBtn.addEventListener("click", () => {
        videoPlayer.muted = false;
        videoPlayer.play();
        if (autoplayBanner) autoplayBanner.style.display = "none";
      });
    }
  }

  function setViewMode(mode) {
    viewMode = mode;
    localStorage.setItem("toffee_view_mode", mode);
    if (mode === "list") {
      channelsGrid.classList.add("list-view");
      if (listViewBtn) listViewBtn.classList.add("active");
      if (gridViewBtn) gridViewBtn.classList.remove("active");
    } else {
      channelsGrid.classList.remove("list-view");
      if (gridViewBtn) gridViewBtn.classList.add("active");
      if (listViewBtn) listViewBtn.classList.remove("active");
    }
  }

  function togglePlayPause() {
    if (!videoPlayer) return;
    if (videoPlayer.paused) {
      videoPlayer.play().catch(e => console.warn(e));
    } else {
      videoPlayer.pause();
    }
  }

  function toggleTheaterMode() {
    isTheaterMode = !isTheaterMode;
    if (appContainer) {
      if (isTheaterMode) {
        appContainer.classList.add("theater-mode");
      } else {
        appContainer.classList.remove("theater-mode");
      }
    }
  }

  function toggleFullscreen() {
    if (!playerWrapper) return;
    if (!document.fullscreenElement) {
      playerWrapper.requestFullscreen().catch(err => {
        console.warn("Fullscreen request failed:", err);
      });
    } else {
      document.exitFullscreen();
    }
  }

  function updateVolumeIcons(isMuted) {
    if (volumeHighIcon) volumeHighIcon.style.display = isMuted ? "none" : "block";
    if (volumeMutedIcon) volumeMutedIcon.style.display = isMuted ? "block" : "none";
  }

  function setupKeyboardShortcuts() {
    document.addEventListener("keydown", (e) => {
      // Don't intercept when user is typing in search input
      if (document.activeElement === searchInput) {
        if (e.key === "Escape") {
          searchInput.blur();
        }
        return;
      }

      if (e.key === "/" && !e.ctrlKey && !e.metaKey) {
        e.preventDefault();
        if (searchInput) {
          searchInput.focus();
          searchInput.select();
        }
      } else if (e.code === "Space" || e.key === "k") {
        e.preventDefault();
        togglePlayPause();
      } else if (e.key === "m" || e.key === "M") {
        if (videoPlayer) {
          videoPlayer.muted = !videoPlayer.muted;
          updateVolumeIcons(videoPlayer.muted);
        }
      } else if (e.key === "f" || e.key === "F") {
        toggleFullscreen();
      } else if (e.key === "t" || e.key === "T") {
        toggleTheaterMode();
      } else if (e.key === "r" || e.key === "R") {
        if (currentChannel) {
          showToast(`Reloading: ${currentChannel.name}`);
          playChannel(currentChannel);
        }
      } else if (e.key === "Escape") {
        if (guideModal) guideModal.style.display = "none";
      }
    });
  }

  async function triggerRefreshPlaylist() {
    const icon = refreshPlaylistBtn.querySelector(".icon-spin");
    if (icon) icon.classList.add("spinning");
    refreshPlaylistBtn.disabled = true;

    showToast("Connecting to upstream Toffee servers...");

    try {
      let res = await fetch("/api/refresh");
      if (!res.ok) {
        res = await fetch("?api=refresh");
      }
      if (!res.ok) {
        res = await fetch("/api/channels?refresh=1");
      }
      if (!res.ok) {
        res = await fetch("?api=channels&refresh=1");
      }
      const data = await res.json();
      await loadChannels();
      showToast(`✓ Updated! ${allChannels.length} channels loaded.`);
    } catch (err) {
      console.error("Refresh failed:", err);
      showToast("Refresh error. Please check server connection.");
    } finally {
      if (icon) icon.classList.remove("spinning");
      refreshPlaylistBtn.disabled = false;
    }
  }

  async function loadChannels() {
    try {
      let res = await fetch("/api/channels");
      if (!res.ok) {
        res = await fetch("?api=channels");
      }
      const data = await res.json();
      allChannels = data.channels || [];
      groupCounts = data.group_counts || {};

      if (channelCountBadge) {
        channelCountBadge.innerText = `${allChannels.length} Channels Live`;
      }
      if (lastUpdatedBadge && data.updated_at) {
        lastUpdatedBadge.innerText = `Synced: ${data.updated_at}`;
        lastUpdatedBadge.style.display = "inline-block";
      }

      // Populate group counts from channels if not provided
      if (Object.keys(groupCounts).length === 0) {
        allChannels.forEach(c => {
          const g = c.group || "General";
          groupCounts[g] = (groupCounts[g] || 0) + 1;
        });
      }

      renderGroupPills();
      filterAndRenderChannels();
    } catch (err) {
      console.error("Failed to load channels:", err);
      if (channelCountBadge) channelCountBadge.innerText = "Offline";
      if (channelsGrid) {
        channelsGrid.innerHTML = `
          <div class="empty-state">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="1.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            <p>Error connecting to Toffee server. Please ensure backend is running.</p>
            <button class="btn btn-secondary btn-sm" onclick="location.reload()">Retry</button>
          </div>
        `;
      }
    }
  }

  function renderGroupPills() {
    if (!groupsFilterContainer) return;
    groupsFilterContainer.innerHTML = "";

    const groupsList = ["All", "⭐ Favorites", "🕒 Recent", ...Object.keys(groupCounts).sort()];

    groupsList.forEach((grp) => {
      let count = 0;
      if (grp === "All") count = allChannels.length;
      else if (grp === "⭐ Favorites") count = favorites.size;
      else if (grp === "🕒 Recent") count = recentChannelIds.length;
      else count = groupCounts[grp] || 0;

      const btn = document.createElement("button");
      btn.className = `pill-btn ${grp === selectedGroup ? "active" : ""}`;
      btn.innerHTML = `<span>${grp}</span> <span class="pill-count">${count}</span>`;

      btn.addEventListener("click", () => {
        selectedGroup = grp;
        document.querySelectorAll(".pill-btn").forEach((b) => b.classList.remove("active"));
        btn.classList.add("active");
        filterAndRenderChannels();
      });

      groupsFilterContainer.appendChild(btn);
    });
  }

  function toggleFavorite(channelId) {
    if (favorites.has(channelId)) {
      favorites.delete(channelId);
      showToast("Removed from Favorites");
    } else {
      favorites.add(channelId);
      showToast("⭐ Added to Favorites");
    }
    localStorage.setItem("toffee_favorites", JSON.stringify([...favorites]));
    renderGroupPills();
    filterAndRenderChannels();
  }

  function updateFavoriteButtonState(channelId) {
    if (!toggleFavoriteNowPlayingBtn) return;
    if (favorites.has(channelId)) {
      toggleFavoriteNowPlayingBtn.classList.add("active");
      toggleFavoriteNowPlayingBtn.title = "Remove from Favorites";
    } else {
      toggleFavoriteNowPlayingBtn.classList.remove("active");
      toggleFavoriteNowPlayingBtn.title = "Add to Favorites";
    }
  }

  function filterAndRenderChannels() {
    if (!channelsGrid) return;
    const query = searchInput ? searchInput.value.trim().toLowerCase() : "";

    const filtered = allChannels.filter((ch) => {
      let matchGroup = true;
      if (selectedGroup === "⭐ Favorites") {
        matchGroup = favorites.has(ch.id);
      } else if (selectedGroup === "🕒 Recent") {
        matchGroup = recentChannelIds.includes(ch.id);
      } else if (selectedGroup !== "All") {
        matchGroup = ch.group.toLowerCase() === selectedGroup.toLowerCase();
      }

      let matchQuery = true;
      if (query) {
        if (query.startsWith("#") && query.length > 1) {
          matchQuery = ch.id.toString() === query.substring(1);
        } else {
          matchQuery = ch.name.toLowerCase().includes(query) ||
                       ch.group.toLowerCase().includes(query) ||
                       ch.id.toString() === query;
        }
      }

      return matchGroup && matchQuery;
    });

    if (filtered.length === 0) {
      channelsGrid.innerHTML = `
        <div class="empty-state">
          <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
          <p>No channels found matching "${searchInput ? searchInput.value : selectedGroup}"</p>
          <button class="btn btn-secondary btn-sm" id="resetFilterBtn">Reset Search & Filters</button>
        </div>`;

      const resetBtn = document.getElementById("resetFilterBtn");
      if (resetBtn) {
        resetBtn.addEventListener("click", () => {
          if (searchInput) searchInput.value = "";
          if (clearSearchBtn) clearSearchBtn.style.display = "none";
          selectedGroup = "All";
          renderGroupPills();
          filterAndRenderChannels();
        });
      }
      return;
    }

    channelsGrid.innerHTML = "";
    filtered.forEach((ch) => {
      const card = document.createElement("div");
      const isCurrent = currentChannel && currentChannel.id === ch.id;
      const isFav = favorites.has(ch.id);

      card.className = `channel-card ${isCurrent ? "active" : ""}`;
      
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
          <span class="card-channel-id">CH #${ch.id}</span>
          <button class="card-favorite-btn ${isFav ? "active" : ""}" title="${isFav ? "Remove Favorite" : "Add Favorite"}">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="${isFav ? "currentColor" : "none"}" stroke="currentColor" stroke-width="2">
              <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
            </svg>
          </button>
          <span class="play-badge">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
            ${isCurrent ? "Playing" : "Play"}
          </span>
        </div>
      `;

      // Favorite button click
      const favBtn = card.querySelector(".card-favorite-btn");
      if (favBtn) {
        favBtn.addEventListener("click", (e) => {
          e.stopPropagation();
          toggleFavorite(ch.id);
        });
      }

      // Card click
      card.addEventListener("click", () => {
        playChannel(ch);
      });

      channelsGrid.appendChild(card);
    });
  }

  function playChannel(channel) {
    currentChannel = channel;

    // Track recently played
    recentChannelIds = [channel.id, ...recentChannelIds.filter(id => id !== channel.id)].slice(0, 20);
    localStorage.setItem("toffee_recents", JSON.stringify(recentChannelIds));

    // Update Now Playing UI
    if (playerPlaceholder) playerPlaceholder.style.display = "none";
    if (nowPlayingCard) nowPlayingCard.style.display = "flex";
    if (nowPlayingTitle) nowPlayingTitle.innerText = channel.name;
    if (nowPlayingGroup) nowPlayingGroup.innerText = channel.group;
    if (nowPlayingChannelId) nowPlayingChannelId.innerText = `CH #${channel.id}`;
    if (nowPlayingStatus) nowPlayingStatus.innerText = "Buffering...";
    if (nowPlayingLogo) nowPlayingLogo.src = channel.logo || "";
    if (vlcLinkBtn) vlcLinkBtn.href = `vlc://${channel.stream_url}`;
    updateFavoriteButtonState(channel.id);

    // Show buffering spinner
    if (videoSpinner) {
      videoSpinner.style.display = "flex";
      if (spinnerText) spinnerText.innerText = `Loading ${channel.name}...`;
    }

    // Highlight card
    filterAndRenderChannels();

    // Destroy previous HLS instance
    if (hlsInstance) {
      hlsInstance.destroy();
      hlsInstance = null;
    }

    const streamUrl = channel.stream_url;
    console.log(`[Player] Connecting to: ${streamUrl}`);

    if (Hls.isSupported()) {
      hlsInstance = new Hls({
        enableWorker: true,
        lowLatencyMode: true,
        backBufferLength: 60,
        manifestLoadingTimeOut: 18000,
        levelLoadingTimeOut: 18000,
      });

      hlsInstance.loadSource(streamUrl);
      hlsInstance.attachMedia(videoPlayer);

      hlsInstance.on(Hls.Events.MANIFEST_PARSED, (event, data) => {
        if (videoSpinner) videoSpinner.style.display = "none";
        if (nowPlayingStatus) nowPlayingStatus.innerText = "Live";
        buildQualityMenu(data.levels || []);

        videoPlayer.play().catch((err) => {
          console.warn("Autoplay was prevented by browser:", err);
          if (nowPlayingStatus) nowPlayingStatus.innerText = "Click play";
          if (autoplayBanner) autoplayBanner.style.display = "flex";
        });
      });

      hlsInstance.on(Hls.Events.LEVEL_SWITCHED, (event, data) => {
        const level = hlsInstance.levels[data.level];
        if (level && level.height) {
          if (nowPlayingStatus) nowPlayingStatus.innerText = `Live ${level.height}p`;
        }
      });

      hlsInstance.on(Hls.Events.ERROR, (event, data) => {
        console.warn("HLS Event Error:", data);
        if (data.fatal) {
          const responseCode = data.response && data.response.code;
          // Auto token refresh on 401/403
          if ([401, 403, 410].includes(responseCode) && Date.now() - lastTokenRefreshAt > 8000) {
            lastTokenRefreshAt = Date.now();
            if (nowPlayingStatus) nowPlayingStatus.innerText = "Refreshing stream token...";
            setTimeout(() => playChannel(channel), 600);
            return;
          }

          switch (data.type) {
            case Hls.ErrorTypes.NETWORK_ERROR:
              if (nowPlayingStatus) nowPlayingStatus.innerText = "Network Retry...";
              hlsInstance.startLoad();
              break;
            case Hls.ErrorTypes.MEDIA_ERROR:
              if (nowPlayingStatus) nowPlayingStatus.innerText = "Recovering media...";
              hlsInstance.recoverMediaError();
              break;
            default:
              if (nowPlayingStatus) nowPlayingStatus.innerText = "Stream Offline";
              if (videoSpinner) videoSpinner.style.display = "none";
              hlsInstance.destroy();
              break;
          }
        }
      });
    } else if (videoPlayer && videoPlayer.canPlayType("application/vnd.apple.mpegurl")) {
      // Safari / iOS Native HLS
      videoPlayer.src = streamUrl;
      videoPlayer.addEventListener("loadedmetadata", () => {
        if (videoSpinner) videoSpinner.style.display = "none";
        if (nowPlayingStatus) nowPlayingStatus.innerText = "Live";
        videoPlayer.play();
      });
    } else {
      if (videoSpinner) videoSpinner.style.display = "none";
      if (nowPlayingStatus) nowPlayingStatus.innerText = "HLS unsupported";
      alert("HLS playback is not natively supported in this browser. Please use Chrome, Edge, or VLC.");
    }
  }

  function buildQualityMenu(levels) {
    if (!qualityMenu) return;
    qualityMenu.innerHTML = "";

    // Auto option
    const autoItem = document.createElement("div");
    autoItem.className = `quality-item ${hlsInstance.currentLevel === -1 ? "active" : ""}`;
    autoItem.innerText = "Auto";
    autoItem.addEventListener("click", () => {
      hlsInstance.currentLevel = -1;
      if (qualityText) qualityText.innerText = "Auto";
      qualityMenu.style.display = "none";
      document.querySelectorAll(".quality-item").forEach(el => el.classList.remove("active"));
      autoItem.classList.add("active");
    });
    qualityMenu.appendChild(autoItem);

    // Levels
    levels.forEach((lvl, idx) => {
      const item = document.createElement("div");
      const label = lvl.height ? `${lvl.height}p` : `${Math.round(lvl.bitrate / 1000)}k`;
      item.className = `quality-item ${hlsInstance.currentLevel === idx ? "active" : ""}`;
      item.innerText = label;
      item.addEventListener("click", () => {
        hlsInstance.currentLevel = idx;
        if (qualityText) qualityText.innerText = label;
        qualityMenu.style.display = "none";
        document.querySelectorAll(".quality-item").forEach(el => el.classList.remove("active"));
        item.classList.add("active");
      });
      qualityMenu.appendChild(item);
    });
  }

  function showToast(message, isError = false) {
    if (!toast) return;
    if (toastMessage) toastMessage.innerText = message;
    if (toastIcon) {
      toastIcon.innerText = isError ? "⚠" : "✓";
      toastIcon.style.color = isError ? "var(--crimson)" : "var(--emerald)";
    }
    toast.classList.add("show");
    setTimeout(() => {
      toast.classList.remove("show");
    }, 2800);
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

})();
