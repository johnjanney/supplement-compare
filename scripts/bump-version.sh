#!/usr/bin/env bash
#
# Bump version in lockstep across the four locations from PROJECTBRIEF.md §11:
#
#   1. plugin/supplement-compare.php  — "* Version: X.Y.Z" header docblock
#   2. plugin/supplement-compare.php  — SUPPLEMENT_COMPARE_VERSION constant
#   3. CHANGELOG.md                   — promote [Unreleased] to [X.Y.Z] — today
#   4. README.md                      — "Current version: X.Y.Z" line
#
# Usage:  scripts/bump-version.sh --major | --minor | --patch
#
# Source of truth for the current version: the plugin header.

set -euo pipefail

PLUGIN_FILE="plugin/supplement-compare.php"
CHANGELOG="CHANGELOG.md"
README="README.md"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$REPO_ROOT"

case "${1:-}" in
    --major) bump=major ;;
    --minor) bump=minor ;;
    --patch) bump=patch ;;
    *) echo "Usage: $0 --major | --minor | --patch" >&2; exit 2 ;;
esac

for f in "$PLUGIN_FILE" "$CHANGELOG" "$README"; do
    [[ -f "$f" ]] || { echo "fatal: $f not found (run from repo root)" >&2; exit 1; }
done

current="$(grep -oE '^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+' "$PLUGIN_FILE" \
            | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -n1)"

if [[ -z "$current" ]]; then
    echo "fatal: could not read current version from $PLUGIN_FILE" >&2
    exit 1
fi

IFS='.' read -r MAJ MIN PAT <<< "$current"

case "$bump" in
    major) MAJ=$((MAJ + 1)); MIN=0; PAT=0 ;;
    minor) MIN=$((MIN + 1)); PAT=0 ;;
    patch) PAT=$((PAT + 1)) ;;
esac

new="${MAJ}.${MIN}.${PAT}"
today="$(date -u +%Y-%m-%d)"

echo "Bumping ${current} → ${new}"

# 1. Plugin header docblock
sed -i -E "s|^([[:space:]]*\*[[:space:]]*Version:[[:space:]]*)[0-9]+\.[0-9]+\.[0-9]+|\1${new}|" "$PLUGIN_FILE"

# 2. SUPPLEMENT_COMPARE_VERSION constant
sed -i -E "s|(define\([[:space:]]*'SUPPLEMENT_COMPARE_VERSION'[[:space:]]*,[[:space:]]*')[0-9]+\.[0-9]+\.[0-9]+(')|\1${new}\2|" "$PLUGIN_FILE"

# 3. README "Current version" line
sed -i -E "s|^(\*\*Current version:\*\*[[:space:]]+)[0-9]+\.[0-9]+\.[0-9]+|\1${new}|" "$README"

# 4. CHANGELOG: rename [Unreleased] block to [X.Y.Z] — today, insert fresh
# [Unreleased] block above. Whatever was under [Unreleased] becomes the body
# of the new dated section.
awk -v new="$new" -v today="$today" '
    BEGIN { inserted = 0 }
    /^## \[Unreleased\][[:space:]]*$/ && !inserted {
        print "## [Unreleased]"
        print ""
        print "### Added"
        print "### Changed"
        print "### Deprecated"
        print "### Removed"
        print "### Fixed"
        print "### Security"
        print ""
        print "---"
        print ""
        print "## [" new "] — " today
        inserted = 1
        next
    }
    { print }
' "$CHANGELOG" > "$CHANGELOG.tmp" && mv "$CHANGELOG.tmp" "$CHANGELOG"

echo
echo "Updated:"
echo "  $PLUGIN_FILE   (Version header + SUPPLEMENT_COMPARE_VERSION)"
echo "  $README        (Current version line)"
echo "  $CHANGELOG     (new [${new}] section; [Unreleased] reset)"
echo
echo "Next steps — review the diff, then commit and tag:"
echo "  git diff"
echo "  git add -A && git commit -m \"Bump version to ${new}\""
echo "  git tag v${new}"
echo "  git push --tags        # (when ready to publish)"
