<?php

namespace Akibeo\Cacher\Tests;

use Akibeo\Cacher\Cacher;
use Kirby\Cms\App;
use Kirby\Exception\PermissionException;
use Kirby\Filesystem\F;
use PHPUnit\Framework\Attributes\DataProvider;

class PluginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // App::destroy() in tearDown forgets registered plugins,
        // so register the plugin again for every test
        require dirname(__DIR__) . '/index.php';
    }

    public function testHelperReturnsACacherForTheCurrentKirby(): void
    {
        $kirby = $this->kirby();

        $this->assertInstanceOf(Cacher::class, cacher());
        $this->assertInstanceOf(Cacher::class, cacher($kirby));
    }

    public function testSiteMethodsAndRoutesAreRegistered(): void
    {
        $kirby = $this->kirby();
        F::write($this->tmp . '/cache/loose.cache', 'x');

        $this->assertSame(1, $kirby->site()->cacheStats()['file_count']);
        $this->assertTrue($kirby->site()->clearCache()['success']);
        $this->assertFalse($kirby->site()->clearCacheNamespace('nope')['success']);

        $patterns = array_column($kirby->extensions('api')['routes'] ?? [], 'pattern');

        $this->assertContains('plugin/cacher/clear-cache', $patterns);
        $this->assertContains('plugin/cacher/clear-namespace/(:any)', $patterns);
        $this->assertContains('plugin/cacher/stats', $patterns);
    }

    /**
     * Kirby with an admin and an editor, logged in as the given one
     */
    protected function kirbyAs(string|null $user, array $options = []): App
    {
        $kirby = $this->kirby($options)->clone([
            'roles' => [
                ['name' => 'admin'],
                ['name' => 'editor'],
            ],
            'users' => [
                ['email' => 'admin@cacher-test.invalid', 'role' => 'admin'],
                ['email' => 'editor@cacher-test.invalid', 'role' => 'editor'],
            ],
        ]);

        if ($user !== null) {
            $kirby->impersonate($user . '@cacher-test.invalid');
        }

        return $kirby;
    }

    /**
     * The API route actions, keyed by pattern
     */
    protected function routes(App $kirby): array
    {
        $routes = $kirby->extensions('api')['routes'] ?? [];

        return array_column($routes, 'action', 'pattern');
    }

    public function testRoutesWorkForAdmins(): void
    {
        $kirby  = $this->kirbyAs('admin');
        $routes = $this->routes($kirby);

        $this->assertSame('success', $routes['plugin/cacher/stats']()['status']);
        $this->assertTrue($routes['plugin/cacher/clear-cache']()['success']);
        $this->assertFalse($routes['plugin/cacher/clear-namespace/(:any)']('nope')['success']);
    }

    public static function nonAdmins(): array
    {
        return [
            'editor'     => ['editor'],
            'logged out' => [null],
        ];
    }

    #[DataProvider('nonAdmins')]
    public function testRoutesRefuseNonAdmins(string|null $user): void
    {
        $kirby = $this->kirbyAs($user);
        F::write($this->tmp . '/cache/loose.cache', 'x');

        foreach ($this->routes($kirby) as $pattern => $action) {
            try {
                $action('akibeo.pricing');
                $this->fail("Route {$pattern} did not refuse a non-admin");
            } catch (PermissionException) {
                // expected
            }
        }

        $this->assertFileExists($this->tmp . '/cache/loose.cache');
    }

    #[DataProvider('nonAdmins')]
    public function testPanelAreaIsHiddenAndRefusedForNonAdmins(string|null $user): void
    {
        $kirby = $this->kirbyAs($user);
        $area  = $kirby->extensions('areas')['cacher'][0]($kirby);

        $this->assertFalse($area['menu']);

        $this->expectException(PermissionException::class);
        $area['views'][0]['action']();
    }

    public function testPanelAreaIsRegistered(): void
    {
        $kirby = $this->kirbyAs('admin', ['akibeo.cacher' => ['namespaces' => ['akibeo.pricing']]]);
        // Kirby keeps a list of definitions per area name
        $area  = $kirby->extensions('areas')['cacher'][0]($kirby);

        $this->assertSame('Cache Manager', $area['label']);
        $this->assertTrue($area['menu']);

        $view = $area['views'][0]['action']();

        $this->assertSame('k-cacher-view', $view['component']);
        $this->assertSame($this->tmp . '/cache', $view['props']['cachePath']);
        $this->assertFalse($view['props']['redisEnabled']);
        $this->assertSame(['akibeo.pricing'], $view['props']['namespaces']);
    }
}
