# Kirby Cacher

[![Tests](https://github.com/wdebusschere/kirby-cacher/actions/workflows/php.yml/badge.svg)](https://github.com/wdebusschere/kirby-cacher/actions/workflows/php.yml) ![Kirby 5](https://img.shields.io/badge/Kirby-5-green.svg) ![License MIT](https://img.shields.io/badge/license-MIT-blue.svg)

A Cache Manager for the [Kirby](https://getkirby.com) Panel: stats and a Clear Cache button for the file cache, the Redis pages cache and any named cache namespaces.

- **Safe with shared Redis** — only this site's keys are deleted, never the whole database. Clearing is refused when the pages cache has no key prefix.
- **No extra config** — Redis is detected from Kirby's own `cache.pages` option.
- **Cache namespaces** — declare the caches your plugins use and clear them one by one from the Panel.
- **Scriptable** — `cacher()->clear()` does the same as the Panel button, for deploy hooks.

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

## Usage

### Panel

Open **Cache Manager** in the Panel menu (admins only; other roles don't see it). It shows the number and size of cached files, the number and memory usage of this site's Redis keys, and one card per declared namespace.

**Clear Cache** removes everything in Kirby's cache root (except `index.html`, `.gitignore`, `.gitkeep` and `.htaccess`) and, when `cache.pages` uses Redis, this site's Redis pages cache.

### PHP

```php
cacher()->clear();                        // same as the Panel button
cacher()->clearNamespace('akibeo.pricing');
cacher()->stats();

// also available as site methods
site()->clearCache();
site()->clearCacheNamespace('akibeo.pricing');
site()->cacheStats();
```

Each clear call returns `['success' => bool, 'cleared' => string[], 'errors' => string[]]`.

### API

All routes require a logged-in admin; other roles get a permission error.

| Method | Route | |
| --- | --- | --- |
| `POST` | `/api/plugin/cacher/clear-cache` | file cache + Redis pages cache |
| `POST` | `/api/plugin/cacher/clear-namespace/{name}` | one declared namespace |
| `GET` | `/api/plugin/cacher/stats` | stats as shown in the Panel |

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
