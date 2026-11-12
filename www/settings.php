<?php
/*
 * Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
 * Licensed under the GNU GPL v2; see LICENSE.
 *
 * Air Defender's settings, built from FPP's own setting helpers (defined in
 * ../settings.json) so each one saves as it changes, the way FPP's settings
 * pages do. The moving heads, the playlist list and the Player's password
 * have no FPP helper, so they are drawn here and saved the same way: to
 * config/plugin.AirDefender through FPP's plugin settings API, except the
 * password, which lives in plugindata (see airdefender_secret.php).
 *
 * FPP includes this page at global scope, so every variable here is prefixed
 * airdefender, to leave FPP's own ($settings, $pluginSettings...) alone.
 */
require_once(dirname(__DIR__) . "/scripts/airdefender_models.php");
require_once(dirname(__DIR__) . "/scripts/airdefender_feeds.php");
require_once(dirname(__DIR__) . "/scripts/airdefender_adsb.php");
require_once(dirname(__DIR__) . "/scripts/airdefender_secret.php");
require_once(dirname(__DIR__) . "/scripts/airdefender_playlists.php");
require_once(dirname(__DIR__) . "/scripts/airdefender_version.php");

const AIRDEFENDER_PLUGIN_NAME = 'AirDefender';

global $pluginSettings;
if (!is_array($pluginSettings ?? null)) {
    $pluginSettings = array();
}

/* A saved Air Defender setting, or $default. */
function airdefender_page_setting(string $key, string $default = ''): string
{
    global $pluginSettings;
    return isset($pluginSettings[$key]) && (string) $pluginSettings[$key] !== '' ? (string) $pluginSettings[$key] : $default;
}

/*
 * A group of settings, laid out like FPP's own (PrintSettingGroup). $items are
 * setting names, or rows of our own (HTML, starting with '<') in among them.
 */
function airdefender_print_group(string $title, array $items, array $options = array()): void
{
    echo "<h2>" . htmlspecialchars($title) . "</h2>\n<div class='container-fluid settingsTable settingsGroupTable'>\n";
    foreach ($items as $item) {
        if ($item !== '' && $item[0] === '<') {
            echo $item;
        } else {
            PrintPluginSetting(AIRDEFENDER_PLUGIN_NAME, $item, 'airdefenderSettingSaved', $options[$item] ?? array());
        }
    }
    echo "</div><br>\n";
}

/* One row in FPP's settings layout: a label and a field. */
function airdefender_row(string $id, string $label, string $field, string $class = ''): string
{
    return "<div class='row" . ($class !== '' ? " {$class}" : '') . "' id='{$id}'>"
        . "<div class='printSettingLabelCol col-md-4 col-lg-3 col-xxxl-2'><div class='description'>"
        . "<i class='fas fa-fw fa-nbsp ui-level-0'></i>{$label}</div></div>"
        . "<div class='printSettingFieldCol col-md'>{$field}</div></div>\n";
}

$airdefenderModels = airdefender_fpp_models();
require_once(dirname(__DIR__) . "/scripts/airdefender_statefile.php");
$airdefenderState = airdefender_state_read();
$airdefenderLastKnown = is_array($airdefenderState['lastKnownChannels'] ?? null) ? $airdefenderState['lastKnownChannels'] : array();

// Until a role is picked it follows FPP's Player Mode, so show that one.
$airdefenderFppRole = airdefender_fpp_role();
if (airdefender_page_setting('nodeRole') === '') {
    $pluginSettings['nodeRole'] = $airdefenderFppRole;
}
$airdefenderIsRemote = airdefender_page_setting('nodeRole') === 'remote';

// The services, as FPP's select helper takes them: label => value.
$airdefenderFeedOptions = array_flip(airdefender_feed_options());
list($airdefenderFppLat, $airdefenderFppLon) = airdefender_fpp_location();
$airdefenderHasPassword = airdefender_master_password() !== '';

/* ---------- rows of our own ---------- */

$airdefenderRoleRows = airdefender_row('adRoleNoteRow', '', "<span id='adRoleNote' class='small'></span>");
$airdefenderPasswordRow = airdefender_row('masterPasswordRow', "Player's UI password",
        "<div class='d-flex flex-wrap align-items-center gap-2'>"
        . "<input type='password' id='adMasterPassword' class='mw-100' size='24' maxlength='64' autocomplete='new-password' placeholder='"
        . ($airdefenderHasPassword ? 'Saved: type to change' : 'Only if the Player has one') . "'>"
        . "<button type='button' class='buttons' id='adPasswordSave'>Save password</button>"
        . "<button type='button' class='buttons" . ($airdefenderHasPassword ? '' : ' d-none') . "' id='adPasswordRemove'>Remove</button>"
        . "</div><div class='form-text'>Only needed if the Player FPP has a UI password (Settings &rarr; UI on the Player). "
        . "The Remote logs in as admin with it to read the Player's status, on your local network. Kept readable only by FPP, outside its config folder.</div>");
$airdefenderRemoteNote = "<div class='row' id='adRemoteNote'><div class='col'><div class='alert alert-info mt-2 mb-0'>"
    . "On a Remote, when to check, the location, the limits and the flight data are set on the <b>Player</b>: this FPP follows it. "
    . "Set up this Remote's own moving heads on the <b>Moving heads</b> tab.</div></div></div>";

$airdefenderSaved = airdefender_decode_playlists(airdefender_page_setting('checkPlaylists'));
$airdefenderFppPlaylists = airdefender_fpp_playlists();
$airdefenderAllPlaylists = $airdefenderFppPlaylists ?? array();
// Keep ticked ones FPP no longer has, so they are not dropped unseen.
$airdefenderListed = array_merge($airdefenderAllPlaylists, array_values(array_diff($airdefenderSaved, $airdefenderAllPlaylists)));
ob_start();
if ($airdefenderFppPlaylists === null) {
    echo "<div class='text-warning-emphasis'>Could not get the playlist list from FPP right now. Reload the page to try again.</div>";
} elseif (!$airdefenderListed) {
    echo "<div class='text-body-secondary'>No playlists in FPP yet.</div>";
} else {
    // A plain list like FPP's own settings; only a long list gets a scrolling box, so a few playlists don't sit in a big empty frame.
    $airdefenderLong = count($airdefenderListed) > 12 ? ' airdefender-playlists-long border rounded p-2' : '';
    echo "<div class='airdefender-playlists{$airdefenderLong}' role='group' aria-label='Playlists Air Defender checks during'>";
    foreach ($airdefenderListed as $airdefenderIdx => $airdefenderPl) {
        $airdefenderId = 'adPl' . $airdefenderIdx;
        echo "<div class='form-check'><input class='form-check-input' type='checkbox' id='{$airdefenderId}' value='" . htmlspecialchars($airdefenderPl, ENT_QUOTES) . "'"
            . (in_array($airdefenderPl, $airdefenderSaved, true) ? ' checked' : '') . ">"
            . "<label class='form-check-label' for='{$airdefenderId}'>" . htmlspecialchars($airdefenderPl)
            . (in_array($airdefenderPl, $airdefenderAllPlaylists, true) ? '' : " <span class='text-warning-emphasis'>(not in FPP)</span>")
            . "</label></div>";
    }
    echo "</div>";
}
echo "<div class='form-text' id='adPlaylistHelp'></div>";
$airdefenderPlaylistRow = airdefender_row('checkPlaylistsRow', 'Playlists to check during', ob_get_clean());

$airdefenderFppLocRow = airdefender_row('adFppLocRow', "FPP's location",
        "<span id='adFppLoc'>" . ($airdefenderFppLat !== null ? htmlspecialchars("{$airdefenderFppLat}, {$airdefenderFppLon}") : "<span class='text-warning-emphasis'>Not set in FPP's settings</span>") . "</span>");
$airdefenderMapRow = airdefender_row('adMapRow', '', "<span class='form-text' id='adMapHelp'></span>");
$airdefenderElevRow = airdefender_row('adElevRow', '',
        "<button type='button' class='buttons' id='adElevLookup'>Look it up</button> "
        . "<span class='form-text' id='adElevHelp'>Asks Open-Meteo (Copernicus elevation data) for the ground's height at the show's location.</span>");

$airdefenderFeedRows = airdefender_row('adFeedTestRow', 'Test',
    "<div class='d-flex flex-wrap align-items-center gap-2'><button type='button' class='buttons' id='adFeedTest'>Test connection</button>"
    . "<span id='adFeedTestOut'></span></div><div class='form-text'>Asks the service once now, with the location and limits saved here.</div>");
?>

<div class="airdefender-settings">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
        <div class="text-body-secondary">Version <?php echo htmlspecialchars(airdefender_version()); ?> &middot; changes save as you make them
            &middot; <a href="https://github.com/G-Hancox/AirDefender/issues/new/choose" target="_blank" rel="noopener">Request a service or feature</a></div>
        <a class="buttons" href="plugin.php?plugin=AirDefender&amp;page=www/index.php"><i class="fas fa-fw fa-satellite-dish"></i> Dashboard</a>
    </div>

    <ul class="nav nav-pills pageContent-tabs" id="adTabs" role="tablist">
        <li class="nav-item"><a class="nav-link active" id="ad-general-tab" data-bs-toggle="tab" href="#ad-general" role="tab" aria-controls="ad-general" aria-selected="true">General</a></li>
        <li class="nav-item"><a class="nav-link" id="ad-heads-tab" data-bs-toggle="tab" href="#ad-heads" role="tab" aria-controls="ad-heads" aria-selected="false">Moving heads</a></li>
        <li class="nav-item ad-player-only"><a class="nav-link" id="ad-when-tab" data-bs-toggle="tab" href="#ad-when" role="tab" aria-controls="ad-when" aria-selected="false">When &amp; where</a></li>
        <li class="nav-item ad-player-only"><a class="nav-link" id="ad-limits-tab" data-bs-toggle="tab" href="#ad-limits" role="tab" aria-controls="ad-limits" aria-selected="false">Limits</a></li>
        <li class="nav-item ad-player-only"><a class="nav-link" id="ad-feed-tab" data-bs-toggle="tab" href="#ad-feed" role="tab" aria-controls="ad-feed" aria-selected="false">Flight data</a></li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="ad-general" role="tabpanel" aria-labelledby="ad-general-tab">
            <?php airdefender_print_group('Air Defender', array('enabled', 'nodeRole', $airdefenderRoleRows, 'masterAddress', $airdefenderPasswordRow, $airdefenderRemoteNote)); ?>
        </div>

        <div class="tab-pane fade" id="ad-heads" role="tabpanel" aria-labelledby="ad-heads-tab">
            <h2>Moving heads</h2>
            <p class="text-body-secondary">
                Pick each head's <b>model</b>, or choose <b>Manual channel</b> and enter its <b>start channel</b> from xLights.
                <b>Shutter ch.</b> is the shutter's channel in the fixture's DMX chart (for example 7), and <b>Closed</b> the value
                that closes it. Air Defender works out the FPP channel.
            </p>
            <?php
            // Only a warning when a head is set to a model; heads on manual channels don't need FPP's model list.
            $airdefenderUsesModels = false;
            for ($n = 1; $n <= AIRDEFENDER_HEADS; $n++) {
                $airdefenderUsesModels = $airdefenderUsesModels || airdefender_page_setting("head{$n}Model") !== '';
            }
            $airdefenderModelNote = $airdefenderUsesModels ? 'alert alert-warning' : 'form-text mb-2';
            ?>
            <?php if ($airdefenderModels === null): ?>
                <div class="<?php echo $airdefenderModelNote; ?>">Could not get FPP's model list right now; Manual channel still works, and each head keeps the channel it last had.</div>
            <?php elseif (!$airdefenderModels): ?>
                <div class="<?php echo $airdefenderModelNote; ?>">No models in FPP yet, so only <b>Manual channel</b> is offered. To pick heads by name, upload from xLights with FPP Connect's <b>Models</b> column set to <b>All</b>, or add them on FPP's Pixel Overlay Models page.</div>
            <?php endif; ?>
            <div class="table-responsive position-relative">
                <table class="table table-sm align-middle airdefender-heads" id="adHeads">
                    <thead><tr>
                        <th scope="col">On</th><th scope="col">Name</th><th scope="col">Model</th>
                        <th scope="col" title="The head's first FPP channel, from xLights">Start ch.</th>
                        <th scope="col" title="The shutter's channel in the fixture's DMX chart">Shutter ch.</th>
                        <th scope="col" title="The value that closes the shutter">Closed</th>
                        <th scope="col">FPP channel</th><th scope="col"><span class="visually-hidden">Remove</span></th>
                    </tr></thead>
                    <tbody>
                    <?php for ($n = 1; $n <= AIRDEFENDER_HEADS; $n++):
                        $model = airdefender_page_setting("head{$n}Model");
                        $missing = $model !== '' && is_array($airdefenderModels) && !isset($airdefenderModels[$model]);
                        $start = (int) airdefender_page_setting("head{$n}Start", '0');
                        $offset = max(1, (int) airdefender_page_setting("head{$n}Offset", '1'));
                        $enabled = airdefender_page_setting("head{$n}Enabled", '0') === '1';
                        $name = airdefender_page_setting("head{$n}Name");
                        $channel = (int) airdefender_page_setting("head{$n}Channel", '0');
                        if ($model !== '' && !empty($airdefenderLastKnown[$n])) {
                            $channel = (int) $airdefenderLastKnown[$n];
                        }
                        $shown = $enabled || $name !== '' || $model !== '' || $start > 0 || $n === 1;
                    ?>
                        <tr data-head="<?php echo $n; ?>"<?php echo $shown ? '' : ' class="d-none"'; ?> data-channel="<?php echo $channel; ?>">
                            <td><input class="form-check-input" type="checkbox" data-field="Enabled" aria-label="Protect head <?php echo $n; ?>"<?php echo $enabled ? ' checked' : ''; ?>></td>
                            <td><input type="text" class="form-control form-control-sm" data-field="Name" value="<?php echo htmlspecialchars($name, ENT_QUOTES); ?>" maxlength="40" placeholder="MH<?php echo $n; ?>" aria-label="Head <?php echo $n; ?> name"></td>
                            <td>
                                <select class="form-select form-select-sm" data-field="Model" aria-label="Head <?php echo $n; ?> model">
                                    <option value=""<?php echo $model === '' ? ' selected' : ''; ?>>Manual channel</option>
                                    <?php if ($missing): ?><option value="<?php echo htmlspecialchars($model, ENT_QUOTES); ?>" selected><?php echo htmlspecialchars($model); ?> (not found)</option><?php endif; ?>
                                    <?php foreach ((array) $airdefenderModels as $mName => $mInfo): ?>
                                        <option value="<?php echo htmlspecialchars((string) $mName, ENT_QUOTES); ?>"<?php echo $model === (string) $mName ? ' selected' : ''; ?>><?php echo htmlspecialchars((string) $mName); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="number" class="form-control form-control-sm" data-field="Start" value="<?php echo $start > 0 ? $start : ''; ?>" data-manual="<?php echo $start > 0 ? $start : ''; ?>" min="1" max="<?php echo AIRDEFENDER_MAX_CHANNEL; ?>" step="1" aria-label="Head <?php echo $n; ?> start channel"></td>
                            <td><input type="number" class="form-control form-control-sm" data-field="Offset" value="<?php echo $offset; ?>" min="1" max="512" step="1" aria-label="Head <?php echo $n; ?> shutter channel in the fixture"></td>
                            <td><input type="number" class="form-control form-control-sm" data-field="ClosedValue" value="<?php echo max(0, min(255, (int) airdefender_page_setting("head{$n}ClosedValue", '0'))); ?>" min="0" max="255" step="1" aria-label="Head <?php echo $n; ?> closed value"></td>
                            <td class="small" data-result></td>
                            <td><button type="button" class="btn btn-sm btn-outline-danger" data-remove title="Remove this head" aria-label="Remove head <?php echo $n; ?>"><i class="fas fa-trash"></i></button></td>
                        </tr>
                    <?php endfor; ?>
                    </tbody>
                </table>
            </div>
            <button type="button" class="buttons" id="adAddHead"><i class="fas fa-plus"></i> Add head</button>
            <div class="form-text mt-2">
                Head not in the model list? In FPP Connect, set the <b>Models</b> column to <b>All</b> (Local leaves out heads on other controllers) and upload again.
            </div>
        </div>

        <div class="tab-pane fade" id="ad-when" role="tabpanel" aria-labelledby="ad-when-tab">
            <?php airdefender_print_group('When to check', array('checkWhen', $airdefenderPlaylistRow, 'checkAnyPlaying')); ?>
            <?php airdefender_print_group('Where the show is', array('useFPPLocation', $airdefenderFppLocRow, 'latitude', 'longitude', $airdefenderMapRow,
                'showElevationFt', $airdefenderElevRow)); ?>
        </div>

        <div class="tab-pane fade" id="ad-limits" role="tabpanel" aria-labelledby="ad-limits-tab">
            <?php airdefender_print_group('Safety limits', array('distanceNM', 'altitudeThresholdFt',
                airdefender_row('adLimitsRow', '', "<span class='form-text' id='adLimitsHelp'></span>"), 'clearDelaySec', 'fetchInterval')); ?>
        </div>

        <div class="tab-pane fade" id="ad-feed" role="tabpanel" aria-labelledby="ad-feed-tab">
            <?php airdefender_print_group('Flight data', array('adsbSourceUrl', 'receiverAddress', 'feedFallback', 'feedDownSafe', $airdefenderFeedRows),
                array('adsbSourceUrl' => $airdefenderFeedOptions)); ?>
        </div>
    </div>
</div>

<style>
    /* The playlist list scrolls inside its own box rather than stretching the page. */
    .airdefender-playlists { columns: 14rem auto; }
    .airdefender-playlists-long { max-height: 18rem; overflow-y: auto; }
    /* FPP draws big checkboxes: give each row their height and the label room beside it. */
    .airdefender-playlists .form-check { break-inside: avoid; display: flex; align-items: center; gap: .5rem; min-height: 2rem; padding-left: 0; margin-bottom: .25rem; }
    .airdefender-playlists .form-check .form-check-input { float: none; margin: 0; flex-shrink: 0; }
    /* Keep the head table's inputs usable on a phone: the table scrolls sideways in its own box. */
    .airdefender-heads { min-width: 46rem; }
    .airdefender-heads input[data-field="Name"], .airdefender-heads select { min-width: 8rem; }
    .airdefender-heads input[type="number"] { min-width: 5.5rem; }
</style>

<script>
(function () {
    var PLUGIN = 'AirDefender';
    var API = 'plugin.php?plugin=AirDefender&page=www/test_api.php&nopage=1';
    var models = <?php echo json_encode(is_array($airdefenderModels) ? $airdefenderModels : new stdClass()); ?>;
    var modelsKnown = <?php echo $airdefenderModels === null ? 'false' : 'true'; ?>;
    var MAX_CH = <?php echo AIRDEFENDER_MAX_CHANNEL; ?>;
    var FPP_ROLE = <?php echo json_encode($airdefenderFppRole); ?>;
    var FPP_LOC = <?php echo json_encode($airdefenderFppLat !== null ? array('lat' => $airdefenderFppLat, 'lon' => $airdefenderFppLon) : null); ?>;
    var $id = function (id) { return document.getElementById(id); };

    function setting(key, fallback) {
        var v = (typeof pluginSettings !== 'undefined' && pluginSettings[key] !== undefined) ? String(pluginSettings[key]) : '';
        return v === '' ? fallback : v;
    }
    // jGrowl shows its text as HTML: names come from the user's own heads, so they go in as plain text.
    function toast(text, ok) { var t = document.createElement('div'); t.textContent = text; $.jGrowl(t.innerHTML, { themeState: ok === false ? 'danger' : 'success' }); }

    /* Save one setting the way FPP's helpers do (config/plugin.AirDefender), with a readable message. */
    function save(key, value, label) {
        // Sent as plain text, so jQuery leaves it exactly as it is (it turns %20 into + in form data).
        return $.ajax({ url: 'api/plugin/' + PLUGIN + '/settings/' + key, data: '' + value, method: 'PUT', timeout: 30000,
                        processData: false, contentType: 'text/plain; charset=UTF-8' })
            .done(function () { pluginSettings[key] = '' + value; if (label) { toast(label + ' saved.'); } })
            .fail(function () { toast('Could not save ' + (label || key) + '. Check the FPP logs.', false); });
    }
    function post(fields) {
        return fetch(API, { method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(fields).toString() })
            .then(function (r) { return r.json().then(function (d) { if (!r.ok) { throw new Error(d.error || ('HTTP ' + r.status)); } return d; }); });
    }

    /* ---------- General ---------- */
    function role() { return $id('nodeRole') ? $id('nodeRole').value : 'master'; }
    function updateRole() {
        var remote = role() === 'remote';
        document.querySelectorAll('.ad-player-only').forEach(function (el) { el.classList.toggle('d-none', remote); });
        $id('adRemoteNote').classList.toggle('d-none', !remote);
        var note = $id('adRoleNote'), label = FPP_ROLE === 'remote' ? 'Remote' : 'Player';
        note.className = 'small ' + (role() === FPP_ROLE ? 'text-body-secondary' : 'text-warning-emphasis');
        note.textContent = role() === FPP_ROLE ? 'Matches FPP’s Player Mode (' + label + ').'
            : 'FPP is in ' + label + ' mode, but Air Defender is set to ' + (remote ? 'Remote' : 'Player') + '.';
    }
    $id('adPasswordSave').addEventListener('click', function () {
        var pw = $id('adMasterPassword').value;
        if (pw === '') { toast('Type the Player’s password first.', false); return; }
        post({ action: 'set_password', password: pw }).then(function () {
            $id('adMasterPassword').value = '';
            $id('adMasterPassword').placeholder = 'Saved: type to change';
            $id('adPasswordRemove').classList.remove('d-none');
            toast('Player’s password saved.');
        }).catch(function (e) { toast(e.message, false); });
    });
    $id('adPasswordRemove').addEventListener('click', function () {
        post({ action: 'set_password', password: '' }).then(function () {
            $id('adMasterPassword').placeholder = 'Only if the Player has one';
            $id('adPasswordRemove').classList.add('d-none');
            toast('Player’s password removed.');
        }).catch(function (e) { toast(e.message, false); });
    });

    /* ---------- Moving heads ---------- */
    var rows = Array.prototype.slice.call(document.querySelectorAll('#adHeads tr[data-head]'));
    function field(row, f) { return row.querySelector('[data-field="' + f + '"]'); }
    function key(row, f) { return 'head' + row.getAttribute('data-head') + f; }
    function label(row) { return field(row, 'Name').value.trim() || 'MH' + row.getAttribute('data-head'); }

    // Works out the head's FPP channel and shows it: [channel or 0, text, Bootstrap text class].
    function resolve(row) {
        var model = field(row, 'Model').value, start = field(row, 'Start'), off = parseInt(field(row, 'Offset').value, 10);
        var known = modelsKnown && Object.prototype.hasOwnProperty.call(models, model);
        if (model === '') {
            if (start.disabled) { start.value = start.getAttribute('data-manual') || ''; }
            start.disabled = false;
            var st = parseInt(start.value, 10);
            if (!(st >= 1)) { return [0, 'Enter the start channel', 'text-warning-emphasis']; }
            if (!(off >= 1)) { return [0, 'Enter the shutter channel', 'text-warning-emphasis']; }
            if (st + off - 1 > MAX_CH) { return [0, 'Past FPP’s last channel', 'text-danger']; }
            return [st + off - 1, 'ch ' + (st + off - 1), 'text-success-emphasis'];
        }
        if (!start.disabled) { start.setAttribute('data-manual', start.value); }
        start.value = known ? models[model].start : '';
        start.disabled = true;
        var last = parseInt(row.getAttribute('data-channel'), 10) || 0;
        if (!modelsKnown) { return [last, 'Model list unavailable' + (last ? ' (ch ' + last + ')' : ''), 'text-warning-emphasis']; }
        if (!known) { return [last, 'Model not found: pick it again' + (last ? ' (still protecting ch ' + last + ')' : ''), 'text-danger']; }
        if (!(off >= 1)) { return [0, 'Enter the shutter channel', 'text-warning-emphasis']; }
        if (off > models[model].count) { return [0, 'Past the end of this model (' + models[model].count + ' ch)', 'text-danger']; }
        return [models[model].start + off - 1, 'ch ' + (models[model].start + off - 1), 'text-success-emphasis'];
    }
    function showRow(row) {
        var r = resolve(row), on = field(row, 'Enabled').checked, cell = row.querySelector('[data-result]');
        cell.textContent = on ? r[1] : 'Off';
        cell.className = 'small ' + (on ? r[2] : 'text-body-secondary');
        return r;
    }
    // Saves the channel the head now resolves to, as its last known channel.
    function saveChannel(row) {
        var r = resolve(row);
        if (r[0] > 0 && String(r[0]) !== setting(key(row, 'Channel'), '')) {
            row.setAttribute('data-channel', r[0]);
            save(key(row, 'Channel'), r[0]);
        }
    }
    function updateAdd() { $id('adAddHead').classList.toggle('d-none', !rows.some(function (r) { return r.classList.contains('d-none'); })); }

    rows.forEach(function (row) {
        showRow(row);
        field(row, 'Enabled').addEventListener('change', function () {
            save(key(row, 'Enabled'), this.checked ? 1 : 0, label(row) + (this.checked ? ' on' : ' off'));
            showRow(row);
        });
        field(row, 'Name').addEventListener('change', function () { save(key(row, 'Name'), this.value.trim(), label(row) + '’s name'); });
        field(row, 'Model').addEventListener('change', function () {
            var sel = this, name = field(row, 'Name'), prev = setting(key(row, 'Model'), '');
            // A head named after its old model takes the new model's name.
            if (sel.value !== '' && (name.value.trim() === '' || name.value.trim() === prev)) {
                name.value = sel.value;
                save(key(row, 'Name'), sel.value);
            }
            save(key(row, 'Model'), sel.value, label(row) + '’s model');
            showRow(row); saveChannel(row);
        });
        ['Start', 'Offset', 'ClosedValue'].forEach(function (f) {
            field(row, f).addEventListener('change', function () {
                var v = parseInt(this.value, 10);
                var lo = parseInt(this.min, 10), hi = parseInt(this.max, 10);
                if (isNaN(v) || v < lo || v > hi) {
                    toast(label(row) + ': enter ' + lo + ' to ' + hi + '.', false);
                    this.value = setting(key(row, f), f === 'Offset' ? '1' : (f === 'ClosedValue' ? '0' : ''));
                    showRow(row);
                    return;
                }
                if (f === 'Start') { this.setAttribute('data-manual', v); }
                save(key(row, f), v, label(row) + ({ Start: '’s start channel', Offset: '’s shutter channel', ClosedValue: '’s closed value' })[f]);
                showRow(row); saveChannel(row);
            });
            field(row, f).addEventListener('input', function () { showRow(row); });
        });
        row.querySelector('[data-remove]').addEventListener('click', function () {
            if (!window.confirm('Remove ' + label(row) + '? Air Defender stops protecting it.')) { return; }
            var name = label(row);
            var blank = { Enabled: 0, Name: '', Model: '', Start: 0, Offset: 1, ClosedValue: 0, Channel: 0 };
            Object.keys(blank).forEach(function (f) { save(key(row, f), blank[f]); });
            field(row, 'Enabled').checked = false; field(row, 'Name').value = ''; field(row, 'Model').value = '';
            field(row, 'Start').disabled = false; field(row, 'Start').value = ''; field(row, 'Start').setAttribute('data-manual', '');
            field(row, 'Offset').value = 1; field(row, 'ClosedValue').value = 0; row.setAttribute('data-channel', 0);
            if (rows.filter(function (r) { return !r.classList.contains('d-none'); }).length > 1) { row.classList.add('d-none'); }
            showRow(row); updateAdd();
            toast(name + ' removed.');
        });
    });
    $id('adAddHead').addEventListener('click', function () {
        var row = rows.filter(function (r) { return r.classList.contains('d-none'); })[0];
        if (!row) { return; }
        row.classList.remove('d-none');
        field(row, 'Enabled').checked = true;
        save(key(row, 'Enabled'), 1);
        showRow(row); updateAdd();
        field(row, 'Name').focus();
    });
    updateAdd();

    /* ---------- When & where ---------- */
    var plBoxes = Array.prototype.slice.call(document.querySelectorAll('.airdefender-playlists input[type=checkbox]'));
    function updatePlaylists() {
        var ticked = plBoxes.filter(function (b) { return b.checked; }).length, help = $id('adPlaylistHelp');
        help.className = 'form-text' + (ticked ? '' : ' text-warning-emphasis');
        help.textContent = ticked
            ? 'Using Remote Falcon? Tick its playlist too, so viewer requests are covered. A sequence started on its own isn’t in a playlist: use Manual tracking on the dashboard's Test tab.'
            : 'Nothing ticked, so it works like All scheduled shows. Tick the playlists with your moving heads.';
    }
    plBoxes.forEach(function (b) {
        b.addEventListener('change', function () {
            var names = plBoxes.filter(function (x) { return x.checked; }).map(function (x) { return encodeURIComponent(x.value); });
            save('checkPlaylists', names.join(','), 'Playlists');
            updatePlaylists();
        });
    });

    function useFpp() { return $id('useFPPLocation') && $id('useFPPLocation').checked; }
    function showLoc() {
        if (useFpp()) { return FPP_LOC; }
        var lat = parseFloat($id('latitude').value), lon = parseFloat($id('longitude').value);
        return isNaN(lat) || isNaN(lon) || Math.abs(lat) > 90 || Math.abs(lon) > 180 ? null : { lat: lat, lon: lon };
    }
    function updateLocation() {
        var fpp = useFpp();
        $id('adFppLocRow').classList.toggle('d-none', !fpp);
        $id('latitudeRow').classList.toggle('d-none', fpp);
        $id('longitudeRow').classList.toggle('d-none', fpp);
        var loc = showLoc(), help = $id('adMapHelp');
        help.textContent = '';
        if (!loc) {
            help.className = 'form-text text-warning-emphasis';
            help.textContent = fpp ? 'FPP has no location set: set it in FPP’s settings, or turn off Use FPP’s location and enter one.'
                : 'Enter the show’s latitude and longitude.';
            return;
        }
        help.className = 'form-text';
        help.appendChild(document.createTextNode(fpp ? 'FPP’s location can be miles off. Make sure the pin is on your show: ' : 'Make sure the pin is on your show: '));
        var a = document.createElement('a');
        a.href = 'https://www.openstreetmap.org/?mlat=' + loc.lat + '&mlon=' + loc.lon + '#map=15/' + loc.lat + '/' + loc.lon;
        a.target = '_blank'; a.rel = 'noopener'; a.textContent = 'Check it on a map';
        help.appendChild(a);
    }
    $id('adElevLookup').addEventListener('click', function () {
        var btn = this, loc = showLoc(), help = $id('adElevHelp');
        if (!loc) { toast('Set the show’s location first.', false); return; }
        btn.disabled = true;
        help.className = 'form-text'; help.textContent = 'Looking it up…';
        post({ action: 'elevation', lat: loc.lat, lon: loc.lon }).then(function (d) {
            if (!d.ok) { throw new Error(d.error || 'No answer'); }
            $id('showElevationFt').value = d.feet;
            showElevationFtChanged();   // FPP's helper saves it
            help.className = 'form-text text-success-emphasis';
            help.textContent = 'Found ' + Number(d.feet).toLocaleString() + ' ft. Elevation data: Open-Meteo, Copernicus DEM.';
        }).catch(function (e) {
            help.className = 'form-text text-warning-emphasis'; help.textContent = 'Could not look it up: ' + e.message;
        }).then(function () { btn.disabled = false; updateLimits(); });
    });

    /* ---------- Limits ---------- */
    function updateLimits() {
        var e = parseInt($id('showElevationFt').value, 10) || 0, ft = parseFloat($id('altitudeThresholdFt').value);
        $id('adLimitsHelp').textContent = e !== 0 && ft >= 0
            ? 'Heads close when an aircraft is inside the distance and less than ' + Math.round(ft).toLocaleString()
              + ' ft above your show (below ' + Math.round(ft + e).toLocaleString() + ' ft above sea level).'
            : 'Heads close when an aircraft is inside the distance and below the altitude (set Show elevation on When & where to count it from your ground).';
    }

    /* ---------- Flight data ---------- */
    $id('adFeedTest').addEventListener('click', function () {
        var btn = this, out = $id('adFeedTestOut'), loc = showLoc();
        if (!loc) { out.className = 'text-warning-emphasis'; out.textContent = 'Set the show’s location first (When & where).'; return; }
        btn.disabled = true; out.className = ''; out.textContent = 'Asking…';
        post({ action: 'feed_test', url: $id('adsbSourceUrl').value, receiver: $id('receiverAddress').value,
               lat: loc.lat, lon: loc.lon, nm: $id('distanceNM').value, ft: $id('altitudeThresholdFt').value,
               elev: parseInt($id('showElevationFt').value, 10) || 0 }).then(function (d) {
            if (!d.ok) { throw new Error(d.error || 'No answer'); }
            out.className = 'text-success-emphasis';
            out.textContent = 'OK · ' + d.aircraft + ' aircraft nearby'
                + (d.nearest ? ' · nearest ' + d.nearest.id + ' at ' + d.nearest.nm + ' NM' + (d.nearest.ft !== null ? ', ' + d.nearest.ft.toLocaleString() + ' ft' : '') : '')
                + (d.inside ? ' · ' + d.inside + ' inside your limits' : '') + ' (' + d.ms + ' ms)' + (d.foundAt ? ' · reading ' + d.foundAt : '');
        }).catch(function (e) { out.className = 'text-danger'; out.textContent = e.message; })
          .then(function () { btn.disabled = false; });
    });

    /* Called by FPP's setting helpers after each save. */
    window.airdefenderSettingSaved = function () { refresh(); };

    function refresh() { updateRole(); updateLocation(); updateLimits(); }

    // Come back to the tab you were on.
    try {
        var tab = localStorage.getItem('airdefenderSettingsTab');
        var link = tab && document.querySelector('#adTabs a[href="' + tab + '"]');
        if (link && !link.closest('.d-none') && window.bootstrap) { bootstrap.Tab.getOrCreateInstance(link).show(); }
    } catch (e) {}
    document.querySelectorAll('#adTabs a').forEach(function (a) {
        a.addEventListener('shown.bs.tab', function () { try { localStorage.setItem('airdefenderSettingsTab', a.getAttribute('href')); } catch (e) {} });
    });
    ['latitude', 'longitude', 'showElevationFt', 'altitudeThresholdFt'].forEach(function (id) {
        if ($id(id)) { $id(id).addEventListener('input', refresh); }
    });
    if ($id('useFPPLocation')) { $id('useFPPLocation').addEventListener('change', refresh); }
    if ($id('nodeRole')) { $id('nodeRole').addEventListener('change', refresh); }
    // Check every is 5 to 15 seconds: put an older setting, or a number typed outside that, back in range and save it.
    (function () {
        var f = $id('fetchInterval');
        if (!f) { return; }
        function clamp() {
            var v = parseInt(f.value, 10), c = Math.min(15, Math.max(5, isNaN(v) ? 10 : v));
            if (String(c) !== f.value) {
                f.value = c;
                if (typeof window.fetchIntervalChanged === 'function') { window.fetchIntervalChanged(); }   // FPP's helper saves it
            }
        }
        f.addEventListener('change', clamp);
        clamp();
    })();
    updatePlaylists();
    refresh();
})();
</script>
