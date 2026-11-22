<?php
/*
 * Air Defender - Copyright (C) 2026 G Hancox. Licensed under the GNU GPL v2; see LICENSE.
 *
 * Races on the test / manual-tracking state files, with no FPP needed:
 *   php tests/test_state_race.php
 * It copies scripts/ into a throwaway plugin folder and runs real processes
 * against it at the same time.
 *   1. Torn reads: one process keeps starting tests while another keeps
 *      reading. A test is always running, so every read must say so; a read
 *      that sees "no test" caught the file half-written (the heads would open).
 *   2. Lost start: a test has just run out; one process notices (and writes it
 *      as finished) while another starts a new test. The new test must survive.
 *   3. Lost manual tracking: the same with manual tracking's expiry.
 */
if (!function_exists('pcntl_fork')) { echo "SKIP: needs the pcntl extension\n"; exit(0); }
$src = dirname(__DIR__) . '/scripts';
$root = sys_get_temp_dir() . '/adrace_' . getmypid();
@mkdir("$root/scripts", 0777, true);
foreach (glob("$src/*.php") as $f) { copy($f, "$root/scripts/" . basename($f)); }
putenv("MEDIADIR=$root"); putenv("LOGDIR=$root");   // the log goes in the throwaway folder too
require "$root/scripts/airdefender_paths.php";
require "$root/scripts/airdefender_test.php";
$failures = 0;
function report($name, $bad, $of) { global $failures; if ($bad) $failures++;
    printf("%s %s: %d of %d\n", $bad ? 'FAIL:' : 'ok:  ', $name, $bad, $of); }

// 1. Torn reads.
airdefender_test_start(600);
$stopAt = microtime(true) + 3;
if (($pid = pcntl_fork()) === 0) { while (microtime(true) < $stopAt) { airdefender_test_start(600); } exit(0); }
$reads = 0; $torn = 0;
while (microtime(true) < $stopAt) { $reads++; if (empty(airdefender_test_raw()['active'])) $torn++; }
pcntl_waitpid($pid, $st);
report('torn reads (a running test read as stopped)', $torn, $reads);

// 2. A test running out at the moment a new one starts.
$lost = 0; $n = 300;
for ($i = 0; $i < $n; $i++) {
    file_put_contents(airdefender_test_file(), json_encode(['active' => true, 'startedAt' => time() - 70,
        'endsAt' => time() - 1, 'duration' => 60, 'lastDuration' => 60]));
    if (($pid = pcntl_fork()) === 0) { airdefender_test_start(600); exit(0); }
    airdefender_test_status();              // notices the old test ran out
    pcntl_waitpid($pid, $st);
    if (empty(airdefender_test_raw()['active'])) $lost++;
}
report('new test lost to the old one running out', $lost, $n);

// 3. Manual tracking running out at the moment it's started again.
$lost = 0;
for ($i = 0; $i < $n; $i++) {
    file_put_contents(airdefender_override_file(), json_encode(['until' => time() - 1, 'minutes' => 5]));
    if (($pid = pcntl_fork()) === 0) { airdefender_override_start(30); exit(0); }
    airdefender_override_until();           // notices the old one ran out
    pcntl_waitpid($pid, $st);
    if (airdefender_override_until() === null) $lost++;
}
report('manual tracking lost to the old one running out', $lost, $n);

exec('rm -rf ' . escapeshellarg($root));
echo $failures ? "FAILED ($failures)\n" : "All passed\n";
exit($failures ? 1 : 0);
