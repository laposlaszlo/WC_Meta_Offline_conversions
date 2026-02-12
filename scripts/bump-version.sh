#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 1 ]]; then
  echo "Usage: $0 <version> [--tag]"
  exit 1
fi

VERSION="$1"
TAG=false
if [[ ${2:-} == "--tag" ]]; then
  TAG=true
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_FILE="$ROOT_DIR/meta-offline-conversions/meta-offline-conversions.php"

if [[ ! -f "$PLUGIN_FILE" ]]; then
  echo "Plugin file not found: $PLUGIN_FILE"
  exit 1
fi

python3 - <<PY
import re
from pathlib import Path

path = Path(r"$PLUGIN_FILE")
text = path.read_text()

# Update header version
text_new, count1 = re.subn(r"(?m)^\s*\*\s*Version:\s*\S+\s*$", f" * Version: {VERSION}", text)
# Update constant
text_new, count2 = re.subn(r"(?m)^define\('MOC_VERSION',\s*'[^']+'\);", f"define('MOC_VERSION', '{VERSION}');", text_new)

if count1 == 0:
    raise SystemExit("Version header not found")
if count2 == 0:
    raise SystemExit("MOC_VERSION constant not found")

path.write_text(text_new)
print(f"Updated version to {VERSION}")
PY

if $TAG; then
  git -C "$ROOT_DIR" tag -a "v$VERSION" -m "Release v$VERSION"
  echo "Created tag v$VERSION"
fi
