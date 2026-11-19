<?php

namespace Ombimo\LaravelHtmlCache\Paths;

use InvalidArgumentException;
use Ombimo\LaravelHtmlCache\Rules\RuleSet;

/**
 * Like LastAsNamePathGenerator, but the pages are spread over directories taken
 * from the md5 of the path, so no single directory grows huge:
 *
 *   blog/test       example.com/aa/bb/blog/test.html     (md5("blog/test") = aabb...)
 *   blog/foo        example.com/cc/dd/blog/foo.html      (md5("blog/foo")  = ccdd...)
 *
 * The prefix only depends on the path, so query variants stay next to their page.
 * A web server cannot compute md5, so these files are served by PHP only.
 */
final class PrefixedLastAsNamePathGenerator extends LastAsNamePathGenerator
{
    /**
     * @param  int  $levels  number of prefix directories
     * @param  int  $length  characters of the hash in each directory
     */
    public function __construct(
        private readonly int $levels = 2,
        private readonly int $length = 2,
    ) {
        if ($levels < 1 || $length < 1 || $levels * $length > 32) {
            throw new InvalidArgumentException('html-cache.path_prefix: "levels" and "length" must be at least 1 and levels * length at most 32 (an md5 hash).');
        }
    }

    protected function baseDirectory(string $host, string $path): string
    {
        return implode('/', [$this->host($host), ...$this->prefix($path)]);
    }

    protected function prefixLength(): int
    {
        return $this->levels;
    }

    protected function isValidPrefix(array $prefix, ?string $path): bool
    {
        foreach ($prefix as $directory) {
            if (preg_match('~^[a-f0-9]{'.$this->length.'}$~', $directory) !== 1) {
                return false;
            }
        }

        return $path === null || $prefix === $this->prefix($path);
    }

    /**
     * @return list<string>
     */
    private function prefix(string $path): array
    {
        $hash = md5(RuleSet::normalize($path));

        return array_map(
            fn (int $level) => substr($hash, $level * $this->length, $this->length),
            range(0, $this->levels - 1),
        );
    }
}
