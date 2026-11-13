/*
 * Air Defender - Copyright (C) 2026 G Hancox. Licensed under the GNU GPL v2; see LICENSE.
 *
 * Tests for the DeadZones frame check, with no FPP needed:
 *   g++ -std=c++17 -Wall -Wextra -o /tmp/dz_test tests/deadzones_core_test.cpp && /tmp/dz_test
 */
#include "../src/DeadZonesCore.h"

#include <cstdio>
#include <cstring>

using namespace airdefender;

static int failures = 0;
#define CHECK(what, cond) do { if (!(cond)) { failures++; std::printf("FAIL: %s (line %d)\n", what, __LINE__); } \
                               else { std::printf("ok:   %s\n", what); } } while (0)

// One head: pan on channel 0, tilt on 1, shutter on 2 (open = 255, closed = 0), another head's shutter on 12.
static DzConfig oneHead() {
    DzConfig c; c.enabled = true; c.margin = 0; c.holdMs = 0;
    DzHead h; h.id = 1; h.name = "Left"; h.panCh = 0; h.tiltCh = 1; h.shutterCh = 2; h.closedValue = 0;
    DzZone z; z.name = "House";
    z.outline = { {100, 50}, {150, 50}, {150, 100}, {100, 100} };   // a square
    h.zones.push_back(z);
    c.heads.push_back(h);
    return c;
}
static void frame(uint8_t* d, int pan, int tilt) { std::memset(d, 0, 16); d[0] = pan; d[1] = tilt; d[2] = 255; d[12] = 255; d[5] = 77; }

int main() {
    uint8_t d[16];
    DzEngine e;

    // Off: never touches anything.
    DzConfig off = oneHead(); off.enabled = false; e.setConfig(off);
    frame(d, 125, 75); e.process(0, d, 16);
    CHECK("off: shutter left open inside a zone", d[2] == 255);

    e.setConfig(oneHead());
    frame(d, 20, 20); e.process(0, d, 16);
    CHECK("outside the zone: shutter open", d[2] == 255);
    frame(d, 125, 75); e.process(25, d, 16);
    CHECK("inside the zone: shutter closed", d[2] == 0);
    CHECK("only the shutter changed", d[0] == 125 && d[1] == 75 && d[5] == 77 && d[12] == 255);
    CHECK("says it's closed by zone 0", e.getStates()[0].reason == DZ_ZONE && e.getStates()[0].zone == 0);
    frame(d, 20, 20); e.process(50, d, 16);
    CHECK("left the zone, no hold, no slew: open again", d[2] == 255);
    CHECK("counted one close", e.getStates()[0].closes == 1);

    // Hold: stays closed for holdMs after leaving.
    DzConfig hold = oneHead(); hold.holdMs = 250; e.setConfig(hold);
    frame(d, 125, 75); e.process(0, d, 16);
    frame(d, 20, 20); e.process(25, d, 16);
    CHECK("hold: still closed 25 ms after leaving", d[2] == 0);
    frame(d, 20, 20); e.process(240, d, 16);
    CHECK("hold: still closed at 240 ms", d[2] == 0);
    frame(d, 20, 20); e.process(300, d, 16);
    CHECK("hold: open after it runs out", d[2] == 255);

    // Margin: just outside the outline still counts.
    DzConfig mar = oneHead(); mar.margin = 5; e.setConfig(mar);
    frame(d, 97, 75); e.process(0, d, 16);
    CHECK("margin: 3 outside the edge is closed", d[2] == 0);
    frame(d, 90, 75); e.process(25, d, 16);
    CHECK("margin: 10 outside the edge is open", d[2] == 255);
    frame(d, 97, 47); e.process(50, d, 16);
    CHECK("margin: near a corner is closed", d[2] == 0);

    // Slew: the data jumps across the zone in one frame; the head still has to swing through it.
    DzConfig slew = oneHead(); slew.heads[0].panPerMs = 0.1f;   // 100 values a second
    e.setConfig(slew);
    frame(d, 50, 75); e.process(0, d, 16);
    CHECK("slew: starts outside, open", d[2] == 255);
    frame(d, 200, 75); e.process(25, d, 16);     // target now past the zone; head really at 52.5
    CHECK("slew: data jumped past the zone, head not there yet: open", d[2] == 255);
    bool closedInside = false, openAfter = false; int t = 25;
    for (int i = 0; i < 80; i++) {
        t += 25; frame(d, 200, 75); e.process(t, d, 16);
        float real = e.getStates()[0].realPan;
        if (real >= 100 && real <= 150 && d[2] == 0) { closedInside = true; }
        if (real >= 100 && real <= 150 && d[2] != 0) { failures++; std::printf("FAIL: open while the head is really at %.1f\n", real); }
        if (real > 150 && d[2] == 255) { openAfter = true; }
    }
    CHECK("slew: closed while the head really swings through the zone", closedInside);
    CHECK("slew: open once the head is really past it", openAfter);
    CHECK("slew: the head ends up where the data says", e.getStates()[0].realPan == 200);

    // Leaving: the data leaves but the head is still inside until it catches up.
    e.setConfig(slew);
    frame(d, 125, 75); e.process(0, d, 16);
    frame(d, 20, 75); e.process(25, d, 16);
    CHECK("slew: data left the zone, head still in it: closed", d[2] == 0);

    // A long gap: the head has had time to arrive, so no stale position.
    e.setConfig(slew);
    frame(d, 125, 75); e.process(0, d, 16);
    frame(d, 20, 75); e.process(5000, d, 16);
    CHECK("gap: after 5 s the head is where the data says: open", d[2] == 255);
    frame(d, 125, 75); e.process(4000, d, 16);
    CHECK("clock went backwards: no crash, closed inside", d[2] == 0);

    // Tilt limit ("not too low").
    DzConfig lim = oneHead(); lim.heads[0].zones.clear(); lim.heads[0].tiltMin = 40; e.setConfig(lim);
    frame(d, 20, 39); e.process(0, d, 16);
    CHECK("tilt limit: below it is closed", d[2] == 0 && e.getStates()[0].reason == DZ_TILT);
    frame(d, 20, 40); e.process(25, d, 16);
    CHECK("tilt limit: at it is open", d[2] == 255);

    // A zone that also closes another head (id 2, shutter on 12, no pan/tilt of its own set).
    DzConfig two = oneHead(); two.heads[0].zones[0].alsoCloses = { 2 };
    DzHead h2; h2.id = 2; h2.name = "Right"; h2.shutterCh = 12; h2.closedValue = 7; two.heads.push_back(h2);
    e.setConfig(two);
    frame(d, 125, 75); e.process(0, d, 16);
    CHECK("also closes: the other head's shutter gets its own closed value", d[2] == 0 && d[12] == 7);
    CHECK("also closes: says why", e.getStates()[1].reason == DZ_OTHER_HEAD);
    frame(d, 20, 20); e.process(25, d, 16);
    CHECK("also closes: both open again after", d[2] == 255 && d[12] == 255);

    // Bad setups must never write outside the frame or crash.
    DzConfig bad = oneHead(); bad.heads[0].shutterCh = 9999; e.setConfig(bad);
    frame(d, 125, 75); e.process(0, d, 16);
    CHECK("shutter channel beyond the frame: nothing written", d[2] == 255);
    bad = oneHead(); bad.heads[0].panCh = 9999; e.setConfig(bad);
    frame(d, 125, 75); e.process(0, d, 16);
    CHECK("pan channel beyond the frame: head left alone", d[2] == 255);
    bad = oneHead(); bad.heads[0].zones[0].outline.resize(2); e.setConfig(bad);
    frame(d, 125, 75); e.process(0, d, 16);
    CHECK("zone with two points: ignored", d[2] == 255);
    e.setConfig(oneHead()); e.process(0, nullptr, 0);
    CHECK("no frame: no crash", true);

    // A triangle and a dented shape, to check the outline test itself.
    std::vector<DzPoint> tri = { {0, 0}, {100, 0}, {0, 100} };
    CHECK("triangle: inside", DzEngine::inZone(tri, 20, 20, 0));
    CHECK("triangle: outside the slope", !DzEngine::inZone(tri, 80, 80, 0));
    std::vector<DzPoint> dent = { {0, 0}, {100, 0}, {100, 100}, {50, 30}, {0, 100} };
    CHECK("dented shape: in a leg", DzEngine::inZone(dent, 10, 60, 0));
    CHECK("dented shape: in the dent is outside", !DzEngine::inZone(dent, 50, 80, 0));

    {
        // Aiming: the aimed head is left alone even inside a zone; when aiming ends it's checked again.
        DzEngine a; a.setConfig(oneHead()); a.setAiming(1);
        frame(d, 125, 75); a.process(1000, d, 16);
        CHECK("aiming: the aimed head's shutter stays open in a zone", d[2] == 255);
        a.setAiming(0);
        frame(d, 125, 75); a.process(1040, d, 16);
        CHECK("aiming ended: closed again in the zone", d[2] == 0);
        DzEngine o; o.setConfig(oneHead()); o.setAiming(2);
        frame(d, 125, 75); o.process(1000, d, 16);
        CHECK("aiming another head: this one still closes", d[2] == 0);
    }
    // Waiting for a sequence (apply = false): nothing touched, but the heads are still followed, so a sequence's
    // first sweep from rest through a zone is caught.
    {
        DzConfig c = oneHead(); c.heads[0].panPerMs = 0.1f;   // 100 values a second
        DzEngine w; w.setConfig(c);
        long long t = 0;
        for (; t < 1000; t += 25) { frame(d, 125, 75); w.process(t, d, 16, false); }
        CHECK("waiting: shutter left open even inside a zone", d[2] == 255);
        CHECK("waiting: nothing counted as closed", w.getStates()[0].closedUntil < 0 && w.getStates()[0].closes == 0);
        // Rest at pan 10, then the sequence starts and sends it to 200, through the zone (100-150).
        for (; t < 2000; t += 25) { frame(d, 10, 75); w.process(t, d, 16, false); }
        bool closedOnTheWay = false;
        for (; t < 5000; t += 25) { frame(d, 200, 75); w.process(t, d, 16, true); closedOnTheWay = closedOnTheWay || d[2] == 0; }
        CHECK("sequence starts: the sweep from rest through the zone closes the shutter", closedOnTheWay);
        CHECK("after the sweep: open again", d[2] == 255);
    }
    // The path between frames: a small zone (pan 120-124) that a single frame's move jumps right over.
    {
        DzConfig c = oneHead(); c.margin = 0;
        c.heads[0].zones[0].outline = { {120, 60}, {124, 60}, {124, 90}, {120, 90} };
        DzEngine p; p.setConfig(c);
        frame(d, 50, 75); p.process(0, d, 16);
        frame(d, 200, 75); p.process(15, d, 16);
        CHECK("path: no slew, the data jumps across a small zone in one frame: closed", d[2] == 0);
        frame(d, 200, 75); p.process(30, d, 16);
        CHECK("path: still there next frame, no hold: open again", d[2] == 255);

        frame(d, 50, 50); p.process(1000, d, 16);
        frame(d, 200, 50); p.process(1015, d, 16);
        CHECK("path: a jump that passes below the zone: open", d[2] == 255);
        frame(d, 118, 40); p.process(1030, d, 16);
        frame(d, 118, 110); p.process(1045, d, 16);
        CHECK("path: a jump that passes beside the zone: open", d[2] == 255);
        frame(d, 50, 75); p.process(5000, d, 16);
        frame(d, 200, 75); p.process(5600, d, 16);
        CHECK("path: after a long gap (head had time to arrive): not counted", d[2] == 255);

        DzConfig cm = c; cm.margin = 3; p.setConfig(cm);
        frame(d, 50, 58); p.process(0, d, 16);
        frame(d, 200, 58); p.process(15, d, 16);
        CHECK("path: passes 2 below the zone, margin 3: closed", d[2] == 0);

        // A fast head with slew set (8 values a frame at 66.7 fps) sweeping past: closed while it crosses.
        DzConfig cs = c; cs.heads[0].panPerMs = 0.55f; p.setConfig(cs);
        long long t = 0; frame(d, 50, 75); p.process(t, d, 16);
        bool closed = false;
        for (int i = 0; i < 40; i++) { t += 15; frame(d, 200, 75); p.process(t, d, 16); closed = closed || d[2] == 0; }
        CHECK("path: fast slewing head sweeps a 4-wide zone: closed on the way", closed);
        CHECK("path: and open once past", d[2] == 255);
    }
    std::printf("%s (%d failed)\n", failures ? "FAILED" : "All passed", failures);
    return failures ? 1 : 0;
}
