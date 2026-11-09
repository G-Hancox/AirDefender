<?php
/*
 * Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
 * Licensed under the GNU GPL v2; see LICENSE.
 *
 * Air Defender's entry under FPP's Help menu: a quick start, what it can't
 * see, and where to ask for help or suggest something. The full help for each
 * page is on that page's help button (F1).
 */
require_once(dirname(__DIR__) . "/scripts/airdefender_version.php");
?>
<div class="airdefender-help">
    <p class="text-body-secondary">Version <?php echo htmlspecialchars(airdefender_version()); ?></p>

    <div class="alert alert-warning"><b>What it can't see.</b> Air Defender only reacts to aircraft that broadcast
        ADS-B and that your chosen service or receiver picks up. Some light aircraft and helicopters don't broadcast it,
        drones generally don't, online coverage thins out close to the ground, and the data is always a little old. It
        doesn't replace your own obligations for sky-pointing beams and lasers.</div>

    <h2>Quick start</h2>
    <ol>
        <li>Open <a href="plugin.php?plugin=AirDefender&amp;page=www/settings.php">Air Defender's settings</a> and tick
            <b>Enable Air Defender</b>.</li>
        <li>On <b>Moving heads</b>, add each head: pick its model (or Manual channel and its start channel), the shutter's
            channel in the fixture, and the value that closes it.</li>
        <li>On <b>When &amp; where</b>, choose when to check, and press <b>Check it on a map</b>: FPP's location can be
            miles off. Then press <b>Look it up</b> for your elevation.</li>
        <li>On the <a href="plugin.php?plugin=AirDefender&amp;page=www/index.php">dashboard</a>, run a 30-second
            <b>Test</b>: the heads should close within a second and open again when it ends.</li>
    </ol>
    <p>Each page's help button (or F1) explains everything on that page.</p>

    <h2>Questions, problems and ideas</h2>
    <ul>
        <li><a href="https://github.com/G-Hancox/AirDefender#readme" target="_blank" rel="noopener">README</a>: setup,
            your own receiver, Remotes, and troubleshooting.</li>
        <li><a href="https://github.com/G-Hancox/AirDefender/issues/new/choose" target="_blank" rel="noopener">Report a
            problem, suggest a feature, or ask for a flight data service</a> on GitHub (needs a free GitHub account).
            Air Defender sends nothing itself; this link just opens the form.</li>
    </ul>
</div>
