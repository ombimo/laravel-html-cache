<?php

namespace Ombimo\LaravelHtmlCache\Paths;

/**
 * The last path segment is the file name, no directory is created for it:
 *
 *   blog/test       example.com/blog/test.html
 *   blog/foo        example.com/blog/foo.html
 *   /               example.com/index.html
 *   blog?page=2     example.com/blog__q-<hash>.html
 *
 * Handy for a web server rule such as `try_files /page-cache/$host$uri.html`.
 */
class LastAsNamePathGenerator extends AbstractPathGenerator
{
    protected function locate(string $host, string $path): array
    {
        $directory = $this->baseDirectory($host, $path);

        if ($path === '/') {
            return [$directory, self::INDEX];
        }

        $segments = $this->segments($path);

        if (strlen(implode('/', $segments)) > self::MAX_PATH) {
            return [$directory, $this->hash($path)];
        }

        $name = array_pop($segments);

        return [implode('/', [$directory, ...$segments]), $name];
    }

    /**
     * Directory every page of the host is stored below.
     */
    protected function baseDirectory(string $host, string $path): string
    {
        return $this->host($host);
    }

    /**
     * Number of leading directories (below the host) that are not part of the path.
     */
    protected function prefixLength(): int
    {
        return 0;
    }

    /**
     * @param  list<string>  $prefix  the leading directories found in the key
     * @param  string|null  $path  null when it was hashed and cannot be checked
     */
    protected function isValidPrefix(array $prefix, ?string $path): bool
    {
        return $prefix === [];
    }

    protected function isReserved(string $segment): bool
    {
        return $segment === self::INDEX || str_contains($segment, self::QUERY_MARK);
    }

    public function parse(string $key): ?array
    {
        if (preg_match('~^(?<host>[^/]+)/(?:(?<dir>.+)/)?(?<name>[^/]+?)(?<query>__q-[a-f0-9]{20})?\.html$~', $key, $matches) !== 1) {
            return null;
        }

        $directories = $matches['dir'] === '' ? [] : explode('/', $matches['dir']);
        $prefix = array_slice($directories, 0, $this->prefixLength());
        $directories = array_slice($directories, $this->prefixLength());

        if (count($prefix) !== $this->prefixLength()) {
            return null;
        }

        $name = $matches['name'];

        if ($name === self::INDEX) {
            $path = $directories === [] ? '/' : false;
        } else {
            $segments = [...$directories, $name];
            $path = array_all($segments, $this->isValidSegment(...))
                ? (array_any($segments, $this->isHashed(...)) ? null : implode('/', $segments))
                : false;
        }

        if ($path === false || ! $this->isValidPrefix($prefix, $path)) {
            return null;
        }

        return [
            'host' => $matches['host'],
            'path' => $path,
            'query' => ($matches['query'] ?? '') !== '',
        ];
    }
}
