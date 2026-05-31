#!/usr/bin/env bash
# Package the plugin into a WordPress.org-ready zip.
#
# Usage:
#   bin/build-zip.sh                # build dist/sendsms-dashboard-X.Y.Z.zip
#   bin/build-zip.sh --output PATH  # write the zip to PATH instead
#   bin/build-zip.sh --no-lint      # skip the php -l sweep
#
# The script reads the version from the plugin file header, cross-checks it
# against readme.txt's Stable tag, lints every PHP file, copies a clean tree
# into dist/staging/sendsms-dashboard/, and zips it.

set -euo pipefail

# ---- locate the repo root (this script lives in bin/) ---------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${REPO_ROOT}"

# ---- plugin metadata ------------------------------------------------------
# Directory slug for the WordPress.org listing (the zip's top-level folder)
# and the main plugin file. The git repository itself is not renamed.
PLUGIN_SLUG="sendsms-subscribers-2fa"
PLUGIN_FILE="${PLUGIN_SLUG}.php"
README_FILE="readme.txt"

if [[ ! -f "${PLUGIN_FILE}" ]]; then
    echo "Error: ${PLUGIN_FILE} not found at ${REPO_ROOT}" >&2
    exit 1
fi
if [[ ! -f "${README_FILE}" ]]; then
    echo "Error: ${README_FILE} not found at ${REPO_ROOT}" >&2
    exit 1
fi

# Extract "Version: X.Y.Z" from the plugin header.
PLUGIN_VERSION="$(awk '/^[[:space:]]*\*?[[:space:]]*Version:[[:space:]]*/ { sub(/^[[:space:]]*\*?[[:space:]]*Version:[[:space:]]*/, ""); sub(/[[:space:]]*$/, ""); print; exit }' "${PLUGIN_FILE}")"
if [[ -z "${PLUGIN_VERSION}" ]]; then
    echo "Error: could not parse Version: from ${PLUGIN_FILE}" >&2
    exit 1
fi

# Extract "Stable tag: X.Y.Z" from readme.txt.
README_STABLE="$(awk '/^Stable tag:[[:space:]]*/ { sub(/^Stable tag:[[:space:]]*/, ""); sub(/[[:space:]]*$/, ""); print; exit }' "${README_FILE}")"
if [[ -z "${README_STABLE}" ]]; then
    echo "Error: could not parse Stable tag: from ${README_FILE}" >&2
    exit 1
fi
if [[ "${PLUGIN_VERSION}" != "${README_STABLE}" ]]; then
    echo "Error: version mismatch — plugin header says '${PLUGIN_VERSION}' but readme.txt Stable tag says '${README_STABLE}'." >&2
    echo "       Bump both before packaging." >&2
    exit 1
fi

# ---- arg parsing ----------------------------------------------------------
DEFAULT_OUTPUT="${REPO_ROOT}/dist/${PLUGIN_SLUG}-${PLUGIN_VERSION}.zip"
OUTPUT="${DEFAULT_OUTPUT}"
RUN_LINT=1

while [[ $# -gt 0 ]]; do
    case "$1" in
        --output)
            shift
            [[ $# -gt 0 ]] || { echo "--output requires a path" >&2; exit 1; }
            OUTPUT="$1"
            shift
            ;;
        --no-lint)
            RUN_LINT=0
            shift
            ;;
        -h|--help)
            sed -n '2,12p' "$0"
            exit 0
            ;;
        *)
            echo "Unknown argument: $1" >&2
            exit 1
            ;;
    esac
done

mkdir -p "$(dirname "${OUTPUT}")"

# ---- lint PHP -------------------------------------------------------------
if [[ "${RUN_LINT}" -eq 1 ]]; then
    if ! command -v php >/dev/null 2>&1; then
        echo "Warning: php not found on PATH, skipping syntax check." >&2
    else
        echo "→ Linting PHP files..."
        # Find every PHP file we'll ship (excluding the same paths rsync will skip).
        LINT_FAIL=0
        while IFS= read -r f; do
            if ! php -l "$f" >/dev/null 2>&1; then
                echo "  ✗ ${f}" >&2
                php -l "$f" >&2 || true
                LINT_FAIL=1
            fi
        done < <(find . \
            -path ./.git -prune -o \
            -path ./.claude -prune -o \
            -path ./vendor -prune -o \
            -path ./node_modules -prune -o \
            -path ./dist -prune -o \
            -path ./bin -prune -o \
            -type f -name '*.php' -print)
        if [[ "${LINT_FAIL}" -ne 0 ]]; then
            echo "Lint failures detected. Fix them before packaging." >&2
            exit 1
        fi
        echo "  All PHP files lint clean."
    fi
fi

# ---- stage the tree -------------------------------------------------------
STAGING_ROOT="${REPO_ROOT}/dist/staging"
STAGING_DIR="${STAGING_ROOT}/${PLUGIN_SLUG}"

rm -rf "${STAGING_ROOT}"
mkdir -p "${STAGING_DIR}"

# Exclude:
#  - VCS / editor state
#  - Dev-only tooling (composer + phpcs + phpunit)
#  - The build script's own outputs and helpers
#  - Anything generated locally that doesn't belong in a release
echo "→ Staging files into ${STAGING_DIR}"
rsync -a --delete \
    --exclude='.git/' \
    --exclude='.gitignore' \
    --exclude='.gitattributes' \
    --exclude='.github/' \
    --exclude='.claude/' \
    --exclude='CLAUDE.md' \
    --exclude='docs/' \
    --exclude='.DS_Store' \
    --exclude='vendor/' \
    --exclude='node_modules/' \
    --exclude='composer.json' \
    --exclude='composer.lock' \
    --exclude='composer.phar' \
    --exclude='phpcs.xml.dist' \
    --exclude='.phpcs-cache' \
    --exclude='phpunit.xml.dist' \
    --exclude='.phpunit.result.cache' \
    --exclude='bin/' \
    --exclude='dist/' \
    --exclude='tests/' \
    --exclude='*.zip' \
    --exclude='Makefile' \
    "${REPO_ROOT}/" \
    "${STAGING_DIR}/"

# Sanity check: confirm essential files are present.
for required in "${PLUGIN_FILE}" "readme.txt"; do
    if [[ ! -f "${STAGING_DIR}/${required}" ]]; then
        echo "Error: required file missing after staging: ${required}" >&2
        exit 1
    fi
done

# ---- build the zip --------------------------------------------------------
rm -f "${OUTPUT}"
echo "→ Building ${OUTPUT}"
(cd "${STAGING_ROOT}" && zip -rq "${OUTPUT}" "${PLUGIN_SLUG}")

# ---- summary --------------------------------------------------------------
ZIP_SIZE_BYTES="$(wc -c < "${OUTPUT}" | tr -d ' ')"
ZIP_SIZE_HUMAN="$(awk -v b="${ZIP_SIZE_BYTES}" 'BEGIN { split("B KB MB GB", u); n=1; while (b>=1024 && n<4) { b/=1024; n++ } printf "%.1f %s", b, u[n] }')"
FILE_COUNT="$(unzip -Z1 "${OUTPUT}" | wc -l | tr -d ' ')"

echo ""
echo "Built ${PLUGIN_SLUG} v${PLUGIN_VERSION}"
echo "  Zip:    ${OUTPUT}"
echo "  Size:   ${ZIP_SIZE_HUMAN} (${ZIP_SIZE_BYTES} bytes)"
echo "  Files:  ${FILE_COUNT}"
echo ""
echo "Top-level entries in the archive:"
unzip -Z1 "${OUTPUT}" | awk -F/ 'NF<=2 {print "  " $0}' | sort -u
