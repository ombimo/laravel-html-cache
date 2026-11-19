<?php

namespace Ombimo\LaravelHtmlCache\Store;

/**
 * Persistence for cached pages. Keys are relative paths such as
 * "example.com/artikel/foo/index.html" (see PathGenerator).
 */
interface Store
{
    public function lastModified(string $key): ?int;

    /**
     * The html of a page, whether it is stored compressed or not.
     */
    public function get(string $key): ?string;

    /**
     * The gzip bytes of a page that is stored compressed, null otherwise. Lets a
     * hit be sent as it is to a client that accepts gzip, without decompressing.
     */
    public function getGzip(string $key): ?string;

    public function put(string $key, string $contents): bool;

    public function delete(string $key): void;

    /**
     * Every html file below a directory. The store cannot tell which of them
     * are cache files, the PathGenerator does (PathGenerator::parse()).
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
     * Remove the directories that hold no files.
     */
    public function deleteEmptyDirectories(): void;
}
