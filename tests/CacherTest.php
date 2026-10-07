<?php

namespace Akibeo\Cacher\Tests;

use Akibeo\Cacher\Cacher;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;

class CacherTest extends TestCase
{
    public function testRedisIsDetectedFromThePagesCacheConfig(): void
    {
        $this->assertFalse((new Cacher($this->kirby()))->redisEnabled());
        $this->assertFalse((new Cacher($this->kirby(['cache' => ['pages' => true]])))->redisEnabled());
        $this->assertFalse((new Cacher($this->kirby(['cache' => ['pages' => ['type' => 'file']]])))->redisEnabled());
        $this->assertTrue((new Cacher($this->kirby(['cache' => ['pages' => ['type' => 'redis']]])))->redisEnabled());
    }

    public function testNamespaceNames(): void
    {
        $this->assertTrue(Cacher::isValidNamespace('akibeo.pricing'));
        $this->assertTrue(Cacher::isValidNamespace('my-cache_1'));
        $this->assertFalse(Cacher::isValidNamespace(''));
        $this->assertFalse(Cacher::isValidNamespace('../pages'));
        $this->assertFalse(Cacher::isValidNamespace('a b'));
    }

    public function testNamespacesComeFromTheOptions(): void
    {
        $this->assertSame([], (new Cacher($this->kirby()))->namespaces());

        $cacher = new Cacher($this->kirby([
            'akibeo.cacher' => ['namespaces' => ['akibeo.pricing', '../evil', 'other']],
        ]));

        $this->assertSame(['akibeo.pricing', 'other'], $cacher->namespaces());
    }

    public function testClearRemovesTheFileCacheButKeepsPlaceholders(): void
    {
        $cacher = new Cacher($this->kirby());

        F::write($this->tmp . '/cache/index.html', '');
        F::write($this->tmp . '/cache/.gitignore', '*');
        F::write($this->tmp . '/cache/loose.cache', 'x');
        F::write($this->tmp . '/cache/site/pages/home.cache', str_repeat('a', 100));
        F::write($this->tmp . '/cache/site/pages/about.cache', str_repeat('b', 100));

        $stats = $cacher->stats();
        $this->assertSame(3, $stats['file_count']);
        $this->assertFalse($stats['redis_enabled']);
        $this->assertSame([], $stats['namespaces']);

        $result = $cacher->clear();

        $this->assertTrue($result['success']);
        $this->assertSame(['File cache cleared (2 items removed)'], $result['cleared']);
        $this->assertSame([], $result['errors']);

        $this->assertFileExists($this->tmp . '/cache/index.html');
        $this->assertFileExists($this->tmp . '/cache/.gitignore');
        $this->assertFileDoesNotExist($this->tmp . '/cache/loose.cache');
        $this->assertDirectoryDoesNotExist($this->tmp . '/cache/site');
        $this->assertSame(0, $cacher->stats()['file_count']);
    }

    public function testClearReportsAMissingCacheDirectory(): void
    {
        $cacher = new Cacher($this->kirby());
        Dir::remove($this->tmp . '/cache');

        $result = $cacher->clear();

        $this->assertFalse($result['success']);
        $this->assertStringStartsWith('Cache directory does not exist', $result['errors'][0]);
    }

    public function testNamespaceStatsForAnUnconfiguredCache(): void
    {
        $cacher = new Cacher($this->kirby(['akibeo.cacher' => ['namespaces' => ['akibeo.pricing']]]));

        // Kirby hands out a NullCache for caches nobody configured
        $this->assertSame('null', $cacher->stats()['namespaces'][0]['type']);
    }

    public function testClearNamespaceOnlyAcceptsDeclaredNamespaces(): void
    {
        $cacher = new Cacher($this->kirby(['akibeo.cacher' => ['namespaces' => ['akibeo.pricing']]]));

        $result = $cacher->clearNamespace('pages');

        $this->assertFalse($result['success']);
        $this->assertSame(['Unknown cache namespace: pages'], $result['errors']);
    }

    public function testClearNamespaceFlushesAFileCache(): void
    {
        $kirby  = $this->kirby([
            'cache'         => ['akibeo.pricing' => true],
            'akibeo.cacher' => ['namespaces' => ['akibeo.pricing']],
        ]);
        $cacher = new Cacher($kirby);
        $cache  = $kirby->cache('akibeo.pricing');

        $cache->set('bike-1', ['price' => 10]);
        $cache->set('bike-2', ['price' => 20]);

        $stats = $cacher->stats()['namespaces'][0];
        $this->assertSame('akibeo.pricing', $stats['name']);
        $this->assertSame('file', $stats['type']);
        $this->assertSame(2, $stats['file_count']);

        $result = $cacher->clearNamespace('akibeo.pricing');

        $this->assertTrue($result['success']);
        $this->assertSame(["Cache namespace 'akibeo.pricing' cleared"], $result['cleared']);
        $this->assertNull($cache->get('bike-1'));
        $this->assertSame(0, $cacher->stats()['namespaces'][0]['file_count']);
    }

    public function testRedisClearOnlyRemovesThisSitesKeys(): void
    {
        $redis  = $this->redis();
        $kirby  = $this->kirbyWithRedis();
        $cacher = new Cacher($kirby);
        $cache  = $kirby->cache('pages');

        try {
            // keys owned by another app and by another site in the same database
            $redis->set('intranet:session:1', 'keep me');
            $redis->set('other.site/pages/home', 'keep me too');

            foreach (['home', 'en/about', 'nl/contact'] as $id) {
                $cache->set($id, ['html' => str_repeat('a', 500)], 10);
            }

            $this->assertSame(5, $redis->dbSize());
            $this->assertSame('cacher-test.invalid/pages/', $cacher->prefix($cache));

            $stats = $cacher->stats();
            $this->assertTrue($stats['redis_enabled']);
            $this->assertSame(3, $stats['redis_key_count']);
            $this->assertNotSame('0 B', $stats['redis_memory_usage']);

            $result = $cacher->clear();

            $this->assertTrue($result['success'], implode('; ', $result['errors']));
            $this->assertSame('Redis pages cache cleared (prefix cacher-test.invalid/pages/)', $result['cleared'][1]);
            $this->assertNull($cache->get('home'));
            $this->assertSame(2, $redis->dbSize());
            $this->assertSame('keep me', $redis->get('intranet:session:1'));
            $this->assertSame('keep me too', $redis->get('other.site/pages/home'));
            $this->assertSame(0, $cacher->stats()['redis_key_count']);
        } finally {
            $redis->del(['intranet:session:1', 'other.site/pages/home']);
            $cache->flush();
        }
    }

    public function testRedisClearDeletesByPrefixItselfOnKirbyWithoutScopedFlush(): void
    {
        $redis  = $this->redis();
        $kirby  = $this->kirbyWithRedis();
        $cacher = new LegacyKirbyCacher($kirby);
        $cache  = $kirby->cache('pages');

        try {
            $redis->set('intranet:session:1', 'keep me');
            $cache->set('home', 'x', 10);
            $cache->set('about', 'y', 10);

            $result = $cacher->clear();

            $this->assertTrue($result['success'], implode('; ', $result['errors']));
            $this->assertSame('Redis pages cache cleared (2 keys, prefix cacher-test.invalid/pages/)', $result['cleared'][1]);
            $this->assertNull($cache->get('home'));
            $this->assertSame('keep me', $redis->get('intranet:session:1'));
            $this->assertSame(1, $redis->dbSize());
        } finally {
            $redis->del('intranet:session:1');
            $cache->flush();
        }
    }

    public function testRedisClearIsRefusedWithoutAPrefix(): void
    {
        $redis  = $this->redis();
        $kirby  = $this->kirbyWithRedis(['prefix' => '']);
        $cacher = new Cacher($kirby);

        try {
            $redis->set('intranet:session:1', 'keep me');

            $result = $cacher->clear();

            $this->assertFalse($result['success']);
            $this->assertStringStartsWith('Refusing to clear Redis', $result['errors'][0]);
            $this->assertSame('keep me', $redis->get('intranet:session:1'));
            $this->assertSame(0, $cacher->stats()['redis_key_count']);
        } finally {
            $redis->del('intranet:session:1');
        }
    }

    public function testRedisNamespaceStatsAndClear(): void
    {
        $redis  = $this->redis();
        $kirby  = $this->kirby([
            'cache' => [
                'akibeo.pricing' => ['type' => 'redis'] + $this->redisOptions(),
            ],
            'akibeo.cacher' => ['namespaces' => ['akibeo.pricing']],
        ]);
        $cacher = new Cacher($kirby);
        $cache  = $kirby->cache('akibeo.pricing');

        try {
            $redis->set('intranet:session:1', 'keep me');
            $cache->set('bike-1', ['price' => 10]);

            $stats = $cacher->stats()['namespaces'][0];
            $this->assertSame('redis', $stats['type']);
            $this->assertSame(1, $stats['file_count']);

            $result = $cacher->clearNamespace('akibeo.pricing');

            $this->assertTrue($result['success'], implode('; ', $result['errors']));
            $this->assertNull($cache->get('bike-1'));
            $this->assertSame('keep me', $redis->get('intranet:session:1'));
        } finally {
            $redis->del('intranet:session:1');
            $cache->flush();
        }
    }
}

/**
 * Behaves like the plugin on a Kirby version whose RedisCache::flush()
 * still runs FLUSHDB
 */
class LegacyKirbyCacher extends Cacher
{
    public static function hasScopedFlush(): bool
    {
        return false;
    }
}
