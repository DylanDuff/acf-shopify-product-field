#!/usr/bin/env bash
set -euo pipefail

# Releases a new version of the plugin:
#   1. Bumps the Version: header in plugin.php
#   2. Packages distributable files into a zip (slug as root folder)
#   3. Commits and pushes the version bump
#   4. Creates a GitHub release with the zip as an asset
#   5. Cleans up the local zip
#
# Usage: ./release.sh <new-version>
# Example: ./release.sh 0.2.0

SLUG="acf-shopify-product-field"
PLUGIN_FILE="plugin.php"

if [ $# -ne 1 ]; then
	echo "Usage: ./release.sh <new-version>" >&2
	exit 1
fi

NEW_VERSION="$1"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

if ! git diff-index --quiet HEAD -- 2>/dev/null; then
	echo "Working tree has uncommitted changes. Commit or stash first." >&2
	exit 1
fi

echo "Bumping version to ${NEW_VERSION}..."
sed -i '' -E "s/(Version:[[:space:]]*)[0-9]+(\.[0-9]+){1,2}/\1${NEW_VERSION}/" "$PLUGIN_FILE"

if ! grep -q "Version: *${NEW_VERSION}" "$PLUGIN_FILE"; then
	echo "Version bump failed -- ${PLUGIN_FILE} does not contain 'Version: ${NEW_VERSION}'." >&2
	exit 1
fi

ZIP_NAME="${SLUG}.zip"
STAGE_DIR="$(mktemp -d)/${SLUG}"

echo "Staging distributable files..."
mkdir -p "$STAGE_DIR"
cp "$PLUGIN_FILE" "$STAGE_DIR/"
cp -R assets "$STAGE_DIR/"
cp -R inc "$STAGE_DIR/"
cp -R plugin-update-checker "$STAGE_DIR/"

echo "Building ${ZIP_NAME}..."
rm -f "$ZIP_NAME"
(cd "$(dirname "$STAGE_DIR")" && zip -qr "${SCRIPT_DIR}/${ZIP_NAME}" "$SLUG")
rm -rf "$(dirname "$STAGE_DIR")"

echo "Committing version bump..."
git add "$PLUGIN_FILE"
git commit -m "chore(release): bump version to ${NEW_VERSION}"
git push

echo "Creating GitHub release v${NEW_VERSION}..."
gh release create "v${NEW_VERSION}" "$ZIP_NAME" \
	--title "v${NEW_VERSION}" \
	--generate-notes

echo "Cleaning up local zip..."
rm -f "$ZIP_NAME"

echo "Done. Released v${NEW_VERSION}."
