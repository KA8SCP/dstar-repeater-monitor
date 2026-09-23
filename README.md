# D-STAR Repeater Network Monitor

**Version 1.0.0 — September 2026**

Web-based monitoring system for D-STAR DPLUS repeater gateways.

The monitor retrieves publicly available gateway dashboard information and displays repeater status, DPLUS module/link state, Last Heard activity, response time, and network-wide activity.

This is a separate project from the D-STAR Reflector Monitor.

## Monitored Repeaters

Version 1.0.0 monitors:

| Repeater | Dashboard |
|---|---|
| WB1GOF | `https://wb1gof.dstargateway.org/` |
| K1HRO | `https://k1hro.dstargateway.org/` |
| W1MRA | `https://w1mra.dstargateway.org/` |
| K1MRA | `https://k1mra.dstargateway.org/` |
| KA1EAR | `https://ka1ear.dstargateway.org/` |
| W1SCV | `https://w1scv.dstargateway.org/` |
| KS1R | `https://ks1r.dstargateway.org/` |
| KD8QOF | `https://kd8qof.dstargateway.org/` |

## Features

For each repeater the monitor can display:

- Online/offline status
- HTTP response status and response time
- DPLUS software version
- DPLUS modules A-E
- Current reflector/link state for each module
- DPLUS Last Heard activity
- User Message when published
- Direct link to the source dashboard

Modules are displayed alphabetically.

Blank Last Heard callsigns are intentionally excluded. The monitor does not manufacture callsigns, link states, or activity that the source gateway does not publish.

## Network Last Heard

Valid DPLUS Last Heard records from all monitored repeaters are combined into the Network Last Heard display.

The display includes:

- Repeater
- Callsign
- User Message
- Module
- Time

If a gateway publishes no valid Last Heard callsign, no activity is generated for that gateway.

## WB1GOF g2_link Support

WB1GOF publishes both DPLUS and g2_link information on the same dashboard.

Version 1.0.0 keeps these two sources separate.

The WB1GOF card can display:

- DPLUS version
- DPLUS modules A-E and reflector links
- DPLUS Last Heard
- g2_link version
- g2_link dashboard version
- g2_link module/link state
- g2_link Last Heard

A g2_link state never overwrites the authoritative DPLUS module/link state.

Gateways that do not publish g2_link information do not display empty g2_link sections.

## K1MRA HTTPS Certificate Handling

During development, the K1MRA HTTPS dashboard presented a certificate chain that could not be validated normally by the monitoring server.

The configuration therefore supports a per-gateway SSL exception:

```php
'insecure_ssl' => true,
```
This exception is configured only for K1MRA.

TLS certificate verification remains enabled for all other gateways. SSL verification should not be disabled globally.

## KD8QOF Reachability

During production testing, `kd8qof.dstargateway.org` resolved correctly but the monitoring server could not establish TCP connections to ports 80 or 443.

The KD8QOF dashboard remained reachable from other Internet connections.

The monitor therefore reports KD8QOF according to reachability from the monitoring server. If connectivity returns, normal monitoring automatically resumes.


## Cache and History

The monitor uses a short-term cache to reduce unnecessary requests to remote gateways.

Availability history is stored in SQLite. The `cache/` and `data/` directories must be writable by the web server.

## Requirements

- Linux
- Apache 2.4 or compatible web server
- PHP 8.x
- PHP cURL
- PHP DOM/XML
- PHP PDO
- PHP SQLite3
- HTTPS/tLS support

## Production Installation

Current installation path:

    /var/www/xlxd/dstar-repeater

Apache must have read access to the application files and write access to `cache/` and `data/`.

## Validation

Before deployment, validate all PHP files:

    for f in *.php; do php -l "$f" || exit 1; done

The web interface should return HTTP 200.

## Security

- Remote dashboards are accessed read-only.
- The monitor does not control or configure remote repeaters.
- TLS verification is enabled by default.
- SSL exceptions should be limited to specific gateways.
- Runtime cache and SQLite data should not be committed to Git.

## Project

**D-STAR Repeater Network Monitor**

Version **v1.0.0** — September 2026

Repository name:

    KA8SCP/dstar-repeater-monitor

