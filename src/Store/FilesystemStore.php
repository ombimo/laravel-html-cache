<?php

namespace Ombimo\LaravelHtmlCache\Store;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Throwable;

/**
 * Keys always name the html file ("example.com/blog/index.html"). A page stored
 * compressed lives next to it as "<key>.gz", which is what nginx `gzip_static`
 * looks for. Both forms are always readable, so switching compression on or off
 * never orphans the files that already exist.
 */
final class FilesystemStore implements Store
{
    /** Any html file (plain or gzip) below a host directory; the PathGenerator decides which of them are ours. */
    private const FILE_PATTERN = '~^[^/]+/.+\.html(\.gz)?$~';

    private const GZIP_SUFFIX = '.gz';

    private const GZIP_MAGIC = "\x1f\x8b";

    /**
     * @param  bool  $atomic  write to a temporary file and rename it (local disks)
     * @param  int|null  $gzipLevel  1-9 stores new pages gzipped, null stores them as plain html
     */
    public function __construct(
        private readonly Filesystem $disk,
        private readonly bool $atomic = true,
        private readonly ?int $gzipLevel = null,
    ) {}

    public function lastModified(string $key): ?int
    {
        $stored = $this->locate($key);

        if ($stored === null) {
            return null;
        }

        try {
            return $this->disk->lastModified($stored);
        } catch (Throwable) {
            return null;
        }
    }

    public function get(string $key): ?string
    {
        $stored = $this->locate($key);

        if ($stored === null) {
            return null;
        }

        try {
            $contents = $this->disk->get($stored);

            if (! is_string($contents)) {
                return null;
            }

            if ($stored === $key) {
                return $contents;
            }

            $html = function_exists('gzdecode') ? gzdecode($contents) : false;

            return is_string($html) ? $html : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function getGzip(string $key): ?string
    {
        try {
            $contents = $this->disk->exists($key.self::GZIP_SUFFIX) ? $this->disk->get($key.self::GZIP_SUFFIX) : null;
        } catch (Throwable) {
            return null;
        }

        return is_string($contents) && str_starts_with($contents, self::GZIP_MAGIC) ? $contents : null;
    }

    public function put(string $key, string $contents): bool
    {
        $compressed = $this->gzipLevel !== null && function_exists('gzencode');

        if ($compressed) {
            $contents = gzencode($contents, $this->gzipLevel);

            if ($contents === false) {
                return false;
            }
        }

        $target = $compressed ? $key.self::GZIP_SUFFIX : $key;

        if (! $this->write($target, $contents)) {
            return false;
        }

        // Only one form may exist, otherwise a stale copy could be read later.
        $this->disk->delete($compressed ? $key : $key.self::GZIP_SUFFIX);

        return true;
    }

    public function delete(string $key): void
    {
        $this->disk->delete($key);
        $this->disk->delete($key.self::GZIP_SUFFIX);
    }

    public function files(string $directory = '', bool $recursive = true): array
    {
        $paths = $recursive ? $this->disk->allFiles($directory) : $this->disk->files($directory);
        $keys = [];

        foreach ($paths as $path) {
            if (preg_match(self::FILE_PATTERN, $path) === 1) {
                $keys[] = str_ends_with($path, self::GZIP_SUFFIX) ? substr($path, 0, -strlen(self::GZIP_SUFFIX)) : $path;
            }
        }

        return array_values(array_unique($keys));
    }

    public function hosts(): array
    {
        return array_map(basename(...), $this->disk->directories(''));
    }

    public function deleteEmptyDirectories(): void
    {
        // Deepest first, so a parent that only held empty directories goes too.
        $directories = $this->disk->allDirectories('');
        usort($directories, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ($directories as $directory) {
            if ($this->disk->allFiles($directory) === [] && $this->disk->directories($directory) === []) {
                $this->disk->deleteDirectory($directory);
            }
        }
    }

    /**
     * The file that holds a key: the gzip form when there is one, else the plain one.
     */
    private function locate(string $key): ?string
    {
        try {
            foreach ([$key.self::GZIP_SUFFIX, $key] as $candidate) {
                if ($this->disk->exists($candidate)) {
                    return $candidate;
                }
            }
        } catch (Throwable) {
            // treated as not stored
        }

        return null;
    }

    private function write(string $key, string $contents): bool
    {
        if (! $this->atomic) {
            return $this->disk->put($key, $contents);
        }

        $temporary = $key.'.'.Str::random(12).'.tmp';

        try {
            if (! $this->disk->put($temporary, $contents)) {
                return false;
            }

            if ($this->disk->move($temporary, $key)) {
                return true;
            }
        } catch (Throwable $e) {
            report($e);
        }

        $this->disk->delete($temporary);

        return false;
    }
}
