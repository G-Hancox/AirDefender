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

        <div class="tab-pane fade" id="ad-dz" role="tabpanel" aria-labelledby="ad-dz-tab">
            <?php airdefender_print_group('DeadZones', array('deadZonesEnabled')); ?>
            <div class="alert alert-info">
                <b>Still being built.</b> DeadZones closes a head's shutter while it points into an area you've masked out.
                The check itself is in place, but zones can't be drawn on this page yet.
            </div>
            <div class="mb-3" id="adDzStatus" role="status"></div>
            <div id="adDzSetup" class="d-none">
                <!-- DeadZones' own pages, so the tab doesn't become one long page. -->
                <ul class="nav nav-tabs mb-3" role="tablist" aria-label="DeadZones pages">
                    <li class="nav-item" role="presentation"><button type="button" class="nav-link active" role="tab" aria-selected="true" data-dz-tab="heads">Heads</button></li>
                    <li class="nav-item" role="presentation"><button type="button" class="nav-link" role="tab" aria-selected="false" data-dz-tab="aim"><i class="fas fa-crosshairs"></i> Aim</button></li>
                    <li class="nav-item" role="presentation"><button type="button" class="nav-link" role="tab" aria-selected="false" data-dz-tab="live">Live</button></li>
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
                </div>

                <div class="d-none" data-dz-pane="aim" role="tabpanel">
                <h2 class="visually-hidden">Aim a head</h2>
                <p class="text-body-secondary">
                    Steer one head from here to find the edges of its zones. Only while no show is playing; every other head is left alone.
                    The shutter opens by itself; the beam starts off and comes on dim. Aiming ends when you leave this page or don't press
                    anything for two minutes, and plane safety still closes the shutter if an aircraft comes near.
                </p>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <select class="form-select w-auto" id="adAimHead" aria-label="Head to aim"></select>
                    <button type="button" class="btn btn-outline-secondary text-body" id="adAimStart"><i class="fas fa-crosshairs"></i> Aim</button>
                    <button type="button" class="btn btn-outline-danger d-none" id="adAimStop">Stop aiming</button>
                    <span class="form-text" id="adAimMsg" role="status"></span>
                </div>
                <div class="d-none" id="adAimPanel">
                    <div class="d-flex flex-wrap gap-4 align-items-start">
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
                </div>
                </div>

                <div class="d-none" data-dz-pane="live" role="tabpanel">
                <div class="form-text" id="adDzLiveNone">Nothing to show yet: DeadZones needs at least one head with pan and tilt set on the Heads page, and fppd running.</div>
                <div class="table-responsive d-none" id="adDzLiveWrap">
                    <h2 class="visually-hidden">Right now</h2>
                    <table class="table table-sm align-middle" id="adDzLive">
                        <thead><tr><th scope="col">Head</th><th scope="col">Pan</th><th scope="col">Tilt</th><th scope="col">Zones</th><th scope="col">Shutter</th></tr></thead>
                        <tbody></tbody>
                    </table>
                    <div class="form-text">Pan and tilt are the channel values (0-255) where the head really is, allowing for how fast it turns.</div>
                </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
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
    function toast(text, ok) { $.jGrowl(text, { themeState: ok === false ? 'danger' : 'success' }); }

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
            // Its DeadZones pan and tilt details go too, so a new head in this slot starts clean.
            document.querySelectorAll('#adDzHeads tr[data-dz-head="' + row.getAttribute('data-head') + '"] [data-dz-field]').forEach(function (input) {
                var k = key(row, input.getAttribute('data-dz-field')), def = input.getAttribute('data-dz-default');
                var blankValue = def === '' ? '0' : def;
                if (setting(k, blankValue) !== blankValue) { save(k, blankValue); }
                input.value = def;
            });
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
        var on = $id('deadZonesEnabled') && $id('deadZonesEnabled').checked, any = false;
        $id('adDzSetup').classList.toggle('d-none', !on);
        // One row for each head in use on the Moving heads tab, under the name it has there.
        document.querySelectorAll('#adDzHeads tr[data-dz-head]').forEach(function (dz) {
            var row = document.querySelector('#adHeads tr[data-head="' + dz.getAttribute('data-dz-head') + '"]');
            var used = row && !row.classList.contains('d-none');
            dz.classList.toggle('d-none', !used);
            if (used) { any = true; dz.querySelector('[data-dz-name]').textContent = label(row); }
        });
        $id('adDzNone').classList.toggle('d-none', any);
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

    // What the compiled part in fppd says, asked every 2 seconds only while this tab is showing.
    var dzTimer = null;
    function dzCell(tr, text, cls) { var td = document.createElement('td'); td.textContent = text; if (cls) { td.className = cls; } tr.appendChild(td); }
    function showDzStatus(d) {
        var box = $id('adDzStatus'), wrap = $id('adDzLiveWrap');
        if (!d) {
            box.className = 'mb-3 text-warning-emphasis';
            box.textContent = "DeadZones isn't available on this FPP right now: its compiled part isn't loaded in fppd. "
                + 'Restart FPPD; if this stays, it could not be built when Air Defender was installed. Everything else in Air Defender works without it.';
            wrap.classList.add('d-none');
            return;
        }
        var setUp = (d.heads || []).filter(function (h) { return h.setUp; }).length;
        box.className = 'mb-3 ' + (d.failed || d.error ? 'text-danger' : 'text-body-secondary');
        box.textContent = d.failed || d.error ? d.error
            : d.enabled ? 'DeadZones is running: ' + setUp + ' head' + (setUp === 1 ? '' : 's') + ' set up.'
            : 'DeadZones is ready and switched off.';
        var body = $id('adDzLive').querySelector('tbody');
        body.textContent = '';
        (d.heads || []).forEach(function (h) {
            var tr = document.createElement('tr');
            dzCell(tr, h.name || 'MH' + h.id);
            dzCell(tr, h.pan === null || h.pan === undefined ? '-' : h.pan);
            dzCell(tr, h.tilt === null || h.tilt === undefined ? '-' : h.tilt);
            dzCell(tr, h.zones);
            var why = { zone: 'Closed: in ' + (h.zone || 'a zone'), tilt: 'Closed: past its tilt limit', 'other-head': "Closed: another head's zone" }[h.reason];
            dzCell(tr, h.closed ? (why || 'Closed') : 'Show', h.closed ? 'text-warning-emphasis fw-semibold' : '');
            body.appendChild(tr);
        });
        wrap.classList.toggle('d-none', !d.enabled || !(d.heads || []).length);
        $id('adDzLiveNone').classList.toggle('d-none', !wrap.classList.contains('d-none'));
    }
    function pollDz() {
        fetch('api/plugin-apis/AirDefender/deadzones', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
            .then(function (d) { showDzStatus(d && d.loaded ? d : null); }, function () { showDzStatus(null); });
    }
    function watchDz() {
        var showing = $id('ad-dz') && $id('ad-dz').classList.contains('active') && !document.hidden;
        if (showing && !dzTimer) { pollDz(); dzTimer = setInterval(pollDz, 2000); }
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
        $id('adAimStop').classList.toggle('d-none', !on);
        $id('adAimStart').classList.toggle('d-none', on);
        $id('adAimHead').disabled = on;
        if (!on) { return; }
        $id('adAimRead').innerHTML = '';
        ['Pan ' + aim.pan, 'Tilt ' + aim.tilt].forEach(function (t) { var d = document.createElement('div'); d.textContent = t; $id('adAimRead').appendChild(d); });
        // Lit (yellow, solid bulb) while the beam is on; plain with an outline bulb while it's off, like xLights' Output to Lights.
        var beam = $id('adAimBeam');
        beam.className = 'btn btn-sm ' + (aim.beam ? 'btn-warning' : 'btn-outline-secondary text-body');
        beam.setAttribute('aria-pressed', aim.beam ? 'true' : 'false');
        beam.title = aim.beam ? 'Beam is on: press to turn it off' : 'Beam is off: press to turn it on';
        beam.innerHTML = '<i class="' + (aim.beam ? 'fas' : 'far') + ' fa-lightbulb"></i> Beam';
        $id('adAimLevel').value = aim.level;
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
    // DeadZones' pages (Heads, Aim, Live); the last one used is remembered in this browser.
    function dzTab(name, remember) {
        document.querySelectorAll('[data-dz-tab]').forEach(function (b) {
            var on = b.getAttribute('data-dz-tab') === name;
            b.classList.toggle('active', on); b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        document.querySelectorAll('[data-dz-pane]').forEach(function (p) { p.classList.toggle('d-none', p.getAttribute('data-dz-pane') !== name); });
        if (name !== 'aim') { aimLeave(); }   // aiming only while its page is showing
        if (name === 'aim') { aimHeads(); }
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
