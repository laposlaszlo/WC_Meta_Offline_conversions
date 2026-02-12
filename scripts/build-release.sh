#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUTPUT="$ROOT_DIR/meta-offline-conversions.zip"

cd "$ROOT_DIR"
rm -f "$OUTPUT"

zip -r "$OUTPUT" meta-offline-conversions \
  -x "**/.DS_Store" \
  -x "**/.git/**" \
  -x "**/.github/**"

echo "Created: $OUTPUT"
