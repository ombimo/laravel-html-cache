<?php

use Illuminate\Support\Carbon;
use Ombimo\LaravelHtmlCache\Facades\LaravelHtmlCache;
use Ombimo\LaravelHtmlCache\Http\Middleware\ResolveHtmlCache;
use Ombimo\LaravelHtmlCache\Http\Middleware\WriteHtmlCache;
use Ombimo\LaravelHtmlCache\Store\Store;
use Ombimo\LaravelHtmlCache\Tests\TestCase;

function pagePath(string $relative): string
{
    return test()->cachePath.'/localhost/'.ltrim($relative, '/');
}

/**
 * The store is a singleton built from the config, so a changed setting needs a fresh one.
 */
function useCompression(array $settings = []): void
{
    config()->set('html-cache.compress', array_merge(['enabled' => true, 'level' => 6, 'serve_encoded' => true], $settings));

    app()->forgetInstance(Store::class);
    app()->forgetInstance(Ombimo\LaravelHtmlCache\LaravelHtmlCache::class);
    app()->forgetInstance(ResolveHtmlCache::class);
    app()->forgetInstance(WriteHtmlCache::class);
}

it('stores plain html unless compression is enabled', function () {
    $this->get('/artikel/halo');

    expect(file_exists(pagePath('artikel/halo/index.html')))->toBeTrue()
        ->and(file_exists(pagePath('artikel/halo/index.html.gz')))->toBeFalse();
});

it('stores a gzip file when compression is enabled', function () {
    useCompression();

    $this->get('/artikel/halo')->assertHeader('X-Html-Cache', 'MISS')->assertSee('artikel halo');

    expect(file_exists(pagePath('artikel/halo/index.html')))->toBeFalse()
        ->and(gzdecode(file_get_contents(pagePath('artikel/halo/index.html.gz'))))->toContain('artikel halo');
});

it('serves a compressed page as plain html to a client without gzip', function () {
    useCompression();

    $this->get('/artikel/halo');
    $response = $this->get('/artikel/halo')->assertHeader('X-Html-Cache', 'HIT');

    expect(TestCase::$hits)->toBe(1)
        ->and($response->headers->has('Content-Encoding'))->toBeFalse()
        ->and($response->getContent())->toContain('artikel halo');
});

it('sends the stored gzip bytes to a client that accepts gzip', function () {
    useCompression();

    $this->get('/artikel/halo');
    $response = $this->get('/artikel/halo', ['Accept-Encoding' => 'gzip, deflate, br'])
        ->assertHeader('X-Html-Cache', 'HIT')
        ->assertHeader('Content-Encoding', 'gzip')
        ->assertHeader('Vary', 'Accept-Encoding');

    expect(gzdecode($response->getContent()))->toContain('artikel halo');
});

it('does not send gzip to a client that refuses it', function (string $acceptEncoding) {
    useCompression();

    $this->get('/artikel/halo');
    $response = $this->get('/artikel/halo', ['Accept-Encoding' => $acceptEncoding])->assertHeader('X-Html-Cache', 'HIT');

    expect($response->headers->has('Content-Encoding'))->toBeFalse()
        ->and($response->getContent())->toContain('artikel halo');
})->with(['gzip;q=0', 'identity', 'br', 'gzip;q=0.0']);

it('always decompresses when serve_encoded is off', function () {
    useCompression(['serve_encoded' => false]);

    $this->get('/artikel/halo');
    $response = $this->get('/artikel/halo', ['Accept-Encoding' => 'gzip'])->assertHeader('X-Html-Cache', 'HIT');

    expect($response->headers->has('Content-Encoding'))->toBeFalse()
        ->and($response->getContent())->toContain('artikel halo');
});

it('uses a different etag for the gzip variant and answers 304', function () {
    useCompression();

    $this->get('/artikel/halo');

    $plain = $this->get('/artikel/halo')->headers->get('ETag');
    $gzip = $this->get('/artikel/halo', ['Accept-Encoding' => 'gzip'])->headers->get('ETag');

    expect($plain)->not->toBe($gzip);

    $this->get('/artikel/halo', ['Accept-Encoding' => 'gzip', 'If-None-Match' => $gzip])->assertStatus(304);
});

it('still reads plain files after compression is switched on and replaces them', function () {
    $this->get('/artikel/halo');
    expect(file_exists(pagePath('artikel/halo/index.html')))->toBeTrue();

    useCompression();

    $this->get('/artikel/halo', ['Accept-Encoding' => 'gzip'])
        ->assertHeader('X-Html-Cache', 'HIT')
        ->assertHeaderMissing('Content-Encoding')
        ->assertSee('artikel halo');

    Carbon::setTestNow(now()->addSeconds(3601));
    $this->get('/artikel/halo')->assertHeader('X-Html-Cache', 'MISS');
    Carbon::setTestNow();

    expect(file_exists(pagePath('artikel/halo/index.html')))->toBeFalse()
        ->and(file_exists(pagePath('artikel/halo/index.html.gz')))->toBeTrue();
});

it('still reads gzip files after compression is switched off and replaces them', function () {
    useCompression();
    $this->get('/artikel/halo');

    useCompression(['enabled' => false]);

    $this->get('/artikel/halo')->assertHeader('X-Html-Cache', 'HIT')->assertSee('artikel halo');

    Carbon::setTestNow(now()->addSeconds(3601));
    $this->get('/artikel/halo')->assertHeader('X-Html-Cache', 'MISS');
    Carbon::setTestNow();

    expect(file_exists(pagePath('artikel/halo/index.html')))->toBeTrue()
        ->and(file_exists(pagePath('artikel/halo/index.html.gz')))->toBeFalse();
});

it('regenerates a page whose gzip file is corrupt', function () {
    useCompression();
    $this->get('/artikel/halo');

    file_put_contents(pagePath('artikel/halo/index.html.gz'), 'not gzip at all');

    $this->get('/artikel/halo')->assertHeader('X-Html-Cache', 'MISS')->assertSee('artikel halo');

    expect(gzdecode(file_get_contents(pagePath('artikel/halo/index.html.gz'))))->toContain('artikel halo');
});

it('forgets, prunes and flushes compressed pages', function () {
    useCompression();

    $this->get('/artikel/a');
    $this->get('/artikel/b');
    $this->get('/artikel?page=2');
    $this->get('/forever');

    expect(LaravelHtmlCache::forget('/artikel/a'))->toBe(1)
        ->and(file_exists(pagePath('artikel/a/index.html.gz')))->toBeFalse()
        ->and(LaravelHtmlCache::forgetMatching('artikel/*'))->toBe(1)
        ->and(LaravelHtmlCache::forget('/artikel'))->toBe(1);

    Carbon::setTestNow(now()->addYears(5));
    expect(LaravelHtmlCache::prune())->toBe(0)
        ->and(LaravelHtmlCache::flush())->toBe(1)
        ->and(file_exists(pagePath('forever/index.html.gz')))->toBeFalse();
    Carbon::setTestNow();
});

it('clamps the level into 1-9', function (mixed $level, int $expected) {
    expect(Ombimo\LaravelHtmlCache\LaravelHtmlCache::level(['enabled' => true, 'level' => $level]))->toBe($expected);
})->with([[0, 1], [12, 9], ['nope', 6], [3, 3]]);

it('has compression off by default', function () {
    expect(Ombimo\LaravelHtmlCache\LaravelHtmlCache::level(null))->toBeNull()
        ->and(Ombimo\LaravelHtmlCache\LaravelHtmlCache::level(['enabled' => false]))->toBeNull();
});
