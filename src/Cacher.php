<?php

namespace Akibeo\Cacher;

use Kirby\Cache\Cache;
use Kirby\Cache\FileCache;
use Kirby\Cache\RedisCache;
use Closure;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Kirby\Http\Remote;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Redis;
use Throwable;

/**
 * Clears and inspects Kirby's caches: the file cache root, the Redis
 * pages cache and any named cache namespaces declared in the options.
 *
 * Redis is only ever cleared by prefix. A Redis database is often shared
 * with other apps, so the plugin never runs FLUSHDB and refuses to clear
 * when it cannot scope the operation to this site's keys.
 */
class Cacher
{
    /**
     * Kirby version from which RedisCache::flush() is scoped to the prefix.
     * Older versions run FLUSHDB on the whole database.
     */
    public const SCOPED_FLUSH_SINCE = '5.5.0';

    /**
     * Files that are kept when the cache root is cleared
     */
    public const KEEP = ['index.html', '.gitignore', '.gitkeep', '.htaccess'];

    public function __construct(protected App $kirby)
    {
    }

    /**
     * The `cache.pages` config as an array ([] for true/false/unset)
     */
    public function pagesConfig(): array
    {
        $config = $this->kirby->option('cache.pages');

        return is_array($config) ? $config : [];
    }

    /**
     * Whether the pages cache is stored in Redis
     */
    public function redisEnabled(): bool
    {
        return ($this->pagesConfig()['type'] ?? null) === 'redis';
    }

    /**
     * The key prefix of a cache, normalized to end with a slash;
     * empty when the cache has no prefix
     */
    public function prefix(Cache $cache): string
    {
        $prefix = (string)($cache->options()['prefix'] ?? '');

        return $prefix === '' ? '' : rtrim($prefix, '/') . '/';
    }

    /**
     * Whether this Kirby version flushes a Redis cache by prefix
     */
    public static function hasScopedFlush(): bool
    {
        return version_compare((string)App::version(), static::SCOPED_FLUSH_SINCE, '>=');
    }

    /**
     * Whether a string is an acceptable cache namespace name
     */
    public static function isValidNamespace(string $name): bool
    {
        return $name !== '' && preg_match('/^[a-zA-Z0-9._-]+$/', $name) === 1;
    }

    /**
     * Cache namespaces declared in `akibeo.cacher.namespaces`
     */
    public function namespaces(): array
    {
        $names = (array)$this->kirby->option('akibeo.cacher.namespaces', []);

        return array_values(array_filter(
            array_map('strval', $names),
            [static::class, 'isValidNamespace']
        ));
    }

    /**
     * Clears the file cache root and, when the pages cache is stored
     * in Redis, this site's Redis pages cache
     *
     * @return array{success: bool, cleared: string[], errors: string[]}
     */
    public function clear(): array
    {
        $cleared = [];
        $errors  = [];

        $this->clearFiles($cleared, $errors);

        if ($this->redisEnabled() === true) {
            $this->clearRedis($cleared, $errors);
        }

        return [
            'success' => $errors === [],
            'cleared' => $cleared,
            'errors'  => $errors,
        ];
    }

    /**
     * Clears one declared cache namespace through Kirby's cache API
     *
     * @return array{success: bool, cleared: string[], errors: string[]}
     */
    public function clearNamespace(string $name): array
    {
        if (in_array($name, $this->namespaces(), true) === false) {
            return $this->result([], ["Unknown cache namespace: {$name}"]);
        }

        try {
            $cache = $this->kirby->cache($name);

            if ($cache instanceof RedisCache) {
                if ($this->prefix($cache) === '') {
                    return $this->result([], ["Refusing to clear '{$name}': the Redis cache has no key prefix, flushing would wipe the whole database"]);
                }

                if (static::hasScopedFlush() === false) {
                    return $this->result([], ["Refusing to clear '{$name}': Kirby " . App::version() . " flushes the whole Redis database; update to Kirby " . static::SCOPED_FLUSH_SINCE . " or later"]);
                }
            }

            if ($cache->flush() === false) {
                return $this->result([], ["Failed to clear cache namespace '{$name}'"]);
            }

            return $this->result(["Cache namespace '{$name}' cleared"], []);
        } catch (Throwable $e) {
            return $this->result([], ["Error clearing '{$name}': " . $e->getMessage()]);
        }
    }

    /**
     * File, Redis and namespace statistics for the Panel view
     */
    public function stats(): array
    {
        $root  = $this->kirby->root('cache');
        $files = static::dirStats($root);

        $stats = [
            'status'             => 'success',
            'cache_path'         => $root,
            'file_count'         => $files['count'],
            'cache_size'         => F::niceSize($files['size']),
            'namespaces'         => array_map([$this, 'namespaceStats'], $this->namespaces()),
            'redis_enabled'      => $this->redisEnabled(),
            'redis_key_count'    => 0,
            'redis_memory_usage' => F::niceSize(0),
        ];

        if ($stats['redis_enabled'] === true) {
            try {
                $cache = $this->kirby->cache('pages');
                $redis = $this->redisStats($cache->options(), $this->prefix($cache));

                $stats['redis_key_count']    = $redis['count'];
                $stats['redis_memory_usage'] = F::niceSize($redis['size']);
            } catch (Throwable) {
                // stats are best-effort; the view shows zeros
            }
        }

        return $stats;
    }

    /**
     * Statistics for one declared namespace
     */
    public function namespaceStats(string $name): array
    {
        $stats = [
            'name'       => $name,
            'type'       => 'unknown',
            'root'       => null,
            'file_count' => 0,
            'cache_size' => F::niceSize(0),
        ];

        try {
            $cache = $this->kirby->cache($name);

            if ($cache instanceof FileCache) {
                $files = static::dirStats($cache->root());

                return [
                    'type'       => 'file',
                    'root'       => $cache->root(),
                    'file_count' => $files['count'],
                    'cache_size' => F::niceSize($files['size']),
                ] + $stats;
            }

            if ($cache instanceof RedisCache) {
                $redis = $this->redisStats($cache->options(), $this->prefix($cache));

                return [
                    'type'       => 'redis',
                    'file_count' => $redis['count'],
                    'cache_size' => F::niceSize($redis['size']),
                ] + $stats;
            }

            $type = strtolower(preg_replace('/Cache$/', '', basename(str_replace('\\', '/', $cache::class))));

            return ['type' => $type] + $stats;
        } catch (Throwable) {
            return $stats;
        }
    }

    /**
     * Counts the files under a directory and sums their size
     *
     * @return array{count: int, size: int}
     */
    public static function dirStats(string|null $path): array
    {
        $count = 0;
        $size  = 0;

        if ($path === null || Dir::exists($path) === false) {
            return ['count' => 0, 'size' => 0];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isFile() === true && in_array($item->getFilename(), static::KEEP, true) === false) {
                $count++;
                $size += $item->getSize();
            }
        }

        return ['count' => $count, 'size' => $size];
    }

    /**
     * Removes everything in the cache root except the files in KEEP
     */
    protected function clearFiles(array &$cleared, array &$errors): void
    {
        $root = $this->kirby->root('cache');

        if (Dir::exists($root) === false) {
            $errors[] = "Cache directory does not exist: {$root}";
            return;
        }

        try {
            $removed = 0;

            foreach (Dir::read($root) as $item) {
                if (in_array($item, static::KEEP, true) === true) {
                    continue;
                }

                $path = $root . '/' . $item;

                if (is_dir($path) === true) {
                    Dir::remove($path);
                } else {
                    F::remove($path);
                }

                $removed++;
            }

            $cleared[] = "File cache cleared ({$removed} items removed)";
        } catch (Throwable $e) {
            $errors[] = 'Error clearing file cache: ' . $e->getMessage();
        }
    }

    /**
     * Deletes this site's keys from the Redis pages cache
     */
    protected function clearRedis(array &$cleared, array &$errors): void
    {
        try {
            $cache  = $this->kirby->cache('pages');
            $prefix = $this->prefix($cache);

            if ($prefix === '') {
                $errors[] = 'Refusing to clear Redis: the pages cache has no key prefix, flushing would wipe the whole database';
                return;
            }

            // Kirby's own flush() is prefix-scoped since 5.5; before that it
            // runs FLUSHDB, so on older versions delete the keys ourselves.
            if (static::hasScopedFlush() === true) {
                if ($cache->flush() === false) {
                    $errors[] = "Failed to clear Redis pages cache (prefix {$prefix})";
                    return;
                }

                $cleared[] = "Redis pages cache cleared (prefix {$prefix})";
                return;
            }

            $deleted = $this->deleteByPrefix($cache->options(), $prefix);
            $cleared[] = "Redis pages cache cleared ({$deleted} keys, prefix {$prefix})";
        } catch (Throwable $e) {
            $errors[] = 'Error clearing Redis cache: ' . $e->getMessage();
        }
    }

    /**
     * Counts the keys under a prefix and sums their memory usage
     *
     * @return array{count: int, size: int}
     */
    protected function redisStats(array $options, string $prefix): array
    {
        $count = 0;
        $size  = 0;

        if ($prefix === '') {
            // without a prefix the keys cannot be told apart from other apps'
            return ['count' => 0, 'size' => 0];
        }

        $redis = $this->connect($options);

        foreach ($this->scan($redis, $prefix) as $keys) {
            foreach ($keys as $key) {
                $count++;
                $memory = $redis->rawCommand('MEMORY', 'USAGE', $key);

                if (is_int($memory) === true) {
                    $size += $memory;
                }
            }
        }

        return ['count' => $count, 'size' => $size];
    }

    /**
     * Deletes all keys under a prefix and returns how many were removed
     */
    protected function deleteByPrefix(array $options, string $prefix): int
    {
        $redis   = $this->connect($options);
        $deleted = 0;

        foreach ($this->scan($redis, $prefix) as $keys) {
            $deleted += (int)$redis->del($keys);
        }

        return $deleted;
    }

    /**
     * Iterates over this site's keys in batches, without blocking Redis
     * the way KEYS * would. Glob characters in the prefix are escaped so
     * it cannot match keys of other prefixes.
     *
     * @return iterable<string[]>
     */
    protected function scan(Redis $redis, string $prefix): iterable
    {
        $pattern = addcslashes($prefix, '\\*?[]') . '*';
        $redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
        $iterator = null;

        while ($keys = $redis->scan($iterator, $pattern, 500)) {
            yield $keys;
        }
    }

    /**
     * Opens a connection from a cache's options, with the same keys
     * Kirby's Redis cache driver uses, so auth, SSL and timeouts
     * behave the same way
     */
    protected function connect(array $options): Redis
    {
        if (class_exists(Redis::class) === false) {
            throw new CacherException('The Redis PHP extension is not installed');
        }

        $allowed = ['host', 'port', 'readTimeout', 'connectTimeout', 'persistent', 'auth', 'ssl', 'retryInterval', 'backoff'];
        $redis   = new Redis(
            array_intersect_key($options, array_flip($allowed)) + ['host' => '127.0.0.1', 'port' => 6379]
        );

        if (isset($options['database']) === true) {
            $redis->select((int)$options['database']);
        }

        return $redis;
    }

    /**
     * Whether the pages cache is switched on (`cache.pages` is `true`
     * or has `active => true`)
     */
    public function pagesCacheActive(): bool
    {
        return ($this->kirby->cache('pages')->options()['active'] ?? false) === true;
    }

    /**
     * The URLs the warmup requests: every published page in every
     * language, minus the pages `cache.pages.ignore` and
     * `akibeo.cacher.warmup.exclude` leave out, plus the URLs of the
     * `akibeo.cacher.warmup.urls` option. Home comes first.
     *
     * Page::isCacheable() is not used on purpose: it inspects the
     * current request and the warmup API route is a POST, so it would
     * refuse every page. Only its `ignore` rules are applied here.
     *
     * @return string[]
     */
    public function warmupUrls(): array
    {
        $site      = $this->kirby->site();
        $languages = $this->kirby->multilang() ? $this->kirby->languages()->codes() : [null];
        $pages     = $site->index()->filter(fn ($page) => $page->isDraft() === false && $this->isWarmable($page));
        $urls      = [];

        if ($home = $site->homePage()) {
            $pages = $pages->prepend($home->id(), $home);
        }

        foreach ($pages as $page) {
            foreach ($languages as $code) {
                $urls[] = $code === null ? $page->url() : $page->url($code);
            }
        }

        $extra = $this->kirby->option('akibeo.cacher.warmup.urls');

        if ($extra instanceof Closure) {
            $extra = $extra($this->kirby);
        }

        foreach ((array)$extra as $url) {
            if (is_string($url) === true && $url !== '') {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Requests pages anonymously so Kirby stores them in the pages
     * cache. `null` warms every URL from warmupUrls(); a list warms
     * only those of its URLs that are in warmupUrls(), so the API
     * cannot be used to make the server request other hosts.
     *
     * @return array{success: bool, cleared: string[], errors: string[], warmed: int}
     */
    public function warmup(array|null $urls = null): array
    {
        if ($this->pagesCacheActive() === false) {
            return $this->result([], ['The pages cache is not active (cache.pages)']) + ['warmed' => 0];
        }

        $allowed = $this->warmupUrls();
        $urls    = $urls === null ? $allowed : array_values(array_unique(array_map('strval', $urls)));
        $delay   = max(0, (int)$this->kirby->option('akibeo.cacher.warmup.delay', 200));
        $warmed  = 0;
        $errors  = [];

        foreach ($urls as $index => $url) {
            if (in_array($url, $allowed, true) === false) {
                $errors[] = "{$url}: not in the warmup list";
                continue;
            }

            if ($index > 0 && $delay > 0) {
                usleep($delay * 1000);
            }

            try {
                $code = $this->fetch($url);

                if ($code >= 200 && $code < 400) {
                    $warmed++;
                } else {
                    $errors[] = "{$url}: HTTP {$code}";
                }
            } catch (Throwable $e) {
                $errors[] = "{$url}: " . $e->getMessage();
            }
        }

        $cleared = $warmed === 1 ? ['1 page warmed'] : ["{$warmed} pages warmed"];

        return $this->result($cleared, $errors) + ['warmed' => $warmed];
    }

    /**
     * Whether a page passes the `cache.pages.ignore` rule and the
     * `akibeo.cacher.warmup.exclude` list (page ids or fnmatch globs)
     */
    protected function isWarmable(Page $page): bool
    {
        $ignore = $this->kirby->cache('pages')->options()['ignore'] ?? null;

        if ($ignore instanceof Closure && $ignore($page) === true) {
            return false;
        }

        if (is_array($ignore) === true && in_array($page->id(), $ignore, true) === true) {
            return false;
        }

        foreach ((array)$this->kirby->option('akibeo.cacher.warmup.exclude', []) as $pattern) {
            if (fnmatch((string)$pattern, $page->id()) === true) {
                return false;
            }
        }

        return true;
    }

    /**
     * Requests a URL without cookies, the way an anonymous visitor
     * would, and returns the HTTP status code
     *
     * @throws \Exception when the request itself fails (DNS, timeout…)
     */
    protected function fetch(string $url): int
    {
        $response = Remote::get($url, [
            'timeout' => (int)$this->kirby->option('akibeo.cacher.warmup.timeout', 30),
            'agent'   => 'Kirby-Cacher/1.0 (cache-warmup)',
        ]);

        return (int)$response->code();
    }

    /**
     * @return array{success: bool, cleared: string[], errors: string[]}
     */
    protected function result(array $cleared, array $errors): array
    {
        return [
            'success' => $errors === [],
            'cleared' => $cleared,
            'errors'  => $errors,
        ];
    }
}
