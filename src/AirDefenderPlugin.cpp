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
 */
#include <fpp-pch.h>

#include <sys/stat.h>
#include <sys/wait.h>
#include <fcntl.h>
#include <unistd.h>
#include <cerrno>
#include <cstring>
#include <ctime>

#include <cstdlib>
#include <exception>
#include <functional>
#include <mutex>
#include <string>

#include "Plugin.h"
#include "Sequence.h"
#include "common.h"
#include "fpphttp.h"
#include "log.h"
#include "settings.h"

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
    }
    virtual ~AirDefenderPlugin() {}

    /* fppd stopping, or unloading the plugin to update or uninstall it: stop the loop, as callbacks.sh would. */
    virtual std::function<bool()> shutdown() override {
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
            if (engine.getConfig().enabled) {
                engine.process(now, seqData, FPPD_MAX_CHANNELS);
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
    }
    virtual void unregisterApis() override {
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
        Json::Value heads(Json::arrayValue);
        static const char* const reasons[] = { "", "zone", "tilt", "other-head" };
        for (size_t i = 0; i < c.heads.size() && i < states.size(); i++) {
            const DzHead& h = c.heads[i];
            const DzHeadState& s = states[i];
            Json::Value jh;
            jh["id"] = h.id;
            jh["name"] = h.name;
            jh["setUp"] = h.panCh >= 0 && h.tiltCh >= 0;
            jh["closed"] = s.closedUntil >= 0;
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

// Safe to unload: no threads, timers, commands or outbound requests of its own, and its one
// route goes through registerPluginApi() and is given back in unregisterApis().
FPP_PLUGIN_SUPPORTS_UNLOAD()

extern "C" {
FPPPlugins::Plugin* createPlugin() {
    return new AirDefenderPlugin();
}
}
