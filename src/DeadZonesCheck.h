/*
 * Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
 * Licensed under the GNU GPL v2; see LICENSE.
 *
 * "Check my sequences": play a sequence's frames through the same DeadZones
 * check the show uses (src/DeadZonesCore.h, slew and all) and list each
 * moment a head's beam is on while it points into a zone, so it can be fixed
 * in xLights. The beam counts as on when the shutter isn't at its closed value
 * and the dimmer (if the head has one set) is above 0.
 *
 * Nothing here knows about FPP or files: src/AirDefenderPlugin.cpp feeds it
 * frames read with FPP's own sequence reader. Tested by
 * tests/deadzones_check_test.cpp.
 */
#ifndef AIRDEFENDER_DEADZONES_CHECK_H
#define AIRDEFENDER_DEADZONES_CHECK_H

#include <string>
#include <vector>

#include "DeadZonesCore.h"

namespace airdefender {

struct DzCheckEvent {
    long long startMs = 0;
    long long endMs = 0;
    std::string why;   // the zone's name, "tilt limit", or "closed with another head"
};

struct DzCheckHead {
    int id = 0;
    std::string name;
    std::vector<DzCheckEvent> events;
    bool more = false;   // stopped listing at maxEvents
};

class DzScan {
public:
    static const int maxEvents = 100;    // per head, per sequence
    static const int joinMs = 500;       // moments closer together than this are one

    DzScan(const DzConfig& c, int stepMs) : step(stepMs > 0 ? stepMs : 25) {
        DzConfig on = c;
        on.enabled = true;   // checked whether or not DeadZones is switched on for the show
        engine.setConfig(on);
        for (const DzHead& h : on.heads) {
            DzCheckHead r; r.id = h.id; r.name = h.name;
            heads.push_back(r);
        }
        open.assign(heads.size(), false);
        last.assign(heads.size(), 0);
        lit.assign(heads.size(), false);
    }

    /* One frame (a copy: the check writes the closed values into it). */
    void frame(unsigned long index, uint8_t* data, size_t len) {
        const DzConfig& c = engine.getConfig();
        long long now = (long long)index * step;
        for (size_t i = 0; i < c.heads.size(); i++) {
            const DzHead& h = c.heads[i];
            lit[i] = ok(h.shutterCh, len) && data[h.shutterCh] != h.closedValue && (!ok(h.dimmerCh, len) || data[h.dimmerCh] > 0);
        }
        engine.process(now, data, len);
        const std::vector<DzHeadState>& st = engine.getStates();
        for (size_t i = 0; i < c.heads.size() && i < st.size(); i++) {
            bool bad = lit[i] && st[i].hit;
            std::string why;
            if (bad) {
                why = st[i].reason == DZ_ZONE && st[i].zone >= 0 && st[i].zone < (int)c.heads[i].zones.size() ? c.heads[i].zones[st[i].zone].name
                    : st[i].reason == DZ_TILT ? "tilt limit" : "closed with another head";
            }
            DzCheckHead& r = heads[i];
            if (open[i] && (!bad || why != r.events.back().why) && now - last[i] > joinMs) {
                open[i] = false;
            }
            if (!bad) {
                continue;
            }
            if (open[i] && why == r.events.back().why) {
                r.events.back().endMs = now + step;
            } else if ((int)r.events.size() < maxEvents) {
                DzCheckEvent e; e.startMs = now; e.endMs = now + step; e.why = why;
                r.events.push_back(e);
                open[i] = true;
            } else {
                r.more = true;
            }
            last[i] = now;
        }
    }

    const std::vector<DzCheckHead>& results() const { return heads; }

private:
    DzEngine engine;
    int step;
    std::vector<DzCheckHead> heads;
    std::vector<bool> open, lit;
    std::vector<long long> last;
    static bool ok(long ch, size_t len) { return ch >= 0 && (size_t)ch < len; }
};

}   // namespace airdefender

#endif
