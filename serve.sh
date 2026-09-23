#!/bin/bash
# ============================================================
#  serve.sh — Local development server for collab.dev
# ============================================================
PORT=8000
echo "============================================================"
echo "🚀 Starting collab.dev frontend server on http://localhost:${PORT}"
echo "📡 Connected to live backend at: http://lamp.morning.codes"
echo "============================================================"
echo "Press Ctrl+C to stop."
echo ""

if command -v python3 &>/dev/null; then
  python3 -m http.server $PORT
elif command -v php &>/dev/null; then
  php -S localhost:$PORT
else
  npx -y serve -l $PORT .
fi
