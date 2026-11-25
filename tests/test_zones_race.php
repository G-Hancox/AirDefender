<?php
/*
 * Air Defender - Copyright (C) 2026 G Hancox. Licensed under the GNU GPL v2; see LICENSE.
 *
 * DeadZones zone changes under concurrency, and the guards on deleting and replacing zones. No FPP needed:
 *   php tests/test_zones_race.php [scripts folder]
 * It copies scripts/ into a throwaway plugin folder (with its own plugindata) and runs real processes.
 *   1. Lost changes: 12 processes each add a zone to the same head at once (a head takes up to 16); all 12
 *      must be there.
 *   2. Guards: deleting or replacing a zone must name the zone expected there, importing must say how many it
 *      replaces, and a head's zones are only cleared once that head is removed in the settings.
 */
if (!function_exists('pcntl_fork')) { echo "SKIP: needs the pcntl extension\n"; exit(0); }
$src = $argv[1] ?? dirname(__DIR__) . '/scripts';
$root = sys_get_temp_dir() . '/adzones_' . getmypid();
@mkdir("$root/plugin/scripts", 0777, true); @mkdir("$root/media/config", 0777, true); @mkdir("$root/media/logs", 0777, true);
foreach (glob("$src/*.php") as $f) { copy($f, "$root/plugin/scripts/" . basename($f)); }
putenv("MEDIADIR=$root/media"); putenv("LOGDIR=$root/media/logs");
file_put_contents("$root/media/config/plugin.AirDefender", "head1Enabled = \"1\"\nhead1Name = \"Left\"\n");
require "$root/plugin/scripts/airdefender_paths.php";
require "$root/plugin/scripts/airdefender_deadzones.php";
$failures = 0;
function check($what, $ok) { global $failures; if (!$ok) $failures++; echo ($ok ? "ok:   " : "FAIL: ") . "$what\n"; }
function zones() { return airdefender_zones_request(['action' => 'zones_get'])[1]['heads']['1']['zones'] ?? []; }
$sq = json_encode([[10, 10], [20, 10], [20, 20]]);

// 1. Twenty adds at once.
$pids = [];
for ($i = 0; $i < 12; $i++) {
    if (($pid = pcntl_fork()) === 0) {
        airdefender_zones_request(['action' => 'zone_save', 'head' => '1', 'name' => "Z$i", 'points' => $sq]);
        exit(0);
    }
    $pids[] = $pid;
}
foreach ($pids as $p) { pcntl_waitpid($p, $st); }
check('12 zones added at once: ' . count(zones()) . ' of 12 kept', count(zones()) === 12);

$guarded = (new ReflectionFunction('airdefender_zones_request'))->getNumberOfParameters() >= 2;
if (!$guarded) { echo "(old code: guards not present, skipping part 2)\n"; }
else {
    // 2. Guards.
    $z = zones(); $first = $z[0]['name'];
    [$c] = airdefender_zones_request(['action' => 'zone_delete', 'head' => '1', 'index' => '0', 'name' => 'not it']);
    check('delete naming the wrong zone: refused (409)', $c === 409 && count(zones()) === 12);
    [$c] = airdefender_zones_request(['action' => 'zone_delete', 'head' => '1', 'index' => '0']);
    check('delete naming no zone: refused (409)', $c === 409 && count(zones()) === 12);
    [$c] = airdefender_zones_request(['action' => 'zone_delete', 'head' => '1', 'index' => '0', 'name' => $first], '10.0.0.5');
    check('delete naming the right zone: deleted', $c === 200 && count(zones()) === 11 && zones()[0]['name'] !== $first);
    [$c] = airdefender_zones_request(['action' => 'zone_save', 'head' => '1', 'name' => 'New', 'points' => $sq, 'index' => '0', 'replaces' => 'wrong']);
    check('replace naming the wrong zone: refused (409)', $c === 409 && zones()[0]['name'] !== 'New');
    $file = json_encode(['heads' => ['1' => ['zones' => [['name' => 'Imp', 'points' => [[1, 1], [9, 1], [9, 9]]]]]]]);
    [$c] = airdefender_zones_request(['action' => 'zones_import', 'zones' => $file, 'replacing' => '5']);
    check('import expecting the wrong number of zones: refused (409)', $c === 409 && count(zones()) === 11);
    [$c] = airdefender_zones_request(['action' => 'zones_import', 'zones' => $file, 'replacing' => '11']);
    check('import expecting the right number: replaced', $c === 200 && count(zones()) === 1);
    [$c] = airdefender_zones_request(['action' => 'head_clear', 'head' => '1']);
    check('clearing a head that is still set up: refused (409)', $c === 409 && count(zones()) === 1);
    file_put_contents("$root/media/config/plugin.AirDefender", "head1Enabled = \"0\"\nhead1Name = \"\"\n");
    [$c] = airdefender_zones_request(['action' => 'head_clear', 'head' => '1']);
    check('clearing a removed head: its zones go', $c === 200 && count(zones()) === 0);
    $log = (string) @file_get_contents("$root/media/logs/plugin-AirDefender.log");
    check('deletes, imports and clears are logged with the address', strpos($log, '(from 10.0.0.5)') !== false
        && substr_count($log, 'DeadZones:') >= 3);
}
// 3. A change saved within the same second, leaving the file the same size, must still reach the loop
// (it re-reads zones.json only when the file changes).
$zf = airdefender_deadzones_zones_file();
airdefender_write_atomic($zf, '{"heads":{"1":{"zones":[{"name":"AAAA"}]}}}');
airdefender_deadzones_zones();
airdefender_write_atomic($zf, '{"heads":{"1":{"zones":[{"name":"BBBB"}]}}}');
$seen = airdefender_deadzones_zones()['heads']['1']['zones'][0]['name'] ?? '?';
check("a same-size change saved in the same second reaches the loop (saw {$seen})", $seen === 'BBBB');

exec('rm -rf ' . escapeshellarg($root));
echo $failures ? "FAILED ($failures)\n" : "All passed\n";
exit($failures ? 1 : 0);
