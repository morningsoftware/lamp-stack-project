#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
if ! command -v node >/dev/null 2>&1; then
  echo "Node.js 20 or newer is required to run the local frontend server."
  exit 1
fi
exec node scripts/dev-server.mjs "$@"
