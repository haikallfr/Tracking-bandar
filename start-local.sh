#!/bin/zsh
set -euo pipefail

cd "$(dirname "$0")"

PORT="${1:-8099}"
URL="http://127.0.0.1:${PORT}"

echo "Menjalankan server lokal di ${URL}"
php -S "127.0.0.1:${PORT}" -t .
