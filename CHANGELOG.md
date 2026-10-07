# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-10-07

First release as a Composer package. Replaces the `kirby-akibeo-cacher` folder
that was copied into each site.

### Added

- Panel view "Cache Manager" with file cache and Redis pages cache stats and a
  Clear Cache button.
- `akibeo.cacher.namespaces` option: named cache namespaces get their own stats
  and Clear button, and `site()->clearCacheNamespace($name)`.
- `cacher()` helper and the `Akibeo\Cacher\Cacher` class, so deploy scripts can
  call `cacher()->clear()` without the Panel.

### Changed

- Redis is detected from `cache.pages`; the `akibeo.cacher.useRedis` option is
  gone and is ignored when still present.
- Redis is cleared by key prefix only, never with `FLUSHDB`: through Kirby's own
  prefix-scoped `flush()` on Kirby 5.5 and later, and with `SCAN` + `DEL` on
  older versions. Clearing is refused when the cache has no prefix.
- Stats `SCAN` only this site's keys instead of `KEYS *` on the whole database.
- Clearing the file cache keeps `index.html`, `.gitignore`, `.gitkeep` and
  `.htaccess` placeholders.
