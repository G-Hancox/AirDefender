<h2>Air Defender Dashboard</h2>

<p>Air Defender watches for low aircraft near your show using live ADS-B flight data, and closes your
moving heads' shutters while one is close. This page shows what it is doing right now and updates by
itself every couple of seconds.</p>

<div class="alert alert-warning"><b>What it can't see.</b> Air Defender only reacts to aircraft that broadcast
ADS-B and that your chosen service or receiver picks up. Some light aircraft and helicopters don't broadcast it,
drones generally don't, online coverage thins out close to the ground, and the data is always a little old. It
does not replace your own obligations for sky-pointing beams and lasers.</div>

<h3>Lite and Detailed</h3>
<p>The switch at the top picks how much the dashboard shows. Your choice is remembered in this browser.</p>
<ul>
    <li><b>Lite</b> (default): the status banner, a one-line summary of the heads, and the last event.
        Refreshes every 5 seconds.</li>
    <li><b>Detailed</b>: adds the <b>Sky</b> radar, the <b>Aircraft</b> card and every head with its channel. Refreshes every 2 seconds.</li>
</ul>
<p>On a <b>Remote</b> there is no Lite or Detailed: it shows the banner, every head with its channel, and the last
event. The radar and aircraft are on the Player's dashboard.</p>
<p>Either way, the dashboard only asks FPP for anything while it is open; the detection loop does the same work
whether you look or not.</p>

<h3>Status banner</h3>
<ul>
    <li><b>All clear</b> (green): no aircraft inside your limits; the show controls the shutters.</li>
    <li><b>Safety active</b> (yellow): the shutters are held closed, with the reason and when it started.</li>
    <li><b>Test running</b> (blue): a test is closing the heads, with the time left.</li>
    <li><b>Can't check the sky</b> (red): the flight-data service is not answering. See the reason, and the
        <b>Close heads if the feed is down</b> setting. The Sky card says when it last answered.</li>
    <li><b>Standby</b> (grey): no show is on, so Air Defender is not checking (see <b>Check for aircraft during</b>
        in Settings). It shows the next scheduled show; checks start 2 minutes before it.</li>
    <li><b>Disabled</b> (grey): Air Defender is switched off in Settings.</li>
    <li><b>Not running</b> (red): the detection loop has stopped (or has not reported in for 2 minutes). Restart FPPD
        (or reboot). A slow flight-data service does not cause this: it shows as a feed problem.</li>
</ul>
<p>The line at the top shows the version, whether this FPP is the Player or a Remote, and which <b>Check for aircraft
during</b> choice is in use.</p>
<p><b>Manual tracking</b> (Player, on the <b>Test</b> tab): Air Defender turns itself on for your shows; manual tracking
checks for aircraft at other times, for 30 minutes to 4 hours, for example while testing a sequence, then stops by itself.
While it runs the banner says <b>Manual tracking active</b> with the time left and a <b>Stop</b> button.
(The FPP command for it is still called Air Defender: Check Now, so playlists that use it keep working.)</p>

<h3>Sky (Detailed)</h3>
<p>Your show is the dot in the middle, north is up unless you turn the radar, and the dashed yellow ring is your
distance limit. The rings inside it are marked in nautical miles (for a 5 NM limit: 1, 2, 3, 4 and 5), and the compass letters and bearing ticks sit round the outside. Yellow marks are aircraft inside your limits (close enough and low enough); grey marks are aircraft
that are too high or too far. An aircraft that reports its heading is a small arrow pointing the way it is flying: solid for a plane, inside a ring
for a helicopter, and hollow for a drone, balloon, glider or anything else. One that doesn't report a heading is a dot. Hover over a mark for its callsign, distance, height (above your show once
<b>Show elevation</b> is set in Settings) and heading. Tap an aircraft for its details in the <b>Aircraft</b> card. Aircraft on the ground are not shown.
The sweep turns once per <b>Check every</b> (10 seconds unless changed), passing the top as each check comes in. It only turns while Air Defender is checking; in standby, or when it is disabled or not running, the radar
stops and fades.</p>
<p><b>Turning the radar:</b> press the small lock beside the Sky title to unlock it (the ring turns blue and dashed),
then drag the radar round or use the arrow buttons, so the top matches the way you face your display. <b>North up</b>
puts it back. Press the lock again to save it; it stays locked so it can't be moved by accident.
On a Remote, the Sky card says it follows its Player instead.</p>

<h3>Aircraft (Detailed)</h3>
<p>Tap an aircraft on the radar for everything the flight data says about it: type and name, callsign, registration and
owner, distance, height, climb or descent, speed, heading, and links to follow it on a live map or FlightAware. Tap it
again, tap empty radar, or press &times; to clear it. With nothing picked, the card names the nearest aircraft.</p>
<p>While an aircraft holds the heads closed, the card shows <b>that</b> aircraft by itself, marked <b>Holding the heads
closed</b>, and taps on other aircraft wait until the all clear, so it's always clear why the heads are shut.</p>

<h3>Moving heads</h3>
<p>Each enabled head, its FPP channel, and whether Air Defender is holding its shutter <b>Closed</b> or the
<b>Show</b> controls it. A head with a problem (for example a model that was renamed) says so in red; in Lite
the summary line counts them.</p>

<h3>When Safety Mode turns on</h3>
<ul>
    <li>An aircraft is inside the distance and below the altitude (aircraft on the ground are ignored).</li>
    <li>For the <b>Hold after it leaves</b> time (30 seconds unless changed) after it leaves, so a plane on the
        edge of the area does not flash the lights.</li>
    <li>If the flight-data service stops answering while an aircraft was near, until it answers again. With
        <b>Close heads if the feed is down</b> on, whenever it stops answering.</li>
    <li>On a Remote: whenever its Player is in Safety Mode, and whenever it cannot reach the Player for
        5 seconds.</li>
</ul>
<p>When Safety Mode turns off, the shutters go back to whatever the sequence is doing; they are not forced
open.</p>

<h3>Test Mode</h3>
<p>Tests have their own <b>Test</b> tab at the top of the dashboard, beside <b>Status</b>. Pick a length (5 seconds to 30 minutes) and press <b>Start Test</b> to close the shutters for that long,
so you can go outside and watch the heads go dark and come back. The countdown keeps going if you refresh or
open the page somewhere else, and <b>Stop Test</b> ends it early. A test started on the Player also closes
the heads on every Remote. You can also start and stop a test from a playlist, schedule or command preset
with the <b>Air Defender: Start Test</b> and <b>Air Defender: Stop Test</b> commands.</p>

<h3>Last Event</h3>
<p>The most recent time Safety Mode turned on or off, or a test started or ended, in this FPP's time zone. Other
notes (a head re-patched, the flight-data service coming back, and so on) are only in the full log,
<code>plugin-AirDefender.log</code>, under <b>Content Setup &rarr; File Manager &rarr; Logs</b>.</p>
<p>The log lists aircraft that passed near your show. It is kept until you press <b>Clear log</b>, which
deletes it.</p>
<p><b>View log</b> opens the log over the dashboard: <b>Safety &amp; tests</b> lists when the heads were closed and
opened, and tests, with how many times the heads closed; <b>Everything</b> shows every line. Pick <b>Today</b>,
<b>Yesterday</b> (handy when a show runs past midnight) or the <b>Last 7 days</b>. Newest first. It only
reads the log when you open it.</p>
