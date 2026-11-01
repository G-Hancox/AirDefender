#!/bin/bash
# Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
# Licensed under the GNU GPL v2; see LICENSE.
set -e
# Air Defender install script. FPP runs this (as root) after a fresh install.
# (Updates run scripts/fpp_upgrade.sh instead.)
#
# Nothing to set up here. Straight after this, FPP asks fppd to load the
# plugin (every FPP Air Defender lists for, 10.0 and later, does), which
# registers the Air Defender commands and starts the detection loop through
# callbacks.sh, so it runs under fppd and stops with it. No restart needed.

exit 0
