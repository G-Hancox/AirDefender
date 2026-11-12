/*
 * Air Defender - Copyright (C) 2026 G Hancox. Licensed under the GNU GPL v2; see LICENSE.
 *
 * Reads a deadzones.json as scripts/airdefender_deadzones.php writes it and runs frames
 * through the check, with no FPP needed (jsoncpp only):
 *   g++ -std=c++17 -I<jsoncpp>/include -o /tmp/dz_cfg tests/deadzones_config_test.cpp (plus every .cpp file in jsoncpp src/lib_json)
 *   /tmp/dz_cfg tests/deadzones_sample.json
 */
#include "../src/DeadZonesConfig.h"

#include <cstdio>
#include <fstream>
#include <vector>

using namespace airdefender;

static int failures = 0;
#define CHECK(what, cond) do { if (!(cond)) { failures++; std::printf("FAIL: %s (line %d)\n", what, __LINE__); } \
                               else { std::printf("ok:   %s\n", what); } } while (0)

int main(int argc, char** argv) {
    std::ifstream in(argc > 1 ? argv[1] : "tests/deadzones_sample.json");
    Json::Value root;
    Json::CharReaderBuilder b;
    std::string errs;
    CHECK("the file is JSON", Json::parseFromStream(b, in, &root, &errs));
    const long CHANNELS = 8192 * 1024;
    DzConfig c = dzParseConfig(root, CHANNELS);
    CHECK("on", c.enabled);
    CHECK("margin and hold read", c.margin == 4 && c.holdMs == 300);
    CHECK("one head", c.heads.size() == 1);
    if (c.heads.size() != 1) { return 1; }
    const DzHead& h = c.heads[0];
    // FPP channels 82448 (pan), 82450 (tilt), 82454 (shutter) are positions 82447, 82449, 82453 in the frame.
    CHECK("channels are one less in the frame", h.panCh == 82447 && h.tiltCh == 82449 && h.shutterCh == 82453);
    CHECK("pan speed: 120 deg/s over 540 deg", std::fabs(h.panPerMs - 120.0f / 540.0f * 255.0f / 1000.0f) < 1e-6);
    CHECK("tilt speed: 180 deg/s over 270 deg", std::fabs(h.tiltPerMs - 180.0f / 270.0f * 255.0f / 1000.0f) < 1e-6);
    CHECK("tilt limit and one usable zone", h.tiltMin == 20 && h.zones.size() == 1 && h.zones[0].outline.size() == 4);
    CHECK("zone also closes head 2", h.zones[0].alsoCloses.size() == 1 && h.zones[0].alsoCloses[0] == 2);

    DzEngine e; e.setConfig(c);
    std::vector<uint8_t> frame(CHANNELS, 0);
    auto set = [&](int pan, int tilt) { frame[82447] = pan; frame[82449] = tilt; frame[82453] = 255; };
    set(20, 60); e.process(1000, frame.data(), frame.size());
    CHECK("outside: open", frame[82453] == 255);
    set(125, 75); e.process(1025, frame.data(), frame.size());
    CHECK("data inside the zone: closed at once", frame[82453] == 0);
    set(20, 10); e.process(9000, frame.data(), frame.size());
    CHECK("below the tilt limit: closed", frame[82453] == 0);

    // Garbage in: nothing set, nothing done, no crash.
    Json::Value junk;
    junk["enabled"] = true; junk["heads"] = "nope"; junk["margin"] = "wide";
    DzConfig j = dzParseConfig(junk, CHANNELS);
    CHECK("junk: no heads, default margin", j.heads.empty() && j.margin == 3);
    Json::Value far; far["enabled"] = true; far["heads"][0]["pan"] = 999999999; far["heads"][0]["tilt"] = -4; far["heads"][0]["shutter"] = 0;
    DzConfig f = dzParseConfig(far, CHANNELS);
    CHECK("channels out of range are 'not set'", f.heads.size() == 1 && f.heads[0].panCh == -1 && f.heads[0].tiltCh == -1 && f.heads[0].shutterCh == -1);
    e.setConfig(f); set(125, 75); e.process(1, frame.data(), frame.size());
    CHECK("a head that isn't set up is left alone", frame[82453] == 255);

    std::printf("%s (%d failed)\n", failures ? "FAILED" : "All passed", failures);
    return failures ? 1 : 0;
}
