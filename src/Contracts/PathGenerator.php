<?php

namespace Ombimo\LaravelHtmlCache\Contracts;

/**
 * Decides where a page lives in the store: it maps (host, path, query) to a
 * relative file key such as "example.com/artikel/foo/index.html" and back.
 *
 * Contract for implementations:
 *  - the key starts with the sanitized host as its first directory;
 *  - the key never contains ".." or any way to leave the store;
 *  - every query variant of a page lives next to the page itself, see belongsTo();
 *  - parse() returns null for any file the generator would not have written, so
 *    the clear command can never delete a foreign file.
 *
 * Built in (config "path_generator"): "default", "last_as_name" and
 * "last_as_name_with_prefix". Anything else is a class name of an implementation.
 */
interface PathGenerator
{
    /**
     * @param  string  $path  normalized request path: "/" or "a/b" (no leading or trailing slash)
     * @param  array<string, string>  $query  the significant query parameters, sorted by name
     */
    public function key(string $host, string $path, array $query = []): string;

    /**
     * The directory that holds the file of a page (and its query variants). Used
     * to list candidates when a page is forgotten.
     */
    public function directory(string $host, string $path): string;

    /**
     * Whether a key is the page itself or one of its query variants.
     *
     * @param  string  $path  normalized request path
     */
    public function belongsTo(string $key, string $host, string $path): bool;

    /**
     * Null when the key is not one of ours; "path" is null when it was hashed.
     *
     * @return array{host: string, path: string|null, query: bool}|null
     */
    public function parse(string $key): ?array;

    /**
     * The directory name used for a host.
     */
    public function host(string $host): string;
}
