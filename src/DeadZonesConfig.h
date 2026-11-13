/*
 * Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
 * Licensed under the GNU GPL v2; see LICENSE.
 *
 * DeadZones: turns plugindata/AirDefender/deadzones.json (written by
 * scripts/airdefender_deadzones.php) into the frame check's settings. Needs
 * only jsoncpp, so it is tested without FPP (tests/deadzones_config_test.cpp).
 * Anything missing or out of range is left "not set" rather than guessed.
 */
#ifndef AIRDEFENDER_DEADZONES_CONFIG_H
#define AIRDEFENDER_DEADZONES_CONFIG_H

#include <algorithm>

#if __has_include(<jsoncpp/json/json.h>)
#include <jsoncpp/json/json.h>
#else
#include <json/json.h>
#endif

#include "DeadZonesCore.h"

namespace airdefender {

inline float dzNumber(const Json::Value& v, const char* key, float fallback) {
    return v.isMember(key) && v[key].isNumeric() ? v[key].asFloat() : fallback;
}
/* FPP channels in the file count from 1; positions in the frame count from 0. Anything else is "not set". */
inline long dzChannel(const Json::Value& v, const char* key, long maxChannels) {
    long ch = v.isMember(key) && v[key].isNumeric() ? (long)v[key].asInt64() : 0;
    return ch >= 1 && ch <= maxChannels ? ch - 1 : -1;
}
/* Degrees a second, over a range in degrees spread across 0-255, as channel values a millisecond. */
inline float dzPerMs(float slew, float range) {
    return slew > 0 && range > 0 ? slew / range * 255.0f / 1000.0f : 0;
}

inline DzConfig dzParseConfig(const Json::Value& root, long maxChannels) {
    DzConfig c;
    c.enabled = root.isMember("enabled") && root["enabled"].asBool();
    c.onlyWhilePlaying = !root.isMember("onlyWhilePlaying") || root["onlyWhilePlaying"].asBool();
    c.margin = std::max(0.0f, std::min(50.0f, dzNumber(root, "margin", 3)));
    c.holdMs = (int)std::max(0.0f, std::min(5000.0f, dzNumber(root, "holdMs", 250)));
    const Json::Value& heads = root["heads"];
    for (Json::ArrayIndex i = 0; heads.isArray() && i < heads.size() && i < 64; i++) {
        const Json::Value& jh = heads[i];
        if (!jh.isObject()) {
            continue;
        }
        DzHead h;
        h.id = jh.isMember("id") && jh["id"].isNumeric() ? jh["id"].asInt() : 0;
        h.name = jh.isMember("name") && jh["name"].isString() ? jh["name"].asString() : "";
        h.shutterCh = dzChannel(jh, "shutter", maxChannels);
        h.panCh = dzChannel(jh, "pan", maxChannels);
        h.tiltCh = dzChannel(jh, "tilt", maxChannels);
        h.dimmerCh = dzChannel(jh, "dimmer", maxChannels);
        h.closedValue = (uint8_t)std::max(0.0f, std::min(255.0f, dzNumber(jh, "closed", 0)));
        h.panPerMs = dzPerMs(dzNumber(jh, "panSlew", 0), dzNumber(jh, "panRange", 0));
        h.tiltPerMs = dzPerMs(dzNumber(jh, "tiltSlew", 0), dzNumber(jh, "tiltRange", 0));
        h.tiltMin = dzNumber(jh, "tiltMin", 0);
        h.tiltMax = dzNumber(jh, "tiltMax", 255);
        const Json::Value& zones = jh["zones"];
        for (Json::ArrayIndex z = 0; zones.isArray() && z < zones.size() && z < 64; z++) {
            const Json::Value& jz = zones[z];
            if (!jz.isObject()) {
                continue;
            }
            DzZone zone;
            zone.name = jz.isMember("name") && jz["name"].isString() ? jz["name"].asString() : "";
            const Json::Value& pts = jz["points"];
            for (Json::ArrayIndex p = 0; pts.isArray() && p < pts.size() && p < 64; p++) {
                if (pts[p].isArray() && pts[p].size() >= 2 && pts[p][0].isNumeric() && pts[p][1].isNumeric()) {
                    DzPoint pt;
                    pt.pan = pts[p][0].asFloat();
                    pt.tilt = pts[p][1].asFloat();
                    zone.outline.push_back(pt);
                }
            }
            const Json::Value& also = jz["alsoCloses"];
            for (Json::ArrayIndex a = 0; also.isArray() && a < also.size() && a < 64; a++) {
                if (also[a].isNumeric()) {
                    zone.alsoCloses.push_back(also[a].asInt());
                }
            }
            if (zone.outline.size() >= 3) {
                h.zones.push_back(zone);
            }
        }
        c.heads.push_back(h);
    }
    return c;
}

}   // namespace airdefender

#endif
