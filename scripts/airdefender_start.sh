#!/bin/bash
# Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
# Licensed under the GNU GPL v2; see LICENSE.
# Air Defender: start the detection loop. callbacks.sh runs this when fppd
# starts or loads the plugin (after an install or update).
# fppd waits for it, as root, so the loop is detached into the
# background and run as the fpp user (the dashboard, which runs as fpp,
# shares its files). It runs under a small watchdog that starts it again
# if it ever stops on its own.

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SUPERVISOR="${PLUGIN_DIR}/scripts/airdefender_supervise.sh"
LOOP="${PLUGIN_DIR}/scripts/airdefender_loop.php"

# FPP's folders, the way FPP provides them (a relocated media folder works).
: "${FPPDIR:=/opt/fpp}"
. "${FPPDIR}/scripts/common" >/dev/null 2>&1 || true
: "${MEDIADIR:=/home/fpp/media}"
: "${LOGDIR:=${MEDIADIR}/logs}"
LOG="${LOGDIR}/plugin-AirDefender.log"

# Stop copies left over from before: the watchdog first, so it cannot restart the loop.
pkill -f "${SUPERVISOR}" 2>/dev/null
pkill -f "${LOOP}" 2>/dev/null
# Give them up to 2 s to go, without waiting when there was nothing to stop.
for _ in $(seq 1 20); do
    pgrep -f "${LOOP}" >/dev/null 2>&1 || break
    sleep 0.1
done
# A loop started by a process that blocks SIGTERM (seen under fppd in FPP's Docker
# image) can't be stopped politely, and would hold the lock so the new loop can't
# run. After 2 s, stop it for certain.
pkill -KILL -f "${SUPERVISOR}" 2>/dev/null
pkill -KILL -f "${LOOP}" 2>/dev/null

if [ "$(id -u)" = "0" ] && id fpp >/dev/null 2>&1; then
    RUN_AS="runuser -u fpp --"
    command -v runuser >/dev/null 2>&1 || RUN_AS="sudo -u fpp"
else
    RUN_AS=""
fi

# The log must belong to the user the loop runs as. Run as root (install,
# update, boot), the redirect below would otherwise create it owned by root,
# the loop could not write to it, and the watchdog could not start PHP: on a
# fresh install the loop never ran until a reboot chowned the media folder.
if [ -n "${RUN_AS}" ]; then
    touch "${LOG}" 2>/dev/null
    chown fpp:fpp "${LOG}" 2>/dev/null
fi

echo "Starting Air Defender detection loop"
# Started with only stdin, stdout and stderr open: whatever ran this (FPP's
# web server during an install or update, say) may have sockets open that
# the loop must not hold on to for the rest of its life.
(
    for fd in /proc/self/fd/*; do
        fd=${fd##*/}
        [ "${fd}" -gt 2 ] 2>/dev/null && eval "exec ${fd}>&-" 2>/dev/null
    done
    exec nohup setsid ${RUN_AS} env FPPDIR="${FPPDIR}" MEDIADIR="${MEDIADIR}" LOGDIR="${LOGDIR}" \
        bash "${SUPERVISOR}" >>"${LOG}" 2>/dev/null </dev/null
) &
