#!/bin/bash
# Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
# Licensed under the GNU GPL v2; see LICENSE.
# Air Defender: stop the detection loop. callbacks.sh runs this when fppd
# stops or unloads the plugin (to uninstall or update it). Its shutter
# overlays live in fppd: they go when fppd stops, and an uninstall releases
# them (fpp_uninstall.sh); after an update the new loop takes them over.

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
echo "Stopping Air Defender detection loop"
# A start still under way first (an unload straight after a load), then the
# watchdog, so neither can start the loop again.
pkill -f "${PLUGIN_DIR}/scripts/airdefender_start.sh" 2>/dev/null
pkill -f "${PLUGIN_DIR}/scripts/airdefender_supervise.sh" 2>/dev/null
pkill -f "${PLUGIN_DIR}/scripts/airdefender_loop.php" 2>/dev/null
# A loop that ignores SIGTERM (started by a process that blocks it, as seen under
# fppd in FPP's Docker image) would keep running old code after an update: give
# it up to 2 s, then stop it for certain.
for _ in $(seq 1 20); do
    pgrep -f "${PLUGIN_DIR}/scripts/airdefender_loop.php" >/dev/null 2>&1 || break
    sleep 0.1
done
pkill -KILL -f "${PLUGIN_DIR}/scripts/airdefender_supervise.sh" 2>/dev/null
pkill -KILL -f "${PLUGIN_DIR}/scripts/airdefender_loop.php" 2>/dev/null
exit 0
