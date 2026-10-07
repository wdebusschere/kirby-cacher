<?php

namespace Akibeo\Cacher\Tests;

use Akibeo\Cacher\Cacher;
use Kirby\Filesystem\F;

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

    public function testPanelAreaIsRegistered(): void
    {
        $kirby = $this->kirby(['akibeo.cacher' => ['namespaces' => ['akibeo.pricing']]]);
        // Kirby keeps a list of definitions per area name
        $area  = $kirby->extensions('areas')['cacher'][0]($kirby);

        $this->assertSame('Cache Manager', $area['label']);

        $view = $area['views'][0]['action']();

        $this->assertSame('k-cacher-view', $view['component']);
        $this->assertSame($this->tmp . '/cache', $view['props']['cachePath']);
        $this->assertFalse($view['props']['redisEnabled']);
        $this->assertSame(['akibeo.pricing'], $view['props']['namespaces']);
    }
}
