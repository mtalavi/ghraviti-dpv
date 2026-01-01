#!/bin/bash
# =============================================================================
# release.sh - Automated Release Script for DPV Hub
# =============================================================================
# Usage:
#   ./scripts/release.sh patch   # 1.0.0 -> 1.0.1 (bug fixes)
#   ./scripts/release.sh minor   # 1.0.0 -> 1.1.0 (new features)
#   ./scripts/release.sh major   # 1.0.0 -> 2.0.0 (breaking changes)
# =============================================================================

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
VERSION_FILE="$PROJECT_ROOT/VERSION"
CHANGELOG_FILE="$PROJECT_ROOT/CHANGELOG.md"

# Check if VERSION file exists
if [ ! -f "$VERSION_FILE" ]; then
    echo -e "${RED}ERROR: VERSION file not found at $VERSION_FILE${NC}"
    exit 1
fi

# Read current version
CURRENT_VERSION=$(cat "$VERSION_FILE" | tr -d '\n\r')
echo -e "${BLUE}Current version: ${YELLOW}$CURRENT_VERSION${NC}"

# Parse version components
IFS='.' read -r MAJOR MINOR PATCH <<< "$CURRENT_VERSION"

# Determine new version based on argument
BUMP_TYPE=${1:-patch}

case $BUMP_TYPE in
    major)
        MAJOR=$((MAJOR + 1))
        MINOR=0
        PATCH=0
        ;;
    minor)
        MINOR=$((MINOR + 1))
        PATCH=0
        ;;
    patch)
        PATCH=$((PATCH + 1))
        ;;
    *)
        echo -e "${RED}ERROR: Invalid bump type '$BUMP_TYPE'. Use: major, minor, or patch${NC}"
        exit 1
        ;;
esac

NEW_VERSION="$MAJOR.$MINOR.$PATCH"
echo -e "${GREEN}New version: ${YELLOW}$NEW_VERSION${NC}"

# Confirm with user
read -p "Create release v$NEW_VERSION? (y/n) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo -e "${YELLOW}Aborted.${NC}"
    exit 0
fi

# Update VERSION file
echo "$NEW_VERSION" > "$VERSION_FILE"
echo -e "${GREEN}✓ Updated VERSION file${NC}"

# Get today's date
TODAY=$(date +%Y-%m-%d)

# Update CHANGELOG.md - add new version header under [Unreleased]
if [ -f "$CHANGELOG_FILE" ]; then
    # Create temp file with new version section
    sed -i "s/## \[Unreleased\]/## [Unreleased]\n\n### Added\n- Nothing yet\n\n### Changed\n- Nothing yet\n\n### Fixed\n- Nothing yet\n\n---\n\n## [$NEW_VERSION] - $TODAY/" "$CHANGELOG_FILE"
    echo -e "${GREEN}✓ Updated CHANGELOG.md${NC}"
fi

# Git operations
echo -e "${BLUE}Committing changes...${NC}"
git add -A
git commit -m "Release v$NEW_VERSION"

echo -e "${BLUE}Creating tag v$NEW_VERSION...${NC}"
git tag -a "v$NEW_VERSION" -m "Release v$NEW_VERSION"

echo -e "${BLUE}Pushing to remote...${NC}"
git push origin main
git push origin "v$NEW_VERSION"

echo ""
echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  ✓ Release v$NEW_VERSION created successfully!${NC}"
echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
echo ""
echo -e "View release at: ${BLUE}https://github.com/YOUR_USERNAME/ghraviti-dpv/releases/tag/v$NEW_VERSION${NC}"
