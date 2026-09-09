#!/bin/bash
# Creates the Payflex OC4 extension package (payflex.ocmod.zip)
#
# The zip is built from extension/payflex/, whose contents map directly to
# what OC4's Extension Installer expects at the root of the archive:
#
#   install.json
#   admin/
#   catalog/
#
# Output: Payflex_output/payflex.ocmod.zip
#
# The filename is NOT versioned. OC4 derives the extension code from the
# archive's filename (installer.php: basename($filename, '.ocmod.zip')) and
# extracts into extension/{code}/, so anything other than "payflex.ocmod.zip"
# installs to the wrong directory and every route 404s.

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SOURCE_DIR="$SCRIPT_DIR/../extension/payflex"
OUTPUT_DIR="$SCRIPT_DIR"

# Colours
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

if [ ! -d "$SOURCE_DIR" ]; then
    echo -e "${RED}Error: extension/payflex not found at $SOURCE_DIR${NC}"
    exit 1
fi

if [ ! -f "$SOURCE_DIR/install.json" ]; then
    echo -e "${RED}Error: install.json not found in extension/payflex${NC}"
    exit 1
fi

# Read version from install.json (display only — see the filename note above).
# Plain POSIX sed rather than grep -P: the previous lookbehind pattern needs a
# bounded-length assertion that ugrep rejects outright, and the "|| echo" guard
# turned that failure into a silent fallback to a hardcoded version.
version=$(sed -n 's/.*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$SOURCE_DIR/install.json" | head -n1)

if [ -z "$version" ]; then
    echo -e "${RED}Error: could not read \"version\" from install.json${NC}"
    exit 1
fi

output="$OUTPUT_DIR/payflex.ocmod.zip"

echo -e "${GREEN}Building Payflex OC4 extension...${NC}"
echo -e "  Version : ${YELLOW}$version${NC}"
echo -e "  Source  : ${YELLOW}$SOURCE_DIR${NC}"
echo -e "  Output  : ${YELLOW}$output${NC}"

# Remove any previous build with the same name
[ -f "$output" ] && rm "$output"

# Zip the contents of extension/payflex/ (not the folder itself)
cd "$SOURCE_DIR"
zip -rq "$output" .

echo -e "${GREEN}Package contents:${NC}"
unzip -l "$output"

echo -e "\n${GREEN}✓ Done: $(du -h "$output" | cut -f1)${NC}"
