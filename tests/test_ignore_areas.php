<?php
/*
 * Air Defender - Copyright (C) 2026 G Hancox. Licensed under the GNU GPL v2; see LICENSE.
 *
 * Ignore areas: which aircraft are ignored, the log lines, and the guards on saving and deleting. No FPP needed:
 *   php tests/test_ignore_areas.php [scripts folder]
 * It copies scripts/ into a throwaway plugin folder (with its own plugindata).
 *   1. Ignored only inside the outline, at or under the ceiling, slower than the speed limit, with a speed sent.
 *   2. Log lines only when an aircraft starts or stops being ignored.
 *   3. Saving must say what it replaces and deleting must name the area; 8 saves at once all kept (up to 5).
 */
$src = $argv[1] ?? dirname(__DIR__) . '/scripts';
$root = sys_get_temp_dir() . '/adignore_' . getmypid();
@mkdir("$root/plugin/scripts", 0777, true); @mkdir("$root/media/config", 0777, true); @mkdir("$root/media/logs", 0777, true);
foreach (glob("$src/*.php") as $f) { copy($f, "$root/plugin/scripts/" . basename($f)); }
putenv("MEDIADIR=$root/media"); putenv("LOGDIR=$root/media/logs");
file_put_contents("$root/media/config/plugin.AirDefender", "");
require "$root/plugin/scripts/airdefender_ignore.php";
$failures = 0;
function check($what, $ok) { global $failures; if (!$ok) $failures++; echo ($ok ? "ok:   " : "FAIL: ") . "$what\n"; }

// A runway-shaped area about 0.5 NM long, 500 ft (ground) at its ramp.
$area = ['name' => 'Regional', 'points' => [[36.000, -86.000], [36.000, -85.990], [36.002, -85.990], [36.002, -86.000]],
    'groundFt' => 500, 'ceilingFt' => 100, 'maxKt' => 40];
[$checked, $why] = airdefender_ignore_check_area($area);
check('a sensible area is accepted', $checked !== null);
$plane = function ($id, $lat, $lon, $reportedFt, $gs, $onGround = false) {
    $elev = 551;   // the show's elevation: altFt arrives already above the show
    return ['id' => $id, 'lat' => $lat, 'lon' => $lon, 'altFt' => $reportedFt === null ? null : $reportedFt - $elev,
        'onGround' => $onGround, 'info' => $gs === null ? [] : ['gs' => $gs]];
};
$planes = [
    'parked' => $plane('PARKED', 36.001, -85.995, 540, 0),
    'taxi' => $plane('TAXI', 36.001, -85.995, 560, 20),
    'roll' => $plane('ROLL', 36.001, -85.995, 520, 70),           // takeoff roll: too fast
    'hover' => $plane('HOVER', 36.001, -85.995, 750, 0),          // hovering 250 ft up: above the ceiling
    'edge' => $plane('EDGE', 36.001, -85.995, 600, 39),           // exactly 100 ft up, 39 kt: ignored
    'outside' => $plane('OUTSIDE', 36.004, -85.995, 540, 0),      // parked, but just outside the outline
    'nospeed' => $plane('NOSPEED', 36.001, -85.995, 540, null),   // no speed sent: never ignored
    'noalt' => $plane('NOALT', 36.001, -85.995, null, 0),
];
$out = airdefender_ignore_apply($planes, 551, [$checked]);
$ign = function ($k) use ($out) { return $out[$k]['ignored'] ?? null; };
check('parked inside: ignored', $ign('parked') === 'Regional');
check('taxiing at 20 kt: ignored', $ign('taxi') === 'Regional');
check('takeoff roll at 70 kt: counts', $ign('roll') === null);
check('hovering 250 ft above the ground: counts', $ign('hover') === null);
check('at the ceiling and just under the speed limit: ignored', $ign('edge') === 'Regional');
check('parked just outside the outline: counts', $ign('outside') === null);
check('no speed sent: counts', $ign('nospeed') === null);
check('no height sent: counts', $ign('noalt') === null);
check('no areas: nothing ignored', !isset(airdefender_ignore_apply($planes, 551, [])['parked']['ignored']));
check('concave outline: the notch is outside', !airdefender_point_in_area(1.5, 1.5,
    [[0, 0], [0, 3], [3, 3], [3, 2], [1, 2], [1, 1], [3, 1], [3, 0]]) && airdefender_point_in_area(0.5, 1.5,
    [[0, 0], [0, 3], [3, 3], [3, 2], [1, 2], [1, 1], [3, 1], [3, 0]]));

// Refused areas.
foreach ([
    'no name' => ['name' => ''] + $area,
    'two corners' => ['points' => [[36, -86], [36.001, -86]]] + $area,
    'ceiling 500 ft' => ['ceilingFt' => 500] + $area,
    'speed 100 kt' => ['maxKt' => 100] + $area,
    'corner not a position' => ['points' => [[36, -86], [36.001, -86], ['x', 1]]] + $area,
    '30 NM across' => ['points' => [[36, -86], [36.5, -86], [36.5, -85.5]]] + $area,
] as $what => $bad) {
    check("refused: $what", airdefender_ignore_check_area($bad)[0] === null);
}

// 2. Log lines.
$lines = [];
$log = function ($m) use (&$lines) { $lines[] = $m; };
$was = airdefender_ignore_log_changes([], $out, $log);
$n1 = count($lines);
$was = airdefender_ignore_log_changes($was, $out, $log);
check("first check logs each ignored aircraft once ($n1 lines)", $n1 === 3);
check('the next check with nothing changed logs nothing', count($lines) === $n1);
$moved = $planes;
$moved['parked'] = $plane('PARKED', 36.001, -85.995, 540, 60);   // started its takeoff roll
$was = airdefender_ignore_log_changes($was, airdefender_ignore_apply($moved, 551, [$checked]), $log);
check('starting to move logs "No longer ignoring"', strpos(end($lines), 'No longer ignoring PARKED') === 0);

// 3. Saving and deleting.
$save = function ($a, $replaces = '') { return airdefender_ignore_request(['action' => 'ignore_save', 'area' => json_encode($a), 'replaces' => $replaces], 'test'); };
check('save a new area', $save($area)[0] === 200);
check('a second "new" area with the same name is refused', $save($area)[0] === 409);
check('replacing an area that is not there is refused', $save($area, 'Nope')[0] === 409);
check('editing it, naming what it replaces, works', $save(['ceilingFt' => 150] + $area, 'Regional')[0] === 200
    && airdefender_ignore_request(['action' => 'ignore_get'], 'test')[1]['areas'][0]['ceilingFt'] === 150);
check('deleting without the right name is refused', airdefender_ignore_request(['action' => 'ignore_delete', 'name' => 'Other'], 'test')[0] === 409);
check('deleting by name works', airdefender_ignore_request(['action' => 'ignore_delete', 'name' => 'Regional'], 'test')[0] === 200
    && airdefender_ignore_request(['action' => 'ignore_get'], 'test')[1]['areas'] === []);
$logText = (string) @file_get_contents("$root/media/logs/plugin-AirDefender.log");
check('changes are logged with where they came from', strpos($logText, 'Ignore area Regional deleted (from test)') !== false);

if (function_exists('pcntl_fork')) {
    $pids = [];
    for ($i = 0; $i < 8; $i++) {
        if (($pid = pcntl_fork()) === 0) {
            $save(['name' => "A$i"] + $area);
            exit(0);
        }
        $pids[] = $pid;
    }
    foreach ($pids as $p) { pcntl_waitpid($p, $st); }
    $kept = count(airdefender_ignore_request(['action' => 'ignore_get'], 'test')[1]['areas']);
    check("8 saves at once: $kept kept (the most allowed is 5)", $kept === 5);
} else {
    echo "SKIP: concurrent saves need the pcntl extension\n";
}
check('the loop reads the saved file', count(airdefender_ignore_areas()) === 5);

exec('rm -rf ' . escapeshellarg($root));
echo $failures ? "$failures FAILED\n" : "All passed\n";
exit($failures ? 1 : 0);
