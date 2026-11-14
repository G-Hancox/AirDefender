/*
 * Air Defender - Copyright (C) 2026 G Hancox. Licensed under the GNU GPL v2; see LICENSE.
 *
 * Which FPP warnings Air Defender should be showing right now. FPP shows its
 * warnings at the top of every page, and apps that watch FPP (such as FPPMon)
 * can pass them on to a phone. Only things that mean the heads aren't being
 * protected count: nothing for an aircraft closing the heads (that's Air
 * Defender doing its job) and nothing outside show times.
 *
 * No FPP here, so it can be tested on its own (tests/warnings_test.cpp). The
 * plugin feeds it what it reads every few seconds and adds or removes FPP
 * warnings to match what it returns.
 */
#ifndef AIRDEFENDER_WARNINGS_H
#define AIRDEFENDER_WARNINGS_H

#include <istream>
#include <string>
#include <vector>

namespace airdefender {

/* What the plugin read this time round. Times are in seconds. */
struct AdWarnInput {
    long long now = 0;
    bool warnFeed = true;        // which warnings are wanted (the Notifications tab)
    bool warnLoop = true;
    bool warnDz = true;
    bool adEnabled = false;      // the "Enable Air Defender" setting
    long long loadedAt = 0;      // when the plugin started (the loop gets time to start)
    bool stateFound = false;     // the loop's status file exists
    long long stateTime = 0;     // when the loop last wrote it (it does at least every 30 s)
    bool checking = false;       // the loop should be checking for aircraft now
    bool remote = false;         // this FPP is a Remote (it asks the Player, not a flight feed)
    bool feedError = false;      // the flight feed isn't answering (Player)
    bool playerLost = false;     // can't reach the Player (Remote)
    bool closedForFeed = false;  // "Close heads if the feed is down" has closed them
    bool dzOn = false;           // DeadZones turned on in the settings
    bool dzFailed = false;       // DeadZones switched itself off after an error
};

/* The settings it needs, from FPP's plugin settings file (lines like: key = "value"). */
struct AdWarnSettings {
    bool warnFeed = true;     // All (the default) means all three
    bool warnLoop = true;
    bool warnDz = true;
    bool adEnabled = false;
    bool dzOn = false;
};
inline AdWarnSettings adParseSettings(std::istream& f) {
    AdWarnSettings out;
    std::string line;
    auto trim = [](std::string& t) {
        size_t a = t.find_first_not_of(" \t\r\""), b = t.find_last_not_of(" \t\r\"");
        t = a == std::string::npos ? "" : t.substr(a, b - a + 1);
    };
    std::string mode = "all";
    bool feed = true, loop = true, dz = true;
    while (std::getline(f, line)) {
        size_t eq = line.find('=');
        if (eq == std::string::npos) { continue; }
        std::string key = line.substr(0, eq), val = line.substr(eq + 1);
        trim(key); trim(val);
        bool on = val == "1" || val == "true" || val == "on";
        if (key == "fppWarnings") { mode = val; }
        else if (key == "warnFeedDown") { feed = on; }
        else if (key == "warnLoopStopped") { loop = on; }
        else if (key == "warnDeadZones") { dz = on; }
        else if (key == "enabled") { out.adEnabled = on; }
        else if (key == "deadZonesEnabled") { out.dzOn = on; }
    }
    if (mode == "off" || mode == "0") {
        out.warnFeed = out.warnLoop = out.warnDz = false;
    } else if (mode == "pick") {
        out.warnFeed = feed; out.warnLoop = loop; out.warnDz = dz;
    }
    return out;
}

class AdWarnings {
public:
    static constexpr int LOOP_STALE_SEC = 90;   // three missed check-ins
    static constexpr int FEED_DOWN_SEC = 120;
    static constexpr int START_GRACE_SEC = 120; // after fppd starts, before "stopped" is said

    static const std::string& loopStopped() {
        static const std::string s = "Air Defender: aircraft detection has stopped, so heads aren't protected from "
                                     "aircraft. Restart FPPD; if that doesn't help, reinstall Air Defender.";
        return s;
    }
    static const std::string& feedDown() {
        static const std::string s = "Air Defender: no flight data for 2 minutes, so heads aren't protected from aircraft.";
        return s;
    }
    static const std::string& feedDownClosed() {
        static const std::string s = "Air Defender: no flight data for 2 minutes. Heads are closed until it answers.";
        return s;
    }
    static const std::string& playerLost() {
        static const std::string s = "Air Defender: can't reach the Player for 2 minutes, so this FPP's heads aren't "
                                     "protected from aircraft.";
        return s;
    }
    static const std::string& dzFailed() {
        static const std::string s = "Air Defender: DeadZones turned itself off after an error (see the DeadZones tab).";
        return s;
    }

    /* The warnings that should be showing now. Remembers when a feed problem started, so call it every few seconds. */
    std::vector<std::string> update(const AdWarnInput& in) {
        std::vector<std::string> out;
        // Worked out whatever is wanted (so a feed problem's 2 minutes keep counting), then filtered.
        if (in.warnDz && in.dzOn && in.dzFailed) {
            out.push_back(dzFailed());
        }
        if (!in.adEnabled) {
            problemSince = -1;
            return out;
        }
        bool fresh = in.stateFound && in.now - in.stateTime <= LOOP_STALE_SEC;
        if (!fresh) {
            problemSince = -1;
            if (in.warnLoop && in.now - in.loadedAt >= START_GRACE_SEC) {
                out.push_back(loopStopped());
            }
            return out;
        }
        bool problem = in.checking && (in.remote ? in.playerLost : in.feedError);
        if (!problem) {
            problemSince = -1;
            return out;
        }
        if (problemSince < 0 || in.now < problemSince) {
            problemSince = in.now;
        }
        if (in.warnFeed && in.now - problemSince >= FEED_DOWN_SEC) {
            out.push_back(in.remote ? playerLost() : in.closedForFeed ? feedDownClosed() : feedDown());
        }
        return out;
    }

private:
    long long problemSince = -1;
};

}   // namespace airdefender

#endif
