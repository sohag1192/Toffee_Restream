#!/usr/bin/env python3
"""
Toffee Live Server Runner
Usage:
  python run_server.py
  python run_server.py --port 8081
"""

import argparse
from restream_proxy import run_server

def main():
    parser = argparse.ArgumentParser(description="Run Toffee Restream Proxy Server and Web Player")
    parser.add_argument("--port", type=int, default=8081, help="Port to run server on (default: 8081)")
    parser.add_argument("--host", type=str, default="0.0.0.0", help="Host interface (default: 0.0.0.0)")
    parser.add_argument("--playlist", type=str, default=None, help="Custom M3U playlist URL")
    args = parser.parse_args()

    run_server(port=args.port, host=args.host, playlist_url=args.playlist)

if __name__ == "__main__":
    main()
