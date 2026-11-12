<h2>Air Defender Settings</h2>

<p>The page has five tabs, in the order you would set things up. Like FPP's own settings, each setting saves as soon
as you change it (a note pops up to say so) and takes effect within a second; FPP does not need a restart. The
<b>?</b> beside a setting explains it.</p>

<h3>General</h3>
<ul>
    <li><b>Enable Air Defender</b>: turns aircraft checking on or off. It is off on a new install until you switch it
        on. A test from the dashboard still works while it is off.</li>
    <li><b>Role</b>: on a new install this follows FPP's own Player Mode, and the note under it says whether it still
        matches; change it if your setup needs something else. <b>Player</b> checks for aircraft and closes its own heads.
        Use it on a single FPP, and on the FPP that runs the show; it also covers heads it sends to over DDP or E1.31,
        such as a Falcon controller. <b>Remote</b> is for an FPP in Remote mode, which plays its own copy of the sequence
        on its own outputs: once a second it asks the Player's Air Defender for its status, and closes its own heads
        whenever the Player is in safety mode (including the Player's tests). If it cannot reach the Player for 5
        seconds, it closes its heads until the Player is back. Install Air Defender on every Remote with heads. The
        Player needs no setting for this, and FPP's MultiSync packets are not involved.</li>
    <li><b>Player FPP address</b> (Remote only): leave blank to find the Player automatically from FPP's MultiSync
        list. Enter its IP address or hostname if there is more than one Player FPP on the network.</li>
    <li><b>Player's UI password</b> (Remote only): needed only if the Player has an FPP UI password. It is kept in
        its own file in FPP's plugindata folder that only the fpp user can read, and never shown. Type it and press
        <b>Save password</b>; <b>Remove</b> clears the saved one.</li>
</ul>
<p>On a Remote, only General and Moving heads are shown: the rest is set on the Player.</p>

<h3>Moving heads</h3>
<p>One row per head (up to 8). <b>Add head</b> adds a row; the bin button removes one (after asking).</p>
<p><b>Import from xLights</b>: pick <b>xlights_rgbeffects.xml</b> from your show folder and tick the moving heads to protect. Each gets its name, its FPP model (when FPP has one with the same name) or xLights start channel, and its shutter channel, plus its DeadZones pan and tilt. A head already here with the same name is updated, not added twice. xLights has no "closed" value, so check that one.</p>
<ul>
    <li><b>On</b>: whether Air Defender protects this head.</li>
    <li><b>Name</b> (optional): what to call the head in the log and on the dashboard. Picking a model fills it in.</li>
    <li><b>Model</b>: the head's model from FPP's model list, or <b>Manual channel</b>. To get your heads into the
        list, upload from xLights with FPP Connect with the <b>Models</b> column set to <b>All</b> (<b>Local</b> leaves
        out heads on other controllers, such as a Falcon), or add them on FPP's Pixel Overlay Models page. xLights
        changes spaces in model names to underscores.</li>
    <li><b>Start ch.</b>: for a manual head, the head's first FPP channel, its Start Channel in xLights (for example
        82448). For a model it is filled in from the model.</li>
    <li><b>Shutter ch.</b>: the shutter's channel number <i>within the fixture</i>, from its DMX chart (for example 7).</li>
    <li><b>Closed</b>: the value (0&ndash;255) that closes the shutter, from the fixture's DMX chart.</li>
</ul>
<p><b>FPP channel</b> shows the channel Air Defender worked out (start + shutter &minus; 1, so 82448 and 7 give
82454). While Safety Mode is on, that channel is held at the closed value over whatever the sequence is doing.
Heads picked by model are looked up by name, so a head re-patched in xLights and uploaded again is followed. If a
model is renamed or deleted, the row turns red and Air Defender keeps protecting the channel it last found for it
until you pick the model again.</p>

<h3>When &amp; where</h3>
<ul>
    <li><b>Check for aircraft during</b>: <b>All scheduled shows</b> (the default) checks during every scheduled show, starting 2
        minutes before each one. <b>Only selected playlists</b> checks only while a playlist selected in the list beside it
        plays, scheduled or started by hand, and from 2 minutes before a scheduled one; with nothing ticked, it works like
        <b>All scheduled shows</b>. Outside those times the dashboard shows <b>Standby</b>, nothing is sent to the flight-data
        service, and the shutters are left to the show. To test a sequence at another time, use <b>Start tracking</b> on the
        dashboard (or the Air Defender: Check Now command): it tracks for the time you pick, then stops by itself.</li>
    <li><b>Playlists to check during</b> (shown with Only selected playlists; your ticks are kept while it is
        hidden): tick the playlists that use your moving heads, so the rest (a late-night static show, say) leave Air
        Defender on Standby. A sequence started on its own is not part of a playlist, so it is not checked unless
        <b>Also check whenever FPP is playing</b> is ticked. A selected playlist FPP no longer has is marked "not in FPP" until you untick it. If you use
        Remote Falcon, tick its playlist too: viewer requests play from it. (During a selected scheduled show Air Defender
        keeps checking through requests anyway, since they play inside that show's schedule slot.)</li>
    <li><b>Also check whenever FPP is playing</b> (off by default): also checks while anything plays that the choice
        above leaves out, such as a single sequence or a playlist started by hand. Checking starts as soon as FPP starts
        playing, so there is no 2-minute head start: the heads stay closed for the few seconds until the first check
        answers, then open if the sky is clear. The log notes this hold, but it doesn't count as a time the heads
        closed for an aircraft.</li>
    <li><b>Use FPP's location</b>: uses the latitude and longitude in FPP's own settings, shown below it. Turn it
        off to enter a different <b>Latitude</b> and <b>Longitude</b>. <b>Check it on a map</b> opens the spot in OpenStreetMap: always check that the pin is on
        your show, not just your town. FPP's location is often rough and can be miles off, and every distance (and the
        show elevation) is measured from this point. To enter your own, right-click your yard in Google Maps and click
        the numbers at the top of the menu to copy them.</li>
    <li><b>Show elevation</b>: your ground's height above sea level, in feet. Aircraft report their height above sea
        level, so Air Defender subtracts this to make the altitude limit count from your show. <b>Look it up</b> fills
        it in for the location above (FPP's or the one you entered), from Open-Meteo's elevation data (Copernicus DEM);
        that sends the location to api.open-meteo.com once, only when you press it. You can also type it in from a map
        or your phone. Left at 0, heights are measured above sea level, as before.</li>
</ul>

<h3>Limits</h3>
<ul>
    <li><b>Distance</b> (nautical miles) and <b>Altitude</b> (feet): heads close when an aircraft is inside the
        distance <i>and</i> below the altitude. With a <b>Show elevation</b> set, the altitude is measured above your
        show, and the note under it shows what that is above sea level.</li>
    <li><b>Hold after it leaves</b>: how long, in seconds, heads stay closed after the last aircraft leaves
        (0&ndash;600, default 30), so one on the edge of the area does not flash them on and off.</li>
    <li><b>Check every</b>: how often, in seconds, to ask for new flight data (5 to 15; never slower, so a fast aircraft can't get too close between looks). Every 10 seconds is about
        1,800 requests in a 5-hour show night, which matters because free services limit how many they answer. A
        smaller distance may want a quicker check.</li>
</ul>

<h3>Flight data</h3>
<ul>
    <li><b>Service</b>: <b>adsb.fi</b> (the default) and <b>adsb.lol</b> are free community services for personal,
        non-commercial use, and are sent your show's location, rounded to about 1 km (with the search radius widened
        by 1 NM to make up for it; Air Defender works out distances from your exact location itself). <b>Your receiver on your network</b> reads your own ADS-B
        receiver (a Pi running ADSB.im, PiAware, or readsb/dump1090 with tar1090): enter its IP address or name in
        <b>Receiver address</b> (with <code>:port</code> if needed), and Test connection finds where it publishes its
        aircraft list. Only home-network addresses are accepted, and it is never sent your location. Data more than 2
        minutes old counts as the receiver not answering. <b>Receiver on this FPP</b> reads one installed on the FPP
        itself (not recommended: it takes CPU from the show). The README explains what a receiver needs.</li>
    <li><b>Use a backup service</b>: off until you turn it on, because it means the backup service is also sent
        your show's location while it is used. When on, if your chosen service does not answer, Air Defender asks the
        backup in the same check: adsb.fi and adsb.lol back each other up, and your own receiver falls back on adsb.fi.
        It tries your chosen service again every minute and goes back to it when it answers. With it off, your own
        receiver never sends anything to an online service.</li>
    <li><b>Test connection</b>: asks the service once, right away, using the location and limits saved here, and shows how many aircraft it sees, the nearest one, how many are inside your limits, or the exact
        error.</li>
    <li><b>Close heads if the feed is down</b>: off (the default), if the service stops answering, heads stay as they are
        unless an aircraft was near when it stopped. On, heads close whenever it stops answering, until it answers
        again: stricter, but an internet outage keeps them closed.</li>
</ul>
