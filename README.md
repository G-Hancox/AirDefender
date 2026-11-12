# Air Defender

**Moving-head safety for Falcon Player (FPP): closes the shutters while low aircraft fly near your show.**

Bright moving heads and beams pointed at the sky can dazzle pilots. Air Defender watches live ADS-B flight data
for your location, and while an aircraft is close enough and low enough, it holds your moving heads' shutters
closed. When the sky is clear again, it hands the shutters back to your sequence.

> **What it can't see.** Air Defender only reacts to aircraft that broadcast **ADS-B** and that your chosen service
> or receiver picks up. Some light aircraft and helicopters don't broadcast it, drones generally don't, online
> coverage thins out close to the ground, and the data is always a little old (your check interval plus the
> service's own delay). It is an extra layer of care, **not a replacement for your own obligations** for
> sky-pointing beams and lasers.

Version 1.7.0 (see [CHANGELOG.md](CHANGELOG.md)). For FPP 10 and later.

---

## How it protects your show

- While an aircraft is **inside your distance limit and below your altitude limit**, each enabled head's **shutter
  channel is held at its closed value** using an FPP channel overlay. FPP writes that value on every output frame,
  over whatever the sequence is doing, so it reaches the fixture however the channel is output: a Kulp's DMX port,
  a pixel cape, or E1.31/DDP to a Falcon or another controller.
- When the aircraft leaves, the heads stay closed for a short **hold** (30 seconds by default) so a plane on the edge
  of the area doesn't flash them on and off. Then the overlay is removed and **the sequence controls the shutter
  again**. Air Defender never forces a shutter open.
- **The DMX signal is never cut.** Most moving heads and lasers hold their last values when DMX stops, which could
  leave a beam frozen open, pointing up. Closing the shutter is the only way to know the fixture is dark.
- If the flight-data service stops answering while an aircraft was near, the heads stay closed until it answers
  again. (Turn on **Close heads if the feed is down** to close them on any outage.)
- The overlays are re-applied every second. fppd starts the detection loop when it starts (and stops it when it
  stops), so after an fppd restart the heads are held again within seconds, and a watchdog restarts the loop if it
  ever stops on its own.

## DeadZones (optional)

Closes a moving head's shutter while it points into an area you've marked out, such as a neighbour's window, the
road or a bedroom, and opens it again once the head has left. It is **off by default** and turned on in Settings, on
the **DeadZones** tab. Plane safety works the same whether it's on or off.

- **Heads**: each head's pan, tilt and dimmer channels, range and slew (how fast it really turns). **Import models
  from xLights** on the Moving heads tab fills these in from your show folder.
- **Zones**: steer a head from the page with **Aim** (only while nothing is playing; the beam stays off until you press
  **Beam**), mark the corners of an area and finish the zone. A picture shows the head as seen from the front of the
  house. Also: tilt limits, **landmarks** to **Re-align** the zones next season if a head goes back up a little
  differently, **Test zones** (the head traces each outline), and **Export** / **Import** to back them up.
- **Check** (optional): plays your sequences through the DeadZones check, without the lights, and lists each moment a
  lit beam points into a zone, so you can tidy it up in xLights. DeadZones protects every sequence during the show
  whether or not you check it.

During the show, a small compiled part inside fppd looks at every frame just before it goes out and, while a head
(allowing for its slew) points into one of its zones, sets that frame's shutter channel to its closed value. It
changes nothing else, costs about a microsecond a frame, and writes nothing to the SD card. The dashboard's Moving
heads card shows **DeadZone** and the zone while it holds a head closed. If it ever hits an error it switches itself
off and says why; plane safety doesn't depend on it.

The compiled part is built on your FPP when Air Defender is installed or updated (a few seconds), and FPP rebuilds it
when FPP itself is upgraded. If it can't be built, everything except DeadZones works as before.

## Install

**From FPP's Plugin Manager (recommended)**

1. In FPP, open **Content Setup → Plugin Manager**.
2. Paste this into the plugin info URL box and press **Retrieve Plugin Info**:

   ```
   https://raw.githubusercontent.com/G-Hancox/AirDefender/master/pluginInfo.json
   ```

3. Review the privacy summary and press **Install**. FPP starts Air Defender straight away; no restart is needed.

**By hand (SSH)**

```bash
cd /home/fpp/media/plugins/
git clone https://github.com/G-Hancox/AirDefender.git
```

Then restart FPPD (FPP only runs plugins installed by hand once fppd starts again).

**Updating:** press **Update** for Air Defender in Plugin Manager. fppd reloads it and the detection loop restarts
with the new code by itself. **Release Notes** in Plugin Manager shows the changelog.

## Quick start

1. Open **Air Defender → Settings** (from the Content Setup menu, or the dashboard's Settings button).
2. **Air Defender:** switch it **On** (a new install starts off). **Role** starts out matching FPP's own mode:
   **Player** on the FPP that runs the show (see [Player and Remote](#player-and-remote) if you have FPP Remotes).
3. **Moving heads:** add each head. Pick its **model**, or choose **Manual channel** and enter its **start channel**
   from xLights. Then enter the **shutter channel** from the fixture's DMX chart (for example 7) and the **closed**
   value (often 0). The row shows the FPP channel it works out, for example `→ FPP ch 82454`.
4. **When & where:** press **Check it on a map** and make sure the pin is on **your show**, not just your town (see
   [Check your location](#check-your-location)). Then press **Look it up** beside **Show elevation** so heights are
   measured from your show.
5. **Flight data:** press **Test connection**. You should see `OK · N aircraft nearby`.
6. Open the **dashboard** and run a **Test**: the heads should close within a second and open again when the timer
   ends. (Settings save as you change them, like FPP's own settings pages.)

Every page has help behind FPP's **?** button.

## Settings

The settings page has five tabs, like FPP's own settings. Each setting saves as you change it (a note pops up to
say so) and takes effect within a second; no restart is needed.

| Tab | What's there |
|---|---|
| **General** | Enable Air Defender; **Role** (Player or Remote, starting from FPP's own mode); on a Remote, the Player's address and UI password. |
| **Moving heads** | Up to 8 heads: name, model or manual start channel, shutter channel in the fixture, closed value. |
| **When & where** | **Check for aircraft during** (all scheduled shows, or only selected playlists) with the playlist list, the show location and its elevation. |
| **Limits** | Distance (NM), altitude (ft), hold after the aircraft leaves, and how often to check. |
| **Flight data** | The service (or your own receiver's address), **Test connection**, an optional backup service (also for your own receiver), and **Close heads if the feed is down**. |

On a Remote only **General** and **Moving heads** are shown: the rest is set on the Player.

### Picking heads by model

To list your heads by name, upload your models from xLights: in **FPP Connect**, set the **Models** column to
**All** for the FPP and upload. (**Local** only includes models on that FPP's own outputs, so it leaves out heads on
a Falcon or other controller.) You can also add models by hand on FPP's **Pixel Overlay Models** page. FPP does not
create models for DMX fixtures by itself, and xLights changes spaces in model names to underscores.

Heads picked by model are looked up by name, so a head re-patched in xLights and uploaded again is followed
automatically. If a model is renamed or deleted, its row turns red and Air Defender keeps protecting the channel it
last found for it until you pick the model again.

### Check your location

**Don't trust the location until you've seen it on a map.** FPP's own location (the default) is often set roughly,
or worked out from your internet connection, and can be miles from your yard. Everything Air Defender decides is
measured from this point: which aircraft are within your distance, and the ground elevation that heights are
measured from. If it's off by a few miles, planes right over your show can count as outside your limits.

1. Open **Settings → When & where** and press **Check it on a map**.
2. Zoom in: the pin should be on your property.
3. If it isn't, turn off **Use FPP's location** and enter your show's latitude and longitude. (In Google Maps,
   right-click your yard and click the numbers at the top of the menu to copy them.) Or fix the location in FPP's
   own settings.
4. Press **Look it up** again for **Show elevation**, so it matches the corrected spot.

### Show elevation

Aircraft report their height above sea level, not above your yard. Set **Show elevation** (feet above sea level) and
Air Defender subtracts it, so the altitude limit means height above your show: with the show at 800 ft and a 3,000 ft
limit, heads close for aircraft below 3,800 ft above sea level. This matters most on high ground; in Denver, at
5,300 ft, a 3,000 ft limit measured from sea level would never trigger.

**Look it up** fills it in for whichever location is in use (FPP's, or the one you entered) from Open-Meteo's
elevation data (Copernicus DEM, 90 m). It sends that location to api.open-meteo.com once, only when you press it. Left
at 0, heights are measured above sea level. The dashboard, radar, log and command-line check all show heights above
the show once it is set.

### When it checks

Air Defender only checks while there's a show to protect. Outside those times it is on **Standby**: it sends nothing
to the flight-data service and leaves the shutters to the show. **Check for aircraft during** (on **When & where**) has two choices:

- **All scheduled shows** (the default): checks during every scheduled show, starting **2 minutes before** each one
  so the heads are already protected when the lights come on.
- **Only selected playlists:** tick the playlists that use your moving heads in the list beside it. Air Defender checks
  only while one of those plays, scheduled or started by hand, and from 2 minutes before a scheduled one. The rest (a
  static display that runs late into the night, say) leave it on Standby. With nothing ticked it works like **All
  scheduled shows**. A sequence started on its own isn't part of a playlist, so it isn't checked unless the box below is
  ticked. The list is hidden with **All scheduled shows**, and your ticks are kept.
- **Also check whenever FPP is playing** (off by default): also checks while anything plays that the choice above
  leaves out, such as a single sequence or a playlist started by hand. Checking starts as soon as FPP starts playing,
  so there is no 2-minute head start: the heads stay closed for the few seconds until the first check answers, then
  open if the sky is clear.

**Using Remote Falcon?** A viewer's request plays from Remote Falcon's own playlist, inserted into your show. With
**Only selected playlists**, tick Remote Falcon's playlist too. (During a selected scheduled show Air Defender keeps
checking through requests anyway, because they play inside that show's schedule slot.)

To test a sequence at another time, use **Start tracking** on the dashboard (30 minutes to 4 hours), or the
**Air Defender: Check Now** command. It stops by itself when the time runs out.

## Player and Remote

Air Defender's **Role** uses FPP's own names. On a new install it follows FPP's **Player Mode** (Settings → FPP
Settings), and the Settings page says whether it still matches; you can change it if your setup needs something else.

| Your setup | Air Defender role |
|---|---|
| One FPP running the show | **Player** |
| An FPP sending E1.31/DDP to a Falcon or other controller with the heads | **Player** on that FPP (it covers the controller) |
| A Player with FPP **Remotes** (Kulp boards, say) that have heads on their own outputs | **Player** on the Player, **Remote** on each Remote with heads |

**How a Remote follows the Player.** A Remote plays its own copy of the sequence on its own outputs, kept in time by
FPP's MultiSync, so the Player's overlays can't reach its heads. So the Remote's Air Defender **asks the Player's Air
Defender for its status once a second**, over your network through FPP's web server, and closes its own heads whenever
the Player is in safety mode, including the Player's tests. The Player needs no setting for this and doesn't need to
know the Remotes exist, and it doesn't depend on MultiSync. If a Remote can't get an answer for **5 seconds** (Player
off or unreachable, Air Defender missing there, its loop not running, or it set to Remote), it closes its heads until
the Player is back. Install Air Defender on every Remote that has heads; a Remote with no heads enabled does nothing.

A Remote finds its Player automatically from FPP's MultiSync list; enter the **Player FPP address** if there is more
than one Player on the network. If the Player has an FPP **UI password**, enter it as **Player's UI password** on the
Remote (it logs in as `admin`, over your local network). The password is kept in its own file in FPP's `plugindata`
folder that only the fpp user can read (not with the other settings, which FPP's backups and crash reports copy), and
is never shown on the page.

## The dashboard

The **status banner** at the top says where things stand:

| Banner | Meaning |
|---|---|
| **All clear** (green) | No aircraft inside your limits; the show controls the shutters. |
| **Safety active** (orange) | The shutters are held closed, with the reason and when it started. |
| **Test running** (blue) | A test is closing the heads, with the time left. |
| **Standby** (grey-blue) | No show on, so not checking; shows the next scheduled show. |
| **Can't check the sky** (yellow) | The flight-data service isn't answering. |
| **Disabled** (grey) | Air Defender is switched off in Settings. |
| **Not running** (red) | The detection loop isn't running. Restart FPPD or reboot. |

Choose **Lite** (refreshes every 5 seconds) or **Detailed** (every 2 seconds, adds a **Sky** radar of nearby aircraft
and every head's state). Your choice is remembered in that browser. The dashboard only asks FPP for anything while it
is open.

On the radar, aircraft that report a heading are small arrows pointing the way they fly (inside a ring for a
helicopter, hollow for a drone, balloon or glider); the rest are dots. Click one for its type, registration, height,
speed and a link to follow it on a live map. To
match the radar to the way you face your display, press the lock beside **Sky**, drag the radar round (or use the
arrows), then press the lock again to save it.

- **Test** closes the heads for 5 seconds to 30 minutes so you can go outside and watch; a test on the Player also
  closes every Remote's heads.
- **Last Event** shows only safety turning on or off and tests. Everything else is in the full log.
- **View log** shows when the heads were closed and opened, and tests, for **Today**, **Yesterday** (handy when a show
  runs past midnight) or the **Last 7 days**, newest first, with how many times the heads closed. **Everything**
  shows the full log instead.
- **Clear log** deletes the Air Defender log. Nothing else trims it, so it keeps everything until you clear it.

## FPP commands

Available in playlists, schedules and command presets:

- **Air Defender: Start Test** (seconds): like Test on the dashboard.
- **Air Defender: Stop Test**: ends a running test.
- **Air Defender: Check Now** (minutes, 0 ends it): tracks aircraft outside show times, like Start tracking on the dashboard. (It keeps its old name so playlists that use it keep working.)

## Flight data

Air Defender asks a free community ADS-B service for the aircraft near your show:

| Service | Limits | Notes |
|---|---|---|
| **adsb.fi** (default) | Published: 1 request a second | Personal, non-commercial use. Data from [adsb.fi](https://adsb.fi). |
| **adsb.lol** | None published; some users report about 500 a day | Data from [adsb.lol](https://adsb.lol) under the ODbL. |
| **Your receiver on your network** | None | Your own ADS-B receiver on another Pi; nothing goes to the internet. See below. |
| **Receiver on this FPP** (tar1090 / dump1090-fa) | None | A receiver running on the FPP itself (not recommended; see below). |

Turn on **Use a backup service** (it is off until you do, since it means the backup service is also sent your
location) and, if the chosen one refuses, limits or doesn't answer, Air Defender asks the backup in the same check,
so there's no gap. adsb.fi and adsb.lol back each other up, and your own receiver falls back on adsb.fi: you keep
your receiver's speed and low-level coverage, with the internet as a safety net if its power, SD card or Wi-Fi fails.
It tries the chosen one again every minute and goes back to it when it answers. With the backup off, your own
receiver never sends anything to an online service.

The number of requests is your check interval over the time it checks: at 10 seconds, about 1,800 for a 5-hour show
night. The settings page shows the estimate as you change it.

airplanes.live is no longer offered: its API now refuses requests without an account. A saved airplanes.live setting
is switched to the default automatically.

### Using your own ADS-B receiver

A receiver at your house hears the aircraft near your show directly, including low ones that the community services'
volunteer receivers can miss. It needs no internet, has no request limits, and nothing is sent anywhere: the
receiver lists every aircraft it hears and Air Defender works out which are near the show.

**What you need**

- **A Raspberry Pi of its own** (a Pi 3B+, 4, 5 or Zero 2 W), with a microSD card and power supply. Keep it separate
  from your FPP: decoding radio takes CPU your show needs.
- **A USB radio for 1090 MHz:** an RTL-SDR stick, such as a FlightAware Pro Stick or an RTL-SDR Blog V3/V4.
- **A 1090 MHz antenna** and cable. Higher and clearer is better: outdoors on a roof or pole hears farthest, but even
  one indoors by a window hears low aircraft within a few miles.

**Software (pick one)**, and follow its own setup guide until you can see aircraft on its map in your browser:

- [ADSB.im](https://adsb.im): a ready-made Pi image with a web setup page. The easiest.
- [PiAware](https://www.flightaware.com/adsb/piaware/): FlightAware's Pi image, with its SkyAware map.
- Any Pi running readsb or dump1090-fa with tar1090.

**Connect it to Air Defender**

1. Find the receiver Pi's IP address (on your router's device list, or the receiver's own setup page). Reserving that
   address for it in your router stops it changing.
2. In **Settings → Flight data**, set **Service** to **Your receiver on your network** and enter the IP address in
   **Receiver address**, for example `192.168.1.50` (add `:port` if your receiver uses one).
3. Press **Test connection**. It finds where the receiver publishes its aircraft list (tar1090, SkyAware or port 8080)
   and shows `OK · N aircraft nearby · reading http://…`.

Air Defender only accepts addresses on your home network. If the receiver's decoder stops while its web page keeps
running, its data goes stale: Air Defender notices after 2 minutes and treats it like a feed that isn't answering.
Turn on **Close heads if the feed is down** if you want the heads closed whenever that happens.

| Test connection says | What to do |
|---|---|
| Can't reach the receiver | Check the Pi is on and on the network, and the address is right. |
| No aircraft data found at the usual places | Enter the full address of its `aircraft.json` (open the receiver's map and look for `data/aircraft.json`). |
| The receiver's data is N min old | Its decoder stopped, or its clock is wrong. Restart the receiver. |
| Not on your home network | Use the receiver's local IP address (usually 192.168.x.x or 10.x.x.x). |

The **Receiver on this FPP** choices read a receiver installed on the FPP itself. That works, but it isn't
recommended: decoding takes CPU from the show, and receiver web pages usually want the port FPP's own web server
uses.

## Privacy

- Your **show's location**, rounded to about 1 km, and search radius go to the flight-data service you pick
  (adsb.fi, adsb.lol, or nowhere with your own receiver), only while Air Defender is checking. Air Defender works
  out each aircraft's distance from your exact location itself. Your own receiver is only asked for its aircraft list.
- Pressing **Look it up** beside Show elevation sends the location once to api.open-meteo.com.
- A Remote asks its Player for its status over your local network, with the Player's UI password if you entered one.
- The log (`plugin-AirDefender.log` in FPP's logs folder) records the callsigns, distance and height of low aircraft
  that passed near your show. It is kept **until you press Clear log** on the dashboard (FPP only rotates plugin
  logs once they reach 10 MB, which this one takes a very long time to do).
- Nothing is sent anywhere until you switch Air Defender on. The backup service and the elevation lookup are off
  until you use them.
- Uninstalling removes the log and the saved Player password.
- Air Defender runs a background process while FPP is running, and holds the shutter channels you list while
  aircraft are near or during a test.
- With **DeadZones** on (off by default), a compiled part built at install runs inside fppd and closes a head's
  shutter in each frame while it points into an area you marked. **Import models from xLights** reads your xLights
  files in your browser only; nothing is sent anywhere.

The full disclosure is in `pluginInfo.json` and is shown by Plugin Manager before you install or update.

## Troubleshooting

Start with the dashboard banner and **Last Event**; the full log (**Content Setup → File Manager → Logs →
plugin-AirDefender.log**) explains every change.

| You see | What to do |
|---|---|
| **Not running** | Restart FPPD (or reboot). If it keeps happening, the log shows why the loop stopped. |
| **Can't check the sky** / "No answer from the ADS-B feed" | Check the FPP has internet access. Try **Test connection**, or the other service. |
| "refused the request (HTTP 403)" or "(HTTP 429)" | The service is refusing or limiting requests. Turn on **backup**, raise **Check every**, or switch service. |
| "No show location" | Set the location in FPP's settings, or turn off **Use FPP's location** and enter one. |
| Aircraft right overhead aren't counted, or the radar looks wrong | The show location is probably off. Press **Check it on a map**; see [Check your location](#check-your-location). |
| A head's row is red: "Model not found" | The model was renamed or deleted in xLights. Pick it again; until then the last channel is still protected. |
| Heads don't close during a test | Check each head is **On**, the **FPP channel** shown matches the shutter channel, and the **closed** value is right for the fixture. |
| **Standby** when you expected checking | No scheduled show is on, or the playlist playing isn't ticked. Use **Start tracking**, or change **Check for aircraft during**. |
| A Remote says "Lost the Player" | Check the Player is on, has Air Defender set to Player, and the address is right. If it says the Player has a UI password, enter it on the Remote. |
| The dashboard says "FPP refused the shutter channels" | fppd isn't accepting overlays; restart FPPD. |

**Command-line check** (over SSH): asks the flight-data service once with your saved settings and lists every
aircraft, marking those inside your limits.

```bash
php /home/fpp/media/plugins/AirDefender/scripts/airdefender_check.php
```

Options `--url`, `--lat`, `--lon`, `--distance`, `--altitude` and `--elevation` override the saved settings. Exit code: 0 clear,
1 an aircraft is inside the limits, 2 could not check.

## Questions, problems and ideas

[Report a problem, suggest a feature, or ask for a flight data service](https://github.com/G-Hancox/AirDefender/issues/new/choose)
on GitHub. **Air Defender - Help** in FPP's Help menu links there too.

## How it works (for developers)

| Piece | File |
|---|---|
| Detection loop: reads settings every second, checks the feed, decides, holds or releases the overlays | `scripts/airdefender_loop.php` |
| Watchdog that keeps the loop running; fppd starts and stops it (through the compiled part when it's built, else through the lifecycle callbacks) | `callbacks.sh`, `scripts/airdefender_supervise.sh`, `scripts/airdefender_start.sh`, `scripts/airdefender_stop.sh` |
| DeadZones, inside fppd: the per-frame check, its settings file, Check my sequences, and its routes (`/api/plugin-apis/AirDefender/deadzones`, `.../deadzones/check`) | `src/AirDefenderPlugin.cpp`, `src/DeadZonesCore.h`, `src/DeadZonesConfig.h`, `src/DeadZonesCheck.h`, `Makefile`, `scripts/build_deadzones.sh`; tests in `tests/` |
| DeadZones zones and aiming (writes `deadzones.json` for the compiled part when settings change) | `scripts/airdefender_deadzones.php`, `scripts/airdefender_aim.php` |
| Shutter overlays (`PUT /api/overlays/range/<ch>`, FPP's proxy to fppd) | `scripts/airdefender_controller.php` |
| Heads by model or manual channel | `scripts/airdefender_models.php` |
| Flight-data services, backup, fetching and parsing | `scripts/airdefender_feeds.php`, `scripts/airdefender_adsb.php` |
| Your own receiver: address check, finding its data, stale-data check | `scripts/airdefender_receiver.php` |
| Tests, Start tracking, Last Event, log | `scripts/airdefender_test.php`, `scripts/airdefender_status.php` |
| FPP's folders (media, logs, config), found the way FPP provides them | `scripts/airdefender_paths.php` |
| FPP's settings and playlists, through its API | `scripts/airdefender_fpp.php` |
| The Player's UI password (on a Remote), in `plugindata/AirDefender/` (0600) | `scripts/airdefender_secret.php` |
| Dashboard and its endpoint | `www/index.php`, `www/test_api.php` |
| Settings page, built from FPP's setting helpers | `www/settings.php`, `settings.json` |
| Help menu page (quick start, links) and GitHub request forms | `www/help.php`, `.github/ISSUE_TEMPLATE/` |
| Status a Remote reads from its master | `www/master_status.php` |
| Install, update and uninstall hooks | `scripts/fpp_install.sh`, `scripts/fpp_upgrade.sh`, `scripts/fpp_uninstall.sh` |

Runtime files written on the device (not in git): `heads.json`, `test.json`, `override.json`, `last_event.json` and
`loop.lock` in the plugin folder, `plugin-AirDefender.log` in FPP's logs folder, and the Player's password in
`plugindata/AirDefender/`. The status the dashboard reads, `state.json`, is kept in memory (`/dev/shm/AirDefender/`,
or the plugin folder where that can't be used), written when something in it changes and every 30 seconds.
`heads.json` holds only what must survive a reboot and is written only when it changes, to spare the SD card.
DeadZones keeps `deadzones.json` and `zones.json` in `plugindata/AirDefender/` (or the plugin folder), written only
when you change them; what's being aimed is in memory (`/dev/shm/AirDefender/aim.json`). The pages use FPP's styles and Bootstrap, so they follow FPP's light and dark themes.

## Credits

Created by **G Hancox**, built with **Claude** (Anthropic).

## License

Copyright (C) 2026 G Hancox.

GNU General Public License v2. See [LICENSE](LICENSE).
