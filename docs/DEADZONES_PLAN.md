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

## To work out

- How FPP builds a plugin's C++ part on install, and what the plugin checker expects.
- Which FPP hook to use for changing channel data per frame, on FPP 10.x.
- Reading each head's pan/tilt channels (8- and 16-bit) from the models Air Defender
  already knows.
- The setup page: per head, a pan/tilt picture with the zones drawn on it.
- Testing with a real head on the rover Pi (Docker can check the logic, not the beam).

## Rules (as for everything in Air Defender)

Light on the Pi, easy on the SD card, and it works.
