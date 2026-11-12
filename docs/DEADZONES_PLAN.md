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
- A **Masking** tab to draw and test zones.
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

## To work out

- How FPP builds a plugin's C++ part on install, and what the plugin checker expects.
- Which FPP hook to use for changing channel data per frame, on FPP 10.x.
- Reading each head's pan/tilt channels (8- and 16-bit) from the models Air Defender
  already knows.
- The setup page: per head, a pan/tilt picture with the zones drawn on it.
- Testing with a real head on the rover Pi (Docker can check the logic, not the beam).

## Rules (as for everything in Air Defender)

Light on the Pi, easy on the SD card, and it works.
