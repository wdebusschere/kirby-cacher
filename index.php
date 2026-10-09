<?php

use Akibeo\Cacher\Cacher;
use Kirby\Cms\App as Kirby;
use Kirby\Exception\PermissionException;

// Composer autoload when installed as a package; plain requires when the
// folder is dropped straight into site/plugins/. Both are idempotent.
@include_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/helpers.php';
require_once __DIR__ . '/src/CacherException.php';
require_once __DIR__ . '/src/Cacher.php';

// Clearing caches and the server paths in the stats are admin-only,
// so editors and other Panel roles cannot reach the routes or the view
$isAdmin = fn (): bool => kirby()->user()?->isAdmin() === true;
$admin   = function () use ($isAdmin): void {
    if ($isAdmin() === false) {
        throw new PermissionException('Only admins can manage the cache');
    }
};

Kirby::plugin('akibeo/cacher', [
    'options' => [
        // Cache namespaces shown in the Panel with their own stats and
        // Clear button, e.g. ['akibeo.pricing']. Each one is cleared
        // through kirby()->cache($name)->flush().
        'namespaces' => [],
        // Warm Up Cache: pages are requested over HTTP like an anonymous
        // visitor would, so the server must be able to reach its own URL
        'warmup' => [
            // extra URLs to warm, e.g. pages that only exist as routes:
            // fn (Kirby $kirby): array => [...] or a plain array
            'urls'    => null,
            // page ids or fnmatch globs to leave out, e.g. ['search', 'blog/*']
            'exclude' => [],
            // per-request timeout in seconds
            'timeout' => 30,
            // pause between two requests in milliseconds
            'delay'   => 200,
            // URLs per request from the Panel
            'batch'   => 10,
        ],
    ],
    'api' => [
        'routes' => [
            [
                'pattern' => 'plugin/cacher/clear-cache',
                'method'  => 'POST',
                'action'  => function () use ($admin) {
                    $admin();
                    return cacher()->clear();
                },
            ],
            [
                'pattern' => 'plugin/cacher/clear-namespace/(:any)',
                'method'  => 'POST',
                'action'  => function (string $namespace) use ($admin) {
                    $admin();
                    return cacher()->clearNamespace($namespace);
                },
            ],
            [
                'pattern' => 'plugin/cacher/stats',
                'method'  => 'GET',
                'action'  => function () use ($admin) {
                    $admin();
                    return cacher()->stats();
                },
            ],
            [
                'pattern' => 'plugin/cacher/warmup-urls',
                'method'  => 'GET',
                'action'  => function () use ($admin) {
                    $admin();
                    return [
                        'urls'  => cacher()->warmupUrls(),
                        'batch' => max(1, (int)kirby()->option('akibeo.cacher.warmup.batch', 10)),
                    ];
                },
            ],
            [
                'pattern' => 'plugin/cacher/warmup',
                'method'  => 'POST',
                'action'  => function () use ($admin) {
                    $admin();
                    $urls = kirby()->request()->get('urls');
                    return cacher()->warmup(is_array($urls) ? $urls : null);
                },
            ],
        ],
    ],
    'siteMethods' => [
        'clearCache'          => fn () => cacher()->clear(),
        'clearCacheNamespace' => fn (string $namespace) => cacher()->clearNamespace($namespace),
        'cacheStats'          => fn () => cacher()->stats(),
        'warmupCache'         => fn (array|null $urls = null) => cacher()->warmup($urls),
    ],
    'areas' => [
        'cacher' => fn () => [
            'label' => 'Cache Manager',
            'icon'  => 'badge',
            'menu'  => $isAdmin(),
            'link'  => 'cacher',
            'views' => [
                [
                    'pattern' => 'cacher',
                    'action'  => function () use ($admin) {
                        $admin();

                        return [
                            'component' => 'k-cacher-view',
                            'title'     => 'Cache Manager',
                            'props'     => [
                                'cachePath'        => kirby()->root('cache'),
                                'redisEnabled'     => cacher()->redisEnabled(),
                                'pagesCacheActive' => cacher()->pagesCacheActive(),
                                'namespaces'       => cacher()->namespaces(),
                            ],
                        ];
                    },
                ],
            ],
        ],
    ],
]);
