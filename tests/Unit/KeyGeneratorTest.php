<?php

use Illuminate\Http\Request;
use Ombimo\LaravelHtmlCache\Rules\Rule;
use Ombimo\LaravelHtmlCache\Support\KeyGenerator;

it('builds a key from host, path and query', function (string $uri, string $key) {
    $request = Request::create($uri);

    expect((new KeyGenerator)->forRequest($request, new Rule('*', 60, ['page'])))->toBe($key);
})->with([
    'home' => ['http://example.com/', 'example.com/index.html'],
    'page' => ['http://example.com/artikel/foo', 'example.com/artikel/foo/index.html'],
    'trailing slash' => ['http://example.com/artikel/foo/', 'example.com/artikel/foo/index.html'],
    'encoded' => ['http://example.com/a%20b', 'example.com/a%20b/index.html'],
]);

it('produces the same key whatever the query order', function () {
    $keys = new KeyGenerator;
    $rule = new Rule('*', 60, ['a', 'b']);

    expect($keys->forRequest(Request::create('/x?a=1&b=2'), $rule))
        ->toBe($keys->forRequest(Request::create('/x?b=2&a=1'), $rule))
        ->toMatch('~^localhost/x/index__q-[a-f0-9]{20}\.html$~');
});

it('hashes unsafe path segments', function (string $segment) {
    $key = (new KeyGenerator)->directory('example.com', "artikel/{$segment}");

    expect($key)->toMatch('~^example\.com/artikel/_h-[a-f0-9]{20}$~');
})->with(['..', '.', '.env', 'index.html', 'index__q-abc', '_h-abc', 'con', 'nul.txt', 'a:b', 'a b', 'name.']);

it('hashes empty segments', function () {
    expect((new KeyGenerator)->directory('example.com', 'artikel//x'))
        ->toMatch('~^example\.com/artikel/_h-[a-f0-9]{20}/x$~');
});

it('hashes very long paths and segments', function () {
    $keys = new KeyGenerator;

    expect($keys->directory('h', str_repeat('a', 150)))->toMatch('~^h/_h-~')
        ->and($keys->directory('h', implode('/', array_fill(0, 40, 'segment'))))->toMatch('~^h/_h-[a-f0-9]{20}$~');
});

it('sanitizes the host', function () {
    expect((new KeyGenerator)->host('Exa/mple.COM:80'))->toBe('exa_mple.com_80')
        ->and((new KeyGenerator)->host('../'))->toBe('_');
});

it('refuses unknown or invalid query parameters', function () {
    $keys = new KeyGenerator;
    $rule = new Rule('*', 60, ['page' => '~^\d+$~']);

    expect($keys->forRequest(Request::create('/x?other=1'), $rule))->toBeNull()
        ->and($keys->forRequest(Request::create('/x?page=a'), $rule))->toBeNull()
        ->and($keys->forRequest(Request::create('/x?page='.str_repeat('1', 200)), $rule))->toBeNull()
        ->and($keys->forRequest(Request::create('/x?other=1'), $rule, [], false))->toBe('localhost/x/index.html');
});

it('parses a key back', function () {
    $keys = new KeyGenerator;

    expect($keys->parse('example.com/index.html'))->toBe(['host' => 'example.com', 'path' => '/', 'query' => false])
        ->and($keys->parse('example.com/a/b/index.html'))->toBe(['host' => 'example.com', 'path' => 'a/b', 'query' => false])
        ->and($keys->parse('example.com/a/index__q-0123456789abcdef0123.html'))->toBe(['host' => 'example.com', 'path' => 'a', 'query' => true])
        ->and($keys->parse('example.com/a/_h-0123456789abcdef0123/index.html')['path'])->toBeNull()
        ->and($keys->parse('index.html'))->toBeNull()
        ->and($keys->parse('example.com/robots.txt'))->toBeNull();
});
