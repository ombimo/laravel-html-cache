<?php

namespace Ombimo\LaravelHtmlCache\Store;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Throwable;

final class FilesystemStore implements Store
{
    /** host/[path/]index.html or host/[path/]index__q-<hash>.html */
    private const FILE_PATTERN = '~^[^/]+/(?:.+/)?index(?:__q-[a-f0-9]{20})?\.html$~';

    /**
     * @param  bool  $atomic  write to a temporary file and rename it (local disks)
     */
    public function __construct(
        private readonly Filesystem $disk,
        private readonly bool $atomic = true,
    ) {}

    public function lastModified(string $key): ?int
    {
        try {
            if (! $this->disk->exists($key)) {
                return null;
            }

            return $this->disk->lastModified($key);
        } catch (Throwable) {
            return null;
        }
    }

    public function get(string $key): ?string
    {
        try {
            $contents = $this->disk->get($key);
        } catch (Throwable) {
            return null;
        }

        return is_string($contents) ? $contents : null;
    }

    public function put(string $key, string $contents): bool
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

    public function delete(string $key): void
    {
        $this->disk->delete($key);
    }

    public function files(string $directory = '', bool $recursive = true): array
    {
        $paths = $recursive ? $this->disk->allFiles($directory) : $this->disk->files($directory);

        return array_values(array_filter(
            $paths,
            fn (string $path) => preg_match(self::FILE_PATTERN, $path) === 1,
        ));
    }

    public function hosts(): array
    {
        return array_map(basename(...), $this->disk->directories(''));
    }

    public function flush(): int
    {
        $count = 0;

        foreach ($this->files() as $key) {
            $this->disk->delete($key);
            $count++;
        }

        // Remove the directories that are now empty, deepest first.
        $directories = $this->disk->allDirectories('');
        usort($directories, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ($directories as $directory) {
            if ($this->disk->allFiles($directory) === [] && $this->disk->directories($directory) === []) {
                $this->disk->deleteDirectory($directory);
            }
        }

        return $count;
    }
}
