<?php

namespace Ombimo\LaravelHtmlCache\Paths;

/**
 * One directory per page, the page is always called index.html:
 *
 *   blog/test       example.com/blog/test/index.html
 *   /               example.com/index.html
 *   blog?page=2     example.com/blog/index__q-<hash>.html
 */
final class DefaultPathGenerator extends AbstractPathGenerator
{
    protected function locate(string $host, string $path): array
    {
        $directory = $this->host($host);

        if ($path === '/') {
            return [$directory, self::INDEX];
        }

        $joined = implode('/', $this->segments($path));

        if (strlen($joined) > self::MAX_PATH) {
            $joined = $this->hash($path);
        }

        return [$directory.'/'.$joined, self::INDEX];
    }

    protected function isReserved(string $segment): bool
    {
        return $segment === self::INDEX.self::EXTENSION || str_starts_with($segment, self::INDEX.self::QUERY_MARK);
    }

    public function parse(string $key): ?array
    {
        if (preg_match('~^(?<host>[^/]+)/(?:(?<path>.+)/)?index(?<query>__q-[a-f0-9]{20})?\.html$~', $key, $matches) !== 1) {
            return null;
        }

        $path = ($matches['path'] ?? '') === '' ? '/' : $matches['path'];

        if ($path !== '/') {
            foreach (explode('/', $path) as $segment) {
                if ($this->isHashed($segment)) {
                    $path = null;

                    break;
                }
            }
        }

        return [
            'host' => $matches['host'],
            'path' => $path,
            'query' => ($matches['query'] ?? '') !== '',
        ];
    }
}
