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
        <li class="nav-item"><a class="nav-link" id="ad-dz-tab" data-bs-toggle="tab" href="#ad-dz" role="tab" aria-controls="ad-dz" aria-selected="false">DeadZones</a></li>
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
                        $source = $model === '' ? airdefender_page_setting("head{$n}Source") : '';   // 'xlights': start and shutter came from xLights' files
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
                            <td class="text-nowrap"><span class="btn btn-sm btn-outline-secondary text-body airdefender-grip d-none me-1" data-grip title="Drag to move" aria-hidden="true"><i class="fas fa-grip-lines"></i></span><input class="form-check-input" type="checkbox" data-field="Enabled" aria-label="Protect head <?php echo $n; ?>"<?php echo $enabled ? ' checked' : ''; ?>></td>
                            <td><input type="text" class="form-control form-control-sm" data-field="Name" value="<?php echo htmlspecialchars($name, ENT_QUOTES); ?>" maxlength="40" placeholder="MH<?php echo $n; ?>" aria-label="Head <?php echo $n; ?> name"></td>
                            <td>
                                <?php $airdefenderOnlyManual = !$airdefenderModels && !$missing && $source !== 'xlights'; ?>
                                <select class="form-select form-select-sm" data-field="Model" aria-label="Head <?php echo $n; ?> model"<?php echo $airdefenderOnlyManual
                                    ? ' disabled data-only-manual title="FPP has no models yet, so this head uses its start channel. To pick heads by name, upload from xLights with FPP Connect\'s Models column set to All."' : ''; ?>>
                                    <?php if ($source === 'xlights'): ?><option value="@xlights" selected>Imported</option><?php endif; ?>
                                    <option value=""<?php echo $model === '' && $source !== 'xlights' ? ' selected' : ''; ?>>Manual channel</option>
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
                            <td class="text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-danger" data-remove title="Remove this head" aria-label="Remove head <?php echo $n; ?>"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                    <?php endfor; ?>
                    </tbody>
                </table>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <button type="button" class="buttons" id="adAddHead"><i class="fas fa-plus"></i> Add head</button>
                <button type="button" class="buttons" id="adReorder" aria-pressed="false"><i class="fas fa-lock"></i> Reorder</button>
                <button type="button" class="buttons" id="adMhXlImport"><i class="fas fa-file-import"></i> Import models from xLights</button>
                <input type="file" class="d-none" id="adMhXlFile" accept=".xml,text/xml" multiple>
                <input type="file" class="d-none" id="adMhXlDir" webkitdirectory>
            </div>
            <div class="form-text" id="adMhXlHelp">For <b>Import models from xLights</b>, pick your xLights <b>show folder</b> (in xLights, <b>File &rarr; Show Folder</b>
                shows where it is). Your browser may ask to "upload" it: nothing leaves this computer, the page only reads xLights' two setup files from it.</div>
            <div class="modal fade" id="adMhXlBusy" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-labelledby="adMhXlBusyTitle" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered airdefender-popup">
                    <div class="modal-content">
                        <div class="modal-body text-center py-4" role="status">
                            <div class="spinner-border text-primary mb-3" aria-hidden="true"></div>
                            <h5 class="mb-2" id="adMhXlBusyTitle">Reading your show folder&hellip;</h5>
                            <div class="form-text mb-3">Your browser is going through the folder. A big one (lots of sequences, music and backups) can take up to a minute.
                                If your browser asks whether to upload the folder, say yes: nothing leaves this computer.</div>
                            <button type="button" class="btn btn-sm btn-outline-secondary text-body" id="adMhXlBusyCancel">Cancel</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal fade" id="adMhXlProblem" tabindex="-1" aria-labelledby="adMhXlProblemTitle" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered airdefender-popup">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="adMhXlProblemTitle"><i class="fas fa-exclamation-triangle text-warning"></i> Import failed</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p id="adMhXlProblemText" role="alert"></p>
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-outline-secondary text-body" id="adMhXlAgain"><i class="fas fa-folder-open"></i> Pick folder again</button>
                                <button type="button" class="btn btn-outline-secondary text-body" id="adMhXlFilesInstead"><i class="fas fa-file"></i> Pick the files instead</button>
                                <button type="button" class="btn btn-primary" id="adMhXlByHand"><i class="fas fa-keyboard"></i> Enter heads by hand</button>
                            </div>
                            <p class="form-text mt-2 mb-0">By hand: <b>Add head</b>, then its name, start channel, shutter channel and closed value.</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="d-none mt-2 p-2 border rounded" id="adMhXlPick">
                <div class="form-text mt-0 mb-2">Moving heads in your xLights show. Tick the ones to protect and press <b>Add heads</b>: each gets its name,
                    its shutter channel and its DeadZones pan and tilt. Its channel comes from FPP's model with the same name when FPP has one; otherwise from
                    its xLights start channel. A head already here with the same name is updated instead of added twice.</div>
                <div class="alert alert-warning d-none py-2 mb-2" id="adMhXlNoNet">Couldn't find <b>xlights_networks.xml</b> (xLights' controller list), so heads whose
                    start channel is on a controller can't be worked out.
                    <button type="button" class="btn btn-sm btn-outline-secondary text-body ms-1" id="adMhXlNetPick">Pick xlights_networks.xml</button>
                    <input type="file" class="d-none" id="adMhXlNetFile" accept=".xml,text/xml"></div>
                <div class="d-flex flex-column gap-1 mb-2" id="adMhXlRows"></div>
                <button type="button" class="btn btn-sm btn-primary" id="adMhXlApply">Add heads</button>
                <button type="button" class="btn btn-sm btn-link" id="adMhXlCancel">Cancel</button>
            </div>
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

        <div class="tab-pane fade" id="ad-dz" role="tabpanel" aria-labelledby="ad-dz-tab">
            <?php airdefender_print_group('DeadZones', array('deadZonesEnabled')); ?>
            <div class="alert alert-info">
                <b>Still being built.</b> DeadZones closes a head's shutter while it points into an area you've masked out.
                Everything is here: heads, zones (with landmarks, testing and backups) and checking your sequences. It still needs testing on real heads before it's released.
            </div>
            <div class="mb-3" id="adDzStatus" role="status"></div>
            <div id="adDzSetup" class="d-none">
                <!-- DeadZones' own pages, so the tab doesn't become one long page. -->
                <ul class="nav nav-tabs mb-3" role="tablist" aria-label="DeadZones pages">
                    <li class="nav-item" role="presentation"><button type="button" class="nav-link active" role="tab" aria-selected="true" data-dz-tab="heads">Heads</button></li>
                    <li class="nav-item" role="presentation"><button type="button" class="nav-link" role="tab" aria-selected="false" data-dz-tab="zones"><i class="fas fa-draw-polygon"></i> Zones</button></li>
                    <li class="nav-item" role="presentation"><button type="button" class="nav-link" role="tab" aria-selected="false" data-dz-tab="check"><i class="fas fa-list-check"></i> Check</button></li>
                </ul>
                <div data-dz-pane="heads" role="tabpanel">
                <h2 class="visually-hidden">Pan and tilt</h2>
                <p class="text-body-secondary">
                    For each head on the <b>Moving heads</b> tab, enter where pan and tilt are in the fixture's DMX chart, as you did for the shutter.
                    <b>Range</b> is how far it can turn
                    (often 540&deg; pan, 270&deg; tilt) and <b>Slew</b> how fast it turns, in degrees a second (the <b>Slew Limit</b> in xLights, 180 unless you changed it).
                    For aiming a head to mark its zones: its <b>Dimmer</b> channel, and the shutter value that <b>opens</b> it (the shutter
                    channel and its closed value are on the Moving heads tab).
                </p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle airdefender-heads" id="adDzHeads">
                        <thead>
                            <tr><th scope="col" rowspan="2">Head</th><th scope="colgroup" colspan="3" class="text-center border-start">Pan</th><th scope="colgroup" colspan="3" class="text-center border-start">Tilt</th><th scope="colgroup" colspan="2" class="text-center border-start">For aiming</th></tr>
                            <tr>
                                <?php foreach (array('Pan', 'Tilt') as $airdefenderAxis): ?>
                                    <th scope="col" class="border-start" title="<?php echo $airdefenderAxis; ?>'s channel in the fixture's DMX chart">Channel</th>
                                    <th scope="col" title="How many degrees it can turn">Range &deg;</th>
                                    <th scope="col" title="How fast it turns, in degrees a second: the Slew Limit in xLights">Slew &deg;/s</th>
                                <?php endforeach; ?>
                                <th scope="col" class="border-start" title="The dimmer's channel in the fixture's DMX chart">Dimmer ch.</th>
                                <th scope="col" title="The shutter value that opens it (often 255)">Shutter open</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        // field => [default shown when empty, min, max, what it is]
                        $airdefenderDzFields = array(
                            // The main (coarse) channel is enough: about 2 degrees a step, plenty for a zone's edge.
                            'Ch' => array('', 1, 512, 'channel'),
                            'Range' => array(null, 1, 3600, 'range in degrees'), 'Slew' => array('180', 1, 3600, 'slew in degrees a second'),
                        );
                        for ($n = 1; $n <= AIRDEFENDER_HEADS; $n++): ?>
                            <tr data-dz-head="<?php echo $n; ?>" class="d-none">
                                <th scope="row" class="fw-normal" data-dz-name></th>
                                <?php foreach (array('Pan' => '540', 'Tilt' => '270') as $airdefenderAxis => $airdefenderRange):
                                    foreach ($airdefenderDzFields as $f => $info):
                                        $default = $f === 'Range' ? $airdefenderRange : $info[0];
                                        $v = (int) airdefender_page_setting("head{$n}{$airdefenderAxis}{$f}", '0');
                                        $shown = $v > 0 ? (string) $v : (in_array($f, array('Range', 'Slew'), true) ? $default : ''); ?>
                                    <td<?php echo $f === 'Ch' ? ' class="border-start"' : ''; ?>><input type="number" class="form-control form-control-sm"
                                        data-dz-field="<?php echo $airdefenderAxis . $f; ?>" data-dz-default="<?php echo $default; ?>" data-dz-what="<?php echo strtolower($airdefenderAxis) . ' ' . $info[3]; ?>"
                                        value="<?php echo $shown; ?>" min="<?php echo $info[1]; ?>" max="<?php echo $info[2]; ?>" step="1"
                                        aria-label="Head <?php echo $n; ?> <?php echo strtolower($airdefenderAxis) . ' ' . $info[3]; ?>"></td>
                                <?php endforeach; endforeach;
                                $dim = (int) airdefender_page_setting("head{$n}DimmerCh", '0');
                                $open = airdefender_page_setting("head{$n}ShutterOpen", '255'); ?>
                                <td class="border-start"><input type="number" class="form-control form-control-sm" data-dz-field="DimmerCh" data-dz-default="" data-dz-what="dimmer channel"
                                    value="<?php echo $dim > 0 ? $dim : ''; ?>" min="1" max="512" step="1" aria-label="Head <?php echo $n; ?> dimmer channel"></td>
                                <td><input type="number" class="form-control form-control-sm" data-dz-field="ShutterOpen" data-dz-default="255" data-dz-what="shutter open value"
                                    value="<?php echo max(0, min(255, (int) $open)); ?>" min="0" max="255" step="1" aria-label="Head <?php echo $n; ?> shutter open value"></td>
                            </tr>
                        <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
                <div class="form-text" id="adDzNone">No heads yet: add them on the <b>Moving heads</b> tab first.</div>
                <div class="form-text mt-2 d-none" id="adDzLockedNote">Channels of heads <b>Imported</b> on the Moving heads tab are locked: they came from xLights. Set the head to <b>Manual channel</b> there to edit them.</div>
                <div class="mt-3 d-flex flex-wrap align-items-center gap-2" id="adDzXlRow">
                    <button type="button" class="btn btn-outline-secondary text-body" id="adXlImport"><i class="fas fa-file-import"></i> Import models from xLights</button>
                    <input type="file" class="d-none" id="adXlFile" accept=".xml,text/xml">
                    <span class="form-text">For heads you set up by hand on the Moving heads tab (imported ones already have these). Pick <b>xlights_rgbeffects.xml</b>
                        from your show folder: pan, tilt, range, slew and dimmer are filled in for each head whose name matches an xLights moving head model; for the rest, you pick which model it is. Check them after.</span>
                </div>
                <div class="d-none mt-2 p-2 border rounded" id="adXlPick">
                    <div class="form-text mt-0 mb-2">These heads have no xLights model with the same name. Pick the one each head is:</div>
                    <div class="d-flex flex-column gap-2 mb-2" id="adXlPickRows"></div>
                    <button type="button" class="btn btn-sm btn-primary" id="adXlPickApply">Fill in</button>
                    <button type="button" class="btn btn-sm btn-link" id="adXlPickCancel">Cancel</button>
                </div>
                </div>

                <div class="d-none" data-dz-pane="zones" role="tabpanel">
                <h2 class="visually-hidden">Zones</h2>
                <p class="text-body-secondary">
                    Each head has its own zones, since each sees your yard from a different angle. Pick a head and press <b>Aim</b> to steer it
                    (only while no show is playing; every other head is left alone). Trace an area by pointing at each corner and pressing
                    <b>Mark point</b>, then <b>Finish zone</b>. Aiming ends when you leave this page or don't press anything for two minutes, and
                    plane safety still closes the shutter if an aircraft comes near.
                </p>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <select class="form-select w-auto" id="adAimHead" aria-label="Head to aim"></select>
                    <button type="button" class="btn btn-outline-secondary text-body" id="adAimStart"><i class="fas fa-crosshairs"></i> Aim</button>
                    <button type="button" class="btn btn-outline-danger d-none" id="adAimStop">Stop aiming</button>
                    <span class="form-text" id="adAimMsg" role="status"></span>
                </div>
                <div class="airdefender-aimgrid" id="adAimGrid">
                  <div class="d-none airdefender-contents" id="adAimPanel">
                    <div class="airdefender-a-more">
                        <ul class="nav nav-tabs" role="tablist" aria-label="What to do while aiming">
                            <li class="nav-item" role="presentation"><button type="button" class="nav-link" role="tab" aria-selected="false" data-more-tab="mark"><i class="fas fa-map-pin"></i> Mark zones</button></li>
                            <li class="nav-item" role="presentation"><button type="button" class="nav-link" role="tab" aria-selected="false" data-more-tab="tilt">Tilt limits</button></li>
                            <li class="nav-item" role="presentation"><button type="button" class="nav-link" role="tab" aria-selected="false" data-more-tab="landmarks">Landmarks</button></li>
                            <li class="nav-item" role="presentation"><button type="button" class="nav-link" role="tab" aria-selected="false" data-more-tab="test">Test zones</button></li>
                        </ul>
                        <div class="border border-top-0 rounded-bottom p-3">
                            <div class="d-none" data-more-pane="mark" role="tabpanel">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2" role="group" aria-label="Mark a zone">
                            <button type="button" class="btn btn-outline-primary" id="adMarkPoint"><i class="fas fa-map-pin"></i> Mark point</button>
                            <button type="button" class="btn btn-outline-secondary text-body" id="adMarkUndo" disabled><i class="fas fa-undo"></i> Undo point</button>
                            <input type="text" class="form-control w-auto" id="adMarkName" maxlength="40" placeholder="Zone name, e.g. Smith house" aria-label="Zone name">
                            <button type="button" class="btn btn-success" id="adMarkFinish" disabled><i class="fas fa-check"></i> Finish zone</button>
                            <button type="button" class="btn btn-link" id="adMarkCancel" disabled>Cancel</button>
                        </div>
                        <div class="form-text mb-2" id="adMarkInfo">Point at a corner of the area and press Mark point. Three or more points make a zone.</div>
                            </div>
                            <div class="d-none" data-more-pane="tilt" role="tabpanel">
                        <div class="d-flex flex-wrap align-items-center gap-2" role="group" aria-label="Tilt limit">
                            <span class="form-text">Tilt limit at this height:</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary text-body" data-tilt-limit="below">Block below</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary text-body" data-tilt-limit="above">Block above</button>
                            <button type="button" class="btn btn-sm btn-link" data-tilt-limit="clear">Clear</button>
                        </div>
                        <div class="form-text">"Too low" can be lower or higher tilt numbers depending on the fixture: use whichever shades the side you don't want on the map.</div>
                            </div>
                            <div class="d-none" data-more-pane="landmarks" role="tabpanel">
                        <div class="form-text mt-0 mb-2">Landmarks are for next season: save them with your zones now, then re-align the zones with them if a head goes back up a little differently.</div>
                        <p class="form-text mt-0 mb-2">Aim at one or two things that won't move (a chimney, a pole, a corner of the house) and save them with
                            your zones. If the head goes back up a little differently next year, aim at them again and press <b>Re-align</b>: every zone moves to match.
                            Two landmarks far apart also fix a head that's turned or tipped.</p>
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2" role="group" aria-label="Save landmarks">
                            <span class="form-text">This season:</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary text-body" data-landmark="1"><i class="fas fa-flag"></i> Save landmark 1 here</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary text-body" data-landmark="2"><i class="fas fa-flag"></i> Save landmark 2 here</button>
                        </div>
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2" role="group" aria-label="Re-align zones">
                            <span class="form-text">Next season:</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary text-body" data-landmark-now="1">Landmark 1 is here now</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary text-body" data-landmark-now="2">Landmark 2 is here now</button>
                            <button type="button" class="btn btn-sm btn-primary" id="adRealign" disabled><i class="fas fa-arrows-alt"></i> Re-align</button>
                            <button type="button" class="btn btn-sm btn-link d-none" id="adRealignUndo">Undo re-align</button>
                        </div>
                        <div class="form-text" id="adRealignInfo"></div>
                            </div>
                            <div class="d-none" data-more-pane="test" role="tabpanel">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <button type="button" class="btn btn-outline-secondary text-body" id="adTestZones" disabled><i class="fas fa-route"></i> Test zones</button>
                        <button type="button" class="btn btn-outline-danger d-none" id="adTestStop">Stop test</button>
                        <span class="form-text" id="adTestInfo">The head traces round each zone's edge so you can check it outside (turn the beam on to see it).</span>
                    </div>
                            </div>
                        </div>
                    </div>
                    <div class="airdefender-a-ctl d-flex flex-wrap gap-3 align-items-start">
                        <div class="airdefender-pad" role="group" aria-label="Move the head">
                            <span></span>
                            <button type="button" class="btn btn-outline-secondary text-body" data-aim-move="0,1" aria-label="Tilt up"><i class="fas fa-chevron-up"></i></button>
                            <span></span>
                            <button type="button" class="btn btn-outline-secondary text-body" data-aim-move="-1,0" aria-label="Pan left"><i class="fas fa-chevron-left"></i></button>
                            <div class="airdefender-pad-read small text-center" id="adAimRead"></div>
                            <button type="button" class="btn btn-outline-secondary text-body" data-aim-move="1,0" aria-label="Pan right"><i class="fas fa-chevron-right"></i></button>
                            <span></span>
                            <button type="button" class="btn btn-outline-secondary text-body" data-aim-move="0,-1" aria-label="Tilt down"><i class="fas fa-chevron-down"></i></button>
                            <span></span>
                        </div>
                        <div>
                            <div class="mb-2">
                                <div class="form-text mb-1">Step</div>
                                <div class="btn-group" role="group" aria-label="Step size">
                                    <button type="button" class="btn btn-sm btn-outline-secondary text-body" data-aim-step="1">Fine</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary active" data-aim-step="5">Medium</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary text-body" data-aim-step="15">Coarse</button>
                                </div>
                            </div>
                            <div class="mb-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary text-body" id="adAimBeam" aria-pressed="false"><i class="far fa-lightbulb"></i> Beam</button>
                                <span class="form-text d-none" id="adAimNoDim">No dimmer channel set for this head: the beam is the show's.</span>
                            </div>
                            <label class="form-text d-block" for="adAimLevel">Brightness</label>
                            <input type="range" class="form-range" id="adAimLevel" min="5" max="255" step="5" value="64" style="max-width: 14rem">
                            <div class="form-text">Hold an arrow to keep moving; the keyboard's arrow keys work too.</div>
                        </div>
                    </div>
                    <div class="airdefender-a-pic">
                        <div class="airdefender-headview">
                            <div class="form-text mb-1">At rest, the head faces</div>
                            <div class="btn-group mb-2" role="group" aria-label="Which way the head faces at rest, seen from the front of the house">
                                <button type="button" class="btn btn-sm btn-outline-secondary text-body" data-rest="-90">Left</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary text-body" data-rest="0">Front</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary text-body" data-rest="90">Right</button>
                            </div>
                            <svg class="airdefender-headpic" id="adHeadPic" viewBox="0 0 200 210" role="img" aria-label="The head, seen from the front of the house"></svg>
                            <div class="form-text">From the front of the house. A guide only: check the real head.</div>
                        </div>
                    </div>
                  </div>
                  <div class="airdefender-a-map" id="adZonesArea">
                    <div>
                        <h3 class="h6 mb-1">Where this head can point <span class="text-body-secondary fw-normal" id="adZonesFor"></span></h3>
                        <svg class="airdefender-zonemap border rounded" id="adZoneMap" viewBox="-8 -8 272 272" role="img" aria-label="This head's zones, by pan (across) and tilt (up)"></svg>
                        <div class="form-text">Across is pan, up is tilt (0-255). Shaded areas close the shutter.</div>
                    </div>
                  </div>
                  <div class="airdefender-a-list">
                    <div class="flex-grow-1" style="min-width: 16rem; max-width: 34rem">
                        <h3 class="h6">Zones</h3>
                        <div class="list-group airdefender-zonelist" id="adZoneList"></div>
                        <div class="form-text" id="adZoneNone">No zones for this head yet.</div>
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <button type="button" class="btn btn-sm btn-outline-secondary text-body" id="adZonesExport"><i class="fas fa-download"></i> Export zones</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary text-body" id="adZonesImport"><i class="fas fa-upload"></i> Import zones</button>
                            <input type="file" class="d-none" id="adZonesFile" accept=".json,application/json">
                        </div>
                        <div class="form-text">A backup of every head's zones, tilt limits and landmarks. Importing replaces them all.</div>
                    </div>
                  </div>
                </div>
                </div>

                <div class="d-none" data-dz-pane="check" role="tabpanel">
                <h2 class="visually-hidden">Check my sequences</h2>
                <div class="alert alert-info py-2"><b>Optional.</b> DeadZones protects every sequence during the show whether or not you check it:
                    this just shows you ahead of time where the shutter will close, so you can tidy those moments up in xLights if you like.</div>
                <p class="text-body-secondary">
                    Plays your sequences through the DeadZones check, without the lights, and lists each moment a head's beam is on while it points into
                    one of its zones, so you can fix it in xLights. Until then DeadZones just closes the shutter there during the show.
                    It only runs while nothing is playing, at low priority, and stops if a show starts. It only reads the sequence files.
                </p>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <button type="button" class="btn btn-outline-primary" id="adCheckAll"><i class="fas fa-list-check"></i> Check all sequences</button>
                    <button type="button" class="btn btn-outline-danger d-none" id="adCheckStop">Stop</button>
                    <span class="form-text" id="adCheckMsg" role="status"></span>
                </div>
                <div class="progress mb-3 d-none" id="adCheckBar" role="progressbar" aria-label="Checking sequences" style="height: .5rem"><div class="progress-bar" style="width: 0%"></div></div>
                <div class="list-group airdefender-checklist" id="adCheckList"></div>
                </div>

            </div>
        </div>
    </div>
</div>

<style>
    /* A long list scrolls in its own box, about as tall as the picture beside it; a short one doesn't. */
    .airdefender-zonelist { max-height: 20rem; overflow-y: auto; }
    .airdefender-zonemap { width: min(100%, 18rem); aspect-ratio: 1; display: block; background: var(--bs-body-bg); }
    .airdefender-zonemap .zone { fill: var(--bs-warning); fill-opacity: .35; stroke: var(--bs-warning); stroke-width: 1.5; }
    .airdefender-zonemap .limit { fill: var(--bs-danger); fill-opacity: .2; }
    .airdefender-zonemap .draft { fill: var(--bs-primary); fill-opacity: .15; stroke: var(--bs-primary); stroke-width: 1.5; stroke-dasharray: 4 3; }
    .airdefender-zonemap .dot { fill: var(--bs-primary); }
    .airdefender-zonemap .mark { fill: var(--bs-warning); stroke: var(--bs-body-color); stroke-width: 1; }
    .airdefender-zonemap .here { fill: none; stroke: var(--bs-body-color); stroke-width: 1.5; }
    .airdefender-zonemap .grid { stroke: var(--bs-border-color); stroke-width: .75; }
    .airdefender-zonemap .label { fill: var(--bs-body-color); }
    .airdefender-pad { display: grid; grid-template-columns: repeat(3, 3.25rem); grid-auto-rows: 3.25rem; gap: .35rem; touch-action: none; }
    .airdefender-pad .btn { width: 100%; height: 100%; }
    .airdefender-pad-read { align-self: center; line-height: 1.2; }
    /* The playlist list scrolls inside its own box rather than stretching the page. */
    .airdefender-playlists { columns: 14rem auto; }
    .airdefender-playlists-long { max-height: 18rem; overflow-y: auto; }
    /* FPP draws big checkboxes: give each row their height and the label room beside it. */
    .airdefender-playlists .form-check { break-inside: avoid; display: flex; align-items: center; gap: .5rem; min-height: 2rem; padding-left: 0; margin-bottom: .25rem; }
    .airdefender-playlists .form-check .form-check-input { float: none; margin: 0; flex-shrink: 0; }
    /* Keep the head table's inputs usable on a phone: the table scrolls sideways in its own box. */
    .airdefender-heads { min-width: 46rem; }
    .airdefender-grip { cursor: grab; touch-action: none; }
    .airdefender-contents { display: contents; }
    .airdefender-aimgrid { display: grid; gap: 1rem 1.5rem; align-items: start; margin-top: 1rem;
        grid-template-columns: minmax(0, 1fr); grid-template-areas: "more" "ctl" "map" "pic" "list"; }
    .airdefender-a-ctl { grid-area: ctl; } .airdefender-a-pic { grid-area: pic; max-width: 12rem; } .airdefender-a-map { grid-area: map; }
    .airdefender-a-ctl > div:last-child { max-width: 15rem; }
    /* These fill their area but don't widen the columns (long text wraps instead of pushing the map aside). */
    .airdefender-a-more, .airdefender-a-list { width: 0; min-width: 100%; }
    .airdefender-a-more { grid-area: more; } .airdefender-a-list { grid-area: list; min-width: 0; }
    @media (min-width: 768px) {
        .airdefender-aimgrid { grid-template-columns: auto minmax(0, 1fr); grid-template-areas: "map list"; }
        .airdefender-aimgrid.airdefender-aiming { grid-template-areas: "more map" "ctl map" "pic list"; }
    }
    @media (min-width: 1200px) {
        .airdefender-aimgrid.airdefender-aiming { grid-template-columns: max-content max-content minmax(18rem, 1fr);
            grid-template-areas: "more more map" "ctl pic map" "ctl pic list"; }
    }
    .airdefender-checklist { max-height: 30rem; overflow-y: auto; }
    .airdefender-headpic { width: 10rem; max-width: 100%; height: auto; display: block; }
    .airdefender-headpic .body { fill: var(--bs-gray-800); stroke: var(--bs-gray-900); stroke-width: 1; stroke-linejoin: round; }
    .airdefender-headpic .shade { fill: var(--bs-black); stroke: none; }
    .airdefender-headpic .bezel { fill: var(--bs-gray-900); stroke: none; }
    .airdefender-headpic .lens { fill: var(--bs-teal); stroke: none; }
    .airdefender-headpic .arm { fill: var(--bs-gray-900); stroke: var(--bs-gray-700); stroke-width: 1; stroke-linejoin: round; }
    .airdefender-headpic .hub { fill: var(--bs-gray-700); stroke: var(--bs-gray-900); stroke-width: 1; }
    .airdefender-headpic .beam-on { stroke: var(--bs-white); stroke-linecap: round; }
    .airdefender-headpic .lens-off { fill: var(--bs-teal); fill-opacity: .25; stroke: none; }
    .modal-dialog.airdefender-popup { max-width: 26rem; width: calc(100% - 2rem); margin-left: auto; margin-right: auto; }
    .airdefender-dragging { opacity: .6; }
    .airdefender-dragging td { background: var(--bs-tertiary-bg); }
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
    // jGrowl shows its text as HTML: names come from the user's own heads and files, so they go in as plain text.
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
    // "Imported" is a manual head whose start and shutter channels were imported (and are locked until it's set to Manual).
    function imported(row) { return field(row, 'Model').value === '@xlights'; }
    function modelOf(row) { return imported(row) ? '' : field(row, 'Model').value; }
    function resolve(row) {
        var model = modelOf(row), start = field(row, 'Start'), off = parseInt(field(row, 'Offset').value, 10);
        var known = modelsKnown && Object.prototype.hasOwnProperty.call(models, model);
        field(row, 'Offset').disabled = reordering || imported(row);
        if (model === '') {
            if (start.hasAttribute('data-from-model')) { start.value = start.getAttribute('data-manual') || ''; start.removeAttribute('data-from-model'); }
            start.disabled = reordering || imported(row);
            var st = parseInt(start.value, 10);
            if (!(st >= 1)) { return [0, 'Enter the start channel', 'text-warning-emphasis']; }
            if (!(off >= 1)) { return [0, 'Enter the shutter channel', 'text-warning-emphasis']; }
            if (st + off - 1 > MAX_CH) { return [0, 'Past FPP’s last channel', 'text-danger']; }
            return [st + off - 1, 'ch ' + (st + off - 1), 'text-success-emphasis'];
        }
        if (!start.hasAttribute('data-from-model')) { start.setAttribute('data-manual', start.value); start.setAttribute('data-from-model', ''); }
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
            if (sel.value === '@xlights') {
                save(key(row, 'Source'), 'xlights'); save(key(row, 'Model'), '', label(row) + ': imported');
                showRow(row); saveChannel(row); updateDeadZones(); return;
            }
            if (setting(key(row, 'Source'), '') !== '') { save(key(row, 'Source'), ''); }
            // A head named after its old model takes the new model's name.
            if (sel.value !== '' && (name.value.trim() === '' || name.value.trim() === prev)) {
                name.value = sel.value;
                save(key(row, 'Name'), sel.value);
            }
            save(key(row, 'Model'), sel.value, label(row) + (sel.value === '' && prev === '' ? ': manual channel, unlocked' : '’s model'));
            updateDeadZones();
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
            var blank = { Enabled: 0, Name: '', Model: '', Source: '', Start: 0, Offset: 1, ClosedValue: 0, Channel: 0 };
            Object.keys(blank).forEach(function (f) { save(key(row, f), blank[f]); });
            // Its DeadZones pan and tilt details go too, so a new head in this slot starts clean.
            document.querySelectorAll('#adDzHeads tr[data-dz-head="' + row.getAttribute('data-head') + '"] [data-dz-field]').forEach(function (input) {
                var k = key(row, input.getAttribute('data-dz-field')), def = input.getAttribute('data-dz-default');
                var blankValue = def === '' ? '0' : def;
                if (setting(k, blankValue) !== blankValue) { save(k, blankValue); }
                input.value = def;
            });
            post({ action: 'head_clear', head: row.getAttribute('data-head') }).catch(function (e) { toast(name + '\u2019s zones: ' + e.message, false); });
            field(row, 'Enabled').checked = false; field(row, 'Name').value = ''; field(row, 'Model').value = '';
            field(row, 'Start').disabled = false; field(row, 'Start').value = ''; field(row, 'Start').setAttribute('data-manual', '');
            field(row, 'Offset').value = 1; field(row, 'ClosedValue').value = 0; row.setAttribute('data-channel', 0);
            if (rows.filter(function (r) { return !r.classList.contains('d-none'); }).length > 1) { row.classList.add('d-none'); }
            showRow(row); updateAdd(); updateDeadZones();
            toast(name + ' removed.');
        });
    });
    // Reorder, like FPP's MultiSync page: press to unlock, drag the heads by their grips, press again to lock the new order.
    // Each head takes everything that belongs to it (settings, DeadZones values, zones) to its new place.
    var reordering = false, tbody = rows[0].parentNode;
    function reorderMode(on) {
        reordering = on;
        var b = $id('adReorder');
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
        b.innerHTML = on ? '<i class="fas fa-lock-open"></i> Done reordering' : '<i class="fas fa-lock"></i> Reorder';
        b.classList.toggle('btn-warning', on);
        rows.forEach(function (r) {
            r.querySelector('[data-grip]').classList.toggle('d-none', !on);
            r.querySelector('[data-remove]').classList.toggle('d-none', on);
            r.classList.toggle('airdefender-dragrow', on);
            r.querySelectorAll('input, select').forEach(function (i) { i.disabled = on || i.hasAttribute('data-only-manual'); });
            showRow(r);   // locks Start and Shutter ch. again where they should be
        });
        $id('adAddHead').disabled = on;
        $id('adMhXlImport').disabled = on;
    }
    rows.forEach(function (row) {
        var grip = row.querySelector('[data-grip]');
        grip.addEventListener('pointerdown', function (e) {
            if (!reordering) { return; }
            e.preventDefault();
            row.classList.add('airdefender-dragging');
            function move(ev) {
                var under = document.elementFromPoint(ev.clientX, ev.clientY), over = under && under.closest('#adHeads tr[data-head]');
                if (!over || over === row || over.classList.contains('d-none')) { return; }
                var box = over.getBoundingClientRect();
                tbody.insertBefore(row, ev.clientY < box.top + box.height / 2 ? over : over.nextSibling);
            }
            function up() {
                row.classList.remove('airdefender-dragging');
                document.removeEventListener('pointermove', move); document.removeEventListener('pointerup', up); document.removeEventListener('pointercancel', up);
            }
            // On the whole page: moving the row in the table would lose a capture on the grip.
            document.addEventListener('pointermove', move); document.addEventListener('pointerup', up); document.addEventListener('pointercancel', up);
        });
    });
    $id('adReorder').addEventListener('click', function () {
        if (!reordering) { reorderMode(true); return; }
        // Locking: the heads in their new order, then the unused slots, become heads 1, 2, 3...
        var inPage = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-head]'));
        var order = inPage.filter(function (r) { return !r.classList.contains('d-none'); })
            .concat(inPage.filter(function (r) { return r.classList.contains('d-none'); }))
            .map(function (r) { return r.getAttribute('data-head'); });
        if (order.every(function (n, i) { return n === String(i + 1); })) { reorderMode(false); return; }
        $id('adReorder').disabled = true;
        post({ action: 'heads_reorder', order: JSON.stringify(order.map(Number)) }).then(function () {
            // Every setting any head has (head<n>Name, head<n>PanCh, ...): head i+1 takes old head order[i]'s.
            var suffixes = {}, old = {}, saves = [];
            Object.keys(typeof pluginSettings !== 'undefined' ? pluginSettings : {}).forEach(function (k) { var m = k.match(/^head(\d)(\D.*)$/); if (m) { suffixes[m[2]] = 1; } });
            Object.keys(suffixes).forEach(function (s) { order.forEach(function (n) { old[n + s] = setting('head' + n + s, ''); }); });
            Object.keys(suffixes).forEach(function (s) {
                order.forEach(function (n, i) {
                    var v = old[n + s];
                    if (v !== old[(i + 1) + s]) { saves.push(save('head' + (i + 1) + s, v)); }
                });
            });
            try { sessionStorage.setItem('airdefenderXlMsg', JSON.stringify({ ok: 'New order saved.', warn: '' })); } catch (e) {}
            $.when.apply($, saves).always(function () { location.reload(); });
        }).catch(function (e) { toast(e.message, false); $id('adReorder').disabled = false; });
    });
    $id('adAddHead').addEventListener('click', function () {
        var row = rows.filter(function (r) { return r.classList.contains('d-none'); })[0];
        if (!row) { return; }
        row.classList.remove('d-none');
        field(row, 'Enabled').checked = true;
        save(key(row, 'Enabled'), 1);
        showRow(row); updateAdd(); updateDeadZones();
        field(row, 'Name').focus();
    });
    updateAdd();

    /* ---------- When & where ---------- */
    var plBoxes = Array.prototype.slice.call(document.querySelectorAll('.airdefender-playlists input[type=checkbox]'));
    function updatePlaylists() {
        var ticked = plBoxes.filter(function (b) { return b.checked; }).length, help = $id('adPlaylistHelp');
        help.className = 'form-text' + (ticked ? '' : ' text-warning-emphasis');
        help.textContent = ticked
            ? 'Using Remote Falcon? Tick its playlist too, so viewer requests are covered. A sequence started on its own isn’t in a playlist: use Start tracking on the dashboard.'
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
    /* ---------- DeadZones: each head's pan and tilt ---------- */
    function updateDeadZones() {
        var on = $id('deadZonesEnabled') && $id('deadZonesEnabled').checked, any = false, anyImported = false, anyByHand = false;
        $id('adDzSetup').classList.toggle('d-none', !on);
        // One row for each head in use on the Moving heads tab, under the name it has there.
        document.querySelectorAll('#adDzHeads tr[data-dz-head]').forEach(function (dz) {
            var row = document.querySelector('#adHeads tr[data-head="' + dz.getAttribute('data-dz-head') + '"]');
            var used = row && !row.classList.contains('d-none');
            dz.classList.toggle('d-none', !used);
            if (used) { any = true; dz.querySelector('[data-dz-name]').textContent = label(row); }
            // An Imported head's channels came from xLights: locked here too, like its start and shutter.
            var imp = used && imported(row);
            if (imp) { anyImported = true; } else if (used) { anyByHand = true; }
            ['PanCh', 'TiltCh', 'DimmerCh'].forEach(function (f) { dz.querySelector('[data-dz-field="' + f + '"]').disabled = imp; });
        });
        $id('adDzNone').classList.toggle('d-none', any);
        $id('adDzXlRow').classList.toggle('d-none', !anyByHand);
        $id('adDzLockedNote').classList.toggle('d-none', !anyImported);
    }
    document.querySelectorAll('#adDzHeads [data-dz-field]').forEach(function (input) {
        input.addEventListener('change', function () {
            var dz = this.closest('tr'), f = this.getAttribute('data-dz-field'), k = 'head' + dz.getAttribute('data-dz-head') + f;
            var name = dz.querySelector('[data-dz-name]').textContent, what = this.getAttribute('data-dz-what');
            var lo = parseInt(this.min, 10), hi = parseInt(this.max, 10), fallback = this.getAttribute('data-dz-default');
            if (this.value.trim() === '') {
                // Emptied: back to its default (range and speed), or not set (0) for a channel.
                this.value = fallback;
                save(k, fallback === '' ? 0 : fallback, name + '’s ' + what);
                return;
            }
            var v = parseInt(this.value, 10);
            if (isNaN(v) || v < lo || v > hi) {
                toast(name + ': enter ' + lo + ' to ' + hi + ' for the ' + what + '.', false);
                var old = parseInt(setting(k, '0'), 10);
                this.value = old > 0 ? old : fallback;
                return;
            }
            this.value = v;
            save(k, v, name + '’s ' + what);
        });
    });
    if ($id('deadZonesEnabled')) { $id('deadZonesEnabled').addEventListener('change', updateDeadZones); }
    if ($id('ad-dz-tab')) { $id('ad-dz-tab').addEventListener('shown.bs.tab', updateDeadZones); }

    // Whether the compiled part in fppd is loaded and running, asked every 5 seconds only while this tab is showing.
    // (What each head is doing right now belongs on the dashboard.)
    var dzTimer = null;
    function showDzStatus(d) {
        var box = $id('adDzStatus');
        if (!d) {
            box.className = 'mb-3 text-warning-emphasis';
            box.textContent = "DeadZones isn't available on this FPP right now: its compiled part isn't loaded in fppd. "
                + 'Restart FPPD; if this stays, it could not be built when Air Defender was installed. Everything else in Air Defender works without it.';
            return;
        }
        var setUp = (d.heads || []).filter(function (h) { return h.setUp; }).length;
        box.className = 'mb-3 ' + (d.failed || d.error ? 'text-danger' : 'text-body-secondary');
        box.textContent = d.failed || d.error ? d.error
            : d.enabled ? 'DeadZones is running: ' + setUp + ' head' + (setUp === 1 ? '' : 's') + ' set up.'
            : 'DeadZones is ready and switched off.';
    }
    function pollDz() {
        fetch('api/plugin-apis/AirDefender/deadzones', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
            .then(function (d) { showDzStatus(d && d.loaded ? d : null); }, function () { showDzStatus(null); });
    }
    function watchDz() {
        var showing = $id('ad-dz') && $id('ad-dz').classList.contains('active') && !document.hidden;
        if (showing && !dzTimer) { pollDz(); dzTimer = setInterval(pollDz, 5000); }
        if (!showing && dzTimer) { clearInterval(dzTimer); dzTimer = null; }
    }
    document.querySelectorAll('#adTabs a').forEach(function (a) { a.addEventListener('shown.bs.tab', watchDz); });
    document.addEventListener('visibilitychange', watchDz);
    setTimeout(watchDz, 500);   // if the page opens on this tab

    /* ---------- DeadZones: aiming one head ---------- */
    var aim = null, aimStep = 5, aimBusy = false, aimPending = null, aimLastPress = 0, aimKeep = null, aimRepeat = null;
    function aimMsg(text, bad) { $id('adAimMsg').textContent = text || ''; $id('adAimMsg').className = 'form-text ' + (bad ? 'text-danger' : ''); }
    function aimHeads() {
        // Heads with pan and tilt entered can be aimed.
        var sel = $id('adAimHead'), keep = sel.value;
        sel.textContent = '';
        document.querySelectorAll('#adDzHeads tr[data-dz-head]:not(.d-none)').forEach(function (dz) {
            var pan = dz.querySelector('[data-dz-field="PanCh"]').value, tilt = dz.querySelector('[data-dz-field="TiltCh"]').value;
            if (pan && tilt) {
                var o = document.createElement('option');
                o.value = dz.getAttribute('data-dz-head'); o.textContent = dz.querySelector('[data-dz-name]').textContent;
                sel.appendChild(o);
            }
        });
        if (keep) { sel.value = keep; }
        $id('adAimStart').disabled = !sel.options.length || !!aim;
        if (!sel.options.length && !aim) { aimMsg('Enter a head\u2019s pan and tilt channels above to aim it.'); }
    }
    function aimShow() {
        var on = !!aim;
        $id('adAimPanel').classList.toggle('d-none', !on);
        $id('adAimGrid').classList.toggle('airdefender-aiming', on);
        $id('adAimStop').classList.toggle('d-none', !on);
        $id('adAimStart').classList.toggle('d-none', on);
        $id('adAimHead').disabled = on;
        if (!on) { zonesDraw(); return; }
        $id('adAimRead').innerHTML = '';
        ['Pan ' + aim.pan, 'Tilt ' + aim.tilt].forEach(function (t) { var d = document.createElement('div'); d.textContent = t; $id('adAimRead').appendChild(d); });
        // Lit (yellow, solid bulb) while the beam is on; plain with an outline bulb while it's off, like xLights' Output to Lights.
        var beam = $id('adAimBeam');
        beam.className = 'btn btn-sm ' + (aim.beam ? 'btn-warning' : 'btn-outline-secondary text-body');
        beam.setAttribute('aria-pressed', aim.beam ? 'true' : 'false');
        beam.title = aim.beam ? 'Beam is on: press to turn it off' : 'Beam is off: press to turn it on';
        beam.innerHTML = '<i class="' + (aim.beam ? 'fas' : 'far') + ' fa-lightbulb"></i> Beam';
        $id('adAimLevel').value = aim.level;
        headDraw();
        zonesDraw();
    }
    function aimSend(fields) {
        // One request at a time; presses made meanwhile are sent together afterwards, so holding an arrow never queues up.
        if (aimBusy) { aimPending = Object.assign(aimPending || {}, fields); return; }
        aimBusy = true;
        post(fields).then(function (d) {
            if (fields.action === 'aim_end') { aim = null; } else if (d.aiming) { aim = d.aiming; }
            if (aim) {
                aimMsg(d.safety ? 'Plane safety is holding the shutter closed right now.' : 'Aiming ' + aim.name + '.');
                $id('adAimNoDim').classList.toggle('d-none', !!d.hasDimmer);
                $id('adAimBeam').classList.toggle('d-none', !d.hasDimmer);
            }
        }).catch(function (e) {
            if (fields.action !== 'aim_end') { aim = null; aimMsg(e.message, true); }
        }).then(function () {
            aimBusy = false; aimShow(); aimHeads(); aimTimers();
            if (aimPending && aim) { var p = aimPending; aimPending = null; p.action = 'aim_set'; aimSend(p); } else { aimPending = null; }
        });
    }
    function aimTimers() {
        // Check in every 5 s while aiming (the loop ends aiming if the page goes quiet); end it after two minutes without a press.
        if (aim && !aimKeep) {
            aimKeep = setInterval(function () {
                if (Date.now() - aimLastPress > 120000) { aimSend({ action: 'aim_end' }); aimMsg('Aiming ended after two minutes without a press.'); return; }
                if (!aimBusy) { aimSend({ action: 'aim_keep' }); }
            }, 5000);
        } else if (!aim && aimKeep) { clearInterval(aimKeep); aimKeep = null; }
    }
    function aimMove(dx, dy) {
        if (!aim) { return; }
        aimLastPress = Date.now();
        aim.pan = Math.max(0, Math.min(255, aim.pan + dx * aimStep));
        aim.tilt = Math.max(0, Math.min(255, aim.tilt + dy * aimStep));
        aimShow();
        aimSend({ action: 'aim_set', pan: aim.pan, tilt: aim.tilt });
    }
    $id('adAimStart').addEventListener('click', function () {
        aimLastPress = Date.now();
        aimMsg('Starting\u2026');
        aimSend({ action: 'aim_start', head: $id('adAimHead').value });
    });
    $id('adAimStop').addEventListener('click', function () { aimSend({ action: 'aim_end' }); aimMsg('Aiming ended; the head is back to the show.'); });
    document.querySelectorAll('[data-aim-step]').forEach(function (b) {
        b.addEventListener('click', function () {
            aimStep = parseInt(b.getAttribute('data-aim-step'), 10);
            document.querySelectorAll('[data-aim-step]').forEach(function (o) { o.classList.toggle('active', o === b); o.classList.toggle('text-body', o !== b); });
        });
    });
    document.querySelectorAll('[data-aim-move]').forEach(function (b) {
        var xy = b.getAttribute('data-aim-move').split(',').map(Number);
        function stop() { if (aimRepeat) { clearInterval(aimRepeat); aimRepeat = null; } }
        b.addEventListener('pointerdown', function (e) {
            e.preventDefault(); stop(); aimMove(xy[0], xy[1]);
            aimRepeat = setInterval(function () { aimMove(xy[0], xy[1]); }, 180);   // held: keeps moving
        });
        ['pointerup', 'pointerleave', 'pointercancel'].forEach(function (ev) { b.addEventListener(ev, stop); });
        b.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); aimMove(xy[0], xy[1]); } });
    });
    document.addEventListener('keydown', function (e) {
        var move = { ArrowUp: [0, 1], ArrowDown: [0, -1], ArrowLeft: [-1, 0], ArrowRight: [1, 0] }[e.key];
        var typing = e.target && /INPUT|SELECT|TEXTAREA/.test(e.target.tagName) && e.target.type !== 'range';
        if (!move || !aim || typing || !$id('ad-dz').classList.contains('active')) { return; }
        e.preventDefault(); aimMove(move[0], move[1]);
    });
    $id('adAimBeam').addEventListener('click', function () {
        if (!aim) { return; }
        aimLastPress = Date.now(); aim.beam = !aim.beam; aimShow();
        aimSend({ action: 'aim_set', beam: aim.beam ? 1 : 0, level: aim.level });
    });
    $id('adAimLevel').addEventListener('change', function () {
        if (!aim) { return; }
        aimLastPress = Date.now(); aim.level = parseInt(this.value, 10);
        aimSend({ action: 'aim_set', level: aim.level, beam: aim.beam ? 1 : 0 });
    });
    // While aiming: Mark zones, Tilt limits, Landmarks or Test zones, one at a time in tabs above the controls;
    // the last one used is remembered in this browser.
    function moreTab(name) {
        if (!document.querySelector('[data-more-pane="' + name + '"]')) { name = 'mark'; }
        document.querySelectorAll('[data-more-tab]').forEach(function (b) {
            var on = b.getAttribute('data-more-tab') === name;
            b.classList.toggle('active', on); b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        document.querySelectorAll('[data-more-pane]').forEach(function (d) { d.classList.toggle('d-none', d.getAttribute('data-more-pane') !== name); });
        try { localStorage.setItem('airdefenderMoreTab', name); } catch (e) {}
    }
    document.querySelectorAll('[data-more-tab]').forEach(function (b) { b.addEventListener('click', function () { moreTab(b.getAttribute('data-more-tab')); }); });
    (function () { var t = 'mark'; try { t = localStorage.getItem('airdefenderMoreTab') || 'mark'; } catch (e) {} moreTab(t); })();

    /* ---------- DeadZones: a picture of the head being aimed, seen from the front of the house ---------- */
    // Which way it faces at rest (DMX pan 0), remembered in this browser for each head; only turns the picture.
    function restOf(head) { try { var v = localStorage.getItem('airdefenderRest' + head); return v === null ? 0 : parseInt(v, 10) || 0; } catch (e) { return 0; } }
    document.querySelectorAll('[data-rest]').forEach(function (b) {
        b.addEventListener('click', function () {
            try { localStorage.setItem('airdefenderRest' + zoneHead(), b.getAttribute('data-rest')); } catch (e) {}
            headDraw();
        });
    });
    function headRange(head, f, fallback) {
        var i = document.querySelector('#adDzHeads tr[data-dz-head="' + head + '"] [data-dz-field="' + f + '"]');
        var v = i ? parseInt(i.value, 10) : NaN;
        return v > 0 ? v : fallback;
    }
    function headDraw() {
        var svg = $id('adHeadPic'); if (!svg || !aim) { return; }
        var head = String(aim.head), rest = restOf(head);
        document.querySelectorAll('[data-rest]').forEach(function (b) {
            var on = parseInt(b.getAttribute('data-rest'), 10) === rest;
            b.classList.toggle('active', on); b.classList.toggle('text-body', !on);
        });
        // DMX to angles, from the rest position (pan 0, tilt 0): pan 0 faces the rest direction picked above and turns
        // across the pan range. Tilt's range is centred on straight up: tilt 0 is its low end on the facing side (about
        // 45 degrees below level on a 270-degree head), and raising it swings the beam up, over the top and down the back.
        var pan = (rest + aim.pan / 255 * headRange(head, 'PanRange', 540)) * Math.PI / 180;
        var tilt = (0.5 - aim.tilt / 255) * headRange(head, 'TiltRange', 270) * Math.PI / 180;   // from straight up, toward the facing side
        // x right, y up, z toward you (you stand in front of the house).
        function add(a, b, k) { return [a[0] + b[0] * k, a[1] + b[1] * k, a[2] + b[2] * k]; }
        var facing = [Math.sin(pan), 0, Math.cos(pan)], axis = [Math.cos(pan), 0, -Math.sin(pan)];
        var f = [facing[0] * Math.sin(tilt), Math.cos(tilt), facing[2] * Math.sin(tilt)];       // the beam's direction
        var u = [axis[1] * f[2] - axis[2] * f[1], axis[2] * f[0] - axis[0] * f[2], axis[0] * f[1] - axis[1] * f[0]];
        var P = [0, 0, 0];
        function scr(v) { var k = 1 + v[2] * 0.0012; return [100 + v[0] * k, 115 - v[1] * k, v[2]]; }   // a touch of perspective
        function poly(pts, cls, extra) {
            var e = svgEl('polygon', { points: pts.map(function (v) { var q = scr(v); return q[0].toFixed(1) + ',' + q[1].toFixed(1); }).join(' '), 'class': cls }, svg);
            Object.keys(extra || {}).forEach(function (k) { e.setAttribute(k, extra[k]); });
            return e;
        }
        svg.textContent = '';
        // Like xLights' default moving head: no base, just two flat yoke arms (rectangles with the top corners cut off)
        // with a round hub where the head pivots, and an eight-sided head that tapers from the lens to the back.
        var items = [], W = 42;
        function ring(c, a1, a2, r, n, rot) {
            var pts = [];
            for (var k = 0; k < n; k++) { var t = (k + (rot || 0)) / n * 2 * Math.PI; pts.push(add(add(c, a1, Math.cos(t) * r), a2, Math.sin(t) * r)); }
            return pts;
        }
        function centre(pts) { return pts.reduce(function (s, v) { return add(s, v, 1 / pts.length); }, [0, 0, 0]); }
        // Yoke arms: flat plates across the facing direction, from the base up past the pivot.
        [-1, 1].forEach(function (side) {
            var c = add(P, axis, side * W), up = [0, 1, 0];
            var plate = [[-18, -72], [18, -72], [18, 6], [8, 18], [-8, 18], [-18, 6]].map(function (q) { return add(add(c, facing, q[0]), up, q[1]); });
            var nz = axis[2] * side;   // plate faces outwards: drawn in front of the head when on your side
            items.push({ z: c[2], draw: function () {
                poly(plate, 'arm');
                if (nz > 0.05) { poly(ring(add(c, axis, side * 2), facing, up, 7, 16), 'hub'); }
            } });
        });
        // The head: eight sides, wide at the lens end (front), narrow at the back.
        var L = 28, A = 30, a = 18;
        var front = ring(add(P, f, L), axis, u, A, 8, 0.5), back = ring(add(P, f, -L), axis, u, a, 8, 0.5);
        var faces = [{ pts: front, n: f, lens: true }, { pts: back.slice().reverse(), n: [-f[0], -f[1], -f[2]] }];
        for (var i = 0; i < 8; i++) {
            var j = (i + 1) % 8, sd = [front[i], front[j], back[j], back[i]], c2 = centre(sd);
            faces.push({ pts: sd, n: [c2[0] - P[0], c2[1] - P[1], c2[2] - P[2]] });
        }
        faces.forEach(function (fc) {
            if (fc.n[2] <= 0) { return; }   // facing away: hidden behind the rest of the head
            var z = centre(fc.pts)[2];
            items.push({ z: z, draw: function () {
                poly(fc.pts, 'body');
                var len = Math.sqrt(fc.n[0] * fc.n[0] + fc.n[1] * fc.n[1] + fc.n[2] * fc.n[2]) || 1;
                poly(fc.pts, 'shade', { 'fill-opacity': (0.45 * (1 - fc.n[2] / len)).toFixed(2) });   // turned away from you: darker
                if (fc.lens) {
                    poly(ring(add(P, f, L + 0.3), axis, u, A * 0.86, 8, 0.5), 'bezel');
                    // Lit teal while the beam is on; dark glass while it's off.
                    poly(ring(add(P, f, L + 0.6), axis, u, A * 0.7, 8, 0.5), aim.beam ? 'lens' : 'lens-off');
                }
            } });
        });
        // The beam, from the lens.
        var tip = add(P, f, L), end = add(P, f, 150);
        if (aim.beam) items.push({ z: f[2] < 0 ? -500 : 500, draw: function () {   // no beam while it's off
            var s0 = scr(tip), s1 = scr(end);
            var e = svgEl('line', { x1: s0[0], y1: s0[1], x2: s1[0], y2: s1[1], 'class': 'beam-on' }, svg);
            if (aim.beam) { e.setAttribute('stroke-width', 3 + aim.level / 255 * 7); e.setAttribute('stroke-opacity', (0.4 + aim.level / 255 * 0.6).toFixed(2)); }
        } });
        items.sort(function (x, y) { return x.z - y.z; }).forEach(function (it) { it.draw(); });
    }

    /* ---------- DeadZones: marking and listing zones ---------- */
    var zonesData = null, draft = [];
    var SVGNS = 'http://www.w3.org/2000/svg';
    function zoneHead() { return $id('adAimHead').value; }
    function svgEl(tag, attrs, parent, text) {
        var e = document.createElementNS(SVGNS, tag);
        Object.keys(attrs).forEach(function (k) { e.setAttribute(k, attrs[k]); });
        if (text !== undefined) { e.textContent = text; }
        parent.appendChild(e);
        return e;
    }
    function pts(list) { return list.map(function (p) { return p[0] + ',' + (255 - p[1]); }).join(' '); }   // tilt goes up the picture
    function zonesLoad() {
        post({ action: 'zones_get' }).then(function (d) { zonesData = d.heads || {}; zonesDraw(); })
            .catch(function (e) { toast('Could not read the zones: ' + e.message, false); });
    }
    function zonesSaved(d) { if (zonesData && d && d.head) { zonesData[String(d.head)] = d.zones; } zonesDraw(); }
    function zonesDraw() {
        var map = $id('adZoneMap'), head = zoneHead(), h = zonesData && head ? zonesData[head] : null;
        $id('adZonesArea').classList.toggle('d-none', !head);
        if (!head) { return; }
        var opt = $id('adAimHead').selectedOptions[0];
        $id('adZonesFor').textContent = opt ? '(' + opt.textContent + ')' : '';
        map.textContent = '';
        [0, 64, 128, 192, 255].forEach(function (v) {
            svgEl('line', { x1: v, y1: 0, x2: v, y2: 255, 'class': 'grid' }, map);
            svgEl('line', { x1: 0, y1: v, x2: 255, y2: v, 'class': 'grid' }, map);
        });
        if (h) {
            if (h.tiltMin > 0) { svgEl('rect', { x: 0, y: 255 - h.tiltMin, width: 255, height: h.tiltMin, 'class': 'limit' }, map); }
            if (h.tiltMax < 255) { svgEl('rect', { x: 0, y: 0, width: 255, height: 255 - h.tiltMax, 'class': 'limit' }, map); }
            h.zones.forEach(function (z) {
                svgEl('polygon', { points: pts(z.points), 'class': 'zone' }, map, undefined);
                var cx = 0, cy = 0;
                z.points.forEach(function (p) { cx += p[0]; cy += 255 - p[1]; });
                svgEl('text', { x: cx / z.points.length, y: cy / z.points.length, 'class': 'label', 'font-size': 11, 'text-anchor': 'middle' }, map, z.name);
            });
        }
        if (h && h.landmarks) {
            ['1', '2'].forEach(function (k) {
                var l = h.landmarks[k]; if (!l) { return; }
                svgEl('polygon', { points: [[l[0], l[1] + 6], [l[0] + 6, l[1]], [l[0], l[1] - 6], [l[0] - 6, l[1]]].map(function (p) { return p[0] + ',' + (255 - p[1]); }).join(' '), 'class': 'mark' }, map);
                svgEl('text', { x: l[0] + 8, y: 255 - l[1] + 4, 'class': 'label', 'font-size': 11 }, map, k);
            });
        }
        if (draft.length) {
            svgEl(draft.length > 2 ? 'polygon' : 'polyline', { points: pts(draft), 'class': 'draft' }, map);
            draft.forEach(function (p) { svgEl('circle', { cx: p[0], cy: 255 - p[1], r: 3.5, 'class': 'dot' }, map); });
        }
        if (aim && String(aim.head) === head) {
            svgEl('circle', { cx: aim.pan, cy: 255 - aim.tilt, r: 7, 'class': 'here' }, map);
            svgEl('line', { x1: aim.pan - 11, y1: 255 - aim.tilt, x2: aim.pan + 11, y2: 255 - aim.tilt, 'class': 'here' }, map);
            svgEl('line', { x1: aim.pan, y1: 255 - aim.tilt - 11, x2: aim.pan, y2: 255 - aim.tilt + 11, 'class': 'here' }, map);
        }
        // The list, with which other heads each zone also closes.
        var list = $id('adZoneList'); list.textContent = '';
        var zones = h ? h.zones : [];
        $id('adZoneNone').classList.toggle('d-none', zones.length > 0);
        // Any other head in use can be closed with it (it needs no pan or tilt of its own for that).
        var others = Array.prototype.slice.call(document.querySelectorAll('#adDzHeads tr[data-dz-head]:not(.d-none)'))
            .filter(function (r) { return r.getAttribute('data-dz-head') !== head; })
            .map(function (r) { return { value: r.getAttribute('data-dz-head'), textContent: r.querySelector('[data-dz-name]').textContent }; });
        zones.forEach(function (z, i) {
            var item = document.createElement('div'); item.className = 'list-group-item';
            var top = document.createElement('div'); top.className = 'd-flex align-items-center gap-2';
            var name = document.createElement('span'); name.className = 'fw-semibold me-auto'; name.textContent = z.name;
            var info = document.createElement('span'); info.className = 'form-text m-0'; info.textContent = z.points.length + ' points';
            var del = document.createElement('button'); del.type = 'button'; del.className = 'btn btn-sm btn-outline-danger';
            del.innerHTML = '<i class="fas fa-trash"></i>'; del.setAttribute('aria-label', 'Delete ' + z.name);
            del.addEventListener('click', function () {
                if (!window.confirm('Delete the zone "' + z.name + '"?')) { return; }
                post({ action: 'zone_delete', head: head, index: i }).then(function (d) { zonesSaved(d); toast('Zone deleted.'); })
                    .catch(function (e) { toast(e.message, false); });
            });
            top.appendChild(name); top.appendChild(info); top.appendChild(del); item.appendChild(top);
            if (others.length) {
                var also = document.createElement('div'); also.className = 'd-flex flex-wrap align-items-center gap-2 mt-1 small';
                var lab = document.createElement('span'); lab.className = 'text-body-secondary'; lab.textContent = 'Also close:'; also.appendChild(lab);
                others.forEach(function (o) {
                    var wrap = document.createElement('label'); wrap.className = 'd-inline-flex align-items-center gap-1';
                    var cb = document.createElement('input'); cb.type = 'checkbox'; cb.className = 'form-check-input m-0';
                    cb.checked = (z.alsoCloses || []).indexOf(parseInt(o.value, 10)) >= 0;
                    cb.addEventListener('change', function () {
                        var ids = Array.prototype.slice.call(also.querySelectorAll('input')).map(function (c, k) { return c.checked ? parseInt(others[k].value, 10) : 0; }).filter(Boolean);
                        post({ action: 'zone_save', head: head, index: i, name: z.name, points: JSON.stringify(z.points), alsoCloses: JSON.stringify(ids) })
                            .then(function (d) { zonesSaved(d); toast('Saved.'); }).catch(function (e) { toast(e.message, false); });
                    });
                    wrap.appendChild(cb); wrap.appendChild(document.createTextNode(o.textContent)); also.appendChild(wrap);
                });
                item.appendChild(also);
            }
            list.appendChild(item);
        });
        markButtons();
    }
    function markButtons() {
        $id('adMarkPoint').disabled = !aim;
        $id('adMarkUndo').disabled = !draft.length;
        $id('adMarkCancel').disabled = !draft.length;
        $id('adMarkFinish').disabled = draft.length < 3;
        document.querySelectorAll('[data-tilt-limit="below"],[data-tilt-limit="above"]').forEach(function (b) { b.disabled = !aim; });
        var h = zonesData ? zonesData[zoneHead()] : null, lm = h && h.landmarks ? h.landmarks : {};
        document.querySelectorAll('[data-landmark],[data-landmark-now]').forEach(function (b) { b.disabled = !aim || tracing; });
        document.querySelectorAll('[data-landmark-now]').forEach(function (b) {
            var k = b.getAttribute('data-landmark-now');
            b.disabled = b.disabled || !lm[k];
            b.classList.toggle('active', !!realignNow[k]);
        });
        $id('adRealign').disabled = !aim || tracing || !Object.keys(realignNow).length;
        $id('adRealignUndo').classList.toggle('d-none', !(h && h.beforeRealign));
        $id('adRealignInfo').textContent = !lm['1'] && !lm['2'] ? 'No landmarks saved for this head yet.'
            : 'Saved: ' + ['1', '2'].filter(function (k) { return lm[k]; }).map(function (k) { return 'landmark ' + k + ' (pan ' + lm[k][0] + ', tilt ' + lm[k][1] + ')'; }).join(', ')
              + (Object.keys(realignNow).length ? '. Marked now: ' + Object.keys(realignNow).map(function (k) { return 'landmark ' + k; }).join(', ') + ': press Re-align.' : '.');
        $id('adTestZones').disabled = !aim || tracing || !(h && h.zones.length);
        $id('adTestZones').classList.toggle('d-none', tracing);
        $id('adTestStop').classList.toggle('d-none', !tracing);
        $id('adMarkInfo').textContent = !aim ? 'Press Aim to steer the head, then mark the corners of an area.'
            : draft.length === 0 ? 'Point at a corner of the area and press Mark point. Three or more points make a zone.'
            : draft.length < 3 ? draft.length + ' point' + (draft.length === 1 ? '' : 's') + ' marked: keep going round the area.'
            : draft.length + ' points marked. Name it and press Finish zone, or keep marking.';
    }
    $id('adMarkPoint').addEventListener('click', function () {
        if (!aim) { return; }
        aimLastPress = Date.now();
        var last = draft[draft.length - 1];
        if (last && last[0] === aim.pan && last[1] === aim.tilt) { toast('That point is already marked: move the head first.', false); return; }
        draft.push([aim.pan, aim.tilt]); zonesDraw();
    });
    $id('adMarkUndo').addEventListener('click', function () { draft.pop(); zonesDraw(); });
    $id('adMarkCancel').addEventListener('click', function () { draft = []; zonesDraw(); });
    $id('adMarkFinish').addEventListener('click', function () {
        var head = zoneHead(), name = $id('adMarkName').value.trim();
        post({ action: 'zone_save', head: head, name: name, points: JSON.stringify(draft) }).then(function (d) {
            draft = []; $id('adMarkName').value = ''; zonesSaved(d);
            toast('Zone saved' + (name ? ': ' + name : '') + '. The shutter now closes there.');
        }).catch(function (e) { toast(e.message, false); });
    });
    document.querySelectorAll('[data-tilt-limit]').forEach(function (b) {
        b.addEventListener('click', function () {
            var side = b.getAttribute('data-tilt-limit');
            if (side !== 'clear' && !aim) { return; }
            aimLastPress = Date.now();
            post({ action: 'tilt_limit', head: zoneHead(), side: side, tilt: aim ? aim.tilt : 0 }).then(function (d) {
                zonesSaved(d);
                toast(side === 'clear' ? 'Tilt limit cleared.' : 'Tilt limit set at ' + aim.tilt + '.');
            }).catch(function (e) { toast(e.message, false); });
        });
    });
    $id('adAimHead').addEventListener('change', function () { draft = []; realignNow = {}; zonesDraw(); });

    /* ---------- DeadZones: landmarks and re-aligning next season ---------- */
    var realignNow = {}, tracing = null;
    document.querySelectorAll('[data-landmark]').forEach(function (b) {
        b.addEventListener('click', function () {
            if (!aim) { return; }
            aimLastPress = Date.now();
            var k = b.getAttribute('data-landmark'), h = zonesData ? zonesData[zoneHead()] : null;
            if (h && h.landmarks && h.landmarks[k] && !window.confirm('Replace landmark ' + k + '? Only do this when the zones are right for where the head is now.')) { return; }
            post({ action: 'landmark_set', head: zoneHead(), slot: k, pan: aim.pan, tilt: aim.tilt }).then(function (d) {
                delete realignNow[k]; zonesSaved(d); toast('Landmark ' + k + ' saved at pan ' + aim.pan + ', tilt ' + aim.tilt + '.');
            }).catch(function (e) { toast(e.message, false); });
        });
    });
    document.querySelectorAll('[data-landmark-now]').forEach(function (b) {
        b.addEventListener('click', function () {
            if (!aim) { return; }
            aimLastPress = Date.now();
            realignNow[b.getAttribute('data-landmark-now')] = [aim.pan, aim.tilt]; markButtons();
        });
    });
    $id('adRealign').addEventListener('click', function () {
        var f = { action: 'realign', head: zoneHead() };
        Object.keys(realignNow).forEach(function (k) { f['now' + k] = JSON.stringify(realignNow[k]); });
        post(f).then(function (d) { realignNow = {}; zonesSaved(d); toast('Zones re-aligned. Use Test zones to check them.'); })
            .catch(function (e) { toast(e.message, false); });
    });
    $id('adRealignUndo').addEventListener('click', function () {
        post({ action: 'realign_undo', head: zoneHead() }).then(function (d) { zonesSaved(d); toast('Re-align undone.'); })
            .catch(function (e) { toast(e.message, false); });
    });

    /* ---------- DeadZones: Test zones (the head traces each outline) ---------- */
    function traceStop(msg) {
        if (tracing) { clearInterval(tracing.timer); tracing = null; }
        $id('adTestInfo').textContent = msg || 'The head traces round each zone\u2019s edge so you can check it outside (turn the beam on to see it).';
        markButtons();
    }
    $id('adTestZones').addEventListener('click', function () {
        var h = zonesData ? zonesData[zoneHead()] : null;
        if (!aim || !h || !h.zones.length) { return; }
        // Every point along every edge, about 3 steps apart, with a short pause at the start of each zone.
        var path = [];
        h.zones.forEach(function (z, zi) {
            var p = z.points.concat([z.points[0]]);
            for (var w = 0; w < 6; w++) { path.push({ p: p[0], zone: zi }); }
            for (var i = 1; i < p.length; i++) {
                var dx = p[i][0] - p[i - 1][0], dy = p[i][1] - p[i - 1][1], n = Math.max(1, Math.ceil(Math.max(Math.abs(dx), Math.abs(dy)) / 3));
                for (var j = 1; j <= n; j++) { path.push({ p: [Math.round(p[i - 1][0] + dx * j / n), Math.round(p[i - 1][1] + dy * j / n)], zone: zi }); }
            }
        });
        var at = 0;
        tracing = { timer: setInterval(function () {
            if (!aim) { traceStop('Test stopped: aiming ended.'); return; }
            if (aimBusy) { return; }   // wait for the head to catch up rather than pile up requests
            if (at >= path.length) { traceStop('Test finished: every zone traced.'); return; }
            var step = path[at++];
            aimLastPress = Date.now();
            aim.pan = step.p[0]; aim.tilt = step.p[1];
            $id('adTestInfo').textContent = 'Tracing ' + h.zones[step.zone].name + ' (' + (step.zone + 1) + ' of ' + h.zones.length + ')\u2026';
            aimShow();
            aimSend({ action: 'aim_set', pan: aim.pan, tilt: aim.tilt });
        }, 150) };
        markButtons();
    });
    $id('adTestStop').addEventListener('click', function () { traceStop('Test stopped.'); });

    /* ---------- DeadZones: export and import ---------- */
    $id('adZonesExport').addEventListener('click', function () {
        post({ action: 'zones_get' }).then(function (d) {
            var heads = {};
            Object.keys(d.heads || {}).forEach(function (k) {
                var h = d.heads[k];
                if (h.zones.length || h.tiltMin > 0 || h.tiltMax < 255 || Object.keys(h.landmarks || {}).length) {
                    heads[k] = { tiltMin: h.tiltMin, tiltMax: h.tiltMax, zones: h.zones, landmarks: h.landmarks };
                }
            });
            var blob = new Blob([JSON.stringify({ airdefenderZones: 1, exported: new Date().toISOString(), heads: heads }, null, 1)], { type: 'application/json' });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob); a.download = 'airdefender-zones-' + new Date().toISOString().slice(0, 10) + '.json';
            document.body.appendChild(a); a.click(); a.remove();
            setTimeout(function () { URL.revokeObjectURL(a.href); }, 5000);
        }).catch(function (e) { toast('Could not export the zones: ' + e.message, false); });
    });
    $id('adZonesImport').addEventListener('click', function () { $id('adZonesFile').value = ''; $id('adZonesFile').click(); });
    $id('adZonesFile').addEventListener('change', function () {
        var file = this.files && this.files[0]; if (!file) { return; }
        if (!window.confirm('Replace every head\u2019s zones, tilt limits and landmarks with the ones in ' + file.name + '?')) { return; }
        file.text().then(function (text) { return post({ action: 'zones_import', zones: text }); })
            .then(function (d) { zonesData = d.heads || {}; draft = []; realignNow = {}; zonesDraw(); toast('Imported ' + d.imported + ' zone' + (d.imported === 1 ? '' : 's') + '.'); })
            .catch(function (e) { toast(e.message, false); });
    });

    /* ---------- DeadZones: pan, tilt and dimmer from xLights' show file ---------- */
    function xlName(t) { return String(t || '').toLowerCase().replace(/[^a-z0-9]/g, ''); }
    $id('adXlImport').addEventListener('click', function () { $id('adXlFile').value = ''; $id('adXlFile').click(); });
    var xlModels = {};
    // Fill one head's row from an xLights moving head model; returns how many values it found.
    function xlApply(dz, m, saves) {
        var n = dz.getAttribute('data-dz-head');
        function num(el, attr) { var v = el ? parseFloat(el.getAttribute(attr)) : NaN; return isNaN(v) || v <= 0 ? null : Math.round(v); }
        var pan = m.getElementsByTagName('PanMotor')[0], tilt = m.getElementsByTagName('TiltMotor')[0];
        var vals = {
            // Newer xLights keeps each motor in its own element; older files have them on the model.
            PanCh: num(pan, 'ChannelCoarse') || num(m, 'DmxPanChannel'),
            PanRange: num(pan, 'RangeOfMotion') || num(m, 'DmxPanDegOfRot'),
            PanSlew: num(pan, 'SlewLimit'),
            TiltCh: num(tilt, 'ChannelCoarse') || num(m, 'DmxTiltChannel'),
            TiltRange: num(tilt, 'RangeOfMotion') || num(m, 'DmxTiltDegOfRot'),
            TiltSlew: num(tilt, 'SlewLimit'),
            DimmerCh: num(m, 'MhDimmerChannel') || num(m, 'DmxDimmerChannel'),
            ShutterOpen: num(m, 'DmxShutterOpen')
        };
        var got = 0;
        Object.keys(vals).forEach(function (f) {
            var v = vals[f], input = dz.querySelector('[data-dz-field="' + f + '"]');
            if (v === null || !input || v < parseInt(input.min, 10) || v > parseInt(input.max, 10)) { return; }
            got++;
            if (String(v) !== input.value) { input.value = v; var r = save('head' + n + f, v); if (saves) { saves.push(r); } }
        });
        return got;
    }
    function xlDone(done, empty) {
        aimHeads();
        if (done.length) { toast('Filled in from xLights: ' + done.join(', ') + '. Check the numbers.'); }
        if (empty.length) { toast('No pan or tilt found in the xLights model for: ' + empty.join(', ') + '.', false); }
    }
    /*
     * Read xLights' show file (and, if picked too, its controllers file): its moving head models by name
     * (sorted names in .names), every model (.all), and the controllers' first channels, so a head's start
     * channel can be worked out the way xLights does.
     */
    function xlParse(files) {
        files = Array.prototype.slice.call(files);
        return Promise.all(files.map(function (f) { return f.text(); })).then(function (texts) {
            var xl = { models: {}, names: [], all: {}, ctrl: {}, univ: {}, hasNetworks: false }, gotModels = false;
            texts.forEach(function (text) {
                var doc = new DOMParser().parseFromString(text, 'text/xml');
                if (doc.getElementsByTagName('parsererror').length) { return; }
                if (doc.documentElement.nodeName === 'Networks') { xlNetworks(doc, xl); return; }
                Array.prototype.forEach.call(doc.getElementsByTagName('model'), function (m) {
                    gotModels = true;
                    xl.all[m.getAttribute('name')] = m;
                    if (/^DmxMovingHead/.test(m.getAttribute('DisplayAs') || '')) { xl.models[m.getAttribute('name')] = m; }
                });
            });
            if (!gotModels) { throw new Error('Pick xLights\u2019 show file, xlights_rgbeffects.xml (and xlights_networks.xml with it for start channels).'); }
            xl.names = Object.keys(xl.models).sort(function (a, b) { return a.localeCompare(b, undefined, { numeric: true }); });
            if (!xl.names.length) { throw new Error('No moving head models found in xLights\u2019 show file.'); }
            return xl;
        });
    }
    /* Controllers in xlights_networks.xml, in order: each starts where the one before ends (as in xLights' Controllers tab). */
    function xlNetworks(doc, xl) {
        xl.hasNetworks = true;
        var next = 1, root = doc.documentElement;
        var ctrls = Array.prototype.filter.call(root.children, function (c) { return c.nodeName === 'Controller'; });
        // Older files have no controllers, just outputs: each is its own block.
        var blocks = ctrls.length ? ctrls : Array.prototype.filter.call(root.children, function (c) { return c.nodeName === 'network'; });
        blocks.forEach(function (c) {
            var outs = c.nodeName === 'Controller' ? Array.prototype.filter.call(c.children, function (o) { return o.nodeName === 'network'; }) : [c];
            if (c.getAttribute('Name')) { xl.ctrl[xlName(c.getAttribute('Name'))] = next; }
            outs.forEach(function (o) {
                var u = o.getAttribute('ComPort') || o.getAttribute('Universe'), ip = o.getAttribute('ComPort') && (c.getAttribute('IP') || o.getAttribute('IP'));
                if (u && /^\d+$/.test(u)) {
                    if (!xl.univ[u]) { xl.univ[u] = next; }
                    if (ip) { xl.univ[ip + ':' + u] = next; }
                }
                next += Math.max(0, parseInt(o.getAttribute('MaxChannels'), 10) || 0);
            });
        });
    }
    /* A model's first channel, as xLights works it out from its Start Channel; null if it can't be. */
    function xlStart(xl, name, depth) {
        var m = xl.all[name];
        if (!m || depth > 20) { return null; }
        var sc = (m.getAttribute('StartChannel') || '').trim(), r;
        if (/^\d+$/.test(sc)) { return parseInt(sc, 10); }
        if ((r = sc.match(/^!(.+):(\d+)$/))) { var c = xl.ctrl[xlName(r[1])]; return c ? c + parseInt(r[2], 10) - 1 : null; }
        if ((r = sc.match(/^#(?:([^:]+):)?(\d+):(\d+)$/))) {
            var u = xl.univ[r[1] ? r[1] + ':' + r[2] : r[2]] || xl.univ[r[2]];
            return u ? u + parseInt(r[3], 10) - 1 : null;
        }
        if ((r = sc.match(/^([@>])(.+):(\d+)$/))) {
            var base = xlStart(xl, r[2], depth + 1);
            if (base === null) { return null; }
            if (r[1] === '@') { return base + parseInt(r[3], 10) - 1; }
            var size = xlSize(xl.all[r[2]]);   // ">": after the end of that model
            return size ? base + size + parseInt(r[3], 10) - 1 : null;
        }
        return null;
    }
    /* How many channels a model uses: known for DMX models (their channel count); null for others. */
    function xlSize(m) {
        if (!m || !/^Dmx/.test(m.getAttribute('DisplayAs') || '')) { return null; }
        var n = parseInt(m.getAttribute('parm1'), 10);
        return n > 0 ? n : null;
    }
    $id('adXlFile').addEventListener('change', function () {
        if (!this.files || !this.files.length) { return; }
        xlParse(this.files).then(function (xl) {
            xlModels = xl.models;
            var names = xl.names;
            var byName = {}; names.forEach(function (k) { byName[xlName(k)] = xlModels[k]; });
            var done = [], empty = [], rowsEl = $id('adXlPickRows');
            rowsEl.textContent = '';
            document.querySelectorAll('#adDzHeads tr[data-dz-head]:not(.d-none)').forEach(function (dz) {
                var n = dz.getAttribute('data-dz-head'), row = document.querySelector('#adHeads tr[data-head="' + n + '"]');
                if (imported(row)) { return; }   // already filled in by the Moving heads import
                var name = label(row), m = byName[xlName(name)] || byName[xlName(modelOf(row))];
                if (m) { (xlApply(dz, m) ? done : empty).push(name); return; }
                // No match by name: let them pick (or skip) which xLights moving head this is.
                var line = document.createElement('label'); line.className = 'd-flex flex-wrap align-items-center gap-2';
                var t = document.createElement('span'); t.style.minWidth = '7rem'; t.textContent = name;
                var sel = document.createElement('select'); sel.className = 'form-select form-select-sm w-auto'; sel.setAttribute('data-xl-head', n);
                [''].concat(names).forEach(function (k) { var o = document.createElement('option'); o.value = k; o.textContent = k || 'Skip this head'; sel.appendChild(o); });
                line.appendChild(t); line.appendChild(sel); rowsEl.appendChild(line);
            });
            $id('adXlPick').classList.toggle('d-none', !rowsEl.children.length);
            xlDone(done, empty);
        }).catch(function (e) { toast(e.message, false); });
    });
    $id('adXlPickApply').addEventListener('click', function () {
        var done = [], empty = [];
        document.querySelectorAll('#adXlPickRows select').forEach(function (sel) {
            if (!sel.value || !xlModels[sel.value]) { return; }
            var dz = document.querySelector('#adDzHeads tr[data-dz-head="' + sel.getAttribute('data-xl-head') + '"]');
            var name = dz.querySelector('[data-dz-name]').textContent;
            (xlApply(dz, xlModels[sel.value]) ? done : empty).push(name);
        });
        $id('adXlPick').classList.add('d-none');
        xlDone(done, empty);
    });
    $id('adXlPickCancel').addEventListener('click', function () { $id('adXlPick').classList.add('d-none'); });

    /* ---------- Moving heads: add them from xLights' show file ---------- */
    var mhXl = null, mhXlFiles = [];
    var canPickFolder = 'webkitdirectory' in document.createElement('input') && !/Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
    if (!canPickFolder) {
        $id('adMhXlHelp').innerHTML = 'For <b>Import models from xLights</b>, pick <b>xlights_rgbeffects.xml</b> and <b>xlights_networks.xml</b> from your xLights show folder.';
        $id('adMhXlAgain').classList.add('d-none');
    }
    // The browser lists the whole folder before the page gets it, which can take a while: show that it's working
    // from when the picker closes (the page gets focus back) until the files arrive or the picker was cancelled.
    // Bootstrap ignores show/hide while a pop-up is still opening or closing, so each one is told what it
    // should be and catches up when its animation ends (a quick folder can close it while it's still opening).
    var mhXlWant = { adMhXlBusy: false, adMhXlProblem: false };
    function mhXlModalSet(id, on) {
        mhXlWant[id] = on;
        try {
            var m = bootstrap.Modal.getOrCreateInstance($id(id));
            if (on) {
                // One at a time: the failure pop-up waits until "Reading..." has closed.
                if (id === 'adMhXlProblem' && $id('adMhXlBusy').classList.contains('show')) { return; }
                m.show();
            } else { m.hide(); }
        } catch (e) {}
    }
    ['adMhXlBusy', 'adMhXlProblem'].forEach(function (id) {
        $id(id).addEventListener('shown.bs.modal', function () { if (!mhXlWant[id]) { mhXlModalSet(id, false); } });
        $id(id).addEventListener('hidden.bs.modal', function () {
            if (mhXlWant[id]) { mhXlModalSet(id, true); }
            if (id === 'adMhXlBusy' && mhXlWant.adMhXlProblem) { mhXlModalSet('adMhXlProblem', true); }
        });
    });
    // Closed with its X (or a click outside): it's not wanted any more.
    $id('adMhXlProblem').addEventListener('hide.bs.modal', function () { mhXlWant.adMhXlProblem = false; });
    function mhXlBusy(on) {
        $id('adMhXlImport').disabled = on;
        mhXlModalSet('adMhXlBusy', on);
    }
    var mhXlWait = null, mhXlTry = 0;
    function mhXlStop() { if (mhXlWait) { clearTimeout(mhXlWait); mhXlWait = null; } mhXlBusy(false); }
    function mhXlPickFolder() {
        var attempt = ++mhXlTry;   // a late answer from an abandoned pick is ignored
        $id('adMhXlDir').value = '';
        $id('adMhXlDir').setAttribute('data-try', attempt);
        $id('adMhXlPick').classList.add('d-none');
        window.addEventListener('focus', function back() {
            window.removeEventListener('focus', back);
            if (attempt !== mhXlTry) { return; }
            mhXlBusy(true);
            // Nothing after 90 s (a browser question left unanswered, or a picker closed without saying so): stop, and say what to do.
            mhXlWait = setTimeout(function () {
                if (attempt !== mhXlTry) { return; }
                mhXlTry++; mhXlStop();
                mhXlProblem('<b>Timed out.</b> Nothing came back from your browser after 90 seconds. If it asked whether to upload the folder, it needs a <b>yes</b> (nothing leaves this computer).');
            }, 90000);
        });
        $id('adMhXlDir').click();
    }
    $id('adMhXlBusyCancel').addEventListener('click', function () { mhXlTry++; mhXlStop(); });
    $id('adMhXlDir').addEventListener('cancel', function () { mhXlStop(); });
    function mhXlPickFiles() { $id('adMhXlFile').value = ''; $id('adMhXlFile').click(); }
    // Why the import failed, in a pop-up with the ways forward (again, files, or by hand).
    function mhXlProblem(html) {
        $id('adMhXlPick').classList.add('d-none');
        $id('adMhXlProblemText').innerHTML = html;
        if (typeof bootstrap === 'undefined') { toast($id('adMhXlProblemText').textContent, false); return; }
        mhXlModalSet('adMhXlProblem', true);
    }
    function mhXlProblemHide() { mhXlModalSet('adMhXlProblem', false); }
    $id('adMhXlImport').addEventListener('click', function () { if (canPickFolder) { mhXlPickFolder(); } else { mhXlPickFiles(); } });
    $id('adMhXlAgain').addEventListener('click', function () { mhXlProblemHide(); mhXlPickFolder(); });
    $id('adMhXlFilesInstead').addEventListener('click', function () { mhXlProblemHide(); mhXlPickFiles(); });
    $id('adMhXlByHand').addEventListener('click', function () {
        mhXlProblemHide();
        // An empty head ready to fill in: the first one with nothing entered, else a new one.
        var empty = rows.filter(function (r) { return !r.classList.contains('d-none') && !field(r, 'Name').value.trim() && !modelOf(r) && !field(r, 'Start').value; })[0];
        if (!empty && !$id('adAddHead').classList.contains('d-none')) { $id('adAddHead').click(); return; }
        if (empty) { empty.scrollIntoView({ block: 'center' }); field(empty, 'Name').focus(); }
    });
    // A folder: just its two setup files (the one nearest the top, if a backup folder has copies too). Nothing else is read.
    $id('adMhXlDir').addEventListener('change', function () {
        var all = Array.prototype.slice.call(this.files || []);
        if (String(mhXlTry) !== this.getAttribute('data-try')) { return; }   // cancelled or timed out meanwhile
        mhXlStop();
        if (!all.length) { return; }
        function find(n) {
            return all.filter(function (f) { return f.name.toLowerCase() === n; })
                .sort(function (a, b) { return (a.webkitRelativePath || '').split('/').length - (b.webkitRelativePath || '').split('/').length; })[0];
        }
        var rgb = find('xlights_rgbeffects.xml'), net = find('xlights_networks.xml');
        var folder = ((all[0].webkitRelativePath || '').split('/')[0]) || 'that folder';
        if (!rgb) {
            var div = document.createElement('div'); div.textContent = folder;
            mhXlProblem('<b>Wrong folder?</b> Couldn\u2019t find xLights\u2019 show file (<b>xlights_rgbeffects.xml</b>) in <b>' + div.innerHTML + '</b>. Pick the folder xLights uses as its show folder: in xLights, <b>File &rarr; Show Folder</b> shows where it is.');
            return;
        }
        mhXlLoad(net ? [rgb, net] : [rgb]);
    });
    $id('adMhXlFile').addEventListener('change', function () { if (this.files && this.files.length) { mhXlLoad(Array.prototype.slice.call(this.files)); } });
    $id('adMhXlNetPick').addEventListener('click', function () { $id('adMhXlNetFile').value = ''; $id('adMhXlNetFile').click(); });
    $id('adMhXlNetFile').addEventListener('change', function () {
        if (this.files && this.files[0]) { mhXlLoad(mhXlFiles.filter(function (f) { return !/networks/i.test(f.name); }).concat([this.files[0]])); }
    });
    function mhXlLoad(files) {
        mhXlFiles = files;
        xlParse(files).then(function (xl) {
            mhXl = xl;
            var list = $id('adMhXlRows'); list.textContent = '';
            var needNet = false;
            xl.names.forEach(function (name) {
                var here = rows.filter(function (r) { return !r.classList.contains('d-none') && xlName(label(r)) === xlName(name); })[0];
                var line = document.createElement('label'); line.className = 'd-flex align-items-center gap-2';
                var cb = document.createElement('input'); cb.type = 'checkbox'; cb.className = 'form-check-input m-0'; cb.checked = true; cb.value = name;
                var t = document.createElement('span'); t.textContent = name;
                var note = document.createElement('span'); note.className = 'form-text m-0';
                var opt = Array.prototype.slice.call(field(rows[0], 'Model').options).some(function (o) { return o.value && o.value !== '@xlights' && xlName(o.value) === xlName(name); });
                var start = xlStart(xl, name, 0);
                if (!opt && !start && !xl.hasNetworks) { needNet = true; }
                note.textContent = [here ? 'already here: will be updated' : '',
                    opt ? 'FPP model' : start ? 'starts at channel ' + start : 'start channel: enter by hand'].filter(Boolean).join(', ');
                line.appendChild(cb); line.appendChild(t); line.appendChild(note); list.appendChild(line);
            });
            $id('adMhXlNoNet').classList.toggle('d-none', !needNet);
            $id('adMhXlPick').classList.remove('d-none');
        }).catch(function (e) { var d = document.createElement('div'); d.textContent = e.message; mhXlProblem('<b>Couldn\u2019t read it.</b> ' + d.innerHTML); });
    }
    $id('adMhXlCancel').addEventListener('click', function () { $id('adMhXlPick').classList.add('d-none'); });
    $id('adMhXlApply').addEventListener('click', function () {
        if (!mhXl) { return; }
        var picked = Array.prototype.slice.call(document.querySelectorAll('#adMhXlRows input:checked')).map(function (c) { return c.value; });
        if (!picked.length) { toast('Tick at least one head.', false); return; }
        var saves = [], added = [], updated = [], noShutter = [], noChannel = [], full = [];
        var used = rows.filter(function (r) { return !r.classList.contains('d-none') && (label(r) !== 'MH' + r.getAttribute('data-head') || field(r, 'Model').value || field(r, 'Start').value); });
        picked.forEach(function (name) {
            var m = mhXl.models[name];
            var row = rows.filter(function (r) { return xlName(label(r)) === xlName(name); })[0];
            if (!row) {
                // The first slot not in use (an empty, untouched head 1 counts as free).
                row = rows.filter(function (r) { return used.indexOf(r) < 0; })[0];
                if (!row) { full.push(name); return; }
                added.push(name);
            } else { updated.push(name); }
            used.push(row);
            var n = row.getAttribute('data-head');
            function put(f, v) { saves.push(save('head' + n + f, v)); }
            put('Enabled', 1); put('Name', name);
            // By FPP model when FPP has one with the same name (xLights' upload names them the same); else xLights' start channel.
            var opt = Array.prototype.slice.call(field(row, 'Model').options).filter(function (o) { return o.value && o.value !== '@xlights' && xlName(o.value) === xlName(name); })[0];
            if (opt) { put('Model', opt.value); put('Source', ''); } else if (modelOf(row) && updated.indexOf(name) >= 0) {
                // Already set up on an FPP model by another name: leave it on that model.
            } else {
                put('Model', '');
                var start = xlStart(mhXl, name, 0), sh = parseInt(m.getAttribute('DmxShutterChannel'), 10);
                if (start > 0 && start <= <?php echo AIRDEFENDER_MAX_CHANNEL; ?>) { put('Start', start); } else { noChannel.push(name); }
                // Locked as "Imported" only when both channels came from the files; otherwise left Manual to finish by hand.
                put('Source', start > 0 && start <= <?php echo AIRDEFENDER_MAX_CHANNEL; ?> && sh > 0 && sh <= 512 ? 'xlights' : '');
            }
            var shutter = parseInt(m.getAttribute('DmxShutterChannel'), 10);
            if (shutter > 0 && shutter <= 512) { put('Offset', shutter); } else { noShutter.push(name); }
            var dz = document.querySelector('#adDzHeads tr[data-dz-head="' + n + '"]');
            if (dz) { xlApply(dz, m, saves); }
        });
        if (full.length) { toast('No room for: ' + full.join(', ') + ' (Air Defender protects up to ' + rows.length + ' heads).', false); }
        var msg = [];
        if (added.length) { msg.push('Added ' + added.join(', ')); }
        if (updated.length) { msg.push('Updated ' + updated.join(', ')); }
        var later = [];
        if (noShutter.length) { later.push('No shutter channel in xLights for ' + noShutter.join(', ') + ': enter it by hand.'); }
        if (noChannel.length) { later.push('Couldn\u2019t work out the start channel for ' + noChannel.join(', ') + (mhXl.hasNetworks ? '' : ' (pick xlights_networks.xml along with the show file)') + ': enter the Start ch. by hand.'); }
        try { sessionStorage.setItem('airdefenderXlMsg', JSON.stringify({ ok: msg.join('. ') + (msg.length ? '. Check each head\u2019s FPP channel and closed value.' : ''), warn: later.join(' ') })); } catch (e) {}
        // Reload once everything is saved, so every head shows exactly what was saved.
        $.when.apply($, saves).always(function () { location.reload(); });
    });
    try {
        var xlMsg = JSON.parse(sessionStorage.getItem('airdefenderXlMsg') || 'null');
        sessionStorage.removeItem('airdefenderXlMsg');
        if (xlMsg) { if (xlMsg.ok) { toast(xlMsg.ok); } if (xlMsg.warn) { toast(xlMsg.warn, false); } }
    } catch (e) {}

    /* ---------- DeadZones: Check my sequences ---------- */
    var CHECK = 'api/plugin-apis/AirDefender/deadzones/check', checkQueue = [], checkSeqs = [], checking = false;
    function fmtTime(ms) { var s = ms / 1000, m = Math.floor(s / 60); return m + ':' + (s - m * 60).toFixed(1).padStart(4, '0'); }
    // GET reads progress; starting or stopping a check is a POST (form fields seq / stop).
    function checkGet(q) {
        var opts = { credentials: 'same-origin', cache: 'no-store' };
        if (q) { opts.method = 'POST'; opts.headers = { 'Content-Type': 'application/x-www-form-urlencoded' }; opts.body = q.replace(/^\?/, ''); }
        return fetch(CHECK, opts)
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { if (!r.ok) { throw new Error(d.error || (r.status === 404 ? 'The DeadZones part of Air Defender isn\u2019t running (see the status above).' : 'HTTP ' + r.status)); } return d; }); });
    }
    function checkRow(seq) {
        var id = 'adCheck-' + seq.replace(/[^A-Za-z0-9]/g, '_'), item = $id(id);
        if (!item) {
            item = document.createElement('div'); item.className = 'list-group-item'; item.id = id;
            var top = document.createElement('div'); top.className = 'd-flex align-items-center gap-2';
            var name = document.createElement('span'); name.className = 'fw-semibold me-auto text-break'; name.textContent = seq.replace(/\.fseq$/i, '');
            var st = document.createElement('span'); st.className = 'form-text m-0 text-nowrap'; st.setAttribute('data-check-state', '');
            var one = document.createElement('button'); one.type = 'button'; one.className = 'btn btn-sm btn-outline-secondary text-body'; one.textContent = 'Check';
            one.setAttribute('data-check-one', '');
            one.addEventListener('click', function () { checkStart([seq]); });
            top.appendChild(name); top.appendChild(st); top.appendChild(one); item.appendChild(top);
            var det = document.createElement('div'); det.className = 'small mt-1'; det.setAttribute('data-check-detail', ''); item.appendChild(det);
            $id('adCheckList').appendChild(item);
        }
        return item;
    }
    function checkShow(seq, state, cls, detail) {
        var item = checkRow(seq), st = item.querySelector('[data-check-state]');
        st.textContent = state; st.className = 'form-text m-0 text-nowrap ' + (cls || '');
        var det = item.querySelector('[data-check-detail]'); det.textContent = '';
        (detail || []).forEach(function (line) {
            var d = document.createElement('div'); d.textContent = line; det.appendChild(d);
        });
    }
    function checkLoad() {
        if (checking) { return; }
        fetch('api/sequence', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (list) {
            checkSeqs = (Array.isArray(list) ? list : []).map(function (n) { return String(n); })
                .map(function (n) { return /\.fseq$/i.test(n) ? n : n + '.fseq'; });
            // Keeps results already shown; adds new sequences and drops deleted ones.
            Array.prototype.slice.call($id('adCheckList').children).forEach(function (item) {
                if (!checkSeqs.some(function (n) { return 'adCheck-' + n.replace(/[^A-Za-z0-9]/g, '_') === item.id; })) { item.remove(); }
            });
            checkSeqs.forEach(function (n) { checkRow(n); });
            $id('adCheckAll').disabled = !checkSeqs.length;
            $id('adCheckMsg').textContent = checkSeqs.length ? checkSeqs.length + ' sequence' + (checkSeqs.length === 1 ? '' : 's') + '.' : 'No sequences on this FPP yet.';
        }).catch(function () { $id('adCheckMsg').textContent = 'Couldn\u2019t get the sequence list from FPP.'; });
    }
    function checkButtons() {
        $id('adCheckAll').classList.toggle('d-none', checking);
        $id('adCheckStop').classList.toggle('d-none', !checking);
        $id('adCheckBar').classList.toggle('d-none', !checking);
        document.querySelectorAll('[data-check-one]').forEach(function (b) { b.disabled = checking; });
    }
    function checkStart(list) {
        checkQueue = list.slice(); checking = true; checkButtons();
        list.forEach(function (n) { checkShow(n, 'Waiting\u2026'); });
        checkNext();
    }
    function checkDone(msg) {
        checking = false; checkQueue = []; checkButtons(); $id('adCheckMsg').textContent = msg || '';
        // Ones it didn't get to go back to blank.
        document.querySelectorAll('#adCheckList [data-check-state]').forEach(function (st) { if (/Waiting/.test(st.textContent)) { st.textContent = ''; } });
    }
    function checkNext() {
        if (!checking) { return; }
        var seq = checkQueue.shift();
        if (!seq) {
            var bad = document.querySelectorAll('#adCheckList .text-danger').length;
            checkDone(bad ? bad + ' sequence' + (bad === 1 ? ' points' : 's point') + ' a lit beam into a zone: see below.' : 'All checked: no lit beams into zones.');
            return;
        }
        checkShow(seq, 'Checking\u2026');
        $id('adCheckMsg').textContent = 'Checking ' + seq.replace(/\.fseq$/i, '') + '\u2026';
        checkGet('?seq=' + encodeURIComponent(seq)).then(function () { checkPoll(seq); })
            .catch(function (e) { checkShow(seq, 'Not checked', 'text-warning-emphasis', [e.message]); if (/playing|zones yet|isn.t running/.test(e.message)) { checkDone(e.message); } else { checkNext(); } });
    }
    function checkPoll(seq) {
        if (!checking) { return; }
        checkGet('').then(function (d) {
            $id('adCheckBar').firstElementChild.style.width = (d.progress || 0) + '%';
            if (d.busy) { checkShow(seq, 'Checking\u2026 ' + (d.progress || 0) + '%'); setTimeout(function () { checkPoll(seq); }, 700); return; }
            if (d.error) { checkShow(seq, 'Not checked', 'text-warning-emphasis', [d.error]); if (/started playing/.test(d.error)) { checkDone(d.error); return; } checkNext(); return; }
            var r = d.result || {}, lines = [];
            (r.heads || []).forEach(function (h) {
                (h.events || []).forEach(function (e) { lines.push(h.name + ': ' + fmtTime(e.start) + ' to ' + fmtTime(e.end) + ', ' + e.why); });
                if (h.more) { lines.push(h.name + ': and more (only the first 100 are listed)'); }
            });
            if (lines.length) { checkShow(seq, lines.length + ' to fix', 'text-danger', lines); } else { checkShow(seq, 'OK', 'text-success-emphasis'); }
            checkNext();
        }).catch(function (e) { checkShow(seq, 'Not checked', 'text-warning-emphasis', [e.message]); checkNext(); });
    }
    $id('adCheckAll').addEventListener('click', function () { checkStart(checkSeqs); });
    $id('adCheckStop').addEventListener('click', function () {
        checkGet('?stop=1').catch(function () {});
        document.querySelectorAll('#adCheckList [data-check-state]').forEach(function (st) { if (/Checking|Waiting/.test(st.textContent)) { st.textContent = 'Stopped'; st.className = 'form-text m-0 text-nowrap'; } });
        checkDone('Stopped.');
    });

    // DeadZones' pages (Heads, Zones, Check); the last one used is remembered in this browser.
    function dzTab(name, remember) {
        document.querySelectorAll('[data-dz-tab]').forEach(function (b) {
            var on = b.getAttribute('data-dz-tab') === name;
            b.classList.toggle('active', on); b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        document.querySelectorAll('[data-dz-pane]').forEach(function (p) { p.classList.toggle('d-none', p.getAttribute('data-dz-pane') !== name); });
        if (name !== 'zones') { traceStop(); aimLeave(); }   // aiming only while its page is showing
        if (name === 'zones') { aimHeads(); zonesLoad(); }
        if (name === 'check') { checkLoad(); }
        if (remember) { try { localStorage.setItem('airdefenderDzTab', name); } catch (e) {} }
    }
    document.querySelectorAll('[data-dz-tab]').forEach(function (b) {
        b.addEventListener('click', function () { dzTab(b.getAttribute('data-dz-tab'), true); });
    });

    // Leaving the page (or the tab) gives the head back to the show.
    function aimLeave() {
        if (!aim) { return; }
        try { navigator.sendBeacon(API, new URLSearchParams({ action: 'aim_end' })); } catch (e) {}
        aim = null; aimShow(); aimHeads(); aimTimers();
    }
    window.addEventListener('pagehide', aimLeave);
    document.querySelectorAll('#adTabs a').forEach(function (a) { a.addEventListener('shown.bs.tab', function () { if (a.id !== 'ad-dz-tab') { aimLeave(); } }); });
    if ($id('deadZonesEnabled')) { $id('deadZonesEnabled').addEventListener('change', function () { if (!this.checked) { aimLeave(); } aimHeads(); }); }
    document.querySelectorAll('#adDzHeads [data-dz-field]').forEach(function (i) { i.addEventListener('change', aimHeads); });
    if ($id('ad-dz-tab')) { $id('ad-dz-tab').addEventListener('shown.bs.tab', aimHeads); }

    updatePlaylists();
    refresh();
    updateDeadZones();
    aimHeads();
    try { var dzt = localStorage.getItem('airdefenderDzTab'); if (dzt && document.querySelector('[data-dz-tab="' + dzt + '"]')) { dzTab(dzt, false); } } catch (e) {}
})();
</script>
