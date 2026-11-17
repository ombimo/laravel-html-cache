<?php

use Illuminate\Support\Carbon;
use Ombimo\LaravelHtmlCache\Facades\LaravelHtmlCache;

beforeEach(function () {
    $this->get('/');
    $this->get('/artikel/a');
    $this->get('/artikel/b');
    $this->get('/artikel?page=2');
    $this->get('/forever');
});

function cachedFiles(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(test()->cachePath, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen(test()->cachePath) + 1));
    }

    sort($files);

    return $files;
}

it('flushes everything by default', function () {
    expect(cachedFiles())->toHaveCount(5);

    $this->artisan('html-cache:clear')->assertSuccessful();

    expect(cachedFiles())->toBe([]);
});

it('forgets a single page with all its query variants', function () {
    $this->artisan('html-cache:clear', ['paths' => ['/artikel']])->assertSuccessful();

    expect(cachedFiles())->toBe([
        'localhost/artikel/a/index.html',
        'localhost/artikel/b/index.html',
        'localhost/forever/index.html',
        'localhost/index.html',
    ]);
});

it('forgets a page given as a full url', function () {
    $this->artisan('html-cache:clear', ['paths' => ['http://localhost/artikel/a']])->assertSuccessful();

    expect(cachedFiles())->not->toContain('localhost/artikel/a/index.html')
        ->toContain('localhost/artikel/b/index.html');
});

it('forgets the home page', function () {
    $this->artisan('html-cache:clear', ['paths' => ['/']])->assertSuccessful();

    expect(cachedFiles())->not->toContain('localhost/index.html')->toHaveCount(4);
});

it('forgets pages matching a pattern', function () {
    $this->artisan('html-cache:clear', ['--pattern' => ['artikel/*']])->assertSuccessful();

    expect(cachedFiles())->toHaveCount(3)->not->toContain('localhost/artikel/a/index.html');
});

it('limits clearing to a host', function () {
    $this->artisan('html-cache:clear', ['--host' => 'other.test'])->assertSuccessful();
    expect(cachedFiles())->toHaveCount(5);

    $this->artisan('html-cache:clear', ['--pattern' => ['*'], '--host' => 'localhost'])->assertSuccessful();
    expect(cachedFiles())->toBe([]);
});

it('only deletes expired pages with --expired', function () {
    Carbon::setTestNow(now()->addSeconds(700));

    $this->artisan('html-cache:clear', ['--expired' => true])->assertSuccessful();

    // "/" and "artikel?page" (600s) expired, "artikel/*" (3600s) and "forever" did not.
    expect(cachedFiles())->toBe([
        'localhost/artikel/a/index.html',
        'localhost/artikel/b/index.html',
        'localhost/forever/index.html',
    ]);

    Carbon::setTestNow();
});

it('deletes pages of routes that are no longer cached with --expired', function () {
    config()->set('html-cache.routes', ['/' => 600]);

    $this->artisan('html-cache:clear', ['--expired' => true])->assertSuccessful();

    expect(cachedFiles())->toBe(['localhost/index.html']);
});

it('is available on the facade', function () {
    expect(LaravelHtmlCache::forget('/artikel/a'))->toBe(1)
        ->and(LaravelHtmlCache::forgetMatching('artikel/*'))->toBe(1)
        ->and(LaravelHtmlCache::flush())->toBe(3);
});

it('never deletes files that are not cache files', function () {
    file_put_contents(test()->cachePath.'/keep.txt', 'x');
    file_put_contents(test()->cachePath.'/localhost/robots.txt', 'x');

    $this->artisan('html-cache:clear')->assertSuccessful();

    expect(cachedFiles())->toBe(['keep.txt', 'localhost/robots.txt']);
});
