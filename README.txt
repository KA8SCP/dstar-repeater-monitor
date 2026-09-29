# Digital Repeater Monitor

**Version 1.0.2 — September 29, 2026**

Web-based monitoring for D-STAR DPLUS gateways, BrandMeister DMR, and Pi-Star multimode repeaters. This is a separate project from the D-STAR Reflector Monitor; the repository remains `KA8SCP/dstar-repeater-monitor`.

## What's new in v1.0.2

- Adds WB1GOF DMR 312543 as a separate repeater from WB1GOF D-STAR, with distinct cache and history identities.
- Adds W1ATD Multimode with enabled modes, network indicators, radio details, Gateway Activity, and a separate Local RF Activity table.
- Adds BrandMeister Last Heard to the DMR card and network-wide activity, including callsign/ID, timeslot, target, timestamp, duration, and talker alias.
- Renames the page to Digital Repeater Monitor and adds DMR and multimode filters.
- Places WB1GOF DMR immediately after WB1GOF D-STAR, so they sit alongside one another in a two-column layout. W1ATD remains last. On narrow screens, cards stack in that order.
- Uses a BrandMeister-specific user-agent to resolve the HTTP 403 observed with the original monitor user-agent.

## Monitored repeaters

The ten cards appear in this order:

| Repeater | Type | Dashboard |
|---|---|---|
| WB1GOF | D-STAR DPLUS | https://wb1gof.dstargateway.org/ |
| WB1GOF DMR 312543 | BrandMeister DMR | https://brandmeister.network/#/device/312543 |
| K1HRO | D-STAR DPLUS | https://k1hro.dstargateway.org/ |
| W1MRA | D-STAR DPLUS | https://w1mra.dstargateway.org/ |
| K1MRA | D-STAR DPLUS | https://k1mra.dstargateway.org/ |
| KA1EAR | D-STAR DPLUS | https://ka1ear.dstargateway.org/ |
| W1SCV | D-STAR DPLUS | https://w1scv.dstargateway.org/ |
| KS1R | D-STAR DPLUS | https://ks1r.dstargateway.org/ |
| KD8QOF | D-STAR DPLUS | https://kd8qof.dstargateway.org/ |
| W1ATD Multimode | Pi-Star | http://stn4571.ip.irlp.net:41390/ |

## D-STAR gateways

DPLUS cards retain module/link state, software version, Remote Users, user messages, Last TX module/status, connection type, Last Heard, response time, and source-dashboard links. Modules are displayed alphabetically; non-module states such as `listening` are preserved.

WB1GOF's g2_link software, dashboard version, module/link state, and Last Heard remain separate from its authoritative DPLUS data. Empty g2_link sections are not displayed on other gateways.

## WB1GOF DMR 312543

Device status comes from the public `https://api.brandmeister.network/v2/device/312543` endpoint. Its `/profile` endpoint supplies static and dynamic talkgroup subscriptions when published. These public reads currently require no API key.

The card displays frequencies, color code, hardware, firmware, master, location, last-seen time, and reported slot state. Device availability follows BrandMeister's 15-minute UTC `last_seen` window; a successful HTTP request alone does not establish a live repeater connection. Slot state on an offline device is explicitly labeled as last reported state.

Last Heard uses the public Socket.IO `/lh` service behind https://brandmeister.network/#/lh?ContextID=312543. A read-only `searchHouse` request selects `ContextID=312543` and returns up to 25 records. PHP uses Engine.IO polling, so no persistent background service or extra package is required. The request has an eight-second overall budget plus a half-second best-effort session close. Results, including failures, are cached for 60 seconds.

A Last Heard failure appears in the activity section and does not change device availability. A profile failure similarly leaves device status intact. The upstream `/lh` interface may change independently of the REST API. Missing callsigns may use a published source ID; activity is not counted as connected users.

BrandMeister requests use `Mozilla/5.0` because the monitor's original user-agent received HTTP 403 from its access rules. TLS certificate verification remains enabled.

## W1ATD Multimode

The Pi-Star dashboard publishes DMR ID **312541**, distinct from WB1GOF's **312543**. The monitor parses the public initial page for enabled modes, network indicators, software and radio information, and activity.

- **Gateway Activity:** callsign, mode/timeslot, target, source, and time.
- **Local RF Activity:** a separate table with callsign, mode, target, time, duration, BER, and RSSI when published.

Only Gateway Activity enters network-wide Last Heard, avoiding duplicate local RF records. Pi-Star timestamps use the configured `America/New_York` timezone with daylight-saving and year-rollover handling. Update that setting if the source dashboard's timezone changes.

Pi-Star availability means that the public dashboard is reachable and recognizable; it does not prove RF operation. Modes and timeslots are not counted as DPLUS modules or connected users.

## Network activity, cache, and history

Network-wide Last Heard combines the supported sources and displays repeater, callsign, message, module/mode, target, and time. The monitor preserves published information rather than manufacturing activity.

Browser refresh and normal status caching default to 15 seconds. BrandMeister activity has a separate 60-second cache. SQLite history samples at most once per minute and retains 90 days by default. The `cache/` and `data/` directories must be writable by the web server.

Availability history records the monitor's observations. Source retrieval failures currently produce an offline status, so an outage in this history can reflect an API/dashboard access problem rather than a failed radio. Consult the card's error message.

## Requirements

- Linux with Apache 2.4 or a compatible web server.
- PHP 8.0 or newer with cURL, DOM/XML, PDO, and PDO SQLite support.
- Outbound HTTP/HTTPS access and a working CA trust store.
- Access to W1ATD's dashboard on TCP port 41390.

## Installation and upgrade

The existing production directory is `/var/www/xlxd/dstar-repeater`.

For a fresh installation, copy the application files and provide writable `cache/` and `data/` directories. For an upgrade from v1.0.1, back up the existing application files and deploy these four files together:

- `config.php`
- `functions.php`
- `digital_repeaters.php` (new)
- `index.php`

Review any site-specific configuration before replacing `config.php`. Preserve existing `data/` and `cache/` contents; no database migration is required. Allow at least 60 seconds for caches to refresh, then reload the browser. Do not include runtime cache files, databases, SSH keys, or development runtimes in Git.

## Validation

Run in the staged application directory before deployment:

```sh
for f in *.php; do php -l "$f" || exit 1; done
php tests/digital_repeaters_test.php
php tests/brandmeister_heard_test.php
```

The tests use captured public fixtures and cover parsing, distinct identities, card order, Pi-Star local/gateway separation, timestamps, BrandMeister history decoding, and error handling. They do not establish live connectivity.

Optional read-only live Last Heard check:

```sh
php -r 'require "functions.php"; echo json_encode(brandmeister_heard_snapshot(312543), JSON_PRETTY_PRINT), PHP_EOL;'
```

On September 29, 2026, both test suites and PHP lint passed on the production Linux server. The live BrandMeister check returned 25 records with no error. After deployment, the operator confirmed DMR Last Heard and the requested card placement.

## Operational notes

- K1MRA retains the existing per-gateway `insecure_ssl` exception because its dashboard previously presented an incomplete certificate chain. Other gateways retain certificate verification. Do not disable TLS validation globally.
- KD8QOF has previously been unreachable from the monitoring server despite being reachable elsewhere. Its status follows the server's access to the source dashboard.
- HTTP 403 indicates refused access, not proof of a repeater outage. Check the response and the BrandMeister-specific user-agent configuration.
- Remote sources are read-only; the application does not control or configure repeaters.

## Earlier release

v1.0.1 introduced DPLUS Remote Users and preserved WB1GOF's separate DPLUS and g2_link information. Those features remain in v1.0.2.
