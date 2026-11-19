<?php

namespace Ombimo\LaravelHtmlCache\Paths;

use Ombimo\LaravelHtmlCache\Contracts\PathGenerator;
use Ombimo\LaravelHtmlCache\Rules\RuleSet;

/**
 * Shared safety rules for the built in generators. Every path segment is either
 * made of safe characters or replaced by a hash, so a request can never escape
 * the store or collide with the file names the package uses.
 */
abstract class AbstractPathGenerator implements PathGenerator
{
    protected const EXTENSION = '.html';

    protected const INDEX = 'index';

    protected const QUERY_MARK = '__q-';

    protected const HASH_PREFIX = '_h-';

    protected const MAX_SEGMENT = 100;

    protected const MAX_PATH = 180;

    /**
     * @param  string  $path  normalized request path
     * @return array{0: string, 1: string} directory (host included) and file name without query suffix or extension
     */
    abstract protected function locate(string $host, string $path): array;

    /**
     * Segments that would collide with a file name this generator writes.
     */
    abstract protected function isReserved(string $segment): bool;

    public function key(string $host, string $path, array $query = []): string
    {
        [$directory, $name] = $this->locate($host, RuleSet::normalize($path));

        return $directory.'/'.$name.$this->querySuffix($query).self::EXTENSION;
    }

    public function directory(string $host, string $path): string
    {
        return $this->locate($host, RuleSet::normalize($path))[0];
    }

    public function belongsTo(string $key, string $host, string $path): bool
    {
        $page = $this->key($host, $path);

        if ($key === $page) {
            return true;
        }

        $base = substr($page, 0, -strlen(self::EXTENSION));

        return preg_match('~^'.preg_quote($base, '~').preg_quote(self::QUERY_MARK, '~').'[a-f0-9]{20}\.html$~', $key) === 1;
    }

    public function host(string $host): string
    {
        $host = trim(preg_replace('~[^a-z0-9.\-]~', '_', strtolower($host)) ?? '', '.');

        return $host === '' ? '_' : substr($host, 0, self::MAX_SEGMENT);
    }

    /**
     * @param  string  $path  normalized request path, not "/"
     * @return list<string>
     */
    protected function segments(string $path): array
    {
        return array_map($this->segment(...), explode('/', $path));
    }

    protected function hash(string $value): string
    {
        return self::HASH_PREFIX.substr(sha1($value), 0, 20);
    }

    protected function segment(string $segment): string
    {
        return $this->isSafe($segment) ? $segment : $this->hash($segment);
    }

    /**
     * Whether a stored segment is one this generator could have written.
     */
    protected function isValidSegment(string $segment): bool
    {
        return $this->isSafe($segment) || preg_match('~^'.self::HASH_PREFIX.'[a-f0-9]{20}$~', $segment) === 1;
    }

    protected function isHashed(string $segment): bool
    {
        return str_starts_with($segment, self::HASH_PREFIX);
    }

    protected function isSafe(string $segment): bool
    {
        return preg_match('#^[A-Za-z0-9._~%-]{1,'.self::MAX_SEGMENT.'}$#', $segment) === 1
            && ! str_starts_with($segment, '.')
            && ! str_ends_with($segment, '.')
            && ! str_starts_with($segment, self::HASH_PREFIX)
            && ! $this->isReserved($segment)
            && preg_match('~^(con|prn|aux|nul|com\d|lpt\d)(\..*)?$~i', $segment) !== 1;
    }

    /**
     * @param  array<string, string>  $query
     */
    private function querySuffix(array $query): string
    {
        if ($query === []) {
            return '';
        }

        return self::QUERY_MARK.substr(sha1(http_build_query($query)), 0, 20);
    }
}
