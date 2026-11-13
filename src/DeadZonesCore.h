/*
 * Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
 * Licensed under the GNU GPL v2; see LICENSE.
 *
 * DeadZones: the part that decides, frame by frame, whether a moving head is
 * pointing into an area the user has masked out, and closes its shutter while
 * it is. Nothing here knows about FPP, so it can be tested by itself (see
 * tests/deadzones_core_test.cpp); src/AirDefenderPlugin.cpp connects it to
 * FPP's per-frame hook.
 *
 * Positions are in the head's own pan and tilt channel values (0-255), so no
 * fixture maths is needed: a zone is an outline traced with the head itself.
 *
 * A head trails the data: the sequence says "point here" before the head gets
 * there. So each head has two positions - where the data says (the target)
 * and where it really is (moved towards the target no faster than the head's
 * slew) - and its shutter is closed while either is inside a zone.
 */
#ifndef AIRDEFENDER_DEADZONES_CORE_H
#define AIRDEFENDER_DEADZONES_CORE_H

#include <algorithm>
#include <cmath>
#include <cstddef>
#include <cstdint>
#include <string>
#include <vector>

namespace airdefender {

struct DzPoint {
    float pan = 0;
    float tilt = 0;
};

struct DzZone {
    std::string name;
    std::vector<DzPoint> outline;   // three or more points, in pan/tilt channel values
    std::vector<int> alsoCloses;    // ids of other heads to close while this head is in the zone
    // The box around the outline, worked out once in setConfig(): a point outside it (plus the margin) can't be in
    // the zone, so most frames skip the outline altogether.
    float minPan = 0, maxPan = -1, minTilt = 0, maxTilt = -1;
};

struct DzHead {
    int id = 0;                     // the head's number in Air Defender's settings (1-8)
    std::string name;
    long shutterCh = -1;            // 0-based positions in the frame; -1 when not set
    long panCh = -1;
    long tiltCh = -1;
    long dimmerCh = -1;             // only for "Check my sequences" (is the beam on?); the live check never touches it
    uint8_t closedValue = 0;
    float panPerMs = 0;             // how fast the head really turns, in channel values per millisecond;
    float tiltPerMs = 0;            // 0 means "keeps up with the data"
    float tiltMin = 0;              // tilt values outside this range count as a zone ("not too low")
    float tiltMax = 255;
    std::vector<DzZone> zones;
};

struct DzConfig {
    bool enabled = false;
    bool onlyWhilePlaying = true;   // checked by the plugin: leave frames alone unless a sequence is playing
    float margin = 3;               // channel values added around every zone, so an edge doesn't flicker
    int holdMs = 250;               // stays closed this long after leaving, for the same reason
    std::vector<DzHead> heads;
};

/* Why a head's shutter is closed right now. */
enum DzReason { DZ_OPEN = 0, DZ_ZONE = 1, DZ_TILT = 2, DZ_OTHER_HEAD = 3 };

struct DzHeadState {
    bool started = false;
    bool hit = false;               // in a zone on this frame (worked out afresh every frame)
    float realPan = 0, realTilt = 0;
    long long closedUntil = -1;     // time (ms) the shutter may open again; -1 when open
    DzReason reason = DZ_OPEN;
    int zone = -1;                  // which of the head's zones, when reason is DZ_ZONE
    unsigned long closes = 0;       // times it has gone from open to closed
};

class DzEngine {
public:
    void setConfig(const DzConfig& c) {
        config = c;
        for (DzHead& h : config.heads) {
            for (DzZone& z : h.zones) {
                if (z.outline.empty()) { z.minPan = 0; z.maxPan = -1; continue; }
                z.minPan = z.maxPan = z.outline[0].pan; z.minTilt = z.maxTilt = z.outline[0].tilt;
                for (const DzPoint& p : z.outline) {
                    z.minPan = std::min(z.minPan, p.pan); z.maxPan = std::max(z.maxPan, p.pan);
                    z.minTilt = std::min(z.minTilt, p.tilt); z.maxTilt = std::max(z.maxTilt, p.tilt);
                }
            }
        }
        states.assign(config.heads.size(), DzHeadState());
        lastMs = -1;
    }
    const DzConfig& getConfig() const { return config; }
    /* The head being aimed from the DeadZones tab (0 for none): left alone, so its beam stays on while a zone is traced. */
    void setAiming(int headId) { aiming = headId; }
    int getAiming() const { return aiming; }
    const std::vector<DzHeadState>& getStates() const { return states; }

    /*
     * One frame, just before it goes out. nowMs is real time, not sequence
     * time (a sequence restarting must not look like time running backwards).
     * Only ever writes a shutter's closed value; pan, tilt and everything
     * else are left exactly as the show set them. With apply false it only
     * follows where the heads really are and changes nothing.
     */
    void process(long long nowMs, uint8_t* data, size_t len, bool apply = true) {
        if (!config.enabled || config.heads.empty() || data == nullptr) {
            return;
        }
        // After a gap (first frame, fppd idle, clock change) the heads have had time to arrive.
        long long dt = lastMs < 0 ? -1 : nowMs - lastMs;
        bool snap = dt < 0 || dt > 500;
        lastMs = nowMs;

        std::vector<int> closeToo;   // ids of heads closed by another head's zone this frame
        for (size_t i = 0; i < config.heads.size(); i++) {
            const DzHead& h = config.heads[i];
            DzHeadState& s = states[i];
            s.hit = false;
            if (h.id == aiming && aiming != 0) {
                s.closedUntil = -1; s.reason = DZ_OPEN; s.zone = -1; s.started = false;
                continue;
            }
            if (!valid(h.panCh, len) || !valid(h.tiltCh, len)) {
                continue;   // not set up: left alone (another head's zone can still close it)
            }
            float pan = data[h.panCh], tilt = data[h.tiltCh];
            if (snap || !s.started) {
                s.realPan = pan; s.realTilt = tilt; s.started = true;
            } else {
                s.realPan = approach(s.realPan, pan, h.panPerMs * dt);
                s.realTilt = approach(s.realTilt, tilt, h.tiltPerMs * dt);
            }
            if (!apply) {   // only following the heads: nothing closed, nothing written
                s.closedUntil = -1; s.reason = DZ_OPEN; s.zone = -1;
                continue;
            }
            int zone = -1;
            DzReason why = check(h, pan, tilt, zone);
            if (why == DZ_OPEN) {
                why = check(h, s.realPan, s.realTilt, zone);
            }
            if (why != DZ_OPEN) {
                if (s.closedUntil < 0) { s.closes++; }
                s.hit = true;
                s.closedUntil = nowMs + config.holdMs;
                s.reason = why; s.zone = zone;
                if (why == DZ_ZONE) {
                    for (int id : h.zones[zone].alsoCloses) { closeToo.push_back(id); }
                }
            }
        }
        for (size_t i = 0; i < config.heads.size() && apply; i++) {
            const DzHead& h = config.heads[i];
            DzHeadState& s = states[i];
            if (h.id == aiming && aiming != 0) {
                continue;
            }
            for (int id : closeToo) {
                if (id == h.id && !s.hit) {   // its own zone, if it's in one, is the better reason to show
                    if (s.closedUntil < 0) { s.closes++; }
                    s.hit = true;
                    s.closedUntil = nowMs + config.holdMs;
                    s.reason = DZ_OTHER_HEAD; s.zone = -1;
                }
            }
            // Out of every zone and the hold has run out: the show has the shutter again.
            if (!s.hit && s.closedUntil >= 0 && nowMs >= s.closedUntil) {
                s.closedUntil = -1; s.reason = DZ_OPEN; s.zone = -1;
            }
            if (s.closedUntil >= 0 && valid(h.shutterCh, len)) {
                data[h.shutterCh] = h.closedValue;
            }
        }
    }

    /* Is this point inside the outline, or within margin of its edge? */
    static bool inZone(const std::vector<DzPoint>& o, float pan, float tilt, float margin) {
        size_t n = o.size();
        if (n < 3) { return false; }
        bool inside = false;
        float m2 = margin * margin;
        for (size_t i = 0, j = n - 1; i < n; j = i++) {
            if (((o[i].tilt > tilt) != (o[j].tilt > tilt)) &&
                (pan < (o[j].pan - o[i].pan) * (tilt - o[i].tilt) / (o[j].tilt - o[i].tilt) + o[i].pan)) {
                inside = !inside;
            }
            if (dist2(o[j], o[i], pan, tilt) <= m2) { return true; }   // on the edge counts as inside
        }
        return inside;
    }

private:
    DzConfig config;
    std::vector<DzHeadState> states;
    long long lastMs = -1;
    int aiming = 0;

    static bool valid(long ch, size_t len) { return ch >= 0 && (size_t)ch < len; }

    static float approach(float from, float to, float step) {
        if (step <= 0) { return to; }   // no slew set: keeps up with the data
        float d = to - from;
        if (std::fabs(d) <= step) { return to; }
        return from + (d > 0 ? step : -step);
    }

    /* Squared distance from a point to the line segment a-b. */
    static float dist2(const DzPoint& a, const DzPoint& b, float pan, float tilt) {
        float dx = b.pan - a.pan, dy = b.tilt - a.tilt;
        float len2 = dx * dx + dy * dy;
        float t = len2 > 0 ? ((pan - a.pan) * dx + (tilt - a.tilt) * dy) / len2 : 0;
        t = t < 0 ? 0 : (t > 1 ? 1 : t);
        float px = a.pan + t * dx - pan, py = a.tilt + t * dy - tilt;
        return px * px + py * py;
    }

    DzReason check(const DzHead& h, float pan, float tilt, int& zone) const {
        if (tilt < h.tiltMin || tilt > h.tiltMax) { zone = -1; return DZ_TILT; }
        float m = config.margin;
        for (size_t z = 0; z < h.zones.size(); z++) {
            const DzZone& zn = h.zones[z];
            if (pan < zn.minPan - m || pan > zn.maxPan + m || tilt < zn.minTilt - m || tilt > zn.maxTilt + m) { continue; }
            if (inZone(zn.outline, pan, tilt, m)) { zone = (int)z; return DZ_ZONE; }
        }
        zone = -1;
        return DZ_OPEN;
    }
};

}   // namespace airdefender

#endif
