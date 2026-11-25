#!/bin/bash
# Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
# Licensed under the GNU GPL v2; see LICENSE.
FPP_DIR="${FPPDIR:-/opt/fpp}"   # read before set -u: FPPDIR is unset on some FPP paths
set -eu
# Air Defender install script. FPP runs this (as root) after a fresh install.
# (Updates run scripts/fpp_upgrade.sh instead.)
#
# Straight after this, FPP asks fppd to load the plugin (every FPP Air Defender
# lists for, 10.0 and later, does), which registers the Air Defender commands
# and starts the detection loop through callbacks.sh, so it runs under fppd and
# stops with it. No restart needed.

# Build the compiled part (DeadZones); never fails the install (see the script).
"$(dirname "$0")/build_deadzones.sh"

# Ignore areas' map: let FPP's pages show OpenStreetMap's map pictures (FPP only allows
# images from itself). The browser loads them only while the Ignore areas editor is open.
"${FPP_DIR}/scripts/ManageApacheContentPolicy.sh" add img-src https://tile.openstreetmap.org >/dev/null 2>&1 || true

exit 0
