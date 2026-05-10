# Souin Cache for WordPress

WordPress integration for the [Souin HTTP cache](https://github.com/darkweak/souin) running as middleware in **Caddy** or **FrankenPHP**. Provides automatic cache invalidation on content changes, an admin UI for configuration, and an optional companion mu-plugin for "always-warm" cache strategies.

> Souin sits in front of PHP and serves cached HTTP responses straight from Redis/DragonflyDB. This plugin makes WordPress aware of Souin so that the cache stays in sync with content changes.

## Contents

- [Architecture](#architecture)
- [Components](#components)
- [Requirements](#requirements)
- [Installation](#installation)
- [Caddyfile reference](#caddyfile-reference)
- [DragonflyDB / Redis](#dragonflydb--redis)
- [Cache strategy: TTL vs invalidation](#cache-strategy-ttl-vs-invalidation)
- [Building a warmer worker](#building-a-warmer-worker)
- [Configuration reference](#configuration-reference)
- [Troubleshooting](#troubleshooting)

## Architecture

```
        ┌─────────────────────┐         ┌──────────────────────┐
        │  Caddy + Souin      │  PHP    │  FrankenPHP / WP     │
HTTP ──▶│  (HTTP cache layer) │ ──────▶ │  (origin)            │
        └──────────┬──────────┘         └──────────┬───────────┘
                   │                               │
                   │ stores HTTP responses         │ on save_post / etc:
                   ▼                               │  1. PURGE Souin API
        ┌─────────────────────┐                   │  2. RPUSH warmer queue
        │  DragonflyDB /      │ ◀─────────────────┘
        │  Redis              │
        └──────────┬──────────┘
                   │ (optional)
                   ▼
        ┌─────────────────────┐
        │  Cache warmer       │  pops queue, crawls /sitemap.xml
        │  (your worker)      │  to repopulate Souin cache
        └─────────────────────┘
```

## Components

This repo ships **two complementary pieces** — the plugin alone is enough for most setups; the mu-plugin is for projects that want full-cache invalidation plus an external warmer.

### 1. Plugin (`souin-cache.php`)

Standard WordPress plugin. Activate from the admin. Provides:

- **Settings page** at *Settings → Souin Cache*
  - Redis host:port + password
  - Excluded URL paths (per line; matched paths get `Cache-Control: no-store` so Souin never caches them)
- **Admin bar button** "🗑 Purge Cache" for manual full-cache flush
- **Automatic surgical purges** via Redis `SCAN` + `DEL` on:
  - Post lifecycle: `save_post`, `deleted_post`, `trashed_post`, `untrashed_post`
  - Term changes: `edited_term`, `delete_term` (categories, tags)
  - Theme / widget / menu changes
  - WooCommerce stock + product updates

It talks to Redis directly via the **phpredis** extension. Souin keys follow the pattern `{METHOD}-http-{host}-{path}` and `IDX_*-http-{host}-{path}`, which the plugin matches with a Redis glob.

### 2. mu-plugin (`mu-plugins/souin-cache-warmer.php`) — *optional*

For projects that want **full-cache purge + automatic warmer trigger** instead of (or alongside) surgical purges. Drop into `wp-content/mu-plugins/` to enable — mu-plugins are auto-loaded and cannot be deactivated from the WP admin.

On every content change it:

1. Calls `PURGE` on Souin's management API (`/souin-api/souin/?path=.+`) — wipes the **entire** cache for the host
2. `RPUSH`es a marker into the Redis list `wp:cache-warm-queue` (configurable)

Use case: long Souin TTL (24h–7d) where the cache should never expire on its own and only refresh when content actually changes. Pair with a worker that pops the queue and crawls your sitemap.

## Requirements

- **WordPress** 5.5+ (PHP 7.4+)
- **phpredis** PECL extension (used by both the plugin and mu-plugin)
- **Caddy** or **FrankenPHP** with the [Souin module](https://github.com/darkweak/souin/tree/master/plugins/caddy) compiled in
- **Redis 6+** or **DragonflyDB** (recommended) as the cache store

## Installation

### 1. Build Caddy/FrankenPHP with Souin

```bash
# Caddy
xcaddy build --with github.com/darkweak/souin/plugins/caddy

# FrankenPHP — Souin support is bundled in the standard image, just enable
# the cache directive in your Caddyfile.
```

### 2. Deploy DragonflyDB or Redis

DragonflyDB is recommended for production: drop-in compatible with the Redis 6 protocol, multi-threaded, more memory-efficient. A minimal Helm values for k8s:

```yaml
# values.yaml for dragonflyoss/dragonfly chart
storage:
  enabled: true
  requests: 2Gi
extraArgs:
  - --cache_mode=true       # LRU eviction so Souin keys don't OOM the box
  - --maxmemory=1.5gb
auth:
  enabled: true
  password: change-me
```

For non-k8s / Docker:

```bash
docker run -d --name dragonfly \
  -p 6379:6379 \
  docker.dragonflydb.io/dragonflydb/dragonfly \
  --cache_mode=true \
  --requirepass change-me
```

### 3. Configure Caddy with the Souin cache directive

See [Caddyfile reference](#caddyfile-reference) below.

### 4. Install this plugin

Copy the repo into `wp-content/plugins/souin-cache/` and activate it from the WP admin. Then go to *Settings → Souin Cache* and enter the Redis host:port + password.

```bash
cd wp-content/plugins/
git clone https://github.com/codeagencybe/souin-cache.git
```

### 5. *(optional)* Install the mu-plugin

Only if you want the always-warm strategy:

```bash
cp wp-content/plugins/souin-cache/mu-plugins/souin-cache-warmer.php \
   wp-content/mu-plugins/
```

Then add to `wp-config.php`:

```php
define( 'WP_REDIS_HOST',     'redis.example.com' );
define( 'WP_REDIS_PASSWORD', 'change-me' );
// Optional overrides:
// define( 'WP_REDIS_PORT',      6379 );
// define( 'SOUIN_API_URL',      'http://127.0.0.1/souin-api/souin/?path=.%2B' );
// define( 'SOUIN_WARMER_QUEUE', 'wp:cache-warm-queue' );
```

### 6. *(optional)* Deploy a warmer worker

See [Building a warmer worker](#building-a-warmer-worker) below.

## Caddyfile reference

A complete, production-ready Caddyfile that supports both the plugin (surgical purge) and the optional mu-plugin (full purge + warmer):

```caddyfile
{
    auto_https off
    order php_server before file_server
    order php before file_server

    cache {
        api {
            souin {
            }
        }

        # Long TTL — invalidation happens explicitly via the plugin /
        # mu-plugin. Drop this to a few minutes if you are using the plugin
        # alone without a warmer (gradual cache miss is OK).
        ttl   168h          # 7d
        stale 24h

        redis {
            configuration {
                Addrs    dragonfly:6379
                Password change-me
            }
        }

        regex {
            # Never cache admin / auth / cart / checkout / cron / xmlrpc.
            # The plugin also lets editors add ad-hoc paths via the UI.
            exclude /wp-admin
            exclude /wp-login\.php
            exclude /wp-cron\.php
            exclude /xmlrpc\.php
            exclude /cart
            exclude /checkout
            exclude /my-account
        }
    }
}

:80 {
    root * /var/www/html
    encode br zstd gzip

    # The mu-plugin needs Souin's management API to issue PURGEs. Mount it
    # but block external access — anyone who can reach the API can wipe
    # your cache at will.
    @souin_api_external {
        path /souin-api/*
        not remote_ip 127.0.0.1 ::1
    }
    respond @souin_api_external 403

    # Bypass cache entirely for logged-in users + active WooCommerce
    # sessions. Without this, two visitors share a cart / login state.
    @nocache {
        header_regexp Cookie "wordpress_logged_in_|woocommerce_items_in_cart|woocommerce_cart_hash"
    }
    handle @nocache {
        php_server
    }

    handle {
        cache
        php_server
    }
}
```

## DragonflyDB / Redis

Both the plugin and the mu-plugin use the same Redis instance that Souin caches into. Recommendations:

- **DragonflyDB**: enable `--cache_mode=true` so Souin keys are LRU-evicted instead of refused once memory fills up. Without it, you'll see `OOM command not allowed when used memory > 'maxmemory'` errors and Souin will refuse to cache anything new.
- **Redis 7+**: set `maxmemory-policy allkeys-lru` for the same reason.
- **Auth**: use a password. Souin, the plugin, and the mu-plugin all support it.
- **Network**: keep Redis on a private network. The cache contains rendered HTML which is mostly public, but the management API path can wipe it all.

## Cache strategy: TTL vs invalidation

| Strategy                         | Souin `ttl` | Purge mode               | Warmer    | Best for                                          |
|----------------------------------|------------|--------------------------|-----------|---------------------------------------------------|
| **Time-based expiry**            | minutes    | none                     | none      | Sites where slightly stale content is acceptable  |
| **Surgical invalidation**        | hours      | plugin (per-URL)         | none      | Most sites — accurate, low complexity             |
| **Always-warm**                  | days       | mu-plugin (full PURGE)   | required  | Sites where every page should always be hot       |
| **Defense in depth**             | days       | plugin **+** mu-plugin   | required  | High-traffic sites that can't afford cold pages   |

The bigger the TTL, the more you depend on invalidation being reliable — but the smaller your origin load. Pick based on how confident you are in your purge logic and how cold-page-sensitive your traffic is.

## Building a warmer worker

The mu-plugin pushes a marker (a unix timestamp) into the Redis list set by `SOUIN_WARMER_QUEUE` (default `wp:cache-warm-queue`). A worker pops it, crawls your sitemap, and lets each request flow through Souin so the cache fills up.

Skeleton (any language; pseudo-shell):

```bash
# Pop a job (BLPOP blocks until one appears)
JOB=$(redis-cli -h $REDIS_HOST -a $REDIS_PASS BLPOP wp:cache-warm-queue 0 | tail -1)

# Fetch the sitemap
curl -s https://example.com/sitemap.xml | \
  grep -oE '<loc>[^<]+</loc>' | sed 's/<\/\?loc>//g' | \
  while read -r url; do
    # GET each URL — Souin will cache the response on its way back.
    curl -s -o /dev/null -A "souin-warmer" "$url"
  done
```

Production options:

- **KEDA `redis-streams` / `redis` scaler** + ScaledJob: scale a crawler pod from 0→1 when the queue is non-empty, do the work, scale back to 0. Native fit for k8s.
- **Cronjob**: simpler — run every minute, only crawl if `LLEN > 0`.
- **Long-running worker** with `BLPOP`: simplest code, costs you one always-on container.

Tips:

- The mu-plugin only enqueues if the list is empty (`LLEN === 0`). Bursts of saves coalesce into one warm cycle.
- Set `parallelism: 1` on the worker so you don't hammer your origin during the warm.
- Crawl `/sitemap.xml` over hand-maintained URL lists — sitemaps stay accurate as content changes.

## Configuration reference

### Plugin options (set in *Settings → Souin Cache*)

| Option                  | Default                                                        | Purpose                                                       |
|-------------------------|----------------------------------------------------------------|---------------------------------------------------------------|
| `souin_redis_url`       | `dragonfly.dragonfly.svc.cluster.local:6379`                   | Redis host:port for direct Redis purges                       |
| `souin_redis_password`  | (empty)                                                        | Redis password                                                |
| `souin_cache_excludes`  | (empty)                                                        | Newline-separated URL prefixes that send `Cache-Control: no-store` |

### mu-plugin constants (set in `wp-config.php`)

| Constant               | Default                                          | Purpose                                                    |
|------------------------|--------------------------------------------------|------------------------------------------------------------|
| `WP_REDIS_HOST`        | *(required — mu-plugin no-ops without it)*      | Redis host                                                 |
| `WP_REDIS_PASSWORD`    | *(required — mu-plugin no-ops without it)*      | Redis password                                             |
| `WP_REDIS_PORT`        | `6379`                                           | Redis port                                                 |
| `SOUIN_API_URL`        | `http://127.0.0.1/souin-api/souin/?path=.%2B`   | Souin management API endpoint for PURGE                    |
| `SOUIN_WARMER_QUEUE`   | `wp:cache-warm-queue`                            | Redis list name the warmer pops from                       |

## Troubleshooting

**Cache never gets populated** — Caddy isn't routing requests through `cache`. Make sure your `handle` block has the `cache` directive *before* `php_server` (see the Caddyfile reference). Visit a page anonymously and check for `Cache-Status: Souin; fwd=uri-miss; stored` in the response headers.

**Purge button does nothing** — phpredis isn't loaded, or Redis credentials are wrong. Check `php -m | grep redis` and the WordPress error log for `[Souin Cache] Redis connect failed`.

**mu-plugin's PURGE isn't firing** — check that `WP_REDIS_HOST` and `WP_REDIS_PASSWORD` are defined; the mu-plugin returns early if either is missing. Also verify the mu-plugin file is in `wp-content/mu-plugins/` (not nested in a subdirectory — WordPress only auto-loads top-level `.php` files there).

**Souin returns 403 to PURGE requests from the mu-plugin** — your Caddyfile is firewalling `/souin-api/*`, but the firewall is also blocking the mu-plugin. Make sure `not remote_ip 127.0.0.1 ::1` excludes the loopback that the mu-plugin uses (and that PHP is running on the same host as Caddy).

**Souin keys grow without bound** — the redis store has no eviction policy. Set `--cache_mode=true` on DragonflyDB or `maxmemory-policy allkeys-lru` on Redis.

**Logged-in users see other users' cached pages** — your `@nocache` matcher is missing or wrong. Verify the `Cookie` regex matches `wordpress_logged_in_*` and your active session cookies (e.g. `woocommerce_items_in_cart`).

## License

GPL-2.0+ — see plugin header.

## Author

[Code Agency](https://codeagency.be)
