<?php

namespace Akibeo\Cacher\Tests;

use Akibeo\Cacher\Cacher;
use Kirby\Cms\App;

class WarmupTest extends TestCase
{
    /**
     * Kirby with a small content tree: home, a listed page, an unlisted
     * page, a draft and a page the site excludes from the pages cache
     */
    protected function kirbyWithPages(array $options = [], array $props = []): App
    {
        return $this->kirby($options + [
            'cache' => [
                'pages' => [
                    'active' => true,
                    'ignore' => fn ($page) => $page->id() === 'ignored',
                ],
            ],
        ])->clone([
            'site' => [
                'children' => [
                    ['slug' => 'about', 'num' => 1],
                    ['slug' => 'hidden'],
                    ['slug' => 'home', 'num' => 2],
                    ['slug' => 'ignored', 'num' => 3],
                    ['slug' => 'secret', 'num' => 4],
                    ['slug' => 'draft', 'isDraft' => true],
                ],
            ],
        ] + $props);
    }

    public function testPagesCacheActive(): void
    {
        $this->assertFalse((new Cacher($this->kirby()))->pagesCacheActive());
        $this->assertFalse((new Cacher($this->kirby(['cache' => ['pages' => false]])))->pagesCacheActive());
        $this->assertFalse((new Cacher($this->kirby(['cache' => ['pages' => ['active' => false]]])))->pagesCacheActive());
        // like Kirby itself: an array config is active unless it says otherwise
        $this->assertTrue((new Cacher($this->kirby(['cache' => ['pages' => ['type' => 'file']]])))->pagesCacheActive());
        $this->assertTrue((new Cacher($this->kirby(['cache' => ['pages' => true]])))->pagesCacheActive());
        $this->assertTrue((new Cacher($this->kirby(['cache' => ['pages' => ['active' => true]]])))->pagesCacheActive());
    }

    public function testWarmupUrlsListsPublishedPagesWithHomeFirst(): void
    {
        $cacher = new Cacher($this->kirbyWithPages());

        $this->assertSame([
            'https://cacher-test.invalid',
            'https://cacher-test.invalid/about',
            'https://cacher-test.invalid/hidden',
            'https://cacher-test.invalid/secret',
        ], $cacher->warmupUrls());
    }

    public function testWarmupUrlsSkipsExcludedPages(): void
    {
        $cacher = new Cacher($this->kirbyWithPages([
            'akibeo.cacher' => ['warmup' => ['exclude' => ['secret', 'ab*']]],
        ]));

        $this->assertSame([
            'https://cacher-test.invalid',
            'https://cacher-test.invalid/hidden',
        ], $cacher->warmupUrls());
    }

    public function testWarmupUrlsCanLeaveOutTheHomePage(): void
    {
        $excluded = new Cacher($this->kirbyWithPages([
            'akibeo.cacher' => ['warmup' => ['exclude' => ['home']]],
        ]));
        $ignored = new Cacher($this->kirbyWithPages([
            'cache' => ['pages' => ['active' => true, 'ignore' => fn ($page) => $page->isHomePage()]],
        ]));

        $this->assertNotContains('https://cacher-test.invalid', $excluded->warmupUrls());
        $this->assertNotContains('https://cacher-test.invalid', $ignored->warmupUrls());
        $this->assertContains('https://cacher-test.invalid/about', $ignored->warmupUrls());
    }

    public function testWarmupUrlsHonoursAnIgnoreArray(): void
    {
        $cacher = new Cacher($this->kirbyWithPages([
            'cache' => ['pages' => ['active' => true, 'ignore' => ['about', 'hidden']]],
        ]));

        $this->assertSame([
            'https://cacher-test.invalid',
            'https://cacher-test.invalid/ignored',
            'https://cacher-test.invalid/secret',
        ], $cacher->warmupUrls());
    }

    public function testWarmupUrlsIncludeEveryLanguage(): void
    {
        $cacher = new Cacher($this->kirbyWithPages([], [
            'languages' => [
                ['code' => 'en', 'default' => true, 'url' => '/'],
                ['code' => 'de'],
            ],
        ]));

        $this->assertSame([
            'https://cacher-test.invalid',
            'https://cacher-test.invalid/de',
            'https://cacher-test.invalid/about',
            'https://cacher-test.invalid/de/about',
            'https://cacher-test.invalid/hidden',
            'https://cacher-test.invalid/de/hidden',
            'https://cacher-test.invalid/secret',
            'https://cacher-test.invalid/de/secret',
        ], $cacher->warmupUrls());
    }

    public function testWarmupUrlsAppendsTheUrlsOption(): void
    {
        $cacher = new Cacher($this->kirbyWithPages([
            'akibeo.cacher' => [
                'warmup' => [
                    'urls' => fn (App $kirby): array => [
                        $kirby->url() . '/properties',
                        'https://cacher-test.invalid/about', // duplicate
                        'https://cacher-test.invalid/properties/42',
                    ],
                ],
            ],
        ]));

        $this->assertSame([
            'https://cacher-test.invalid',
            'https://cacher-test.invalid/about',
            'https://cacher-test.invalid/hidden',
            'https://cacher-test.invalid/secret',
            'https://cacher-test.invalid/properties',
            'https://cacher-test.invalid/properties/42',
        ], $cacher->warmupUrls());
    }

    public function testWarmupUrlsAcceptsAPlainArrayAsUrlsOption(): void
    {
        $cacher = new Cacher($this->kirbyWithPages([
            'akibeo.cacher' => ['warmup' => ['urls' => ['https://cacher-test.invalid/extra']]],
        ]));

        $this->assertContains('https://cacher-test.invalid/extra', $cacher->warmupUrls());
    }

    public function testWarmupUrlsWorkDuringAPostRequest(): void
    {
        // Page::isCacheable() inspects the current request and says no to
        // every page during a POST, which is what the warmup API route is
        $cacher = new Cacher($this->kirbyWithPages([], [
            'request' => ['method' => 'POST', 'body' => ['urls' => []]],
        ]));

        $this->assertCount(4, $cacher->warmupUrls());
    }

    public function testWarmupReturnsAnErrorWhenThePagesCacheIsOff(): void
    {
        $cacher = new FakeHttpCacher($this->kirby());

        $result = $cacher->warmup();

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['warmed']);
        $this->assertSame(['The pages cache is not active (cache.pages)'], $result['errors']);
        $this->assertSame([], $cacher->fetched);
    }

    public function testWarmupRefusesUrlsThatAreNotInTheList(): void
    {
        $cacher = new FakeHttpCacher($this->kirbyWithPages(['akibeo.cacher' => ['warmup' => ['delay' => 0]]]));

        $result = $cacher->warmup(['https://example.com', 'https://cacher-test.invalid/about']);

        $this->assertFalse($result['success']);
        $this->assertSame(1, $result['warmed']);
        $this->assertSame(['https://example.com: not in the warmup list'], $result['errors']);
        $this->assertSame(['https://cacher-test.invalid/about'], $cacher->fetched);
    }

    public function testWarmupReportsUrlsThatAreNotStrings(): void
    {
        $cacher = new FakeHttpCacher($this->kirbyWithPages(['akibeo.cacher' => ['warmup' => ['delay' => 0]]]));

        $result = $cacher->warmup([['nested'], 42, 'https://cacher-test.invalid/about']);

        $this->assertFalse($result['success']);
        $this->assertSame(1, $result['warmed']);
        $this->assertSame(['Invalid URL at position 0', 'Invalid URL at position 1'], $result['errors']);
        $this->assertSame(['https://cacher-test.invalid/about'], $cacher->fetched);
    }

    public function testWarmupNeedsAnAbsoluteSiteUrl(): void
    {
        // on the CLI without the `url` option Kirby only knows relative URLs
        $cacher = new FakeHttpCacher($this->kirbyWithPages()->clone(['options' => ['url' => null]]));

        $this->assertSame('/about', $cacher->warmupUrls()[1]);

        $result = $cacher->warmup();

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['warmed']);
        $this->assertSame(["The site URL is not absolute ('/'); set the `url` option so the warmup can request the pages"], $result['errors']);
        $this->assertSame([], $cacher->fetched);
    }

    public function testWarmupFetchesEveryUrlByDefault(): void
    {
        $cacher = new FakeHttpCacher($this->kirbyWithPages(['akibeo.cacher' => ['warmup' => ['delay' => 0]]]));

        $result = $cacher->warmup();

        $this->assertTrue($result['success']);
        $this->assertSame(4, $result['warmed']);
        $this->assertSame(['4 pages warmed'], $result['cleared']);
        $this->assertSame($cacher->warmupUrls(), $cacher->fetched);
    }

    public function testWarmupReportsFailedRequestsAndKeepsGoing(): void
    {
        $cacher = new FakeHttpCacher($this->kirbyWithPages(['akibeo.cacher' => ['warmup' => ['delay' => 0]]]));
        $cacher->responses = [
            'https://cacher-test.invalid/about'  => 500,
            'https://cacher-test.invalid/hidden' => new \Exception('Could not resolve host'),
        ];

        $result = $cacher->warmup();

        $this->assertFalse($result['success']);
        $this->assertSame(2, $result['warmed']);
        $this->assertSame([
            'https://cacher-test.invalid/about: HTTP 500',
            'https://cacher-test.invalid/hidden: Could not resolve host',
        ], $result['errors']);
        $this->assertCount(4, $cacher->fetched);
    }

    public function testWarmupMakesNoRequestWithoutNetwork(): void
    {
        // the real HTTP client against a .invalid host: must fail, never hang
        $cacher = new Cacher($this->kirbyWithPages(['akibeo.cacher' => ['warmup' => ['delay' => 0, 'timeout' => 2]]]));

        $result = $cacher->warmup(['https://cacher-test.invalid/about']);

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['warmed']);
        $this->assertStringStartsWith('https://cacher-test.invalid/about: ', $result['errors'][0]);
    }
}

/**
 * Records the URLs that would be fetched instead of making HTTP requests
 */
class FakeHttpCacher extends Cacher
{
    public array $fetched = [];

    /** @var array<string, int|\Throwable> */
    public array $responses = [];

    protected function fetch(string $url): int
    {
        $this->fetched[] = $url;
        $response = $this->responses[$url] ?? 200;

        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response;
    }
}
