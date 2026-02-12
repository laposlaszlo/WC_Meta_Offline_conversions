#!/usr/bin/env bash
set -euo pipefail

VERSION="${1:-}"
if [[ -z "$VERSION" ]]; then
  echo "Usage: $0 <version>"
  exit 1
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CHANGELOG="$ROOT_DIR/CHANGELOG.md"

if [[ ! -f "$CHANGELOG" ]]; then
  echo "Changelog not found: $CHANGELOG"
  exit 1
fi

LATEST_TAG=""
if git -C "$ROOT_DIR" rev-parse --git-dir >/dev/null 2>&1; then
  if git -C "$ROOT_DIR" tag -l | grep -q .; then
    LATEST_TAG=$(git -C "$ROOT_DIR" describe --tags --abbrev=0 2>/dev/null || true)
  fi
fi

if [[ -n "$LATEST_TAG" ]]; then
  RANGE="$LATEST_TAG..HEAD"
else
  RANGE="HEAD"
fi

COMMITS=$(git -C "$ROOT_DIR" log $RANGE --pretty=format:"- %s" --no-merges || true)
if [[ -z "$COMMITS" ]]; then
  COMMITS="- No notable changes"
fi

TODAY=$(date +%Y-%m-%d)

python3 - <<PY
from pathlib import Path

path = Path(r"$CHANGELOG")
text = path.read_text()

header = "# Changelog\n\n"
if not text.startswith(header):
    raise SystemExit("Invalid changelog header")

section = """## [$VERSION] - $TODAY
$COMMITS

"""

if "## [Unreleased]" in text:
    parts = text.split("## [Unreleased]", 1)
    new_text = parts[0] + "## [Unreleased]\n\n" + section + parts[1].lstrip()
else:
    new_text = text + "\n" + section

path.write_text(new_text)
print("Changelog updated for $VERSION")
PY
