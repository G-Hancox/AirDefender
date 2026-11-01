# Air Defender changelog

## 1.6.1 (2026-11-01)

Second round of FPP's review.

- fppd no longer waits on Air Defender when it starts or reloads the plugin: the start runs in the background,
  and stopping also stops a start still under way.
- The update script asks fppd's API which FPP version is running, instead of looking inside FPP's code.
- Installing and uninstalling no longer ask for an FPPD restart: fppd loads and unloads the plugin itself.

## 1.6.0 (2026-10-31)

Changes from FPP's review for the plugin list.

- **Pages look like FPP's own.** Settings is built from FPP's setting helpers, in tabs like FPP's settings page:
  each setting saves as you change it (no Save button any more), with FPP's help icons. The dashboard uses FPP's
  styles and Bootstrap in light and dark; only the radar has styles of its own. Messages pop up the way FPP's do.
- **Started by fppd.** fppd starts the detection loop (and stops it) through the plugin's callbacks, also straight
  after an install or update, so it never runs under the web server. No restart is needed after installing.
- **Fix: a shutter could be held open over the show on FPP 10.0-10.2.** After fppd restarted during safety mode,
  releasing the heads asked fppd to delete overlays it no longer had, which those versions turn into a value of 255.
  Air Defender now only releases overlays set since fppd last started.
- **FPP's settings through its API:** the location, time zone, Player Mode and playlists come from FPP's API instead
  of its files, and the model list only from fppd (keeping the last good one when it can't answer). An FPP whose
  location was never set counts as having none, instead of using FPP's default in Colorado.
- **Location rounded:** the flight-data services get the show's location to about 1 km, with the search a mile
  wider to make up for it. Distances are still worked out from the exact location.
- **Less writing to the SD card:** the status file is written when something changes (and every 30 seconds), not
  every second.
- **A slow feed no longer looks like a stopped loop:** the dashboard says "Not running" only when the loop has
  really stopped, and a Remote closes its heads only when the Player says so or stops answering.
- **What it can't see:** the description, README, dashboard and help say plainly that Air Defender only sees aircraft
  that broadcast ADS-B, a little late, and doesn't replace your own obligations for sky-pointing beams and lasers.
- **The log is kept until Clear log** (the privacy block, help and README said 7 days, which FPP doesn't do).
- Only the listed flight-data services are ever used. A Reinstall from FPP 10.4 keeps a Remote's saved Player
  password. Removed code for settings from before 1.5.0.

## 1.5.0 (2026-10-28)

- **View log** on the dashboard (beside Clear log): when the heads were closed and opened, and tests, for Today,
  Yesterday or the Last 7 days, newest first, with how many times the heads closed. **Everything** shows the full log.
  It reads FPP's rotated copies too, and only when opened, so the dashboard stays light.

## 1.4.0 (2026-10-27)

- **Player and Remote:** the role uses FPP's own names (Master is now **Player**) everywhere: Settings, dashboard, log,
  help and README. Saved settings are unchanged.
- **Role follows FPP on a new install:** until it is saved, the role matches FPP's own Player Mode, so an FPP in
  Remote mode starts as a Remote. You can still change it, and Settings says when it no longer matches FPP's mode.
- README and help explain how a Remote follows its Player: it asks the Player's Air Defender once a second, so the
  Player needs no setting and MultiSync isn't involved.

## 1.3.0 (2026-10-27)

- **Your own ADS-B receiver:** new **Your receiver on your network** service, for a receiver on its own Pi (ADSB.im,
  PiAware, or readsb/dump1090 with tar1090). Enter its IP address; Test connection finds where it publishes its
  aircraft list (tar1090, SkyAware or port 8080) and Air Defender remembers it. Only home-network addresses are
  accepted, and the receiver is never sent your location.
- Receiver data more than 2 minutes old counts as the receiver not answering, so a stopped decoder can't look like an
  empty sky. This also applies to a receiver on the FPP itself (now labelled **Receiver on this FPP**).
- README: what you need for a receiver of your own, how to set it up and connect it, and what Test connection's
  messages mean. The command-line check takes `--receiver ADDRESS`.

## 1.2.1 (2026-10-26)

- Dashboard: shorter Standby messages ("Static Night isn't selected", "Waiting for a selected playlist"), and the role
  and mode (for example "Master · Selected playlists") moved to a line under the title, so the banner no longer
  crowds Check now.

## 1.2.0 (2026-10-26)

- **When to check** is now two clear choices: **All scheduled shows** (every scheduled show, as before) or **Only
  selected playlists** (only the playlists you tick, scheduled or started by hand). The playlist list is greyed out
  unless it's in use, and keeps your ticks.
- **Always** is gone: a saved Always reads as Only selected playlists with nothing ticked, which checks whenever a
  playlist plays. The old When playing setting is the same choice.
- Settings: the location and elevation sit under When to check, and the playlist list has the full height of the
  section beside them, in columns, so a long list fits.

## 1.1.0 (2026-10-26)

- New **Only these playlists** setting (Settings → When and where): tick the playlists that use your moving heads, and
  Air Defender checks only during those, so a late-night static show no longer keeps it checking. The dashboard's
  Standby says when a playlist that isn't ticked is playing. None ticked checks during every playlist, as before.
- Remote Falcon requests (and any playlist inserted into a show) keep Air Defender checking during a ticked scheduled
  show, because they play inside that show's schedule slot. Ticking Remote Falcon's playlist as well is recommended.

## 1.0.0 (2026-10-26)

First full release, tested on a live show: an FPP master sending DDP to a Falcon controller with the moving heads.
- Heads close over a playing sequence during a test and go back to the show when it ends.
- Checking starts 2 minutes before a scheduled show.
- After an FPPD restart during a test, the heads close again within seconds.
- Real aircraft coming into range close the shutters, and they reopen after the hold once the aircraft leaves.

No code changes since 0.10.0.

## 0.10.0 (2026-10-26)

Brought in line with FPP's plugin guidelines, ready for FPP's plugin list:
- **Light and dark:** the dashboard and settings follow FPP's theme. Light uses FPP's own colours; dark keeps the
  radar-scope green. Widths scale, so the pages fit a phone.
- **Log:** now `plugin-AirDefender.log` in FPP's logs folder, rotated by FPP (daily, 7 kept) instead of by Air
  Defender. The old log from earlier versions is left alone; delete it in File Manager → Logs.
- **Backup service off by default:** only the chosen service (adsb.fi) is sent anything unless you turn the backup
  on. A setting you already saved is kept.
- **Master's password:** moved out of the settings file into FPP's `plugindata` folder, readable only by the fpp
  user. A saved one is moved automatically. Uninstalling removes it and the log.
- **Paths:** FPP's folders are found the way FPP provides them, so a relocated media folder works. The loop's lock
  file is in the plugin folder, not `/tmp`.
- **Plugin card:** the description names every outside service and what it is sent.

## 0.9.6 (2026-10-26)

Ready for FPP's plugin list (FPP's plugin check now reports no findings):
- Talks to fppd through FPP's documented `/api/` address instead of fppd's internal port.
- Plugin Manager shows the Air Defender icon before install, not just after.
- An update asks for an FPPD restart, so changes to the Air Defender commands show up.
- The install script stops at the first error, and starting the loop no longer waits half a second when there is
  nothing to stop.

## 0.9.5 (2026-10-26)

- Fixed: on a fresh install the detection loop did not start until FPP was rebooted, and the dashboard said Not
  running. The install runs as root, so it created the log file owned by root; the loop runs as the fpp user and
  could not write to it, and the watchdog could not start it. The log is now given to the fpp user before the loop
  starts, and the loop runs even if its log cannot be written.
- The loop no longer takes an unreadable lock file for "already running" (it logs it and keeps retrying), and it
  starts without holding on to files or sockets left open by whatever started it.

## 0.9.4 (2026-10-26)

- New **Show elevation** setting: aircraft report their height above sea level, so Air Defender now subtracts your
  ground's elevation and the altitude limit means height above your show. **Look it up** fills it in from Open-Meteo
  for the location in use (FPP's or your own). Left at 0, nothing changes.
- The dashboard, radar, Test connection, log and command-line check show heights above the show once it is set.

## 0.9.3 (2026-10-26)

Fixes for a fresh install:
- Settings showed Air Defender as Enabled before anything was saved, while it was actually off (the dashboard said Disabled). It now starts off everywhere until you switch it on, so nothing is sent anywhere before then.
- Settings help now shows adsb.fi as the default service, and explains the backup option and the Remote's master password.
- A head typed into the empty first row was saved as Off unless its switch was turned on too. An empty row now switches itself on when you fill it in; a head you switch off on purpose stays off.

## 0.9.2 (2026-10-26)

- New radar-scope icon (shown on the plugin's card in FPP).
- The dashboard and settings use the icon's colours: radar greens, with orange for aircraft and safety, blue for tests, and red and yellow for problems.
- The dashboard's Sky radar is drawn like the icon, with a turning sweep (still for anyone whose system asks for reduced motion) and the distance limit marked inside the rim.

## 0.9.1 (2026-10-26)

- While on the backup flight-data service, the chosen one is now tried again every minute (was every 5 minutes), and Air Defender switches back as soon as it answers.
- The dashboard names both services while on the backup, for example "adsb.fi (backup: adsb.lol not answering)", so it is clear which one is chosen in Settings.

## 0.9.0 (2026-10-26)

First complete version, ready for live testing.

### Protection
- Closes each moving head's shutter with an FPP channel overlay while an aircraft is inside the distance and below the altitude limit, and hands the shutter back to the sequence afterwards. The DMX signal is never cut, since most fixtures hold their last values without it.
- Works for heads on FPP's own outputs (such as a Kulp's DMX port) and heads the master sends to over DDP or E1.31 (such as a Falcon).
- Holds the heads closed for a while after the aircraft leaves (default 30 seconds), and keeps them closed if the flight-data service stops answering while an aircraft was near.
- Optional "Close heads if feed is down" for stricter protection.
- Re-applies the shutter overlays every second, so they survive an fppd restart.
- A watchdog restarts the detection loop within seconds if it ever stops.

### When it checks
- Checks only during scheduled shows by default, starting 2 minutes before each one; or whenever FPP is playing; or always.
- "Check now" on the dashboard (or the Air Defender: Check Now command) checks outside show times for a set time, then turns itself off.

### Heads
- Pick each head by its model (uploaded from xLights by FPP Connect with Models set to All), or enter its start channel by hand; then the shutter's channel in the fixture and its closed value. Air Defender works out the FPP channel.
- Follows a head re-patched in xLights, and keeps protecting a head whose model was renamed or deleted.

### Master and Remote
- A Remote follows its master, including the master's tests, and closes its own heads if it loses the master for 5 seconds.
- Finds the master automatically, or uses an address you enter; supports a master with an FPP UI password.

### Flight data
- adsb.fi (default) and adsb.lol, each the other's automatic backup, or a local ADS-B receiver (never backed up by an online service).
- Test connection on the settings page.

### Dashboard and settings
- Dashboard with a status banner, Lite and Detailed views, a Sky radar, the heads' state, a test with countdown, and Last Event (safety on/off and tests only).
- Settings page in five sections with live hints and an unsaved-changes bar.
- FPP commands: Air Defender: Start Test, Stop Test and Check Now.
- The log keeps 7 days and can be cleared from the dashboard. Times follow FPP's time zone.
