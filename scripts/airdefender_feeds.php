<?php
/*
 * Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
 * Licensed under the GNU GPL v2; see LICENSE.
 *
 * The flight-data services Air Defender can ask. The show's location is only
 * ever sent to one of these (the ones the privacy block names): a saved
 * setting that isn't on the list is not used.
 *
 * adsb.fi is the default because it publishes its limit (1 request a second,
 * far above what Air Defender needs); adsb.lol publishes none, and has been
 * reported to limit some users to about 500 a day. Each is the other's backup.
 */

const AIRDEFENDER_FEED_ADSBFI = "https://opendata.adsb.fi/api/v3/lat/{lat}/lon/{lon}/dist/{radius}";
const AIRDEFENDER_FEED_ADSBLOL = "https://api.adsb.lol/v2/point/{lat}/{lon}/{radius}";
const AIRDEFENDER_DEFAULT_FEED = AIRDEFENDER_FEED_ADSBFI;
// A receiver of your own on the home network, at the address saved as
// receiverAddress (see airdefender_receiver.php).
const AIRDEFENDER_FEED_RECEIVER = 'receiver';

/* URL template => label, as offered in Settings. */
function airdefender_feed_options(): array
{
    return [
        AIRDEFENDER_FEED_ADSBFI => 'adsb.fi (default)',
        AIRDEFENDER_FEED_ADSBLOL => 'adsb.lol',
        AIRDEFENDER_FEED_RECEIVER => 'Your receiver on your network',
        'http://localhost/tar1090/data/aircraft.json' => 'Receiver on this FPP (tar1090)',
        'http://localhost:8080/data/aircraft.json' => 'Receiver on this FPP (dump1090-fa)',
    ];
}

/*
 * The feed to use for a saved setting: [url, note]. One that isn't on the
 * list (typed into the config file by hand, say) is not used: the default
 * service is, with a note saying so. Otherwise note is null.
 */
function airdefender_effective_feed(?string $saved): array
{
    $saved = trim((string) $saved);
    if ($saved === '') {
        return [AIRDEFENDER_DEFAULT_FEED, null];
    }
    if (!array_key_exists($saved, airdefender_feed_options())) {
        return [AIRDEFENDER_DEFAULT_FEED,
            "The saved flight data service isn't one Air Defender offers, so it is using adsb.fi. Pick a service in Settings."];
    }
    return [$saved, null];
}

/*
 * The address to ask a service for the aircraft around the show. It gets the
 * location to 2 decimal places (about 1 km), with the radius widened by 1 NM
 * to make up for it, so it returns the same aircraft without learning which
 * house is the show's. Air Defender works out each aircraft's distance from
 * the exact location itself.
 */
function airdefender_feed_url(string $template, float $lat, float $lon, float $nm): string
{
    return str_replace(
        ['{lat}', '{lon}', '{radius}'],
        [sprintf('%.2f', $lat), sprintf('%.2f', $lon), max(1, (int) ceil($nm)) + 1],
        $template
    );
}

/*
 * The online service to fall back on when $template does not answer, or null:
 * adsb.fi and adsb.lol back each other up, and a receiver of your own falls
 * back on adsb.fi. Only used with Use a backup service ticked (off by
 * default), so a show with a receiver that leaves it off never has its
 * location sent to an online service.
 */
function airdefender_feed_backup(string $template): ?string
{
    if ($template === AIRDEFENDER_FEED_ADSBFI) {
        return AIRDEFENDER_FEED_ADSBLOL;
    }
    if ($template === AIRDEFENDER_FEED_ADSBLOL) {
        return AIRDEFENDER_FEED_ADSBFI;
    }
    if (array_key_exists($template, airdefender_feed_options())) {
        return AIRDEFENDER_FEED_ADSBFI;   // a receiver of your own
    }
    return null;
}

/* A short name for a feed address: the service's name, or its host. */
function airdefender_feed_label(string $template): string
{
    $options = airdefender_feed_options();
    if (isset($options[$template])) {
        return preg_replace('/ \(default\)$/', '', $options[$template]);
    }
    return parse_url($template, PHP_URL_HOST) ?: 'custom feed';
}
