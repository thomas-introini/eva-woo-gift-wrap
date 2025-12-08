#!/bin/bash
#
# Build script for EVA Gift Wrap plugin.
# Creates a distributable .zip bundle ready for WordPress installation.
#

set -e

# Plugin name and version.
PLUGIN_SLUG="eva-gift-wrap"
VERSION=$(sed -n -E 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([0-9.]+).*$/\1/p' eva-gift-wrap.php | head -n 1)
if [ -z "${VERSION}" ]; then
	echo "❌ Could not extract Version from eva-gift-wrap.php header" >&2
	exit 1
fi

# Build directories.
BUILD_DIR="build"
DIST_DIR="dist"
BUNDLE_NAME="${PLUGIN_SLUG}-${VERSION}"

echo "🎁 Building ${PLUGIN_SLUG} v${VERSION}..."

# Clean previous builds.
rm -rf "${BUILD_DIR}"
rm -rf "${DIST_DIR}"

# Create build directories.
mkdir -p "${BUILD_DIR}/${PLUGIN_SLUG}"
mkdir -p "${DIST_DIR}"

# Files and directories to include in the bundle.
INCLUDE=(
    "eva-gift-wrap.php"
    "readme.md"
    "src"
    "assets"
    "languages"
)

# Copy files to build directory.
for item in "${INCLUDE[@]}"; do
    if [ -e "$item" ]; then
        cp -r "$item" "${BUILD_DIR}/${PLUGIN_SLUG}/"
        echo "  ✓ Copied ${item}"
    else
        echo "  ⚠ Skipped ${item} (not found)"
    fi
done

# Create the zip archive.
cd "${BUILD_DIR}"
zip -r "../${DIST_DIR}/${BUNDLE_NAME}.zip" "${PLUGIN_SLUG}" -x "*.DS_Store" -x "*__MACOSX*"
cd ..

# Clean up build directory.
rm -rf "${BUILD_DIR}"

# Output result.
BUNDLE_PATH="${DIST_DIR}/${BUNDLE_NAME}.zip"
BUNDLE_SIZE=$(du -h "${BUNDLE_PATH}" | cut -f1)

echo ""
echo "✅ Build complete!"
echo "   📦 ${BUNDLE_PATH} (${BUNDLE_SIZE})"
echo ""
echo "To install, upload the zip file via WordPress Admin → Plugins → Add New → Upload Plugin."

