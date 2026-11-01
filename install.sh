#!/bin/bash
# ========================================================
# Toffee Live TV Restream - Auto Installer (Ubuntu / Debian)
# ========================================================

set -e

PORT=8081
INSTALL_DIR=$(pwd)
CURRENT_USER=$(whoami)

echo "========================================================"
echo "    Installing Toffee Live TV Restream on Ubuntu        "
echo "========================================================"
echo "Directory: $INSTALL_DIR"
echo "Port: $PORT"
echo ""

sudo apt-get update -y
sudo apt-get install -y python3 python3-pip curl ufw

if sudo ufw status | grep -q "Status: active"; then
    sudo ufw allow $PORT/tcp
fi

SERVICE_FILE="/etc/systemd/system/toffee-restream.service"

sudo bash -c "cat <<EOF > $SERVICE_FILE
[Unit]
Description=Toffee Live TV Restream Proxy
After=network.target

[Service]
Type=simple
User=$CURRENT_USER
WorkingDirectory=$INSTALL_DIR
ExecStart=/usr/bin/python3 $INSTALL_DIR/run_server.py --port $PORT
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF"

sudo systemctl daemon-reload
sudo systemctl enable toffee-restream.service
sudo systemctl restart toffee-restream.service

SERVER_IP=$(curl -s -4 ifconfig.me || hostname -I | awk '{print $1}')

echo ""
echo "========================================================"
echo "               Toffee Service Started!                  "
echo "========================================================"
echo "Web Player:   http://${SERVER_IP}:${PORT}"
echo "M3U Playlist: http://${SERVER_IP}:${PORT}/playlist.m3u"
echo "========================================================"
