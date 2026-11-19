<?php

use Ombimo\LaravelHtmlCache\Contracts\PathGenerator;
use Ombimo\LaravelHtmlCache\Paths\DefaultPathGenerator;
use Ombimo\LaravelHtmlCache\Paths\LastAsNamePathGenerator;
use Ombimo\LaravelHtmlCache\Paths\PrefixedLastAsNamePathGenerator;

/** @return array<string, PathGenerator> */
function generators(): array
{
    return [
        'default' => new DefaultPathGenerator,
        'last_as_name' => new LastAsNamePathGenerator,
        'last_as_name_with_prefix' => new PrefixedLastAsNamePathGenerator,
    ];
}

it('keeps the default layout: a directory per page with index.html', function () {
    $paths = new DefaultPathGenerator;

    expect($paths->key('example.com', 'blog/test'))->toBe('example.com/blog/test/index.html')
        ->and($paths->key('example.com', '/'))->toBe('example.com/index.html')
        ->and($paths->key('example.com', 'blog', ['page' => '2']))->toMatch('~^example\.com/blog/index__q-[a-f0-9]{20}\.html$~');
});

it('uses the last segment as the file name', function () {
    $paths = new LastAsNamePathGenerator;

    expect($paths->key('example.com', 'blog/test'))->toBe('example.com/blog/test.html')
        ->and($paths->key('example.com', 'blog/foo'))->toBe('example.com/blog/foo.html')
        ->and($paths->key('example.com', 'blog'))->toBe('example.com/blog.html')
        ->and($paths->key('example.com', '/'))->toBe('example.com/index.html')
        ->and($paths->key('example.com', 'blog', ['page' => '2']))->toMatch('~^example\.com/blog__q-[a-f0-9]{20}\.html$~');
});

it('adds a prefix calculated from the md5 of the path', function () {
    $paths = new PrefixedLastAsNamePathGenerator;
    $test = md5('blog/test');
    $foo = md5('blog/foo');

    expect($paths->key('example.com', 'blog/test'))->toBe('example.com/'.substr($test, 0, 2).'/'.substr($test, 2, 2).'/blog/test.html')
        ->and($paths->key('example.com', 'blog/foo'))->toBe('example.com/'.substr($foo, 0, 2).'/'.substr($foo, 2, 2).'/blog/foo.html')
        ->and($paths->key('example.com', 'blog/foo', ['a' => '1']))->toStartWith('example.com/'.substr($foo, 0, 2).'/'.substr($foo, 2, 2).'/blog/foo__q-');
});

it('has a configurable prefix size', function () {
    $hash = md5('blog/test');

    expect((new PrefixedLastAsNamePathGenerator(3, 1))->key('h', 'blog/test'))
        ->toBe('h/'.$hash[0].'/'.$hash[1].'/'.$hash[2].'/blog/test.html')
        ->and(fn () => new PrefixedLastAsNamePathGenerator(0, 2))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PrefixedLastAsNamePathGenerator(9, 4))->toThrow(InvalidArgumentException::class);
});

it('hashes unsafe segments in every layout', function (string $segment) {
    foreach (generators() as $name => $paths) {
        expect($paths->key('example.com', "artikel/{$segment}"))
            ->toContain('/_h-')
            ->not->toContain('..');
    }
})->with(['..', '.', '.env', 'index__q-abc', '_h-abc', 'con', 'nul.txt', 'a:b', 'a b', 'name.', '/x']);

it('keeps names that would collide with its own files out of the last_as_name layouts', function (string $path) {
    foreach (['last_as_name', 'last_as_name_with_prefix'] as $name) {
        expect(generators()[$name]->key('h', $path))->toContain('_h-');
    }
})->with(['index', 'blog/index', 'blog/x__q-0123456789abcdef0123']);

it('hashes very long paths', function () {
    foreach (generators() as $paths) {
        expect($paths->key('h', implode('/', array_fill(0, 40, 'segment'))))->toContain('/_h-')
            ->and(strlen($paths->key('h', implode('/', array_fill(0, 40, 'segment')))))->toBeLessThan(200);
    }
});

it('parses what it wrote and nothing else', function (string $name, string $path, array $query) {
    $paths = generators()[$name];
    $key = $paths->key('example.com', $path, $query);

    expect($paths->parse($key))->toBe(['host' => 'example.com', 'path' => $path, 'query' => $query !== []]);
})->with(['default', 'last_as_name', 'last_as_name_with_prefix'])->with([
    'home' => ['/', []],
    'page' => ['blog/test', []],
    'query' => ['blog', ['page' => '2']],
    'deep' => ['a/b/c/d', []],
]);

it('returns null for files it did not write', function (string $name) {
    $paths = generators()[$name];

    expect($paths->parse('robots.txt'))->toBeNull()
        ->and($paths->parse('example.com/robots.txt'))->toBeNull()
        ->and($paths->parse('example.com/a/readme.md'))->toBeNull()
        ->and($paths->parse('example.com/a/index.html.tmp'))->toBeNull();
})->with(['default', 'last_as_name', 'last_as_name_with_prefix']);

it('refuses foreign html files in the last_as_name layouts', function () {
    $plain = new LastAsNamePathGenerator;
    $prefixed = new PrefixedLastAsNamePathGenerator;

    expect($plain->parse('example.com/blog/index.html'))->toBeNull()
        ->and($plain->parse('example.com/.hidden.html'))->toBeNull()
        ->and($plain->parse('example.com/a b.html'))->toBeNull()
        ->and($prefixed->parse('example.com/blog/test.html'))->toBeNull()
        ->and($prefixed->parse('example.com/zz/zz/blog/test.html'))->toBeNull()
        // right shape, wrong prefix for this path
        ->and($prefixed->parse('example.com/00/00/blog/test.html'))->toBeNull();
});

it('marks hashed paths as unknown', function () {
    foreach (generators() as $paths) {
        expect($paths->parse($paths->key('h', 'a/b c/d'))['path'])->toBeNull();
    }
});

it('knows which keys belong to a page', function (string $name) {
    $paths = generators()[$name];
    $page = $paths->key('example.com', 'blog');
    $variant = $paths->key('example.com', 'blog', ['page' => '2']);

    expect($paths->belongsTo($page, 'example.com', 'blog'))->toBeTrue()
        ->and($paths->belongsTo($variant, 'example.com', 'blog'))->toBeTrue()
        ->and($paths->belongsTo($paths->key('example.com', 'blog2'), 'example.com', 'blog'))->toBeFalse()
        ->and($paths->belongsTo($paths->key('example.com', 'blog/x'), 'example.com', 'blog'))->toBeFalse()
        ->and($paths->belongsTo($paths->key('other.com', 'blog'), 'example.com', 'blog'))->toBeFalse();
})->with(['default', 'last_as_name', 'last_as_name_with_prefix']);

it('lists the directory of a page', function () {
    expect((new DefaultPathGenerator)->directory('h', 'blog/test'))->toBe('h/blog/test')
        ->and((new LastAsNamePathGenerator)->directory('h', 'blog/test'))->toBe('h/blog')
        ->and((new LastAsNamePathGenerator)->directory('h', '/'))->toBe('h');
});
