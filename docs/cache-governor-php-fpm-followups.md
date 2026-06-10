# Cache Governor PHP-FPM Awareness Follow-Ups

## Current Stopping Point

Anchor Cache Governor is in a good stopping state for WordPress-side cache control:

- WP-Optimize page cache is kept on a conservative profile.
- WP-Optimize preload-after-purge, scheduled preload, sitemap preload, and separate mobile cache are disabled.
- Cache lifespan can be long, such as 60 days on archive-heavy sites.
- Anchor warms only one URL per runner pass and backs off after failures.
- Public content saves enqueue only targeted warm URLs.
- Older WP-Optimize HTML files can be scanned and queued for slow refresh.
- Recent governor activity is logged in a bounded event history.
- Plugin-aware WPO exceptions preserve existing exclusions and add ecommerce path/cookie rules when supported plugins are active.

That covers the safe WordPress-only layer. The next meaningful improvement is server-pressure awareness, which needs some server-side signal instead of guessing from a single WordPress request.

## Why PHP-FPM Awareness Needs Server-Side Data

WordPress can see request context and can call `sys_getloadavg()`, but that is not enough to know whether a specific site is exhausting PHP-FPM capacity. The incidents that prompted this work were often pool-specific:

- A site can have maxed PHP-FPM children while Apache is not saturated.
- Another site on the same server can be fine at the same time.
- MariaDB pressure can amplify PHP-FPM wait time.
- A public page may respond quickly while background workers or another pool are saturated.

For this reason, Cache Governor should not make aggressive decisions from WordPress-only signals. It should consume a small, trusted server-health signal when available and degrade gracefully when it is not.

## Preferred Future Design

Add a small server-pressure input that Anchor can read locally or through an authenticated endpoint.

The best source is the existing server-health-report stack because it already knows about:

- PHP-FPM worker pools and per-pool pressure.
- `max_children` events.
- Apache backlog saturation versus calm Apache state.
- MariaDB pressure.
- Recent access-log hot paths and user agents.

Anchor should not shell out from arbitrary WordPress requests. Instead, it should read one of these:

- A local JSON summary file written by server-health-report with restrictive permissions.
- A same-server HTTP endpoint that returns a compact signed or token-protected JSON summary.
- A WordPress option populated by a controlled cron task that fetches the protected server-health summary.

## Minimal Signal Anchor Needs

For each site, the governor only needs a compact status object:

```json
{
  "generated_at": "2026-06-10T00:00:00Z",
  "site": "example.com",
  "php_fpm": {
    "pool": "example_com",
    "busy_workers": 3,
    "max_children": 12,
    "pressure": 0.25,
    "recent_max_children": 0
  },
  "server": {
    "load_1m": 1.2,
    "mariadb_busy": false,
    "apache_backlog_saturated": false
  },
  "recommendation": "normal"
}
```

The `recommendation` field should be one of:

- `normal`: run the normal slow warm/stale-scan cadence.
- `defer`: do not warm now; reschedule later.
- `pause`: pause warming and stale scans for a longer window.
- `emergency`: stop all background cache work and only preserve existing cache.

## Governor Behavior With Server Pressure

If a server-pressure signal is available and fresh:

- `normal`: run current behavior.
- `defer`: do not start warm or stale-scan jobs; reschedule using the profile delay.
- `pause`: set `paused_until` in warm stats and skip stale scans until then.
- `emergency`: clear pending warm runner events, leave the queue intact, and log the reason.

If the signal is missing or stale:

- Fall back to current behavior.
- Keep `sys_getloadavg()` as a weak safety gate.
- Log `server_pressure_unavailable` only occasionally to avoid noisy history.

## Server-Side Work Needed

This probably belongs in `server-health-report` before Anchor consumes it:

- Add a compact per-site pressure export keyed by host/docroot/pool.
- Include timestamp, pool pressure, recent max-children count, MariaDB pressure, and Apache saturation state.
- Make the export cheap to read and safe to expose only to trusted local consumers.
- Document the file path or endpoint contract.

After that, Anchor can add:

- A configurable pressure-source URL or local file path.
- A short cache/transient around the fetched pressure signal.
- A diagnostic panel showing last pressure readback.
- Event-history entries when cache work is deferred by server pressure.

## What Not To Do Yet

- Do not let Anchor run WHM commands from normal WordPress requests.
- Do not have Anchor parse large Apache logs directly.
- Do not make global server-wide blocking decisions from Anchor.
- Do not pause all sites because one PHP-FPM pool is busy unless the server-health signal says the whole server is in emergency state.

## Practical Next Step

The next pass should start in `server-health-report`, not Anchor:

1. Define and verify the compact per-site pressure JSON.
2. Deploy it through the existing WHM/server-health workflow.
3. Add read-only Anchor diagnostics for the signal.
4. Only then let Anchor use the signal to defer warm and stale-scan jobs.
