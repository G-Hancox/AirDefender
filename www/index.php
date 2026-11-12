<?php
/*
 * Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
 * Licensed under the GNU GPL v2; see LICENSE.
 *
 * The dashboard: what Air Defender is doing now, a test, and the log. Built
 * from Bootstrap and FPP's own styles; the only CSS of its own is for the
 * radar picture, which FPP has nothing like.
 *
 * FPP includes this page at global scope, so every variable here is prefixed
 * airdefender, to leave FPP's own ($settings, $pluginSettings...) alone.
 */
require_once(dirname(__DIR__) . "/scripts/airdefender_feeds.php");
require_once(dirname(__DIR__) . "/scripts/airdefender_status.php");
require_once(dirname(__DIR__) . "/scripts/airdefender_version.php");

global $pluginSettings;

/* A saved Air Defender setting (FPP loads them into $pluginSettings for plugin pages), or $default. */
function airdefender_dash_setting(string $key, string $default): string
{
    global $pluginSettings;
    return is_array($pluginSettings ?? null) && isset($pluginSettings[$key]) && (string) $pluginSettings[$key] !== ''
        ? (string) $pluginSettings[$key] : $default;
}

list($airdefenderFeed) = airdefender_effective_feed(airdefender_dash_setting('adsbSourceUrl', ''));
$airdefenderTest = airdefender_test_status();
$airdefenderPage = array(
    'test' => $airdefenderTest,
    'live' => airdefender_live_status(true),
    // Last Event shows only triggers: safety on/off and tests. Everything else is in the log.
    'lastEvent' => airdefender_last_event(),
    'limits' => array(
        'nm' => (float) airdefender_dash_setting('distanceNM', '5'),
        'ft' => (int) airdefender_dash_setting('altitudeThresholdFt', '3000'),
        'holdSec' => (int) airdefender_dash_setting('clearDelaySec', '30'),
        'elevationFt' => (int) airdefender_dash_setting('showElevationFt', '0'),
    ),
    // The compass direction shown at the top of the radar (0 = north up).
    'radarRotation' => ((int) airdefender_dash_setting('radarRotation', '0') % 360 + 360) % 360,
    'feedLabel' => airdefender_feed_label($airdefenderFeed),
);
?>
<style>
    /* The radar: a scope with range rings, a sweep and the aircraft, drawn in
       the theme's own colours (Bootstrap's variables, light and dark). */
    .airdefender-radar { width: 100%; max-width: 16rem; display: block; margin: 0 auto; }
    .airdefender-radar .scope { fill: var(--bs-success-bg-subtle); stroke: var(--bs-success); }
    .airdefender-radar .grid { fill: none; stroke: var(--bs-success-border-subtle); }
    .airdefender-radar .wedge { fill: var(--bs-success); opacity: .25; }
    .airdefender-radar .beam { stroke: var(--bs-success); }
    .airdefender-radar .centre { fill: var(--bs-success); }
    .airdefender-radar .limit { fill: none; stroke: var(--bs-warning); }
    .airdefender-radar .label, .airdefender-radar .plane { fill: var(--bs-secondary-color); }
    .airdefender-radar .limit-label, .airdefender-radar .plane.in { fill: var(--bs-warning); }
    .airdefender-radar .ring, .airdefender-radar .hollow { fill: none; stroke: var(--bs-secondary-color); stroke-width: 1.2; stroke-linejoin: round; }
    .airdefender-radar .in .ring, .airdefender-radar .in .hollow { stroke: var(--bs-warning); }
    .airdefender-radar .hit { fill: transparent; cursor: pointer; }
    .airdefender-radar .picked { fill: none; stroke: var(--bs-primary); stroke-width: 1.5; }
    .airdefender-radar.turning .hit { cursor: inherit; }
    .airdefender-radar .compass { fill: var(--bs-body-color); font-weight: 700; }
    .airdefender-radar.turning { cursor: grab; touch-action: none; }
    .airdefender-radar.turning .scope { stroke: var(--bs-primary); stroke-dasharray: 6 4; }
    .airdefender-sweep { transform-origin: 100px 100px; animation: airdefender-spin linear infinite; }
    @keyframes airdefender-spin { to { transform: rotate(360deg); } }
    @media (prefers-reduced-motion: reduce) { .airdefender-sweep { animation: none; } }
    .airdefender-radar.idle { opacity: .55; }
</style>

<div class="airdefender-dash" id="ad">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div class="text-body-secondary" id="adMeta">&nbsp;</div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <div class="btn-group" role="group" aria-label="Dashboard view">
                <button type="button" class="btn btn-outline-secondary active" data-view="lite" aria-pressed="true" title="Refreshes every 5 seconds">Lite</button>
                <button type="button" class="btn btn-outline-secondary text-body" data-view="detailed" aria-pressed="false" title="Adds the radar and every head; refreshes every 2 seconds">Detailed</button>
            </div>
            <a class="buttons" href="plugin.php?plugin=AirDefender&amp;page=www/settings.php"><i class="fas fa-fw fa-cog"></i> Settings</a>
        </div>
    </div>

    <div class="alert d-flex flex-wrap align-items-center justify-content-between gap-3" id="adBanner" role="status">
        <div>
            <h3 class="alert-heading h4 mb-1" id="adBannerTitle">&nbsp;</h3>
            <div id="adBannerSub">&nbsp;</div>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2 d-none" id="adOverrideOff" title="Track aircraft now, even with no show on; stops by itself when the time runs out">
            <select class="form-select w-auto" id="adOverrideMin" aria-label="How long to track">
                <option value="30">30 min</option>
                <option value="60" selected>1 hour</option>
                <option value="120">2 hours</option>
                <option value="240">4 hours</option>
            </select>
            <button type="button" class="btn btn-outline-secondary text-body" id="adOverrideStart">Start tracking</button>
        </div>
        <button type="button" class="btn btn-danger d-none" id="adBannerTestStop">Stop test</button>
        <div class="d-flex flex-wrap align-items-center gap-2 d-none" id="adOverrideOn">
            <span class="badge text-bg-secondary fs-6" id="adOverrideLeft"></span>
            <button type="button" class="btn btn-outline-secondary text-body" id="adOverrideEnd">Stop tracking</button>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3" role="tablist" aria-label="Air Defender pages">
        <li class="nav-item" role="presentation"><button type="button" class="nav-link active" role="tab" aria-selected="true" data-tab="status"><i class="fas fa-fw fa-satellite-dish"></i> Status</button></li>
        <li class="nav-item" role="presentation"><button type="button" class="nav-link" role="tab" aria-selected="false" data-tab="test"><i class="fas fa-fw fa-stopwatch"></i> Test</button></li>
    </ul>

    <div data-pane="status" role="tabpanel">
    <div class="row g-3">
        <div class="col-md-6 col-xl-4 d-none" data-detail>
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-baseline gap-2">
                    <span class="fw-bold">Sky</span>
                    <small class="text-body-secondary text-end flex-grow-1" id="adFeed"></small>
                    <button type="button" class="btn btn-sm btn-outline-secondary text-body py-0 px-1" id="adTurn"
                        title="Rotate the radar to set your north: click to unlock, then drag" aria-pressed="false"
                        aria-label="Rotate the radar to set your north"><i class="fas fa-fw fa-lock" id="adTurnIcon"></i></button>
                </div>
                <div class="card-body">
                    <svg class="airdefender-radar" id="adRadar" viewBox="0 0 200 200" role="img" aria-label="Aircraft around the show"></svg>
                    <div class="form-text text-center" id="adSkyNote"></div>
                    <div class="form-text text-center d-none" id="adTurnHelp">
                        Drag the radar to turn it, or use the arrows. <span class="fw-semibold" id="adTurnTop"></span>
                        <div class="d-flex justify-content-center gap-1 mt-1">
                            <button type="button" class="btn btn-sm btn-outline-secondary text-body" data-turn="-15" aria-label="Turn left 15 degrees"><i class="fas fa-undo"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary text-body" data-turn="0">North up</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary text-body" data-turn="15" aria-label="Turn right 15 degrees"><i class="fas fa-redo"></i></button>
                        </div>
                        Press the lock to save it.
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-4 d-none" data-detail>
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-baseline gap-2">
                    <span class="fw-bold">Aircraft</span><small class="text-end" id="adPlaneState"></small>
                </div>
                <div class="card-body" aria-live="polite">
                    <div class="text-body-secondary" id="adPlaneEmpty"></div>
                    <div class="d-none" id="adPlane">
                        <div class="d-flex align-items-start gap-2">
                            <div class="me-auto">
                                <div class="fw-semibold fs-5" id="adPlaneTitle"></div>
                                <div class="text-body-secondary" id="adPlaneSub"></div>
                            </div>
                            <button type="button" class="btn-close" id="adPlaneClose" aria-label="Clear the selected aircraft"></button>
                        </div>
                        <dl class="row mb-2 mt-2" id="adPlaneRows"></dl>
                        <div class="d-flex flex-wrap gap-3" id="adPlaneLinks"></div>
                        <div class="form-text d-none" id="adPlaneLocked">Other aircraft are locked until the all clear.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-baseline gap-2">
                    <span class="fw-bold">Moving heads</span><small class="text-body-secondary text-end" id="adFeedLite" data-lite></small>
                </div>
                <div class="card-body">
                    <div id="adHeadsSummary" data-lite></div>
                    <div class="list-group list-group-flush d-none" id="adHeadsList" data-detail></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div class="text-break flex-grow-1"><span class="text-body-secondary">Last event</span> &middot; <span id="adLastEvent"><?php echo htmlspecialchars($airdefenderPage['lastEvent']); ?></span></div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <small class="text-body-secondary" id="adLimits"></small>
                <button type="button" class="buttons" id="adViewLog" title="When the heads were closed and opened, and tests">View log</button>
                <button type="button" class="buttons" id="adClearLog" title="Delete the Air Defender log, which lists aircraft that passed nearby. It is kept until you clear it.">Clear log</button>
            </div>
        </div>
    </div>
    </div>

    <div class="d-none" data-pane="test" role="tabpanel">
    <div class="row g-3">
        <div class="col-lg-8 col-xl-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-baseline gap-2">
                    <span class="fw-bold">Test</span><small class="text-body-secondary">closes the heads for a while</small>
                </div>
                <div class="card-body">
                    <div id="adTestIdle">
                        <div class="d-flex flex-wrap gap-1 mb-2" role="group" aria-label="Test length">
                            <button type="button" class="btn btn-sm btn-outline-secondary text-body" data-sec="30">30 s</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary text-body" data-sec="60">1 min</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary text-body" data-sec="120">2 min</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary text-body" data-sec="300">5 min</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary text-body" data-sec="600">10 min</button>
                        </div>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <div class="input-group w-auto">
                                <input type="number" class="form-control" id="adTestSeconds" min="<?php echo (int) $airdefenderTest['minSec']; ?>"
                                    max="<?php echo (int) $airdefenderTest['maxSec']; ?>" step="5" size="6"
                                    value="<?php echo (int) $airdefenderTest['lastDuration']; ?>" aria-label="Test length in seconds">
                                <span class="input-group-text">seconds</span>
                            </div>
                            <button type="button" class="btn btn-warning" id="adTestStart">Start test</button>
                        </div>
                        <div class="form-text">Go outside and watch the heads close, then open again when time runs out.</div>
                    </div>
                    <div id="adTestRunning" class="d-none">
                        <div class="d-flex flex-wrap align-items-end justify-content-between gap-2">
                            <div>
                                <div class="text-body-secondary small">Heads back on in</div>
                                <div class="display-6 font-monospace text-primary" id="adCount">--:--</div>
                            </div>
                            <button type="button" class="btn btn-danger" id="adTestStop">Stop test</button>
                        </div>
                        <div class="progress mt-3" role="progressbar" aria-label="Time left in the test">
                            <div class="progress-bar" id="adBar"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-xl-6">
            <div class="small text-body-secondary">
                <p>A test closes every head's shutter for the time you pick, so you can go outside and check the heads go dark
                    and come back. It doesn't need an aircraft, a show, or the flight data.</p>
                <p>A test started on the Player also closes the heads on every Remote. While it runs, the banner at the top has a
                    <b>Stop test</b> button on every tab.</p>
                <p class="mb-0">Playlists and schedules can run one too, with the <b>Air Defender: Start Test</b> and
                    <b>Air Defender: Stop Test</b> commands.</p>
            </div>
        </div>
    </div>
    </div>

    <div class="alert alert-secondary mt-3 mb-0 small">
        Air Defender only sees aircraft that broadcast ADS-B and that the flight data reaches, a little late. Some light aircraft,
        helicopters and drones don't broadcast it. It doesn't replace your own obligations for sky-pointing beams and lasers.
    </div>

    <div class="modal fade" id="adLogDlg" tabindex="-1" aria-labelledby="adLogTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title h5" id="adLogTitle">Air Defender log</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <div class="btn-group btn-group-sm" role="group" aria-label="Which days">
                            <button type="button" class="btn btn-outline-secondary active" data-range="today">Today</button>
                            <button type="button" class="btn btn-outline-secondary text-body" data-range="yesterday">Yesterday</button>
                            <button type="button" class="btn btn-outline-secondary text-body" data-range="week">Last 7 days</button>
                        </div>
                        <div class="btn-group btn-group-sm" role="group" aria-label="What to show">
                            <button type="button" class="btn btn-outline-secondary active" data-what="events" title="When the heads were closed and opened, and tests">Safety &amp; tests</button>
                            <button type="button" class="btn btn-outline-secondary text-body" data-what="all">Everything</button>
                        </div>
                    </div>
                    <div class="text-body-secondary small mb-2" id="adLogSummary"></div>
                    <table class="table table-sm mb-0"><tbody id="adLogList"></tbody></table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var API = 'plugin.php?plugin=AirDefender&page=www/test_api.php&nopage=1';
    var cfg = <?php echo json_encode($airdefenderPage); ?>;
    var FEED_LABEL = cfg.feedLabel;
    var status = cfg.test, live = cfg.live;
    var clockOffset = status.serverTime - Date.now() / 1000;
    var view = 'lite', pollTimer = null, tickTimer = null, finishing = false;
    var rotation = cfg.radarRotation || 0, turning = false, savedRotation = rotation;

    var $id = function (id) { return document.getElementById(id); };
    var root = $id('ad');
    function hide(el, hidden) { el.classList.toggle('d-none', !!hidden); }
    function toast(text, ok) { $.jGrowl(text, { themeState: ok === false ? 'danger' : 'success' }); }

    function fmtClock(sec) {
        sec = Math.max(0, Math.ceil(sec));
        var m = Math.floor(sec / 60), s = sec % 60;
        return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }
    function ago(sec) {
        if (sec === null || sec === undefined) { return ''; }
        if (sec < 60) { return sec + ' s ago'; }
        if (sec < 3600) { return Math.floor(sec / 60) + ' min ago'; }
        return Math.floor(sec / 3600) + ' h ago';
    }
    function num(n) { return Number(n).toLocaleString(); }
    function testLeft() {
        return status.active && status.endsAt ? status.endsAt - (Date.now() / 1000 + clockOffset) : 0;
    }
    function limits() {
        var l = (live && live.limits && live.limits.nm !== null) ? live.limits : cfg.limits;
        return { nm: l.nm, ft: l.ft, holdSec: l.holdSec, elevationFt: l.elevationFt || 0 };
    }

    /* ---------- banner ---------- */
    function renderBanner() {
        var title, sub, cls;
        var left = testLeft();
        if (live && !live.loopRunning) {
            cls = 'danger'; title = 'Not running';
            sub = "Air Defender's detection loop is not running, so aircraft are not being checked. Restart FPPD (or reboot).";
        } else if (status.active && left > 0) {
            cls = 'primary'; title = 'Test running';
            sub = 'Heads closed · back on in ' + fmtClock(left);
        } else if (live && !live.enabled) {
            cls = 'secondary'; title = 'Disabled';
            sub = 'Turn on Air Defender in Settings to check for aircraft.';
        } else if (live && live.role !== 'remote' && live.checking === false) {
            cls = 'secondary'; title = 'Standby';
            sub = live.skippedPlaylist ? live.skippedPlaylist + " isn't selected"
                : live.checkWhen === 'playing' ? (live.playlistFilter ? 'Waiting for a selected playlist' : 'Waiting for a playlist')
                : 'Waiting for a scheduled show';
            if (live.nextShow && live.nextShow.start) {
                var d = new Date(live.nextShow.start * 1000);
                sub += ' · Next: ' + (live.nextShow.name || 'show') + ', ' + d.toLocaleDateString([], { weekday: 'short' }) + ' '
                    + d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
            }
        } else if (live && live.safe) {
            cls = 'warning'; title = 'Safety active';
            sub = (live.now || '') + (live.safeSince ? ' · since ' + new Date(live.safeSince * 1000).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '');
        } else if (live && live.role !== 'remote' && live.feed && live.feed.error) {
            cls = 'danger'; title = "Can't check the sky";
            sub = live.feed.error;
        } else {
            cls = 'success'; title = 'All clear';
            sub = (live && live.now) || '';
        }
        if (live && live.outputError) {
            sub += ' · FPP refused the shutter channels: ' + live.outputError;
        }
        // Start tracking: offered when checks depend on show times; shows time left while on.
        var ovLeft = live && live.overrideUntil ? live.overrideUntil - (Date.now() / 1000 + clockOffset) : 0;
        var canOverride = live && live.loopRunning && live.enabled && live.role !== 'remote';
        hide($id('adOverrideOn'), !(ovLeft > 0));
        hide($id('adOverrideOff'), !(canOverride && !(ovLeft > 0)));
        if (ovLeft > 0) {
            $id('adOverrideLeft').textContent = 'Tracking · ' + (ovLeft >= 3600 ? Math.floor(ovLeft / 3600) + ' h ' : '')
                + Math.ceil((ovLeft % 3600) / 60) + ' min left';
        }
        hide($id('adBannerTestStop'), !(status.active && left > 0));
        $id('adBanner').className = 'alert alert-' + cls + ' d-flex flex-wrap align-items-center justify-content-between gap-3';
        $id('adBannerTitle').textContent = title;
        $id('adBannerSub').textContent = sub;
        $id('adMeta').textContent = 'Version ' + <?php echo json_encode(airdefender_version()); ?> + (!live ? ''
            : live.role === 'remote' ? ' · Remote, follows the Player'
            : ' · Player, ' + (live.checkWhen === 'playing' ? 'selected playlists' : 'all scheduled shows'));
    }

    /* ---------- test ---------- */
    function renderTest() {
        var left = testLeft(), running = status.active && left > 0;
        hide($id('adTestIdle'), running);
        hide($id('adTestRunning'), !running);
        if (running) {
            $id('adCount').textContent = fmtClock(left);
            $id('adBar').style.width = Math.max(0, Math.min(100, status.duration ? left / status.duration * 100 : 0)) + '%';
        }
        if (status.active && left <= 0 && !finishing) {
            finishing = true;
            poll().then(function () { finishing = false; }, function () { finishing = false; });
        }
        // Tick the countdown only while a test runs.
        if (running && !tickTimer) {
            tickTimer = setInterval(function () { renderTest(); renderBanner(); }, 500);
        } else if (!running && tickTimer) {
            clearInterval(tickTimer);
            tickTimer = null;
        }
    }
    function markPresets() {
        var v = parseInt($id('adTestSeconds').value, 10);
        root.querySelectorAll('[data-sec]').forEach(function (b) {
            var on = parseInt(b.getAttribute('data-sec'), 10) === v;
            b.classList.toggle('active', on);
            b.classList.toggle('text-body', !on);   // plain buttons readable in dark; the chosen one keeps Bootstrap's colours
        });
    }

    /* ---------- heads ---------- */
    function renderHeads() {
        var heads = (live && live.heads) || [];
        var list = $id('adHeadsList');
        list.textContent = '';
        if (!heads.length) {
            var none = document.createElement('div');
            none.className = 'list-group-item px-0 text-body-secondary';
            none.textContent = 'No heads enabled. Add them in Settings.';
            list.appendChild(none);
        }
        heads.forEach(function (h) {
            var item = document.createElement('div');
            item.className = 'list-group-item px-0';
            var line = document.createElement('div');
            line.className = 'd-flex justify-content-between align-items-center gap-2';
            var name = document.createElement('span');
            name.textContent = h.name + ' ';
            var ch = document.createElement('small');
            ch.className = 'text-body-secondary';
            ch.textContent = h.channel ? 'ch ' + h.channel : 'no channel';
            name.appendChild(ch);
            var badge = document.createElement('span');
            badge.className = 'badge ' + (h.closed ? 'text-bg-warning' : 'text-bg-secondary');
            badge.textContent = h.closed ? 'Closed' : 'Show';
            line.appendChild(name);
            line.appendChild(badge);
            item.appendChild(line);
            if (h.problem) {
                var p = document.createElement('div');
                p.className = 'small text-danger';
                p.textContent = h.problem;
                item.appendChild(p);
            }
            list.appendChild(item);
        });

        var closed = heads.filter(function (h) { return h.closed; }).length;
        var problems = heads.filter(function (h) { return h.problem; }).length;
        var summary = !heads.length ? 'No heads enabled. Add them in Settings.'
            : heads.length + (heads.length === 1 ? ' head' : ' heads') + ' · '
              + (closed === heads.length ? 'all closed' : closed === 0 ? 'the show controls them' : closed + ' closed');
        $id('adHeadsSummary').textContent = summary + (problems ? ' · ' + problems + ' need attention (see Detailed)' : '');
        $id('adHeadsSummary').classList.toggle('text-danger', !!problems);
    }

    /* ---------- sky ---------- */
    function feedText() {
        if (!live) { return ''; }
        if (live.role === 'remote') {
            var m = live.master || {};
            return m.connected ? 'Player ' + (m.address || '') + ' OK' : 'Player not connected';
        }
        var f = live.feed || {};
        if (live.checking === false) { return 'standby'; }
        if (f.error) { return 'feed problem' + (f.lastOkAgo !== null && f.lastOkAgo !== undefined ? ', last answer ' + ago(f.lastOkAgo) : ''); }
        var label = (f.used || FEED_LABEL) + (f.onBackup ? ' (backup: ' + (f.chosen || 'your chosen service') + ' not answering)' : '');
        return f.lastOkAgo !== null && f.lastOkAgo !== undefined ? label + ' · answering' : 'not checked yet';
    }
    // The sweep's speed and timing: one turn per "Check every", lined up with the last check. Only moved when the
    // checks drift more than a second and a half from it, so the second-by-second reports don't make it twitch.
    var sweepAnchor = 0, sweepPeriodSec = 6;
    function syncSweep() {
        var f = (live && live.role !== 'remote' && live.feed) || {};
        var sec = f.intervalSec >= 1 ? f.intervalSec : 6;
        if (sec !== sweepPeriodSec) { sweepPeriodSec = sec; sweepAnchor = 0; }
        if (typeof f.lastOkAgoMs === 'number') {
            var p = sec * 1000, at = Date.now() - f.lastOkAgoMs;
            var off = (((at - sweepAnchor) % p) + p) % p;
            if (!sweepAnchor || Math.min(off, p - off) > 1500) { sweepAnchor = at; }
        }
        return sweepPeriodSec;
    }
    // What kind of aircraft, from the category it broadcasts (A7 is a helicopter; B and later are gliders,
    // balloons, drones and the like).
    function planeKind(p) {
        if (p.cat === 'A7') { return 'heli'; }
        if (p.cat && p.cat.charAt(0) >= 'B') { return 'other'; }
        return 'plane';
    }
    var KIND_NAME = { A1: 'Light aircraft', A2: 'Small aircraft', A3: 'Large aircraft', A4: 'Large aircraft (757 class)',
                      A5: 'Heavy aircraft', A6: 'High-performance aircraft', A7: 'Helicopter', B1: 'Glider',
                      B2: 'Balloon or airship', B3: 'Parachutist', B4: 'Ultralight', B6: 'Drone', B7: 'Spacecraft' };
    function planeKey(p) { return p.hex || p.id; }

    // The Aircraft card. Tap an aircraft on the radar for everything the flight data says about it. While an
    // aircraft holds the heads closed, the card shows that one instead, and taps on others wait for the all clear.
    var pickedKey = null, lockedKey = null;
    function lockedNow() {
        return !!(live && live.role !== 'remote' && live.safe && (live.reason === 'aircraft' || live.reason === 'hold'));
    }
    function shownKey(planes) {
        if (!lockedNow()) { lockedKey = null; return pickedKey; }
        if (live.reason === 'aircraft') {
            // The nearest aircraft inside the limits (the list comes nearest first).
            for (var i = 0; i < planes.length; i++) { if (planes[i].in) { lockedKey = planeKey(planes[i]); break; } }
        }
        return lockedKey;
    }
    function showPicked(planes) {
        planes = planes || [];
        var empty = $id('adPlaneEmpty'), box = $id('adPlane'), state = $id('adPlaneState');
        var locked = lockedNow(), key = shownKey(planes), p = null;
        planes.forEach(function (q) { if (planeKey(q) === key) { p = q; } });
        state.className = 'text-end ' + (locked ? 'text-warning-emphasis fw-semibold' : 'text-body-secondary');
        state.textContent = locked ? (live.reason === 'aircraft' ? 'Holding the heads closed' : 'Left your limits · hold time')
            : p ? 'Selected' : '';
        hide($id('adPlaneClose'), locked);
        hide($id('adPlaneLocked'), !locked);
        if (!p) {
            hide(box, true); hide(empty, false);
            if (live && live.role === 'remote') { empty.textContent = 'This Remote follows its Player; its dashboard shows the aircraft.'; return; }
            var near = planes[0];
            empty.textContent = locked ? 'The aircraft has left the flight data.'
                : (key ? 'That aircraft is out of range now. ' : '') + 'Tap an aircraft on the radar for its details.'
                  + (near ? ' Nearest: ' + near.id + ' · ' + near.nm + ' NM' + (near.ft === null ? '' : ' · ' + num(near.ft) + ' ft') + '.' : '');
            return;
        }
        hide(empty, true); hide(box, false);
        $id('adPlaneTitle').textContent = p.desc || p.type || KIND_NAME[p.cat] || 'Aircraft';
        $id('adPlaneSub').textContent = [p.id !== p.hex ? p.id : '', p.reg !== p.id ? p.reg : '', p.op].filter(Boolean).join(' · ')
            || 'No callsign';
        var above = live && live.limits && live.limits.elevationFt;
        var rows = [
            ['Type', [p.type, KIND_NAME[p.cat]].filter(Boolean).join(' · ')],
            ['Distance', p.nm + ' NM ' + compass(p.brg).split(' ')[0] + ' of the show'],
            ['Height', p.ft === null || p.ft === undefined ? 'Not reported' : num(p.ft) + ' ft' + (above ? ' above the show' : '')],
            ['Climb', p.vs === undefined ? null : Math.abs(p.vs) < 200 ? 'Level' : (p.vs > 0 ? 'Climbing ' : 'Descending ') + num(Math.abs(p.vs)) + ' ft/min'],
            ['Speed', p.gs === undefined ? null : p.gs + ' kn (' + Math.round(p.gs * 1.15078) + ' mph)'],
            ['Heading', p.trk === null || p.trk === undefined ? null : compass(p.trk)],
            ['Limits', p.in ? 'Inside' : 'Outside (too far or too high)'],
            ['Built', p.year],
            ['Squawk', p.sq],
            ['ICAO code', p.hex ? p.hex.toUpperCase() : null]
        ];
        var dl = $id('adPlaneRows'); dl.textContent = '';
        rows.forEach(function (r) {
            if (r[1] === null || r[1] === undefined || r[1] === '') { return; }
            var dt = document.createElement('dt'); dt.className = 'col-4 fw-normal text-body-secondary'; dt.textContent = r[0];
            var dd = document.createElement('dd'); dd.className = 'col-8 mb-1'; dd.textContent = r[1];
            dl.appendChild(dt); dl.appendChild(dd);
        });
        var links = $id('adPlaneLinks'); links.textContent = '';
        function link(text, href) {
            var a = document.createElement('a'); a.className = 'link-secondary'; a.href = href; a.target = '_blank';
            a.rel = 'noopener noreferrer'; a.textContent = text; links.appendChild(a);
        }
        if (p.hex) { link('Live map (adsb.fi)', 'https://globe.adsb.fi/?icao=' + encodeURIComponent(p.hex)); }
        if (p.id && p.id !== p.hex) { link('FlightAware', 'https://www.flightaware.com/live/flight/' + encodeURIComponent(p.id)); }
    }
    $id('adRadar').addEventListener('click', function (e) {
        var t = e.target;
        if (turning || lockedNow() || !t || !t.getAttribute) { return; }   // locked until the all clear
        var key = t.getAttribute('data-key');
        if (!key) { if (pickedKey) { pickedKey = null; renderSky(); } return; }   // tapping empty radar clears it
        pickedKey = pickedKey === key ? null : key;
        renderSky();
    });
    $id('adPlaneClose').addEventListener('click', function () { pickedKey = null; renderSky(); });

    function renderSky() {
        showPicked([]);   // the Aircraft card; filled in below once the radar is drawn
        $id('adFeed').textContent = feedText();
        $id('adFeedLite').textContent = feedText();
        if (view !== 'detailed') { return; }

        var svg = $id('adRadar'), lim = limits();
        var NS = 'http://www.w3.org/2000/svg';
        // The scope shows a little past the limit, so the limit ring sits inside the rim.
        var outer = Math.max(1, Math.ceil(lim.nm || 5)) * 1.15;
        var R = 88;
        function el(tag, attrs, text, parent) {
            var e = document.createElementNS(NS, tag);
            for (var k in attrs) { e.setAttribute(k, attrs[k]); }
            if (text !== undefined) { e.textContent = text; }
            (parent || svg).appendChild(e);
            return e;
        }
        svg.textContent = '';
        // Drawn like the plugin's icon: a radar scope with range rings and a sweep.
        el('circle', { cx: 100, cy: 100, r: R, 'class': 'scope', 'stroke-width': 2.5 });
        el('circle', { cx: 100, cy: 100, r: R * 2 / 3, 'class': 'grid', 'stroke-width': 1.2 });
        el('circle', { cx: 100, cy: 100, r: R / 3, 'class': 'grid', 'stroke-width': 1.2 });
        el('line', { x1: 100, y1: 100 - R, x2: 100, y2: 100 + R, 'class': 'grid', 'stroke-width': 0.8 });
        el('line', { x1: 100 - R, y1: 100, x2: 100 + R, y2: 100, 'class': 'grid', 'stroke-width': 0.8 });
        // No sweep while nothing is being checked (standby, disabled, loop stopped), so a still radar means idle.
        var idle = !!live && (!live.loopRunning || !live.enabled || (live.role !== 'remote' && live.checking === false));
        svg.classList.toggle('idle', idle);
        var sweep = el('g', { 'class': 'airdefender-sweep' + (idle ? ' d-none' : '') });
        // One turn per check: the beam passes the top as each check comes in. Rebuilt every refresh, so each one
        // starts where the turn should be and it never jumps.
        var period = syncSweep() * 1000;
        sweep.style.animationDuration = (period / 1000) + 's';
        sweep.style.animationDelay = (-(((Date.now() - sweepAnchor) % period) + period) % period / 1000) + 's';
        var ex = (100 - R * Math.sin(Math.PI / 3)).toFixed(1), ey = (100 - R * Math.cos(Math.PI / 3)).toFixed(1);
        el('path', { d: 'M100 100 L' + ex + ' ' + ey + ' A' + R + ' ' + R + ' 0 0 1 100 ' + (100 - R) + ' Z', 'class': 'wedge' }, undefined, sweep);
        el('line', { x1: 100, y1: 100, x2: 100, y2: 100 - R, 'class': 'beam', 'stroke-width': 2, 'stroke-linecap': 'round' }, undefined, sweep);
        el('circle', { cx: 100, cy: 100, r: R * Math.min(1, (lim.nm || outer) / outer),
                       'class': 'limit', 'stroke-width': 1.5, 'stroke-dasharray': '4 3' });
        // The compass points, turned with the radar (its top shows the direction set with the lock button).
        [['N', 0], ['E', 90], ['S', 180], ['W', 270]].forEach(function (c) {
            var a = (c[1] - rotation) * Math.PI / 180, r = R - 9;
            el('text', { x: (100 + r * Math.sin(a)).toFixed(1), y: (100 - r * Math.cos(a) + 3).toFixed(1),
                         'class': c[0] === 'N' ? 'compass' : 'label', 'font-size': c[0] === 'N' ? 10 : 8, 'text-anchor': 'middle' }, c[0]);
        });
        el('text', { x: 196, y: 196, 'class': 'limit-label', 'font-size': 8, 'text-anchor': 'end' }, (lim.nm || 5) + ' NM limit');
        el('circle', { cx: 100, cy: 100, r: 4, 'class': 'centre' });

        if (live.role === 'remote') {
            $id('adSkyNote').textContent = 'This Remote follows its Player, which checks the sky.';
            return;
        }
        if (live.checking === false) {
            $id('adSkyNote').textContent = 'Standby: not checking right now.';
            return;
        }
        var planes = live.aircraft || [], inside = 0, highlight = shownKey(planes);
        planes.forEach(function (p) {
            var r = Math.min(1, p.nm / outer) * R, a = (p.brg - rotation) * Math.PI / 180;
            var x = (100 + r * Math.sin(a)).toFixed(1), y = (100 - r * Math.cos(a)).toFixed(1);
            var s = p.in ? 1.6 : 1, kind = planeKind(p);
            var g = el('g', { 'class': p.in ? 'plane in' : 'plane', transform: 'translate(' + x + ' ' + y + ')' });
            if (p.trk !== null && p.trk !== undefined) {
                // An arrow pointing the way it's heading: solid for a plane, inside a rotor ring for a helicopter,
                // hollow for a drone, balloon, glider or anything else.
                var t = el('g', { transform: 'rotate(' + ((p.trk - rotation + 360) % 360) + ')' }, undefined, g);
                var k = kind === 'heli' ? 0.7 * s : s;
                el('polygon', { points: [0, -6 * k, 4 * k, 5 * k, 0, 2.5 * k, -4 * k, 5 * k].join(' '),
                                'class': kind === 'other' ? 'hollow' : '' }, undefined, t);
                if (kind === 'heli') { el('circle', { r: 6.5 * s, 'class': 'ring' }, undefined, t); }
            } else {
                el('circle', { r: p.in ? 5 : 3 }, undefined, g);
            }
            if (planeKey(p) === highlight) { el('circle', { r: 9 * s, 'class': 'picked' }, undefined, g); }
            // A bigger invisible target, so it's easy to hover or tap.
            var hit = el('circle', { r: 9, 'class': 'hit', 'data-key': planeKey(p) }, undefined, g);
            el('title', {}, (p.type ? p.type + ' · ' : '') + p.id + ' · ' + p.nm + ' NM · ' + (p.ft === null ? '? ft' : num(p.ft) + ' ft')
                + (p.trk !== null && p.trk !== undefined ? ' · heading ' + compass(p.trk) : '') + ' · click for details', hit);
            if (p.in) { inside++; }
        });
        showPicked(planes);
        $id('adSkyNote').textContent = !live.feed || live.feed.lastOkAgo === null ? 'Waiting for flight data'
            : planes.length + ' aircraft around · ' + inside + ' inside ' + lim.nm + ' NM / ' + num(lim.ft) + ' ft';
    }

    /* ---------- turning the radar ---------- */
    function compass(deg) {
        return ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'][Math.round(((deg % 360) + 360) % 360 / 45) % 8] + ' (' + Math.round(deg) + '\u00b0)';
    }
    function setRotation(deg) {
        rotation = ((Math.round(deg) % 360) + 360) % 360;
        $id('adTurnTop').textContent = 'Top of the radar: ' + compass(rotation) + '.';
        renderSky();
    }
    function setTurning(on) {
        turning = on;
        $id('adRadar').classList.toggle('turning', on);
        hide($id('adTurnHelp'), !on);
        $id('adTurnIcon').className = 'fas fa-fw ' + (on ? 'fa-lock-open' : 'fa-lock');
        $id('adTurn').setAttribute('aria-pressed', on ? 'true' : 'false');
        $id('adTurn').classList.toggle('active', on);
        $id('adTurn').classList.toggle('text-body', !on);
        var tip = on ? 'Click to lock and save where north is' : 'Rotate the radar to set your north: click to unlock, then drag';
        // FPP turns titles into Bootstrap tooltips; update that one if it's there, so there's never two.
        var tt = window.bootstrap && bootstrap.Tooltip && bootstrap.Tooltip.getInstance($id('adTurn'));
        if (tt && tt.setContent) { tt.setContent({ '.tooltip-inner': tip }); } else { $id('adTurn').setAttribute('title', tip); }
        $id('adTurn').setAttribute('aria-label', on ? 'Lock and save the radar' : 'Rotate the radar to set your north');
        if (on) { setRotation(rotation); }
    }
    $id('adTurn').addEventListener('click', function () {
        if (!turning) { setTurning(true); return; }
        setTurning(false);
        if (rotation === savedRotation) { return; }
        // Saved with Air Defender's other settings, the way FPP's settings pages save.
        $.ajax({ url: 'api/plugin/AirDefender/settings/radarRotation', data: '' + rotation, method: 'PUT', timeout: 30000,
                 processData: false, contentType: 'text/plain; charset=UTF-8' })
            .done(function () { savedRotation = rotation; toast('Radar turned: ' + compass(rotation) + ' at the top.'); })
            .fail(function () { toast('Could not save the radar\u2019s direction. Check the FPP logs.', false); });
    });
    root.querySelectorAll('[data-turn]').forEach(function (b) {
        b.addEventListener('click', function () {
            var d = parseInt(b.getAttribute('data-turn'), 10);
            setRotation(d === 0 ? 0 : rotation + d);
        });
    });
    (function () {
        var svg = $id('adRadar'), start = null;
        function angle(ev) {
            var box = svg.getBoundingClientRect();
            return Math.atan2(ev.clientX - (box.left + box.width / 2), -(ev.clientY - (box.top + box.height / 2))) * 180 / Math.PI;
        }
        svg.addEventListener('pointerdown', function (ev) {
            if (!turning) { return; }
            start = { a: angle(ev), r: rotation };
            svg.setPointerCapture(ev.pointerId);
        });
        svg.addEventListener('pointermove', function (ev) {
            if (!turning || !start) { return; }
            // Dragging clockwise turns the picture clockwise, so the top moves to a direction further anticlockwise.
            setRotation(start.r - (angle(ev) - start.a));
        });
        ['pointerup', 'pointercancel'].forEach(function (t) {
            svg.addEventListener(t, function () {
                if (start) { setRotation(Math.round(rotation / 5) * 5); }   // to the nearest 5 degrees
                start = null;
            });
        });
    })();

    /* ---------- footer ---------- */
    function renderFooter(lastEvent) {
        if (typeof lastEvent === 'string') { $id('adLastEvent').textContent = lastEvent; }
        var lim = limits();
        $id('adLimits').textContent = lim.nm + ' NM · ' + num(lim.ft) + ' ft' + (lim.elevationFt ? ' above show' : '') + ' · hold ' + lim.holdSec + ' s'
            + (live && live.role === 'remote' ? '' : ' · ' + FEED_LABEL);
    }

    function renderAll(data) {
        if (data) {
            status = data;
            clockOffset = data.serverTime - Date.now() / 1000;
            if (data.live) { live = data.live; }
        }
        renderBanner(); renderTest(); renderHeads(); renderSky(); renderFooter(data ? data.lastEvent : cfg.lastEvent);
    }

    /* ---------- server ---------- */
    function call(method, body) {
        var opts = { method: method, credentials: 'same-origin', cache: 'no-store' };
        if (body) { opts.headers = { 'Content-Type': 'application/x-www-form-urlencoded' }; opts.body = body; }
        return fetch(API + (view === 'detailed' ? '&view=detailed' : ''), opts).then(function (r) {
            return r.json().then(function (d) {
                if (!r.ok) { throw new Error(d.error || ('HTTP ' + r.status)); }
                renderAll(d);
                return d;
            });
        });
    }
    function poll() { return call('GET').catch(function () {}); }
    function schedule() {
        if (pollTimer) { clearInterval(pollTimer); }
        pollTimer = setInterval(poll, view === 'detailed' ? 2000 : 5000);
    }

    /* ---------- view switch ---------- */
    function setView(v, save) {
        view = v === 'detailed' ? 'detailed' : 'lite';
        root.querySelectorAll('[data-detail]').forEach(function (el) { hide(el, view !== 'detailed'); });
        root.querySelectorAll('[data-lite]').forEach(function (el) { hide(el, view === 'detailed'); });
        root.querySelectorAll('[data-view]').forEach(function (b) {
            var on = b.getAttribute('data-view') === view;
            b.classList.toggle('active', on);
            b.classList.toggle('text-body', !on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        if (save) { try { localStorage.setItem('airdefenderView', view); } catch (e) {} }
        schedule();
        renderAll();
        if (save) { poll(); }
    }
    root.querySelectorAll('[data-view]').forEach(function (b) {
        b.addEventListener('click', function () { setView(b.getAttribute('data-view'), true); });
    });

    /* ---------- controls ---------- */
    function busy(btn, promise) {
        btn.disabled = true;
        return promise.then(function () { btn.disabled = false; }, function () { btn.disabled = false; });
    }
    root.querySelectorAll('[data-sec]').forEach(function (b) {
        b.addEventListener('click', function () { $id('adTestSeconds').value = b.getAttribute('data-sec'); markPresets(); });
    });
    $id('adTestSeconds').addEventListener('input', markPresets);
    $id('adTestStart').addEventListener('click', function () {
        var sec = parseInt($id('adTestSeconds').value, 10);
        if (!(sec >= status.minSec && sec <= status.maxSec)) {
            toast('Pick a test length between ' + status.minSec + ' and ' + status.maxSec + ' seconds.', false);
            return;
        }
        busy(this, call('POST', 'action=start&duration=' + encodeURIComponent(sec))
            .then(function () { toast('Test started: heads closing.'); setTimeout(poll, 1500); })   // the loop needs about a second to move the heads
            .catch(function (e) { toast('Could not start the test: ' + e.message, false); }));
    });
    $id('adBannerTestStop').addEventListener('click', function () { $id('adTestStop').click(); });

    // Status and Test tabs; the last one used is remembered in this browser.
    function setTab(name, remember) {
        root.querySelectorAll('[data-tab]').forEach(function (b) {
            var on = b.getAttribute('data-tab') === name;
            b.classList.toggle('active', on); b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        root.querySelectorAll('[data-pane]').forEach(function (p) { hide(p, p.getAttribute('data-pane') !== name); });
        if (remember) { try { localStorage.setItem('airdefenderTab', name); } catch (e) { /* not kept */ } }
    }
    root.querySelectorAll('[data-tab]').forEach(function (b) {
        b.addEventListener('click', function () { setTab(b.getAttribute('data-tab'), true); });
    });
    try { if (localStorage.getItem('airdefenderTab') === 'test') { setTab('test', false); } } catch (e) { /* default tab */ }

    $id('adTestStop').addEventListener('click', function () {
        busy(this, call('POST', 'action=stop')
            .then(function () { toast('Test stopped.'); setTimeout(poll, 1500); })
            .catch(function (e) { toast('Could not stop the test: ' + e.message, false); }));
    });
    $id('adOverrideStart').addEventListener('click', function () {
        busy(this, call('POST', 'action=override&minutes=' + encodeURIComponent($id('adOverrideMin').value))
            .then(function () { toast('Tracking started.'); setTimeout(poll, 2500); })   // the loop checks within a couple of seconds
            .catch(function (e) { toast('Could not start tracking: ' + e.message, false); }));
    });
    $id('adOverrideEnd').addEventListener('click', function () {
        busy(this, call('POST', 'action=override_off')
            .then(function () { toast('Tracking stopped.'); setTimeout(poll, 2500); })
            .catch(function (e) { toast('Could not stop tracking: ' + e.message, false); }));
    });
    $id('adClearLog').addEventListener('click', function () {
        if (!window.confirm('Delete the Air Defender log? This cannot be undone.')) { return; }
        busy(this, call('POST', 'action=clear_log')
            .then(function () { toast('Log cleared.'); })
            .catch(function (e) { toast('Could not clear the log: ' + e.message, false); }));
    });

    /* ---------- View log ---------- */
    var logRange = 'today', logWhat = 'events';
    function loadLog() {
        var list = $id('adLogList');
        $id('adLogSummary').textContent = 'Loading…';
        list.textContent = '';
        fetch(API + '&log=' + logRange + '&what=' + logWhat, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var lines = d.lines || [];
                var when = { today: 'today', yesterday: 'yesterday', week: 'in the last 7 days' }[d.range] || '';
                $id('adLogSummary').textContent = !lines.length
                    ? (logWhat === 'events' ? 'Nothing happened ' + when + '.' : 'Nothing logged ' + when + '.')
                    : (d.closedCount ? 'Heads closed ' + d.closedCount + (d.closedCount === 1 ? ' time ' : ' times ') + when + '.'
                                     : 'Heads not closed for aircraft ' + when + '.')
                      + ' Newest first.' + (d.truncated ? ' Showing the newest ' + lines.length + ' lines.' : '');
                var lastDay = '';
                lines.forEach(function (l) {
                    var t = new Date(l.time * 1000);
                    var day = t.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' });
                    if (d.range === 'week' && day !== lastDay) {
                        var hr = document.createElement('tr'), th = document.createElement('th');
                        th.colSpan = 2; th.className = 'table-active'; th.textContent = day;
                        hr.appendChild(th); list.appendChild(hr);
                        lastDay = day;
                    }
                    var row = document.createElement('tr');
                    var tm = document.createElement('td'); tm.className = 'text-nowrap font-monospace text-body-secondary';
                    tm.textContent = t.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' });
                    var tx = document.createElement('td'); tx.className = 'text-break'
                        + (/^SAFETY ON/.test(l.text) ? ' text-warning-emphasis' : /^SAFETY OFF/.test(l.text) ? ' text-success-emphasis' : /^Test /.test(l.text) ? ' text-primary-emphasis' : '');
                    tx.textContent = l.text;
                    row.appendChild(tm); row.appendChild(tx); list.appendChild(row);
                });
            })
            .catch(function (e) { $id('adLogSummary').textContent = 'Could not read the log: ' + e.message; });
    }
    function logButtons(attr, value) {
        $id('adLogDlg').querySelectorAll('[' + attr + ']').forEach(function (b) {
            var on = b.getAttribute(attr) === value;
            b.classList.toggle('active', on);
            b.classList.toggle('text-body', !on);
        });
    }
    $id('adViewLog').addEventListener('click', function () {
        bootstrap.Modal.getOrCreateInstance($id('adLogDlg')).show();
        loadLog();
    });
    $id('adLogDlg').querySelectorAll('[data-range]').forEach(function (b) {
        b.addEventListener('click', function () { logRange = b.getAttribute('data-range'); logButtons('data-range', logRange); loadLog(); });
    });
    $id('adLogDlg').querySelectorAll('[data-what]').forEach(function (b) {
        b.addEventListener('click', function () { logWhat = b.getAttribute('data-what'); logButtons('data-what', logWhat); loadLog(); });
    });

    // A modal belongs at the top of the page, so nothing around the plugin's content clips it.
    document.body.appendChild($id('adLogDlg'));

    var saved = 'lite';
    try { saved = localStorage.getItem('airdefenderView') || 'lite'; } catch (e) {}
    markPresets();
    setView(saved, false);
})();
</script>
