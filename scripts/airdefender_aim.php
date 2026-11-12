<?php
/*
 * Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
 * Licensed under the GNU GPL v2; see LICENSE.
 *
 * DeadZones aiming: steer one moving head from the DeadZones tab, to mark the
 * edges of its zones. While aiming, that head's pan, tilt, shutter (open) and
 * dimmer are held with FPP overlays on their channels, the same way plane
 * safety holds a shutter closed (scripts/airdefender_controller.php). Every
 * other head, and every other channel, is left alone.
 *
 * Rules:
 *   - Only while no show is playing (the page is refused, and the loop ends
 *     aiming if a show starts).
 *   - Plane safety wins: while it's on, the shutter isn't opened (and the
 *     safety overlay closes it).
 *   - It ends by itself: the page ends it when you leave or stop pressing for
 *     two minutes, and the loop ends it if the page stops checking in.
 *   - The DeadZones check leaves the aimed head alone while aiming, so the
 *     beam stays on while you trace a zone's edge.
 *
 * What's being aimed lives in memory (/dev/shm/AirDefender/aim.json, see
 * airdefender_statefile.php), never on the SD card.
 */

require_once(__DIR__ . '/airdefender_statefile.php');
require_once(__DIR__ . '/airdefender_controller.php');
require_once(__DIR__ . '/airdefender_deadzones.php');

if (!function_exists('airdefender_aim_file')) {

    define('AIRDEFENDER_AIM_EXPIRE_SEC', 30);   // the page checks in every 5 s; after this long without it, the loop ends aiming

    function airdefender_aim_file(): string
    {
        $dir = AIRDEFENDER_STATE_MEM_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755);
        }
        return (is_dir($dir) && is_writable($dir) ? $dir : airdefender_deadzones_dir()) . '/aim.json';
    }

    function airdefender_aim_read(): ?array
    {
        $a = json_decode((string) @file_get_contents(airdefender_aim_file()), true);
        return is_array($a) && !empty($a['head']) ? $a : null;
    }

    function airdefender_aim_write(?array $a): void
    {
        $file = airdefender_aim_file();
        if ($a === null) {
            @unlink($file);
            return;
        }
        if (@file_put_contents($file . '.tmp', json_encode($a)) !== false) {
            @rename($file . '.tmp', $file);
        }
    }

    /* A head's channels for aiming, from the file the DeadZones check uses (pan and tilt must be set). */
    function airdefender_aim_head(int $id): ?array
    {
        $dz = json_decode((string) @file_get_contents(airdefender_deadzones_file()), true);
        foreach ((is_array($dz['heads'] ?? null) ? $dz['heads'] : []) as $h) {
            if ((int) ($h['id'] ?? 0) === $id && !empty($h['pan']) && !empty($h['tilt']) && !empty($h['shutter'])) {
                return $h;
            }
        }
        return null;
    }

    /* Whether plane safety (or a test) is holding the shutters closed right now. */
    function airdefender_aim_safety_on(): bool
    {
        $s = airdefender_state_read();
        return !empty($s['safetyMode']);
    }

    /* Whether FPP is playing anything (a show, a playlist or a single sequence); null if fppd didn't answer. */
    function airdefender_aim_playing(): ?bool
    {
        $raw = @file_get_contents(AirDefenderOutputs::FPPD_API . '/fppd/status', false,
            stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]));
        $st = json_decode((string) $raw, true);
        if (!is_array($st)) {
            return null;
        }
        $sched = strtolower((string) ($st['scheduler']['status'] ?? 'idle'));
        $name = strtolower((string) ($st['status_name'] ?? 'idle'));
        return $sched === 'playing' || $sched === 'manual'
            || in_array($name, ['playing', 'paused', 'stopping gracefully', 'stopping gracefully after loop'], true);
    }

    /*
     * The DeadZones page's requests. $req: action, head, pan, tilt, beam, level.
     * Returns [http status, answer].
     */
    function airdefender_aim_request(array $req): array
    {
        $action = (string) ($req['action'] ?? '');
        $a = airdefender_aim_read();
        if ($action === 'aim_end') {
            airdefender_aim_end();
            return [200, ['aiming' => null]];
        }
        if ($action === 'aim_start') {
            $id = (int) ($req['head'] ?? 0);
            $h = airdefender_aim_head($id);
            if ($h === null) {
                return [400, ['error' => "That head can't be aimed yet: turn DeadZones on and enter its pan and tilt channels, then wait a second for Air Defender to pick them up."]];
            }
            $playing = airdefender_aim_playing();
            if ($playing === null) {
                return [503, ['error' => "Couldn't ask FPP whether a show is playing. Is fppd running?"]];
            }
            if ($playing) {
                return [409, ['error' => "A show is playing. Aiming takes over a head, so it's only allowed while nothing is playing."]];
            }
            if ($a !== null && (int) $a['head'] !== $id) {
                airdefender_aim_end();   // one head at a time
            }
            $a = [
                'head' => $id, 'name' => (string) ($h['name'] ?? "MH{$id}"),
                // Start from where it was last aimed this session, else the middle.
                'pan' => $a !== null && (int) $a['head'] === $id ? (int) $a['pan'] : 128,
                'tilt' => $a !== null && (int) $a['head'] === $id ? (int) $a['tilt'] : 128,
                'beam' => false, 'level' => 64,
            ];
        } elseif ($a === null) {
            return [409, ['error' => 'Aiming has ended (it ends after two minutes without a press, or when a show starts). Press Aim to start again.']];
        } else {
            $h = airdefender_aim_head((int) $a['head']);
            if ($h === null) {
                airdefender_aim_end();
                return [409, ['error' => "That head's DeadZones settings changed, so aiming ended."]];
            }
        }
        if ($action === 'aim_set') {
            foreach (['pan', 'tilt', 'level'] as $k) {
                if (isset($req[$k]) && is_numeric($req[$k])) {
                    $a[$k] = max(0, min(255, (int) $req[$k]));
                }
            }
            if (isset($req['beam'])) {
                $a['beam'] = in_array((string) $req['beam'], ['1', 'true', 'on'], true);
            }
        } elseif ($action !== 'aim_start' && $action !== 'aim_keep') {
            return [400, ['error' => 'Unknown action']];
        }
        $a['seen'] = time();
        $a['run'] = AirDefenderOutputs::fppdRunId();
        $a['closed'] = (int) ($h['closed'] ?? 0);
        $a['channels'] = ['pan' => (int) $h['pan'], 'tilt' => (int) $h['tilt'], 'dimmer' => (int) ($h['dimmer'] ?? 0), 'shutter' => (int) $h['shutter']];
        airdefender_aim_write($a);
        $err = airdefender_aim_apply($a, $h);
        if ($err !== '') {
            return [500, ['error' => "FPP refused: {$err}", 'aiming' => $a]];
        }
        return [200, ['aiming' => $a, 'hasDimmer' => !empty($h['dimmer']), 'safety' => airdefender_aim_safety_on()]];
    }

    class AirDefenderAimOutputs extends AirDefenderOutputs
    {
        public function set(int $channel, int $value): bool { return $this->hold($channel, max(0, min(255, $value))); }
        public function clear(int $channel): bool { return $this->release($channel); }
    }

    /*
     * Put the head where $a says: pan, tilt, dimmer (beam on: its level; off: 0),
     * and the shutter open unless plane safety is on. Returns '' or an error.
     */
    function airdefender_aim_apply(array $a, array $h): string
    {
        $out = new AirDefenderAimOutputs();
        $ok = $out->set((int) $h['pan'], (int) $a['pan']) && $out->set((int) $h['tilt'], (int) $a['tilt']);
        if (!empty($h['dimmer'])) {
            $ok = $ok && $out->set((int) $h['dimmer'], !empty($a['beam']) ? (int) $a['level'] : 0);
        }
        if (!airdefender_aim_safety_on()) {
            $ok = $ok && $out->set((int) $h['shutter'], (int) $h['open']);
        }
        return $ok ? '' : $out->lastError;
    }

    /*
     * Give the head back to the show: remove the overlays aiming set. Only in
     * the fppd run they were set in (an fppd restart has already dropped them,
     * and on FPP 10.0-10.2 deleting one fppd doesn't have would hold it at 255).
     * The shutter is left to plane safety while that's on.
     */
    function airdefender_aim_end(string $why = ''): void
    {
        $a = airdefender_aim_read();
        airdefender_aim_write(null);
        if ($a === null) {
            return;
        }
        $channels = is_array($a['channels'] ?? null) ? $a['channels'] : [];
        if (($a['run'] ?? null) === AirDefenderOutputs::fppdRunId()) {
            $out = new AirDefenderAimOutputs();
            $safe = airdefender_aim_safety_on();
            foreach ($channels as $role => $ch) {
                if (!$ch || ($role === 'shutter' && $safe)) {
                    continue;
                }
                // Set, then delete: plane safety may already have removed the shutter's overlay, and on
                // FPP 10.0-10.2 deleting one fppd doesn't have holds the channel at 255 (shutter open).
                // Setting it first (the shutter to its closed value) means the delete always finds one.
                $out->set((int) $ch, $role === 'shutter' ? (int) ($a['closed'] ?? 0) : 0);
                $out->clear((int) $ch);
            }
        }
        if ($why !== '' && function_exists('airdefender_log')) {
            airdefender_log("Aiming " . ($a['name'] ?? 'a head') . " ended: {$why}.");
        }
    }

    /* Called by the detection loop every second: end aiming that the page has stopped checking in for, or if a show starts. */
    function airdefender_aim_tick(?array $show, int $now): void
    {
        $a = airdefender_aim_read();
        if ($a === null) {
            return;
        }
        if ($show !== null && !empty($show['playing'])) {
            airdefender_aim_end('a show started');
        } elseif ($now - (int) ($a['seen'] ?? 0) > AIRDEFENDER_AIM_EXPIRE_SEC) {
            airdefender_aim_end('the DeadZones page stopped checking in');
        }
    }
}
