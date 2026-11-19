<?php

use Illuminate\Contracts\Foundation\Application;
use Ombimo\LaravelHtmlCache\Contracts\PathGenerator;
use Ombimo\LaravelHtmlCache\Facades\LaravelHtmlCache;
use Ombimo\LaravelHtmlCache\Paths\LastAsNamePathGenerator;
use Ombimo\LaravelHtmlCache\Support\KeyGenerator;
use Ombimo\LaravelHtmlCache\Tests\TestCase;

/** Switch the layout before anything resolved the generator. */
function useGenerator(string $name): void
{
    config()->set('html-cache.path_generator', $name);

    foreach ([PathGenerator::class, KeyGenerator::class, Ombimo\LaravelHtmlCache\LaravelHtmlCache::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    LaravelHtmlCache::clearResolvedInstances();
}

/** @return list<string> */
function storedFiles(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(test()->cachePath, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen(test()->cachePath) + 1));
    }

    sort($files);

    return $files;
}

it('keeps the old layout when nothing is configured', function () {
    $this->get('/artikel/halo');

    expect(storedFiles())->toBe(['localhost/artikel/halo/index.html']);
});

it('stores and serves pages as {last}.html', function () {
    useGenerator('last_as_name');

    $this->get('/artikel/halo')->assertHeader('X-Html-Cache', 'MISS');
    $this->get('/artikel/halo')->assertHeader('X-Html-Cache', 'HIT')->assertSee('artikel halo');
    $this->get('/');
    $this->get('/artikel?page=2');

    expect(TestCase::$hits)->toBe(3)
        ->and(storedFiles())->toHaveCount(3)
        ->toContain('localhost/artikel/halo.html', 'localhost/index.html')
        ->and(glob(test()->cachePath.'/localhost/artikel__q-*.html'))->toHaveCount(1);
});

it('stores and serves pages below a prefix of the md5 of the path', function () {
    useGenerator('last_as_name_with_prefix');

    $this->get('/artikel/halo');
    $this->get('/artikel/halo')->assertHeader('X-Html-Cache', 'HIT');

    $hash = md5('artikel/halo');

    expect(storedFiles())->toBe(['localhost/'.substr($hash, 0, 2).'/'.substr($hash, 2, 2).'/artikel/halo.html'])
        ->and(TestCase::$hits)->toBe(1);
});

it('clears pages in the new layouts', function (string $generator) {
    useGenerator($generator);

    // A foreign file next to the cache must survive every clear.
    mkdir(test()->cachePath.'/localhost', 0777, true);
    file_put_contents(test()->cachePath.'/localhost/Keep Me.html', 'mine');

    $this->get('/');
    $this->get('/artikel/a');
    $this->get('/artikel/b');
    $this->get('/artikel?page=2');
    $this->get('/artikel');

    expect(storedFiles())->toHaveCount(6);

    $this->artisan('html-cache:clear', ['paths' => ['/artikel']])->assertSuccessful();
    expect(storedFiles())->toHaveCount(4);

    $this->artisan('html-cache:clear', ['--pattern' => ['artikel/*']])->assertSuccessful();
    expect(storedFiles())->toHaveCount(2);

    $this->artisan('html-cache:clear')->assertSuccessful();
    expect(storedFiles())->toBe(['localhost/Keep Me.html']);
})->with(['last_as_name', 'last_as_name_with_prefix']);

it('prunes expired pages in the new layouts', function (string $generator) {
    useGenerator($generator);

    $this->get('/artikel/a');
    $this->get('/forever');

    $this->travel(2)->hours();

    expect(LaravelHtmlCache::prune())->toBe(1)->and(storedFiles())->toHaveCount(1);
})->with(['last_as_name', 'last_as_name_with_prefix']);

it('accepts the class name of a path generator', function () {
    useGenerator(LastAsNamePathGenerator::class);

    expect(app(PathGenerator::class))->toBeInstanceOf(LastAsNamePathGenerator::class);

    $this->get('/artikel/halo');

    expect(storedFiles())->toBe(['localhost/artikel/halo.html']);
});

it('accepts a custom path generator class', function () {
    useGenerator(UnderscorePathGenerator::class);

    $this->get('/artikel/halo');

    expect(storedFiles())->toBe(['flat/artikel_halo.html']);
});

it('rejects a generator that does not exist or does not implement the contract', function (string $name) {
    useGenerator($name);

    expect(fn () => app(PathGenerator::class))->toThrow(InvalidArgumentException::class);
})->with(['nope', Application::class, stdClass::class]);

it('reads the prefix size from the config', function () {
    config()->set('html-cache.path_prefix', ['levels' => 1, 'length' => 3]);
    useGenerator('last_as_name_with_prefix');

    $this->get('/artikel/halo');

    expect(storedFiles())->toBe(['localhost/'.substr(md5('artikel/halo'), 0, 3).'/artikel/halo.html']);
});

/** Fixture: everything in one directory, "/" replaced by "_". */
class UnderscorePathGenerator implements PathGenerator
{
    public function key(string $host, string $path, array $query = []): string
    {
        return 'flat/'.str_replace('/', '_', $path).($query === [] ? '' : '__q-'.substr(sha1(http_build_query($query)), 0, 20)).'.html';
    }

    public function directory(string $host, string $path): string
    {
        return 'flat';
    }

    public function belongsTo(string $key, string $host, string $path): bool
    {
        return str_starts_with($key, substr($this->key($host, $path), 0, -5));
    }

    public function parse(string $key): ?array
    {
        return str_starts_with($key, 'flat/') ? ['host' => 'flat', 'path' => null, 'query' => false] : null;
    }

    public function host(string $host): string
    {
        return $host;
    }
}
