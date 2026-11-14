/*
 * Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
 * Licensed under the GNU GPL v2; see LICENSE.
 *
 * The compiled part of Air Defender: DeadZones. FPP loads it into fppd and
 * calls modifyChannelData() for every frame, just before the frame goes to the
 * outputs. While a moving head points into an area the user masked out, its
 * shutter channel is set to its closed value for that frame. Nothing else in
 * the frame is ever changed.
 *
 * It does as little as it can:
 *   - Off (the default), each frame costs one check and nothing is touched.
 *   - What to do comes from one small file the PHP side writes when settings
 *     change (plugindata/AirDefender/deadzones.json). It is looked at once a
 *     second and read again only when it has changed; nothing is ever written.
 *   - If anything goes wrong, DeadZones turns itself off and says why in
 *     fppd's log and in its status. The show and plane safety carry on.
 *
 * Plane safety (the PHP loop and its overlay models) does not depend on this
 * library: if it isn't built or FPP won't load it, only DeadZones is missing.
 *
 * Status, for the settings page: GET /api/plugin-apis/AirDefender/deadzones
 *
 * "Check my sequences" (DeadZones tab): POST .../deadzones/check with
 * seq=<file> starts reading one sequence with FPP's own reader, on its own
 * low-priority thread and only while nothing is playing (stop=1 stops it);
 * GET .../deadzones/check gives its progress and results. Reads only.
 *
 * FPP warnings (src/AdWarnings.h): every 5 s a small thread reads the loop's
 * status (in memory) and the settings, and adds or removes Air Defender's own
 * FPP warnings to match: feed down 2 min, detection stopped, DeadZones off
 * after an error. FPP shows them on every page; apps such as FPPMon pass them
 * on. They are removed when things recover and when the plugin stops.
 */
#include <fpp-pch.h>

#include <sys/resource.h>
#include <sys/stat.h>
#include <sys/syscall.h>
#include <sys/wait.h>
#include <csignal>
#include <fcntl.h>
#include <unistd.h>
#include <cerrno>
#include <cstring>
#include <ctime>

#include <atomic>
#include <condition_variable>
#include <cstdlib>
#include <fstream>
#include <set>
#include <exception>
#include <memory>
#include <thread>
#include <functional>
#include <mutex>
#include <string>

#include "Plugin.h"
#include "Sequence.h"
#include "Warnings.h"
#include "common.h"
#include "fpphttp.h"
#include "log.h"
#include "settings.h"

#include "fseq/FSEQFile.h"

#include "AdWarnings.h"
#include "DeadZonesCheck.h"
#include "DeadZonesConfig.h"
#include "DeadZonesCore.h"

using namespace airdefender;

class AirDefenderPlugin : public FPPPlugins::Plugin, public FPPPlugins::ChannelDataPlugin, public FPPPlugins::APIProviderPlugin {
public:
    AirDefenderPlugin() :
        FPPPlugins::Plugin("AirDefender"), FPPPlugins::ChannelDataPlugin(), FPPPlugins::APIProviderPlugin() {
        pluginDir = FPP_DIR_PLUGIN("/AirDefender");
        // FPP's plugindata folder, or the plugin's own folder where that can't be written (see airdefender_deadzones.php).
        configFiles[0] = FPP_DIR_MEDIA("/plugindata/AirDefender/deadzones.json");
        configFiles[1] = pluginDir + "/deadzones.json";
        configFile = configFiles[0];
        LogInfo(VB_PLUGIN, "Air Defender: DeadZones loaded (off until it is set up and turned on)\n");
        // fppd loads a plugin as scripts or as compiled, never both, so with this library loaded the
        // lifecycle callbacks don't run: start plane safety's detection loop here instead, in the
        // background, exactly as callbacks.sh would (the script waits for an old loop to go first).
        runScript("airdefender_start.sh", true);
        warnLoadedAt = time(nullptr);
        warnThread = std::thread([this]() { runWarnings(); });
    }
    // Threads still running must be stopped before their objects go (else std::terminate).
    virtual ~AirDefenderPlugin() {
        stopWarnings();
        stopCheck();
    }

    /* fppd stopping, or unloading the plugin to update or uninstall it: stop the loop, as callbacks.sh would. */
    virtual std::function<bool()> shutdown() override {
        stopWarnings();   // also takes its warnings off FPP's list
        stopCheck();
        runScript("airdefender_stop.sh", false);
        return nullptr;
    }

    /* Every frame, on FPP's output thread. Must be quick and must never throw. */
    virtual void modifyChannelData(int /* ms */, uint8_t* seqData) override {
        if (failed) {
            return;
        }
        try {
            // Real time, not sequence time: a sequence starting again must not look like time going backwards.
            long long now = GetTimeMS();
            std::lock_guard<std::mutex> lock(mutex);
            if (now - lastLook >= 1000 || now < lastLook) {
                lastLook = now;
                reloadIfChanged();
                engine.setAiming(aimedHead());
            }
            const DzConfig& c = engine.getConfig();
            // "While a sequence is playing": test patterns, effects and Display Testing are left alone.
            bool waiting = c.enabled && c.onlyWhilePlaying && !(sequence && sequence->IsSequenceRunning());
            if (c.enabled) {
                // Waiting: keep following where each head really is (so the first moves of a sequence are
                // right), but change nothing in the frame.
                engine.process(now, seqData, FPPD_MAX_CHANNELS, !waiting);
                frames++;
            }
        } catch (const std::exception& e) {
            fail(e.what());
        } catch (...) {
            fail("unknown error");
        }
    }

    virtual void registerApis() override {
        FPPPlugins::registerPluginApi(
            "/AirDefender/deadzones",
            [this](const HttpRequestPtr& /* req */, HttpCallback&& callback) {
                callback(makeStringResponse(status(), 200, "application/json"));
            },
            { drogon::Get }, false);
        FPPPlugins::registerPluginApi(
            "/AirDefender/deadzones/check",
            [this](const HttpRequestPtr& req, HttpCallback&& callback) {
                int code = 200;
                // Starting or stopping a check is a POST (FPP has no login, so a GET must never do work); GET only reads progress.
                bool post = req->method() == drogon::Post;
                std::string body = checkRequest(post ? req->getParameter("seq") : std::string(), post && req->getParameter("stop") == "1", code);
                callback(makeStringResponse(body, code, "application/json"));
            },
            { drogon::Get, drogon::Post }, false);
    }
    virtual void unregisterApis() override {
        FPPPlugins::unregisterPluginApi("/AirDefender/deadzones/check");
        FPPPlugins::unregisterPluginApi("/AirDefender/deadzones");
    }

private:
    DzEngine engine;
    std::mutex mutex;            // the frame check and the status request are on different threads
    std::string configFile;      // whichever of configFiles is in use
    std::string configFiles[2];
    std::string pluginDir;
    std::string error;           // why DeadZones turned itself off, or why its file couldn't be used
    long long lastLook = 0;
    long long fileTime = -1;
    long long fileSize = -1;
    unsigned long long frames = 0;
    volatile bool failed = false;

    // FPP warnings: their own thread, waking every 5 s.
    std::thread warnThread;
    std::mutex warnMutex;
    std::condition_variable warnWake;
    bool warnStop = false;
    long long warnLoadedAt = 0;
    AdWarnings warnings;
    std::set<std::string> warningsShown;
    long long settingsTime = -1, stateTime = -1;
    AdWarnSettings setWarn;
    bool setEnabled = false, setDzOn = false;
    Json::Value lastState;

    void stopWarnings() {
        {
            std::lock_guard<std::mutex> lock(warnMutex);
            warnStop = true;
        }
        warnWake.notify_all();
        if (warnThread.joinable()) {
            warnThread.join();
        }
    }

    void runWarnings() {
        std::unique_lock<std::mutex> lock(warnMutex);
        while (!warnStop) {
            lock.unlock();
            try {
                warningsTick();
            } catch (...) {
                // A warning is a nicety: never let it take anything else down.
            }
            lock.lock();
            warnWake.wait_for(lock, std::chrono::seconds(5), [this]() { return warnStop; });
        }
        lock.unlock();
        for (const std::string& w : warningsShown) {
            WarningHolder::RemoveWarning(w);
        }
        warningsShown.clear();
    }

    /* The three settings it needs, from FPP's plugin settings file (lines like: key = "value"), read when it changes. */
    void readWarningSettings() {
        std::string path = FPP_DIR_MEDIA("/config/plugin.AirDefender");
        struct stat st;
        if (stat(path.c_str(), &st) != 0) {
            setWarn = AdWarnSettings(); setEnabled = false; setDzOn = false; settingsTime = -1;
            return;
        }
        if ((long long)st.st_mtime == settingsTime) {
            return;
        }
        settingsTime = (long long)st.st_mtime;
        std::ifstream f(path);
        AdWarnSettings w = adParseSettings(f);
        setWarn = w; setEnabled = w.adEnabled; setDzOn = w.dzOn;
    }

    void warningsTick() {
        readWarningSettings();
        AdWarnInput in;
        in.now = time(nullptr);
        in.warnFeed = setWarn.warnFeed;
        in.warnLoop = setWarn.warnLoop;
        in.warnDz = setWarn.warnDz;
        in.adEnabled = setEnabled;
        in.loadedAt = warnLoadedAt;
        in.dzOn = setDzOn;
        in.dzFailed = failed;
        // The loop's status: the newer of the copy in memory and the one in the plugin folder (see airdefender_statefile.php).
        struct stat a, b;
        std::string mem = "/dev/shm/AirDefender/state.json", disk = pluginDir + "/state.json";
        bool am = stat(mem.c_str(), &a) == 0, bd = stat(disk.c_str(), &b) == 0;
        if (am || bd) {
            bool useMem = am && (!bd || a.st_mtime >= b.st_mtime);
            long long t = (long long)(useMem ? a.st_mtime : b.st_mtime);
            in.stateFound = true;
            in.stateTime = t;
            if (t != stateTime) {
                Json::Value s;
                if (LoadJsonFromFile(useMem ? mem : disk, s) && s.isObject()) {
                    lastState = s;
                    stateTime = t;
                }
            }
            const Json::Value& s = lastState;
            in.checking = s.get("checking", true).asBool();
            in.remote = s.get("role", "master").asString() == "remote";
            in.feedError = s.isMember("feedError") && !s["feedError"].isNull();
            in.playerLost = s.isMember("master") && s["master"].isObject() && !s["master"].get("connected", true).asBool();
            in.closedForFeed = s.get("reason", "").asString() == "feed-down";
        }
        std::vector<std::string> want = warnings.update(in);
        std::set<std::string> now(want.begin(), want.end());
        for (const std::string& w : warningsShown) {
            if (!now.count(w)) {
                WarningHolder::RemoveWarning(w);
                LogInfo(VB_PLUGIN, "Air Defender: cleared FPP warning: %s\n", w.c_str());
            }
        }
        for (const std::string& w : now) {
            if (!warningsShown.count(w)) {
                WarningHolder::AddWarning(w);
                LogWarn(VB_PLUGIN, "Air Defender: FPP warning: %s\n", w.c_str());
            }
        }
        warningsShown = now;
    }

    // "Check my sequences": one sequence at a time, on its own thread.
    std::thread checkThread;
    std::mutex checkMutex;               // guards checkSeq, checkResult, checkError
    std::atomic<bool> checkBusy{ false }, checkCancel{ false };
    std::atomic<int> checkProgress{ 0 };
    std::string checkSeq, checkError;
    Json::Value checkResult;

    void stopCheck() {
        checkCancel = true;
        if (checkThread.joinable()) {
            checkThread.join();
        }
        checkCancel = false;
    }

    std::string checkStatus() {
        Json::Value out;
        std::lock_guard<std::mutex> lock(checkMutex);
        out["busy"] = (bool)checkBusy;
        out["seq"] = checkSeq;
        out["progress"] = (int)checkProgress;
        out["error"] = checkError;
        out["result"] = checkResult;
        return SaveJsonToString(out);
    }

    std::string checkRequest(const std::string& seqParam, bool stop, int& code) {
        if (stop) {
            stopCheck();
            return checkStatus();
        }
        if (seqParam.empty()) {
            return checkStatus();
        }
        if (checkBusy) {
            code = 409;
            return "{\"error\":\"Already checking a sequence.\"}";
        }
        std::string seq = seqParam;
        if (seq.find('/') != std::string::npos || seq.find("..") != std::string::npos) {
            code = 400;
            return "{\"error\":\"Unknown sequence.\"}";
        }
        if (seq.size() < 5 || seq.compare(seq.size() - 5, 5, ".fseq") != 0) {
            seq += ".fseq";
        }
        if (sequence && sequence->IsSequenceRunning()) {
            code = 409;
            return "{\"error\":\"A sequence is playing. Check when nothing is playing, so the show isn't slowed down.\"}";
        }
        DzConfig cfg;
        {
            std::lock_guard<std::mutex> lock(mutex);
            cfg = engine.getConfig();
        }
        bool anyZones = false;
        for (const DzHead& h : cfg.heads) {
            anyZones = anyZones || !h.zones.empty() || h.tiltMin > 0 || h.tiltMax < 255;
        }
        if (!anyZones) {
            code = 400;
            return "{\"error\":\"No zones yet: mark some on the Zones page first.\"}";
        }
        std::string path = FPP_DIR_MEDIA("/sequences/" + seq);
        if (!FileExists(path)) {
            code = 404;
            return "{\"error\":\"Sequence not found.\"}";
        }
        stopCheck();   // the last one has finished; collect its thread
        {
            std::lock_guard<std::mutex> lock(checkMutex);
            checkSeq = seq; checkError = ""; checkResult = Json::Value();
        }
        checkProgress = 0;
        checkBusy = true;
        checkThread = std::thread([this, path, cfg]() { runCheck(path, cfg); });
        return checkStatus();
    }

    /* On the check's own thread: read the sequence frame by frame and run it through the DeadZones check. */
    void runCheck(const std::string& path, const DzConfig& cfg) {
        // Gentle on the Pi: lowest priority, so anything else fppd does comes first.
        setpriority(PRIO_PROCESS, (id_t)syscall(SYS_gettid), 19);
        std::string error;
        Json::Value result;
        try {
            std::unique_ptr<FSEQFile> file(FSEQFile::openFSEQFile(path));
            if (!file) {
                throw std::runtime_error("FPP couldn't open the sequence file.");
            }
            uint32_t numFrames = file->getNumFrames(), channels = file->getChannelCount();
            int step = file->getStepTime() > 0 ? file->getStepTime() : 25;
            // Only the heads' own channels are needed.
            std::vector<std::pair<uint32_t, uint32_t>> ranges;
            long top = 0;
            for (const DzHead& h : cfg.heads) {
                for (long ch : { h.panCh, h.tiltCh, h.shutterCh, h.dimmerCh }) {
                    if (ch >= 0) { ranges.push_back({ (uint32_t)ch, 1 }); top = std::max(top, ch); }
                }
            }
            file->prepareRead(ranges, 0);
            std::vector<uint8_t> data(std::max<size_t>((size_t)channels, (size_t)top + 1) + 16, 0);
            DzScan scan(cfg, step);
            for (uint32_t f = 0; f < numFrames; f++) {
                if (checkCancel) {
                    throw std::runtime_error("Stopped.");
                }
                if ((f & 63) == 0 && sequence && sequence->IsSequenceRunning()) {
                    throw std::runtime_error("A sequence started playing, so the check stopped to keep out of its way.");
                }
                FSEQFile::FrameData* fd = file->getFrame(f);
                if (fd) {
                    std::fill(data.begin(), data.end(), 0);
                    fd->readFrame(data.data(), (uint32_t)data.size());
                    delete fd;
                }
                scan.frame(f, data.data(), data.size());
                checkProgress = numFrames ? (int)((f + 1) * 100ULL / numFrames) : 100;
            }
            result["frames"] = (Json::UInt)numFrames;
            result["stepMs"] = step;
            result["lengthMs"] = (Json::UInt64)((unsigned long long)numFrames * step);
            Json::Value heads(Json::arrayValue);
            int total = 0;
            for (const DzCheckHead& h : scan.results()) {
                Json::Value jh;
                jh["id"] = h.id; jh["name"] = h.name; jh["more"] = h.more;
                Json::Value ev(Json::arrayValue);
                for (const DzCheckEvent& e : h.events) {
                    Json::Value je;
                    je["start"] = (Json::UInt64)e.startMs; je["end"] = (Json::UInt64)e.endMs; je["why"] = e.why;
                    ev.append(je);
                    total++;
                }
                jh["events"] = ev;
                heads.append(jh);
            }
            result["heads"] = heads;
            result["total"] = total;
        } catch (const std::exception& e) {
            error = e.what();
        } catch (...) {
            error = "The check failed.";
        }
        {
            std::lock_guard<std::mutex> lock(checkMutex);
            checkError = error;
            checkResult = result;
        }
        checkBusy = false;
    }

    /*
     * Run one of the plugin's scripts the way fppd runs a plugin's lifecycle callback (fork and exec,
     * not system(), which failed with -1 inside fppd and ran nothing). The start one is detached from
     * fppd (its own session, so it outlives this process's wait) and not waited for; the stop one is
     * waited for up to 5 s, like fppd's own callbacks.
     */
    void runScript(const char* script, bool background) {
        std::string path = pluginDir + "/scripts/" + script;
        if (!FileExists(path)) {
            LogErr(VB_PLUGIN, "Air Defender: %s is missing\n", path.c_str());
            return;
        }
        pid_t pid = fork();
        if (pid < 0) {
            LogErr(VB_PLUGIN, "Air Defender: could not start %s: %s\n", script, strerror(errno));
            return;
        }
        if (pid == 0) {
            // fppd's threads block or ignore some signals, and a child keeps that across exec: give the
            // scripts (and the loop they start) a clean slate, so they can be stopped normally.
            sigset_t none;
            sigemptyset(&none);
            sigprocmask(SIG_SETMASK, &none, nullptr);
            for (int sig : { SIGTERM, SIGINT, SIGHUP, SIGQUIT, SIGCHLD, SIGPIPE }) {
                signal(sig, SIG_DFL);
            }
            // Child: quiet, and (to start the loop) a second fork so the script belongs to no one in fppd.
            int devnull = open("/dev/null", O_RDWR);
            if (devnull >= 0) {
                dup2(devnull, 0); dup2(devnull, 1); dup2(devnull, 2);
            }
            if (background) {
                setsid();
                if (fork() != 0) {
                    _exit(0);
                }
            }
            execl("/bin/bash", "bash", path.c_str(), (char*)nullptr);
            _exit(127);
        }
        // Parent: the start one's first child exits at once; the stop one runs its course (up to 5 s).
        long long deadline = GetTimeMS() + (background ? 1000 : 5000);
        while (true) {
            int r = waitpid(pid, nullptr, WNOHANG);
            if (r == pid || (r < 0 && errno != EINTR)) {
                break;
            }
            if (GetTimeMS() >= deadline) {
                LogWarn(VB_PLUGIN, "Air Defender: %s still running; carrying on without waiting\n", script);
                break;
            }
            usleep(10000);
        }
        LogInfo(VB_PLUGIN, "Air Defender: ran %s\n", script);
    }

    /* Something went wrong inside the frame check: stop for good (until fppd restarts) and say so once. */
    void fail(const std::string& why) {
        failed = true;
        std::lock_guard<std::mutex> lock(mutex);   // released by the time an exception gets here
        error = "DeadZones turned itself off: " + why;
        LogErr(VB_PLUGIN, "Air Defender: %s. The show and plane safety are not affected.\n", error.c_str());
    }

    /*
     * The head being aimed from the DeadZones tab, from the small file in memory the page's requests keep
     * (scripts/airdefender_aim.php); 0 if none, or if it hasn't been touched for 30 s (the page has gone).
     */
    int aimedHead() {
        const std::string paths[] = { "/dev/shm/AirDefender/aim.json", FPP_DIR_MEDIA("/plugindata/AirDefender/aim.json"), pluginDir + "/aim.json" };
        for (const std::string& path : paths) {
            struct stat st;
            if (stat(path.c_str(), &st) != 0) {
                continue;
            }
            if (time(nullptr) - st.st_mtime > 30) {
                return 0;
            }
            Json::Value a;
            if (LoadJsonFromFile(path, a) && a.isObject() && a.isMember("head") && a["head"].isNumeric()) {
                return a["head"].asInt();
            }
            return 0;
        }
        return 0;
    }

    /* Called with the mutex held. Reads the file again only when its time or size has changed. */
    void reloadIfChanged() {
        struct stat st, other;
        // Use whichever of the two places has the newer file.
        bool a = stat(configFiles[0].c_str(), &st) == 0, b = stat(configFiles[1].c_str(), &other) == 0;
        if (b && (!a || other.st_mtime > st.st_mtime)) {
            st = other;
            configFile = configFiles[1];
        } else {
            configFile = configFiles[0];
        }
        if (!a && !b) {
            if (fileTime != -1 || fileSize != -1) {   // it was there and has gone: off
                fileTime = -1; fileSize = -1;
                engine.setConfig(DzConfig());
            }
            return;
        }
        long long t = (long long)st.st_mtime, size = (long long)st.st_size;
        if (t == fileTime && size == fileSize) {
            return;
        }
        fileTime = t; fileSize = size;
        Json::Value root;
        if (!LoadJsonFromFile(configFile, root) || !root.isObject()) {
            error = "Could not read " + configFile;
            LogErr(VB_PLUGIN, "Air Defender: %s; DeadZones is off until it can be read.\n", error.c_str());
            engine.setConfig(DzConfig());
            return;
        }
        engine.setConfig(dzParseConfig(root, FPPD_MAX_CHANNELS));
        error = "";
        LogInfo(VB_PLUGIN, "Air Defender: DeadZones %s, %d head(s)\n", engine.getConfig().enabled ? "on" : "off",
                (int)engine.getConfig().heads.size());
    }

    /* What the settings page shows: whether it's on, and each head's position and shutter right now. */
    std::string status() {
        Json::Value out;
        std::lock_guard<std::mutex> lock(mutex);
        const DzConfig& c = engine.getConfig();
        const std::vector<DzHeadState>& states = engine.getStates();
        out["loaded"] = true;
        out["enabled"] = c.enabled && !failed;
        out["failed"] = (bool)failed;
        out["error"] = error;
        out["frames"] = (Json::UInt64)frames;
        out["onlyWhilePlaying"] = c.onlyWhilePlaying;
        out["waiting"] = c.enabled && !failed && (c.onlyWhilePlaying && !(sequence && sequence->IsSequenceRunning()));
        Json::Value heads(Json::arrayValue);
        static const char* const reasons[] = { "", "zone", "tilt", "other-head" };
        bool idle = out["waiting"].asBool();
        for (size_t i = 0; i < c.heads.size() && i < states.size(); i++) {
            const DzHead& h = c.heads[i];
            const DzHeadState& s = states[i];
            Json::Value jh;
            jh["id"] = h.id;
            jh["name"] = h.name;
            jh["setUp"] = h.panCh >= 0 && h.tiltCh >= 0;
            jh["closed"] = !idle && s.closedUntil >= 0;   // waiting for a sequence: nothing is being closed
            jh["reason"] = reasons[s.reason];
            jh["zone"] = s.reason == DZ_ZONE && s.zone >= 0 && s.zone < (int)h.zones.size() ? h.zones[s.zone].name : "";
            jh["pan"] = s.started ? Json::Value((int)std::lround(s.realPan)) : Json::Value();
            jh["tilt"] = s.started ? Json::Value((int)std::lround(s.realTilt)) : Json::Value();
            jh["closes"] = (Json::UInt64)s.closes;
            jh["zones"] = (int)h.zones.size();
            heads.append(jh);
        }
        out["heads"] = heads;
        return SaveJsonToString(out);
    }
};

// Safe to unload: no timers, commands or outbound requests of its own; the sequence check's and
// the warnings' threads are stopped and joined in shutdown() (the warnings taken off FPP's list),
// and its routes go through registerPluginApi() and are given back in unregisterApis().
FPP_PLUGIN_SUPPORTS_UNLOAD()

extern "C" {
FPPPlugins::Plugin* createPlugin() {
    return new AirDefenderPlugin();
}
}
