#!/bin/bash

# Configuration
# -----------------------------------------------------------------------------
# IMPORTANT: "free" and "pro" are DIVERGENT products with separate release
# channels. Do not cross-wire them.
#   FREE = this plugin, tablecrafter-wp-data-tables  ->  WordPress.org SVN (here)
#   PRO  = gravity-tables (data-tables-for-gravity-forms)  ->  Freemius ONLY
# The PRO plugin must NEVER be synced to this (free) wp.org SVN repo. The guards
# below refuse to run if GIT_PATH is not the free plugin or SVN_PATH is not the
# free plugin's wp.org repo.
# -----------------------------------------------------------------------------
PLUGIN_SLUG="tablecrafter-wp-data-tables"
# Canonical free-plugin git checkout (the source of the release to publish).
# Was a stale clone under ~/websites/tablecrafter/...; the live repo is here:
GIT_PATH="/Users/isupercoder/websites/wp-data-tables"
# Local working copy of the free plugin's wp.org SVN repo (svn co
# https://plugins.svn.wordpress.org/tablecrafter-wp-data-tables/ <path>):
SVN_PATH="/Users/isupercoder/websites/tablecrafter/app/public/wp-content/plugins/tablecrafter-svn"
IGNORE_FILE=".svnignore"

# Color codes
GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${GREEN}Starting Sync from Git to SVN...${NC}"

# Check paths
if [ ! -d "$SVN_PATH" ]; then
    echo -e "${RED}Error: SVN directory not found at $SVN_PATH${NC}"
    exit 1
fi

if [ ! -d "$GIT_PATH" ]; then
    echo -e "${RED}Error: Git directory not found at $GIT_PATH${NC}"
    exit 1
fi

# Guard 1 (anti cross-wire): GIT_PATH must be the FREE plugin. The PRO plugin
# (gravity-tables, text domain "gravity-tables") ships via Freemius, never here.
if ! grep -rql "Text Domain: ${PLUGIN_SLUG}" "$GIT_PATH"/*.php 2>/dev/null; then
    echo -e "${RED}Error: GIT_PATH ($GIT_PATH) is not the '${PLUGIN_SLUG}' plugin.${NC}"
    echo -e "${RED}Refusing to sync — the PRO plugin must never be published to this free wp.org repo.${NC}"
    exit 1
fi

# Guard 2 (anti cross-wire): SVN_PATH must be the FREE plugin's wp.org repo.
SVN_URL="$(svn info "$SVN_PATH" 2>/dev/null | awk -F': ' '/^URL/{print $2; exit}')"
if [[ "$SVN_URL" != *"/${PLUGIN_SLUG}" ]]; then
    echo -e "${RED}Error: SVN_PATH is not the '${PLUGIN_SLUG}' wp.org repo (URL: ${SVN_URL:-unknown}).${NC}"
    echo -e "${RED}Refusing to sync to avoid cross-wiring free/pro.${NC}"
    exit 1
fi

# Ensure SVN is up to date
echo "Updating SVN..."
cd "$SVN_PATH"
svn update

# Sync contents to trunk
echo "Syncing files..."
rsync -rc --exclude-from="$GIT_PATH/$IGNORE_FILE" "$GIT_PATH/" "$SVN_PATH/trunk/" --delete --delete-excluded

# Check status
cd "$SVN_PATH/trunk"
echo "SVN Status:"
svn status

# Prompt for commit
read -p "Do you want to commit these changes to SVN? (y/n) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    read -p "Enter commit message: " COMMIT_MSG
    svn commit -m "$COMMIT_MSG"
    echo -e "${GREEN}Successfully committed to SVN!${NC}"
    
    # Tagging
    read -p "Do you want to create a new tag? (y/n) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        read -p "Enter version number (e.g., 2.4.0): " VERSION
        svn copy "$SVN_PATH/trunk" "$SVN_PATH/tags/$VERSION"
        svn commit -m "Tagging version $VERSION"
        echo -e "${GREEN}Successfully tagged version $VERSION!${NC}"
    fi
else
    echo "Aborted."
fi
