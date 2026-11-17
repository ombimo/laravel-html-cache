<?php

namespace Ombimo\LaravelHtmlCache\Rules;

/**
 * One cached route: the pattern that matched, how long its pages stay fresh
 * (null = forever) and which query parameters are part of the cache key.
 */
final readonly class Rule
{
    /**
     * @param  array<int|string, string>  $query  list of names, or name => "~regex~" for the value
     */
    public function __construct(
        public string $pattern,
        public ?int $ttl,
        public array $query = [],
    ) {}
}
