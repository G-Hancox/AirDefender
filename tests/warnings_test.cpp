/*
 * Air Defender - Copyright (C) 2026 G Hancox. Licensed under the GNU GPL v2; see LICENSE.
 *
 * Tests for which FPP warnings Air Defender shows, with no FPP needed:
 *   g++ -std=c++17 -Wall -Wextra -o /tmp/warn_test tests/warnings_test.cpp && /tmp/warn_test
 */
#include "../src/AdWarnings.h"

#include <cstdio>
#include <sstream>

using namespace airdefender;

static int failures = 0;
#define CHECK(what, cond) do { if (!(cond)) { failures++; std::printf("FAIL: %s (line %d)\n", what, __LINE__); } \
                               else { std::printf("ok:   %s\n", what); } } while (0)

static AdWarnInput ok(long long now) {
    AdWarnInput in; in.now = now; in.adEnabled = true; in.loadedAt = 0; in.stateFound = true; in.stateTime = now - 5;
    in.checking = true;
    return in;
}
static bool has(const std::vector<std::string>& w, const std::string& s) {
    for (const std::string& x : w) { if (x == s) { return true; } }
    return false;
}

int main() {
    {
        AdWarnings w;
        CHECK("all well: no warnings", w.update(ok(1000)).empty());
    }
    {
        // Feed down: nothing for the first 2 minutes, then one warning, gone when it answers.
        AdWarnings w; AdWarnInput in = ok(1000); in.feedError = true;
        CHECK("feed down at once: no warning yet", w.update(in).empty());
        in.now = 1119; in.stateTime = 1115;
        CHECK("feed down 119 s: still none", w.update(in).empty());
        in.now = 1120; in.stateTime = 1118;
        std::vector<std::string> r = w.update(in);
        CHECK("feed down 2 min: warns", r.size() == 1 && has(r, AdWarnings::feedDown()));
        in.closedForFeed = true;
        CHECK("heads closed for it: says so instead", has(w.update(in), AdWarnings::feedDownClosed()));
        in.feedError = false; in.closedForFeed = false; in.now = 1130; in.stateTime = 1128;
        CHECK("feed back: warning gone", w.update(in).empty());
        in.feedError = true; in.now = 1140; in.stateTime = 1138;
        CHECK("down again: the 2 minutes start over", w.update(in).empty());
    }
    {
        // Outside show times (standby) a dead feed doesn't matter.
        AdWarnings w; AdWarnInput in = ok(1000); in.feedError = true; in.checking = false;
        w.update(in); in.now = 2000; in.stateTime = 1995;
        CHECK("standby, feed down 1000 s: no warning", w.update(in).empty());
        in.checking = true; in.now = 2010; in.stateTime = 2008;
        CHECK("show starts: the 2 minutes start then", w.update(in).empty());
        in.now = 2130; in.stateTime = 2128;
        CHECK("2 min into the show: warns", has(w.update(in), AdWarnings::feedDown()));
    }
    {
        // A Remote: losing the Player counts, a feed error (it has none) doesn't.
        AdWarnings w; AdWarnInput in = ok(1000); in.remote = true; in.feedError = true;
        w.update(in); in.now = 1200; in.stateTime = 1195;
        CHECK("Remote ignores feed errors", w.update(in).empty());
        in.playerLost = true; w.update(in); in.now = 1320; in.stateTime = 1318;
        CHECK("Remote, Player lost 2 min: warns", has(w.update(in), AdWarnings::playerLost()));
    }
    {
        // The loop: stopped writing for over 90 s, or never started.
        AdWarnings w; AdWarnInput in = ok(1000); in.stateTime = 1000 - 90;
        CHECK("90 s since the loop wrote: still fine", w.update(in).empty());
        in.stateTime = 1000 - 91;
        CHECK("91 s: loop stopped", has(w.update(in), AdWarnings::loopStopped()));
        in.feedError = true;
        std::vector<std::string> r = w.update(in);
        CHECK("loop stopped: only that one (its feed news is stale)", r.size() == 1);
        AdWarnInput fresh = ok(1000); fresh.stateFound = false; fresh.loadedAt = 950;
        CHECK("no status file 50 s after start: give it time", w.update(fresh).empty());
        fresh.now = 1070;
        CHECK("no status file 2 min after start: loop stopped", has(w.update(fresh), AdWarnings::loopStopped()));
    }
    {
        // Turned off: Air Defender off means no Air Defender warnings; the setting off means none at all.
        AdWarnings w; AdWarnInput in = ok(1000); in.adEnabled = false; in.stateFound = false;
        CHECK("Air Defender off: no warnings", w.update(in).empty());
        in = ok(1000); in.stateFound = false; in.warningsOn = false; in.dzOn = true; in.dzFailed = true;
        CHECK("warnings setting off: nothing, even with problems", w.update(in).empty());
    }
    {
        // DeadZones.
        AdWarnings w; AdWarnInput in = ok(1000); in.dzFailed = true;
        CHECK("DeadZones failed but not turned on: no warning", w.update(in).empty());
        in.dzOn = true;
        CHECK("DeadZones on and failed: warns", has(w.update(in), AdWarnings::dzFailed()));
        in.adEnabled = false;
        CHECK("still warns with Air Defender off (DeadZones is separate)", has(w.update(in), AdWarnings::dzFailed()));
    }
    {
        // Clock going backwards mustn't leave a warning stuck or crash.
        AdWarnings w; AdWarnInput in = ok(1000); in.feedError = true;
        w.update(in); in.now = 500; in.stateTime = 498;
        CHECK("clock back: starts the count again", w.update(in).empty());
    }
    {
        // Reading FPP's plugin settings file.
        std::istringstream f("enabled = \"1\"\nnodeRole = \"master\"\ndeadZonesEnabled = \"1\"\nlatitude = \"41.5\"\n");
        AdWarnSettings s = adParseSettings(f);
        CHECK("settings: on, DeadZones on, warnings on by default", s.adEnabled && s.dzOn && s.warningsOn);
        std::istringstream g("enabled=\"0\"\r\nfppWarnings = \"0\"\r\n");
        s = adParseSettings(g);
        CHECK("settings: off, warnings off, Windows line ends", !s.adEnabled && !s.warningsOn && !s.dzOn);
        std::istringstream e("");
        s = adParseSettings(e);
        CHECK("settings: empty file: defaults", !s.adEnabled && s.warningsOn);
    }
    std::printf("%s (%d failed)\n", failures ? "FAILED" : "All passed", failures);
    return failures ? 1 : 0;
}
