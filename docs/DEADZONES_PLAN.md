# DeadZones - plan (not built yet)

Status: planning only. Nothing on this branch changes how Air Defender works yet.

## The idea

An optional part of Air Defender, off unless turned on: mask out areas a moving head
must never point at (a neighbour's house, the road, a window) or below (too low).
When a head points into a masked area, its shutter closes for as long as it is there.
Same job as plane safety - "close the shutter when it isn't safe" - for a different reason.

## How it would work

- **Speed:** the check must happen on every frame (20-40 a second), as the show plays.
  The PHP loop (once a second) can't do that, so this part is a small compiled (C++)
  piece that FPP runs as part of the plugin, hooked into the show output just before
  each frame goes out.
- **Each frame:** read each head's pan and tilt from the frame, check it against that
  head's zones, and set the shutter closed for that frame if it's inside one.
  A few comparisons per head per frame: tiny for the Pi, and nothing written to the SD card.
- **Zones are in the head's own pan/tilt values,** so no fixture specs or real-world
  angles are needed.
- **Minimum tilt** per head for "not too low".
- **Sharing the shutter with plane safety:** closed if either says closed.
- **Fail safe:** if the zone code has a problem, it turns itself off and leaves the show
  and plane safety alone.

## Setting zones up (point and mark)

1. Point the head at the edge of the area to block (simple pan/tilt controls on the page,
   or FPP's own test tools).
2. Press **Mark point**; repeat around the area, like tracing its outline.
3. Save. The outline is a no-go zone for that head.

## Settings and pages (from planning, Nov 11)

- **Enable DeadZones** switch in Air Defender's settings, off by default.
- A **DeadZones** tab to draw, test and re-align zones.
- **Which heads a zone applies to:** all heads, or only the ones ticked. A zone can belong to
  one head (its own pan/tilt outline) or be shared by several.
- **Lag per head:** heads physically trail the data, so the shutter stays closed after the
  data leaves a zone. Better than a fixed delay: use each head's **pan and tilt slew limits**
  (degrees per second) to work out where the head really is, and keep the shutter closed
  until that position has left the zone. xLights moving head models have slew limits; find
  out whether they reach FPP when models are uploaded, or offer to import them, with a
  per-head value to type in (and a sensible default) when they aren't there.
- **Edge margin** so a head wobbling on an edge doesn't flicker.
- **Test zones** button that traces each zone's outline with the head, to check after a
  head drifts or is swapped.
- **Check my sequences** (manual): reads the sequence files and lists each moment a head
  would point into a DeadZone, to fix in xLights. Reads only; writes one small report.
- **Shutter, or dimmer if there's no shutter.** Most shows hold the shutter open and sequence the dimmer.
  On most heads the shutter and strobe share one channel (closed / open / strobe ranges); forcing it to its
  closed value blacks the beam whatever the dimmer does, as plane safety already does. Only fixtures with
  no shutter at all (strobe on the dimmer channel) need the dimmer forced to 0 instead.

## Head setup and next season

- Reuse what Air Defender already has per head (model, shutter channel, closed value). Add pan and
  tilt channels (8 or 16-bit) and slew limits as extra columns in the same head table, shown only
  with DeadZones on.
- **Heads won't sit in exactly the same spot every year.** Rather than re-drawing every zone:
  - each head has one or two **landmarks** (say, a house corner and the chimney) marked with the
    zones. Next season, point the head at the landmarks again and press **Re-align**: every zone
    for that head shifts by the same amount (pan/tilt offset, and a twist if two landmarks).
  - **Export / import zones** to a small file, so they survive a reinstall or a new SD card.
  - Re-drawing only if a head moves a lot (different mount or side of the yard).

## Other things to plan for

- The compiled part hooks into FPP itself: rebuild and retest after major FPP releases.
- FPP's maintainers review compiled code more closely: build cleanly on install, fail safe.
- Runs on whichever FPP outputs the heads (Player sending to a Falcon over the network,
  or a Remote with its own heads), like plane safety.

## Progress

- [x] Step 1: research (below).
- [x] Step 2: **Enable DeadZones** setting and a **DeadZones** tab in Settings with each head's pan and
  tilt details (channel, range, speed), saved as `head<N>PanCh`, `PanRange`, `PanSlew` and the same for
  `Tilt`. No fine channel: the main channel gives about 2 degrees a step, plenty for a zone's edge. Stored only; nothing reads them yet.
- [x] Step 3 (code): the compiled per-frame check (`src/`), the PHP side that feeds it
  (`scripts/airdefender_deadzones.php`, writing `plugindata/AirDefender/deadzones.json` only when it
  changes), the build on install/update that never fails the install (`scripts/build_deadzones.sh`), a
  live status on the DeadZones tab, and API docs. Tested: the check's tests (zones, margin, hold, slew,
  tilt limit, a zone closing other heads; about 2 microseconds a frame for 8 heads), the config reader
  against a file written by the PHP side, and a syntax check against FPP's real headers.
- [ ] Step 3 (on a real FPP): build it with FPP's makefiles (Docker or a Pi), time the build, see it
  load in fppd and the status show on the DeadZones tab. Couldn't be done here: FPP's Docker image
  can't be downloaded from this workspace.
- [ ] Step 4: drawing zones (mark points, landmarks and re-align, test zones, export/import), import from xLights.
- [ ] Step 5: Check my sequences.
- [ ] Step 6: real-head testing on a Pi before it goes near dev.

## Decision (Nov 11): built into Air Defender

DeadZones ships inside Air Defender, not as a separate add-on: one plugin, one head table, one
dashboard. Three guards: off by default (when off, the compiled part does one check per frame and
nothing else); the build is optional (if it fails, plane safety still installs and works, and the
DeadZones tab says it's unavailable); and it switches itself off if anything goes wrong inside it.
The extra install time is one small file to compile; measure it in Docker and on a Pi in step 3.

## Step 1 research (Nov 11) - what we found

**The per-frame hook exists and is supported.** FPP's plugin interface (`src/Plugin.h`,
`FPPPlugins::ChannelDataPlugin`) has
`modifyChannelData(int ms, uint8_t* seqData)`, called "immediately before sending to
outputs (after overlays)". That is exactly the point DeadZones needs: read pan/tilt from
`seqData`, write the shutter's closed value into it. Because it runs after overlays, it also
sits on top of plane safety's overlay (closed if either says closed, with no extra work).
FPP's own example plugin, [fpp-brightness](https://github.com/FalconChristmas/fpp-brightness),
changes channel data through this same hook.

**Building it.** A root-level `Makefile` that includes FPP's
`$(SRCDIR)/makefiles/common/setup.mk`, built by `make "SRCDIR=${SRCDIR}"` in
`scripts/fpp_install.sh` (never in preStart/postStart). The library is
`lib<repoName>.so`, so `libAirDefender.so`, exporting `createPlugin()`.
- **FPP core upgrades rebuild it for us:** FPP runs `make -C <plugin>` on every plugin with a
  root Makefile before restarting fppd. So "rebuild after a big FPP release" is automatic;
  what's left to us is fixing the source if FPP changes the interface.
- **The interface is versioned** (`FPP_PLUGIN_API_VERSION`, 6 on FPP 10.x). A plugin built
  against the wrong version is refused at load instead of crashing the show. Good for safety.
- **Unloading cleanly** (guideline 2.9): implement `shutdown()`, opt in with
  `FPP_PLUGIN_SUPPORTS_UNLOAD()`, use FPP's makefiles (`-fno-gnu-unique`). Our part starts no
  threads and registers no routes, so this is simple.
- **Install risk to plan for:** today Air Defender is scripts only and installs in seconds.
  With a compiled part, install compiles on the device (a minute or so on a Pi). If that
  build ever fails, plane safety must still install and work: make the build non-fatal, and
  show "DeadZones unavailable: the compiled part didn't build" on the DeadZones tab.

**xLights does have everything we need, but it doesn't reach FPP.** A moving head's pan and
tilt motors in xLights each store `ChannelCoarse`, `ChannelFine`, `MinLimit`, `MaxLimit`,
`RangeOfMotion`, `OrientHome`, `SlewLimit` (degrees per second, default 180) and reverse /
upside-down flags; the shutter has `DmxShutterChannel` and its open value. But when xLights
uploads models to FPP it only sends name, start channel, channel count and layout
(`CreateOverlayModelJSON` in xLights' `FPP.cpp`). So:
- **Import from xLights:** let the user pick their `xlights_rgbeffects.xml` (from the show
  folder) on the DeadZones tab; read each moving head's pan/tilt channels, 16-bit or not,
  range and slew limits, matched to the heads by model name. Parsed in the browser or PHP,
  once; nothing kept but the numbers.
- **Or type them in** per head, with defaults (8-bit, 180 deg/s).
- Range of motion lets zones be stored in degrees if we ever want; pan/tilt values are enough
  to start.

**Design consequences.**
- The compiled part reads a small zones file at load and when settings change (FPP calls
  `settingChanged()`), never per frame. Per frame it only does arithmetic in memory.
- Slew model per head: keep a "where the head really is" pan/tilt, moved toward the data's
  target by at most slew x frame time each frame; the shutter stays closed while either the
  target or that real position is inside a zone (plus the edge margin).
- Fail safe: any exception or bad zones file turns the zone check off and logs once.
- Later, plane safety itself could move onto this hook (no overlay models needed). Not now.

## To work out

- (Done, see Step 1 research) How FPP builds a plugin's C++ part, and which hook to use.
- What the plugin checker says about a Makefile and a `.so` built on install (run the linter
  on a skeleton before writing the real thing).
- Reading each head's pan/tilt channels (8- and 16-bit) from the models Air Defender
  already knows.
- The setup page: per head, a pan/tilt picture with the zones drawn on it.
- Testing with a real head on the rover Pi (Docker can check the logic, not the beam).

## Rules (as for everything in Air Defender)

Light on the Pi, easy on the SD card, and it works.
