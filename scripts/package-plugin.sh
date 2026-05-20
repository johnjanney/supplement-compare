#!/usr/bin/env bash
#
# Build an installable .zip of the plugin at the current version.
# Produces ./supplement-compare-X.Y.Z.zip at the repo root.
#
# The zip's top-level directory is `supplement-compare/` so that
# WordPress's "Add New → Upload Plugin" extracts it to
# wp-content/plugins/supplement-compare/. The plugin slug, header
# Text Domain, and zip dir name must all stay aligned — change them
# together if you ever need to.
#
# Excludes:
#   - .gitkeep placeholders
#   - WSL/Windows Zone.Identifier alternate-data-stream files
#   - macOS .DS_Store
#   - Anything else not under plugin/ (no docs/extractor/seed-data in the zip)
#
# Usage:  scripts/package-plugin.sh

set -euo pipefail

PLUGIN_FILE="plugin/supplement-compare.php"
PLUGIN_SLUG="supplement-compare"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$REPO_ROOT"

if [[ ! -f "$PLUGIN_FILE" ]]; then
    echo "fatal: $PLUGIN_FILE not found (run from repo root or scripts/)" >&2
    exit 1
fi

if ! command -v zip >/dev/null 2>&1; then
    echo "fatal: 'zip' is not installed. On Ubuntu/WSL2: sudo apt install zip" >&2
    exit 1
fi

version="$(grep -oE '^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+' "$PLUGIN_FILE" \
            | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -n1)"

if [[ -z "$version" ]]; then
    echo "fatal: could not read version from $PLUGIN_FILE header" >&2
    exit 1
fi

# Cross-check that the SUPPLEMENT_COMPARE_VERSION constant matches the
# header. If these drift, bump-version.sh has a bug or someone hand-edited.
constant_version="$(grep -oE "define\(\s*'SUPPLEMENT_COMPARE_VERSION'\s*,\s*'[0-9]+\.[0-9]+\.[0-9]+'" "$PLUGIN_FILE" \
                    | grep -oE "[0-9]+\.[0-9]+\.[0-9]+" || true)"

if [[ -n "$constant_version" && "$constant_version" != "$version" ]]; then
    echo "fatal: plugin header version ($version) and SUPPLEMENT_COMPARE_VERSION constant ($constant_version) disagree." >&2
    echo "       Run scripts/bump-version.sh to resync, or fix manually." >&2
    exit 1
fi

zipname="${PLUGIN_SLUG}-${version}.zip"

tmpdir="$(mktemp -d -t supcomp-pkg-XXXXXXXX)"
trap 'rm -rf "$tmpdir"' EXIT

staging="$tmpdir/$PLUGIN_SLUG"
mkdir -p "$staging"

# Copy plugin tree.
cp -a plugin/. "$staging/"

# Strip files that shouldn't ship.
find "$staging" \
    \( -name '.gitkeep' -o -name '.DS_Store' -o -name '*:Zone.Identifier' -o -name '*.swp' \) \
    -delete

# Remove any pre-existing zip of this version, then build fresh.
rm -f "$REPO_ROOT/$zipname"
( cd "$tmpdir" && zip -r "$REPO_ROOT/$zipname" "$PLUGIN_SLUG" -q )

bytes="$(stat -c '%s' "$REPO_ROOT/$zipname" 2>/dev/null || stat -f '%z' "$REPO_ROOT/$zipname")"
file_count="$(unzip -l "$REPO_ROOT/$zipname" | tail -n1 | awk '{print $2}')"

echo "Built ${REPO_ROOT}/${zipname}"
echo "  version: ${version}"
echo "  size:    ${bytes} bytes"
echo "  files:   ${file_count}"
echo
echo "Next steps:"
echo "  1. WP Admin → Plugins → Add New → Upload Plugin → choose ${zipname}"
echo "  2. Activate. Verify version ${version} shows on the Plugins screen."
echo "  3. (Optional) Commit + tag: git tag v${version} && git push --tags"
