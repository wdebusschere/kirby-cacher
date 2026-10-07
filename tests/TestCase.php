<?php

namespace Akibeo\Cacher\Tests;

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Redis;

abstract class TestCase extends BaseTestCase
{
    protected string $tmp;

    protected function setUp(): void
    {
        // Keep Kirby from installing its Whoops error handlers per App,
        // which PHPUnit would otherwise flag on every test.
        App::$enableWhoops = false;

        $this->tmp = sys_get_temp_dir() . '/kirby-cacher-' . uniqid();
        Dir::make($this->tmp . '/cache');
    }

    protected function tearDown(): void
    {
        App::destroy();
        Dir::remove($this->tmp);
    }

    protected function kirby(array $options = []): App
    {
        return new App([
            'roots' => [
                'index' => $this->tmp,
                'cache' => $this->tmp . '/cache',
            ],
            'options' => ['url' => 'https://cacher-test.invalid'] + $options,
        ]);
    }

    /**
     * Redis connection options for the tests, or null when no test
     * server is configured (REDIS_HOST, optional REDIS_PORT and REDIS_DB)
     */
    protected function redisOptions(): array|null
    {
        $host = getenv('REDIS_HOST');

        if ($host === false || $host === '' || extension_loaded('redis') === false) {
            return null;
        }

        return [
            'host'     => $host,
            'port'     => (int)(getenv('REDIS_PORT') ?: 6379),
            'database' => (int)(getenv('REDIS_DB') ?: 15),
        ];
    }

    /**
     * A raw connection to the test database; skips the test when
     * there is no test server
     */
    protected function redis(): Redis
    {
        $options = $this->redisOptions();

        if ($options === null) {
            $this->markTestSkipped('Set REDIS_HOST to run the Redis tests');
        }

        $redis = new Redis();

        if (@$redis->connect($options['host'], $options['port'], 2) === false) {
            $this->markTestSkipped('Redis test server not reachable');
        }

        $redis->select($options['database']);

        if ($redis->dbSize() !== 0) {
            $this->fail('The Redis test database is not empty; refusing to run against it');
        }

        return $redis;
    }

    /**
     * Kirby with a Redis pages cache on the test server
     */
    protected function kirbyWithRedis(array $pages = [], array $options = []): App
    {
        return $this->kirby([
            'cache' => [
                'pages' => $pages + ['active' => true, 'type' => 'redis'] + $this->redisOptions(),
            ],
        ] + $options);
    }
}
