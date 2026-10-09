# Kirby Cacher

[![Tests](https://github.com/wdebusschere/kirby-cacher/actions/workflows/php.yml/badge.svg)](https://github.com/wdebusschere/kirby-cacher/actions/workflows/php.yml) ![Kirby 5](https://img.shields.io/badge/Kirby-5-green.svg) ![License MIT](https://img.shields.io/badge/license-MIT-blue.svg)

A Cache Manager for the [Kirby](https://getkirby.com) Panel: stats and a Clear Cache button for the file cache, the Redis pages cache and any named cache namespaces.

- **Safe with shared Redis** — only this site's keys are deleted, never the whole database. Clearing is refused when the pages cache has no key prefix.
- **No extra config** — Redis is detected from Kirby's own `cache.pages` option.
- **Cache namespaces** — declare the caches your plugins use and clear them one by one from the Panel.
- **Warm up** — after clearing, pre-fill the pages cache (file or Redis) by requesting every published page in every language, with a progress bar in the Panel.
- **Scriptable** — `cacher()->clear()` and `cacher()->warmup()` do the same as the Panel buttons, for deploy hooks.

## Installation

### Composer

```bash
composer require akibeo/kirby-cacher
```

### Download / Git submodule

Copy this repository into `site/plugins/kirby-cacher/`:

```bash
git submodule add https://github.com/wdebusschere/kirby-cacher.git site/plugins/kirby-cacher
```

No build step is required. The plugin registers itself as `akibeo/cacher` and reads its options from the `akibeo.cacher` namespace.

### Upgrading from the in-tree `kirby-akibeo-cacher` folder

1. `composer require akibeo/kirby-cacher`
2. Delete `site/plugins/kirby-akibeo-cacher/` and remove its `!/site/plugins/kirby-akibeo-cacher` line from `.gitignore`.
3. Remove `'akibeo.cacher' => ['useRedis' => …]` from your config; the option is ignored.

The plugin id, API routes, site methods and the Panel menu entry are unchanged.

## Requirements

- Kirby 5. Kirby 5.5 or later is recommended: from that version Kirby's own Redis `flush()` is scoped to the site's key prefix. On older versions Kirby itself still runs `FLUSHDB` whenever it clears the pages cache (for example after a content change in the Panel); this plugin's button is prefix-scoped on every version.
- For the Redis stats and clearing: the [phpredis](https://github.com/phpredis/phpredis) extension, version 6 or later, which Kirby's Redis cache driver needs anyway.

## Configuration

Nothing is required. To clear the pages cache from Redis, configure Kirby's pages cache as usual:

```php
return [
    'cache' => [
        'pages' => [
            'active'   => true,
            'type'     => 'redis',
            'host'     => '127.0.0.1',
            'port'     => 6379,
            'database' => 1,
            'auth'     => 'secret',
        ],
    ],
];
```

Kirby prefixes every key with the site's index URL and the cache name, e.g. `www.example.com/pages/`. The plugin only ever touches keys under that prefix, so one Redis database can be shared between several sites and apps.

### Cache namespaces

Plugins and site code often keep their own caches via `kirby()->cache('my.namespace')`. List them to give each one stats and a Clear button in the Panel:

```php
return [
    'akibeo.cacher' => [
        'namespaces' => ['akibeo.pricing', 'my.api'],
    ],
];
```

Each namespace is cleared through `kirby()->cache($name)->flush()`. A Redis-backed namespace is only cleared when it has a key prefix and Kirby is 5.5 or later.

### Warm up

Warming makes real HTTP requests to the site's own URLs, like an anonymous visitor would: Kirby only stores a page in the pages cache when the request carries no session or cookies, so the authenticated Panel request cannot render the pages itself. The server therefore has to be able to reach its own public URL. On a staging site behind HTTP basic auth every request gets a 401 and nothing is cached.

All options are optional:

```php
return [
    'akibeo.cacher' => [
        'warmup' => [
            // pages that only exist as routes or virtual pages are not in
            // site()->index(); return their URLs here (closure or plain array)
            'urls' => function (Kirby\Cms\App $kirby): array {
                $urls = [];
                foreach (['nl' => 'te-koop', 'fr' => 'fr/a-vendre'] as $segment) {
                    $urls[] = url($segment);
                }
                return $urls;
            },
            // page ids or fnmatch globs to leave out
            'exclude' => ['search', 'account/*'],
            // per-request timeout in seconds
            'timeout' => 30,
            // pause between two requests in milliseconds, so the site and
            // any upstream APIs are not hammered
            'delay'   => 200,
            // URLs per request when warming from the Panel
            'batch'   => 10,
        ],
    ],
];
```

Pages excluded by Kirby's own `cache.pages.ignore` option (closure or array of ids) are skipped as well. Drafts are never warmed; listed and unlisted pages are.

## Usage

### Panel

Open **Cache Manager** in the Panel menu (admins only; other roles don't see it). It shows the number and size of cached files, the number and memory usage of this site's Redis keys, and one card per declared namespace.

**Clear Cache** removes everything in Kirby's cache root (except `index.html`, `.gitignore`, `.gitkeep` and `.htaccess`) and, when `cache.pages` uses Redis, this site's Redis pages cache.

**Warm Up Cache** requests every published page in every language so the pages cache is filled before the first visitor arrives. The Panel sends the URLs to the server in batches and shows a progress bar, so large sites do not run into `max_execution_time`. The button is disabled when the pages cache is off. Failed requests are listed in the result; the others are still warmed.

### PHP

```php
cacher()->clear();                        // same as the Panel button
cacher()->clearNamespace('akibeo.pricing');
cacher()->warmup();                       // every URL from cacher()->warmupUrls()
cacher()->warmup([$url1, $url2]);         // a subset of those URLs
cacher()->warmupUrls();                   // the list the warmup uses
cacher()->stats();

// also available as site methods
site()->clearCache();
site()->clearCacheNamespace('akibeo.pricing');
site()->warmupCache();
site()->cacheStats();
```

Each clear call returns `['success' => bool, 'cleared' => string[], 'errors' => string[]]`; `warmup()` adds `'warmed' => int`. A deploy script typically runs `cacher()->clear()` followed by `cacher()->warmup()`.

### API

All routes require a logged-in admin; other roles get a permission error.

| Method | Route | |
| --- | --- | --- |
| `POST` | `/api/plugin/cacher/clear-cache` | file cache + Redis pages cache |
| `POST` | `/api/plugin/cacher/clear-namespace/{name}` | one declared namespace |
| `GET` | `/api/plugin/cacher/stats` | stats as shown in the Panel |
| `GET` | `/api/plugin/cacher/warmup-urls` | `{ urls: string[], batch: int }` |
| `POST` | `/api/plugin/cacher/warmup` | JSON body `{ urls: string[] }`; only URLs from `warmup-urls` are requested, anything else is reported as an error and never fetched |

## Why no FLUSHDB?

A Redis database is frequently shared: several sites on one hosting account, or a site next to an intranet or a queue. `FLUSHDB` deletes all of it. Kirby stores every pages-cache key under a prefix, so deleting by prefix is all a cache button needs. When there is no prefix the plugin refuses to clear rather than guess.

## Development

```bash
composer install
REDIS_HOST=127.0.0.1 REDIS_DB=15 composer test
```

Without `REDIS_HOST` the Redis tests are skipped. The Redis test database must be empty; the tests refuse to run against one that is not.

## License

[MIT](LICENSE)
