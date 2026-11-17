<?php

namespace Ombimo\LaravelHtmlCache\Store;

/**
 * Persistence for cached pages. Keys are relative paths such as
 * "example.com/artikel/foo/index.html" (see KeyGenerator).
 */
interface Store
{
    public function lastModified(string $key): ?int;

    public function get(string $key): ?string;

    public function put(string $key, string $contents): bool;

    public function delete(string $key): void;

    /**
     * Cache files (never anything else) below a directory.
     *
     * @return list<string> keys
     */
    public function files(string $directory = '', bool $recursive = true): array;

    /**
     * Top level directories, i.e. the hosts that have cached pages.
     *
     * @return list<string>
     */
    public function hosts(): array;

    /**
     * Delete every cache file and the directories left empty.
     *
     * @return int number of files deleted
     */
    public function flush(): int;
}
