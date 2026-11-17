<?php

use Ombimo\LaravelHtmlCache\Rules\RuleSet;

it('matches patterns and reads every value form', function () {
    $rules = new RuleSet([
        '/' => 10,
        'faq' => null,
        'about' => true,
        'artikel' => ['ttl' => 20, 'query' => ['page']],
        'event/*' => ['query' => ['page']],
        'blog',
    ], [], 99);

    expect($rules->match('/')->ttl)->toBe(10)
        ->and($rules->match('faq')->ttl)->toBeNull()
        ->and($rules->match('about')->ttl)->toBe(99)
        ->and($rules->match('artikel')->ttl)->toBe(20)
        ->and($rules->match('artikel')->query)->toBe(['page'])
        ->and($rules->match('event/foo')->ttl)->toBe(99)
        ->and($rules->match('blog')->ttl)->toBe(99)
        ->and($rules->match('nope'))->toBeNull();
});

it('uses the first matching pattern', function () {
    $rules = new RuleSet(['artikel/special' => 5, 'artikel/*' => 50], [], null);

    expect($rules->match('artikel/special')->ttl)->toBe(5)
        ->and($rules->match('artikel/other')->ttl)->toBe(50);
});

it('lets except win', function () {
    $rules = new RuleSet(['*' => 60], ['search', 'admin/*'], null);

    expect($rules->match('search'))->toBeNull()
        ->and($rules->match('admin/users'))->toBeNull()
        ->and($rules->match('artikel'))->not->toBeNull();
});

it('normalizes slashes', function () {
    $rules = new RuleSet(['/artikel/*/' => 5], [], null);

    expect($rules->match(RuleSet::normalize('/artikel/foo/')))->not->toBeNull()
        ->and(RuleSet::normalize('///'))->toBe('/')
        ->and(RuleSet::normalize(''))->toBe('/');
});

it('supports regex patterns for routes and except', function () {
    $rules = new RuleSet([
        '~^venue/\d+/[^/]+$~' => 60,
        '~^(kampus|sekolah)/[^/]+__\d+$~' => 120,
        '~^blog/(?!draft)[^/]+$~i',
    ], ['~^venue/9/~'], 5);

    expect($rules->match('venue/12/gedung-a')->ttl)->toBe(60)
        ->and($rules->match('venue/lokasi/jakarta'))->toBeNull()
        ->and($rules->match('venue/12/gedung-a/fasilitas'))->toBeNull()
        ->and($rules->match('venue/9/x'))->toBeNull()
        ->and($rules->match('sekolah/sma-1__3')->ttl)->toBe(120)
        ->and($rules->match('sekolah/sma-1__3/prodi'))->toBeNull()
        ->and($rules->match('BLOG/Hello')->ttl)->toBe(5)
        ->and($rules->match('blog/Hello')->ttl)->toBe(5)
        ->and($rules->match('blog/draft'))->toBeNull();
});
