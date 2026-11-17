<?php

use Illuminate\Support\Carbon;
use Ombimo\LaravelHtmlCache\Tests\TestCase;

function cacheFile(string $relative): string
{
    return test()->cachePath.'/localhost/'.ltrim($relative, '/');
}

it('stores the page on a miss and serves it on the next request without running the route', function () {
    $this->get('/artikel/halo')->assertOk()->assertHeader('X-Html-Cache', 'MISS')->assertSee('artikel halo');

    expect(TestCase::$hits)->toBe(1)
        ->and(file_get_contents(cacheFile('artikel/halo/index.html')))->toContain('artikel halo');

    $this->get('/artikel/halo')->assertOk()->assertHeader('X-Html-Cache', 'HIT')->assertSee('artikel halo');

    expect(TestCase::$hits)->toBe(1);
});

it('caches the home page', function () {
    $this->get('/')->assertHeader('X-Html-Cache', 'MISS');
    $this->get('/')->assertHeader('X-Html-Cache', 'HIT');

    expect(file_exists(cacheFile('index.html')))->toBeTrue();
});

it('leaves routes without a rule alone', function () {
    $this->get('/uncached')->assertOk()->assertHeaderMissing('X-Html-Cache');
    $this->get('/uncached');

    expect(TestCase::$hits)->toBe(2);
});

it('lets except win over routes', function () {
    $this->get('/private/page')->assertOk()->assertHeaderMissing('X-Html-Cache');
    $this->get('/private/page');

    expect(TestCase::$hits)->toBe(2);
});

it('regenerates an expired page', function () {
    $this->get('/');
    $this->get('/')->assertHeader('X-Html-Cache', 'HIT');

    Carbon::setTestNow(now()->addSeconds(601));

    $this->get('/')->assertHeader('X-Html-Cache', 'MISS');
    expect(TestCase::$hits)->toBe(2);

    Carbon::setTestNow();
});

it('keeps a page forever when the ttl is null', function () {
    $this->get('/forever');

    Carbon::setTestNow(now()->addYears(5));

    $this->get('/forever')->assertHeader('X-Html-Cache', 'HIT');

    Carbon::setTestNow();
});

it('applies a changed ttl to pages that are already stored', function () {
    $this->get('/');
    Carbon::setTestNow(now()->addSeconds(100));

    $this->get('/')->assertHeader('X-Html-Cache', 'HIT');

    config()->set('html-cache.routes./', 10);
    config()->set('html-cache.routes', ['/' => 10]);

    $this->get('/')->assertHeader('X-Html-Cache', 'MISS');

    Carbon::setTestNow();
});

it('does not store pages that must not be cached', function (string $uri) {
    $this->get($uri);
    $this->get($uri);

    expect(TestCase::$hits)->toBeLessThanOrEqual(2)
        ->and(glob(test()->cachePath.'/localhost/'.trim($uri, '/').'/index.html'))->toBe([]);
})->with(['/json', '/redirect', '/missing', '/big']);

it('does not store a response that sets cookies', function () {
    $this->get('/cookie')->assertCookie('visitor')->assertHeaderMissing('X-Html-Cache');
    $this->get('/cookie');

    expect(TestCase::$hits)->toBe(2)
        ->and(file_exists(cacheFile('cookie/index.html')))->toBeFalse();
});

it('can store a response that sets cookies without the cookies', function () {
    config()->set('html-cache.cookies', 'strip');

    $this->get('/cookie')->assertCookie('visitor');
    $response = $this->get('/cookie');

    $response->assertHeader('X-Html-Cache', 'HIT');
    expect($response->headers->getCookies())->toBe([]);
});

it('never reads or writes the cache for non GET requests', function () {
    $this->post('/artikel/halo')->assertOk()->assertHeaderMissing('X-Html-Cache');

    expect(file_exists(cacheFile('artikel/halo/index.html')))->toBeFalse();
});

it('answers HEAD from the cache without a body', function () {
    $this->get('/');
    $response = $this->call('HEAD', '/');

    $response->assertHeader('X-Html-Cache', 'HIT');
    expect($response->getContent())->toBe('');
});

it('answers 304 when the client already has the page', function () {
    $this->get('/');
    $etag = $this->get('/')->headers->get('ETag');

    $this->get('/', ['If-None-Match' => $etag])->assertStatus(304);
});

it('sends the configured cache control header on hits', function () {
    config()->set('html-cache.headers.cache_control', 'public, max-age=0, s-maxage=600');

    $this->get('/');

    expect($this->get('/')->headers->get('Cache-Control'))->toContain('s-maxage=600');
});

it('does nothing when disabled', function () {
    config()->set('html-cache.enabled', false);

    $this->get('/')->assertHeaderMissing('X-Html-Cache');
    $this->get('/');

    expect(TestCase::$hits)->toBe(2)->and(file_exists(cacheFile('index.html')))->toBeFalse();
});

it('can bypass the cache with a header', function () {
    $this->get('/');

    $this->get('/', ['X-Html-Cache-Bypass' => '1'])->assertHeader('X-Html-Cache', 'BYPASS');
    expect(TestCase::$hits)->toBe(2);
});

it('can bypass the cache with a cookie', function () {
    config()->set('html-cache.bypass.cookies', ['preview']);

    $this->get('/');

    $this->withUnencryptedCookie('preview', '1')->get('/')->assertHeader('X-Html-Cache', 'BYPASS');
    expect(TestCase::$hits)->toBe(2);
});

it('bypasses hosts that are not allowed', function () {
    config()->set('html-cache.hosts', ['example.com']);

    $this->get('/')->assertHeader('X-Html-Cache', 'BYPASS');
    $this->get('http://example.com/')->assertHeader('X-Html-Cache', 'MISS');
    $this->get('http://example.com/')->assertHeader('X-Html-Cache', 'HIT');

    expect(file_exists(test()->cachePath.'/example.com/index.html'))->toBeTrue();
});

describe('query strings', function () {
    it('keeps an allowed parameter in the key', function () {
        $this->get('/artikel?page=2')->assertHeader('X-Html-Cache', 'MISS')->assertSee('index 2');
        $this->get('/artikel?page=3')->assertHeader('X-Html-Cache', 'MISS')->assertSee('index 3');
        $this->get('/artikel?page=2')->assertHeader('X-Html-Cache', 'HIT')->assertSee('index 2');
        $this->get('/artikel')->assertHeader('X-Html-Cache', 'MISS')->assertSee('index 1');

        expect(glob(cacheFile('artikel/index__q-*.html')))->toHaveCount(2);
    });

    it('ignores tracking parameters', function () {
        $this->get('/artikel?utm_source=a&fbclid=b');
        $this->get('/artikel?fbclid=c')->assertHeader('X-Html-Cache', 'HIT');
    });

    it('bypasses values that fail the constraint', function () {
        $this->get('/artikel?page=abc')->assertHeader('X-Html-Cache', 'BYPASS');
        $this->get('/artikel?page=99999')->assertHeader('X-Html-Cache', 'BYPASS');
        $this->get('/artikel?page[]=1')->assertHeader('X-Html-Cache', 'BYPASS');

        expect(glob(cacheFile('artikel/index__q-*.html')))->toBe([]);
    });

    it('bypasses unknown parameters by default', function () {
        $this->get('/artikel/halo?x=1')->assertHeader('X-Html-Cache', 'BYPASS');

        expect(file_exists(cacheFile('artikel/halo/index.html')))->toBeFalse();
    });

    it('can ignore unknown parameters instead', function () {
        config()->set('html-cache.query.unknown', 'ignore');

        $this->get('/artikel/halo?x=1')->assertHeader('X-Html-Cache', 'MISS');
        $this->get('/artikel/halo?y=2')->assertHeader('X-Html-Cache', 'HIT');
    });
});

it('cannot be tricked into writing outside the store', function (string $uri) {
    $this->get($uri);

    $files = glob(test()->cachePath.'/*', GLOB_ONLYDIR) ?: [];

    foreach ($files as $dir) {
        expect(realpath($dir))->toStartWith(realpath(test()->cachePath));
    }

    expect(file_exists(dirname(test()->cachePath).'/index.html'))->toBeFalse();
})->with(['/artikel/..%2f..%2fx', '/artikel/%2e%2e/%2e%2e/x', '/artikel/index.html', '/artikel/con', '/artikel//x']);

it('stores pages on a configured filesystem disk', function () {
    $root = test()->cachePath.'/disk-root';
    config()->set('filesystems.disks.page-cache', ['driver' => 'local', 'root' => $root]);
    config()->set('html-cache.store.disk', 'page-cache');

    $this->get('/artikel/halo')->assertHeader('X-Html-Cache', 'MISS');
    $this->get('/artikel/halo')->assertHeader('X-Html-Cache', 'HIT');

    expect(file_exists($root.'/localhost/artikel/halo/index.html'))->toBeTrue();
});

it('leaves no temporary files behind', function () {
    $this->get('/artikel/halo');
    $this->get('/');

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(test()->cachePath, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        expect($file->getFilename())->toMatch('~^index(__q-[a-f0-9]{20})?\.html$~');
    }
});

it('makes the middleware available as route middleware aliases', function () {
    $aliases = app('router')->getMiddleware();

    expect($aliases)->toHaveKeys(['html-cache.resolve', 'html-cache.write']);
});
