// g++ -std=c++17 -I src -o /tmp/dzc tests/deadzones_check_test.cpp && /tmp/dzc
#include <cassert>
#include <cstdio>
#include <cstring>
#include "../src/DeadZonesCheck.h"
using namespace airdefender;

static DzConfig cfg() {
    DzConfig c; c.enabled = false; c.margin = 0; c.holdMs = 0;
    DzHead h; h.id = 1; h.name = "MH1"; h.panCh = 0; h.tiltCh = 1; h.shutterCh = 2; h.dimmerCh = 3; h.closedValue = 0;
    DzZone z; z.name = "House"; z.outline = { {100, 100}, {150, 100}, {150, 150}, {100, 150} };
    h.zones.push_back(z);
    c.heads.push_back(h);
    return c;
}

int main() {
    // 40 frames of 25 ms: pan sweeps into the zone (frames 10-19), beam on throughout except 15-16 dimmer 0.
    {
        DzScan s(cfg(), 25);
        for (unsigned f = 0; f < 40; f++) {
            uint8_t d[8] = {0};
            d[0] = (f >= 10 && f < 20) ? 120 : 20; d[1] = 120; d[2] = 255; d[3] = (f == 15 || f == 16) ? 0 : 200;
            s.frame(f, d, sizeof d);
        }
        const auto& r = s.results()[0];
        // dimmer off for 50 ms (< 500 ms join) so one moment: 250 ms to 500 ms
        assert(r.events.size() == 1);
        assert(r.events[0].startMs == 250 && r.events[0].endMs == 500 && r.events[0].why == "House");
    }
    // Beam off (shutter closed) while in the zone: nothing to report.
    {
        DzScan s(cfg(), 25);
        for (unsigned f = 0; f < 40; f++) { uint8_t d[8] = {120, 120, 0, 200}; s.frame(f, d, sizeof d); }
        assert(s.results()[0].events.empty());
    }
    // Two separate visits more than 500 ms apart: two moments.
    {
        DzScan s(cfg(), 50);
        for (unsigned f = 0; f < 60; f++) {
            uint8_t d[8] = {0}; d[0] = (f < 5 || (f >= 40 && f < 45)) ? 120 : 20; d[1] = 120; d[2] = 255; d[3] = 255;
            s.frame(f, d, sizeof d);
        }
        assert(s.results()[0].events.size() == 2);
        assert(s.results()[0].events[1].startMs == 2000);
    }
    // Slow slew: the head is still in the zone after the data leaves it, so the moment runs on.
    {
        DzConfig c = cfg(); c.heads[0].panPerMs = 0.1f;   // 100 values a second
        DzScan s(c, 25);
        for (unsigned f = 0; f < 80; f++) {
            uint8_t d[8] = {0}; d[0] = f < 40 ? 125 : 10; d[1] = 120; d[2] = 255; d[3] = 255;
            s.frame(f, d, sizeof d);
        }
        const auto& e = s.results()[0].events;
        assert(e.size() == 1 && e[0].startMs == 0 && e[0].endMs > 1000);   // data left at 1000 ms; the head ~250 ms later
    }
    // Tilt limit.
    {
        DzConfig c = cfg(); c.heads[0].tiltMin = 50;
        DzScan s(c, 25);
        for (unsigned f = 0; f < 4; f++) { uint8_t d[8] = {10, 20, 255, 255}; s.frame(f, d, sizeof d); }
        assert(s.results()[0].events.size() == 1 && s.results()[0].events[0].why == "tilt limit");
    }
    std::puts("deadzones check: all passed");
    return 0;
}
