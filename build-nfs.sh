#!/bin/bash
# Build script for the Custom NFS Shares plugin (custom.nfs.shares).
# Adapted from build.sh (custom.smb.shares). Uses separate build-nfs/ and
# archive-nfs/ directories so SMB and NFS builds never collide in this repo.
# Do NOT modify build.sh — this is the NFS-specific sibling.
#
# Usage:
#   ./build-nfs.sh              Full build with tests (unit + integration)
#   ./build-nfs.sh --fast       Fast build (skip tests)
#   ./build-nfs.sh a            Force version suffix 'a'
set -e

if [ "$1" = "-h" ] || [ "$1" = "--help" ]; then
    echo "Usage: $0 [OPTIONS] [VERSION_SUFFIX]"
    echo ""
    echo "Options:"
    echo "  -f, --fast           Fast build (skip tests)"
    echo "  -h, --help           Show this help"
    echo ""
    echo "Arguments:"
    echo "  VERSION_SUFFIX       Optional suffix to append to version (e.g., 'a', 'b')"
    exit 0
fi

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Configuration (NFS-specific; distinct dirs from the SMB build)
PLUGIN_NAME="custom.nfs.shares"
BASE_VERSION=$(date +"%Y.%m.%d")
BUILD_DIR="build-nfs"
ARCHIVE_DIR="archive-nfs"

# Parse arguments
FAST_BUILD=false
VERSION_SUFFIX=""
for arg in "$@"; do
    case $arg in
        -f|--fast) FAST_BUILD=true ;;
        -h|--help) ;;
        *) VERSION_SUFFIX="$arg" ;;
    esac
done

# Auto-increment point release if version already exists
VERSION="${BASE_VERSION}${VERSION_SUFFIX}"
if [ -z "$VERSION_SUFFIX" ]; then
    EXISTING=$(ls -1 ${ARCHIVE_DIR}/${PLUGIN_NAME}-${BASE_VERSION}*.txz 2>/dev/null | wc -l | tr -d ' ')
    if [ "$EXISTING" -gt 0 ]; then
        HIGHEST=$(ls -1 ${ARCHIVE_DIR}/${PLUGIN_NAME}-${BASE_VERSION}*.txz 2>/dev/null | \
            sed -E "s/.*${BASE_VERSION}([a-z])?-.*/\1/" | sort | tail -1)
        if [ -z "$HIGHEST" ]; then
            VERSION="${BASE_VERSION}a"
        else
            NEXT=$(echo "$HIGHEST" | tr 'a-y' 'b-z')
            VERSION="${BASE_VERSION}${NEXT}"
        fi
        echo "Note: Version ${BASE_VERSION} exists, auto-incrementing to ${VERSION}"
    fi
fi

log_info()    { echo -e "${BLUE}i${NC} $1"; }
log_success() { echo -e "${GREEN}+${NC} $1"; }
log_warning() { echo -e "${YELLOW}!${NC} $1"; }
log_error()   { echo -e "${RED}x${NC} $1"; }

main() {
    echo "===================================================="
    echo " Custom NFS Shares Plugin - Build"
    echo " Version: $VERSION"
    [ "$FAST_BUILD" = true ] && echo " Mode: FAST (skip tests)"
    echo "===================================================="
    echo ""

    if ! command -v composer &> /dev/null; then
        log_error "Composer not found. Please install composer."
        exit 1
    fi

    USE_MAKEPKG=false
    if command -v makepkg &> /dev/null; then
        USE_MAKEPKG=true
        log_success "makepkg found (Slackware packaging)"
    else
        log_warning "makepkg not found - using tar fallback"
    fi

    if [ ! -d "vendor" ]; then
        log_info "Installing dependencies..."
        composer install --no-interaction --prefer-dist
    fi

    if [ "$FAST_BUILD" = false ]; then
        log_info "Auto-fixing linting issues..."
        composer lint:fix > /dev/null 2>&1 || true
        composer lint || { log_error "Lint failed"; exit 1; }
        vendor/bin/phpstan analyse -c phpstan-nfs.neon --no-progress || { log_error "Static analysis failed"; exit 1; }
        composer test:unit || { log_error "Unit tests failed"; exit 1; }
        composer test:integration || { log_error "Integration tests failed"; exit 1; }
    else
        log_warning "Quality gates + tests skipped (fast build mode)"
    fi

    echo ""
    log_info "Building plugin package..."

    rm -rf ${BUILD_DIR} ${ARCHIVE_DIR}
    mkdir -p ${BUILD_DIR}/usr/local/emhttp/plugins ${ARCHIVE_DIR}

    # Copy ONLY the NFS plugin runtime tree (never vendor/, never SMB files)
    cp -R source/usr/local/emhttp/plugins/${PLUGIN_NAME} \
        ${BUILD_DIR}/usr/local/emhttp/plugins/${PLUGIN_NAME}

    # Strip dev/mac artifacts; prevent AppleDouble re-embedding during tar
    find ${BUILD_DIR} -name ".DS_Store" -delete 2>/dev/null || true
    find ${BUILD_DIR} -name "*.bak" -delete 2>/dev/null || true
    find ${BUILD_DIR} -name "._*" -delete 2>/dev/null || true
    find ${BUILD_DIR} -name ".gitkeep" -delete 2>/dev/null || true
    export COPYFILE_DISABLE=1

    # Permissions per Slackware convention
    find ${BUILD_DIR} -type d -exec chmod 755 {} \;
    find ${BUILD_DIR} -type f -exec chmod 644 {} \;
    find ${BUILD_DIR} -name "*.sh" -exec chmod 755 {} \;
    # Event scripts must be executable
    if [ -d "${BUILD_DIR}/usr/local/emhttp/plugins/${PLUGIN_NAME}/event" ]; then
        find ${BUILD_DIR}/usr/local/emhttp/plugins/${PLUGIN_NAME}/event -type f -exec chmod 755 {} \;
    fi

    echo "${VERSION}" > ${BUILD_DIR}/usr/local/emhttp/plugins/${PLUGIN_NAME}/VERSION

    PACKAGE_NAME="${PLUGIN_NAME}-${VERSION}-x86_64-1.txz"
    cd ${BUILD_DIR}
    if [ "$USE_MAKEPKG" = true ]; then
        makepkg -l y -c y ../${ARCHIVE_DIR}/${PACKAGE_NAME}
    else
        tar --owner=root --group=root -cJf ../${ARCHIVE_DIR}/${PACKAGE_NAME} *
    fi
    cd ..

    cd ${ARCHIVE_DIR}
    if command -v md5sum &> /dev/null; then
        md5sum ${PACKAGE_NAME} | awk '{print $1}' > ${PLUGIN_NAME}-${VERSION}.md5
    else
        md5 -q ${PACKAGE_NAME} > ${PLUGIN_NAME}-${VERSION}.md5
    fi
    MD5=$(cat ${PLUGIN_NAME}-${VERSION}.md5)
    cd ..

    log_success "Package built successfully"
    echo ""
    echo "Package: ${ARCHIVE_DIR}/${PACKAGE_NAME}"
    echo "MD5:     ${MD5}"
    echo "Size:    $(du -h ${ARCHIVE_DIR}/${PACKAGE_NAME} | cut -f1)"
    echo ""
    echo "Update custom.nfs.shares.plg with:"
    echo "  <!ENTITY version   \"${VERSION}\">"
    echo "  <!ENTITY md5       \"${MD5}\">"
    echo ""
}

main "$@"
