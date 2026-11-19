# Laravel HTML Cache

Route-based full page HTML cache for Laravel. Two middleware do the work:

- **`ResolveHtmlCache`** serves a stored `.html` file when there is a fresh one, before the session, the router, the controller or the database are touched.
- **`WriteHtmlCache`** stores the rendered page after the application produced it (written in `terminate()`, after the response has been sent).

A config file decides which request paths are cached and for how long.

## Installation

```bash
composer require ombimo/laravel-html-cache
php artisan vendor:publish --tag="html-cache-config"
```

## Setup

Register both middleware globally (Laravel 11+ `bootstrap/app.php`). The resolver must come first so a hit never starts the session:

```php
use Ombimo\LaravelHtmlCache\Http\Middleware\ResolveHtmlCache;
use Ombimo\LaravelHtmlCache\Http\Middleware\WriteHtmlCache;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->prepend(ResolveHtmlCache::class);
    $middleware->append(WriteHtmlCache::class);
})
```

Route middleware aliases `html-cache.resolve` and `html-cache.write` are registered too, but a route middleware runs after the session has started, so use the global form for the best performance.

Then list the routes in `config/html-cache.php`:

```php
'default_ttl' => 3600,

'routes' => [
    '/'            => 600,                       // home page, 10 minutes
    'artikel/*'    => 3600,                      // TTL in seconds
    'faq'          => null,                      // never expires
    'artikel'      => ['ttl' => 600, 'query' => ['page' => '~^[1-9]\d{0,2}$~']],
],

'except' => ['search'],
'hosts'  => ['example.com', 'www.example.com'],
```

Patterns are matched against the **request path** (`Str::is` wildcard syntax, where `*` also matches `/`). A pattern starting with `~` is a regular expression instead, e.g. `'~^venue/\d+/[^/]+$~' => 3600` for detail pages only. Route names cannot be used because the resolver runs before routing. The first matching pattern wins and `except` always wins.

## What gets cached

A response is stored only when **all** of these are true: the request is a `GET`, the path matches a route, the status is in `statuses` (default `200`), the body is `text/html`, it is not a redirect, stream or file download, it is not larger than `max_size`, and **it does not set cookies** (`cookies: 'skip'`). Never cache per-visitor output: CSRF tokens, flash messages, logged-in users. Set `cookies` to `'strip'` to store the body anyway and drop the cookies.

Only successful pages are stored, so 404 URLs cannot fill the disk.

### Query strings

- `query.ignore` lists tracking parameters (`utm_*`, `fbclid`, ...) that are dropped from the key.
- A route lists the parameters that change its output in `query`. Give a regex to restrict the value (`'page' => '~^[1-9]\d{0,2}$~'`).
- Any other parameter makes the request **bypass** the cache (`query.unknown`), so nobody can create unlimited variants. Set it to `'ignore'` to drop unknown parameters instead.

### Hosts

The host is part of the cache key. Set `hosts` to the domains you serve; other `Host` headers bypass the cache.

### Bypass

Send the `X-Html-Cache-Bypass` header (or a cookie listed in `bypass.cookies`) to skip the cache, e.g. to preview unpublished content.

The `X-Html-Cache` response header reports `HIT`, `MISS` or `BYPASS` (rename or disable it in `headers.debug`). Cached responses support `ETag` / `Last-Modified` and answer `304`.

## Storage

By default pages are stored as `{host}/{path}/index.html` (query variants as `index__q-<hash>.html`), see [Path generators](#path-generators) for other layouts. Unusual path segments (`..`, dot files, `index.html`, Windows reserved names, very long segments) are replaced by a hash, so a request can never write outside the store.

```php
'store' => [
    'disk' => null,                               // a disk name from config/filesystems.php, or null
    'path' => storage_path('app/html-cache'),     // used when disk is null
],
```

### Compression

HTML shrinks by roughly 70-85% when gzipped. Turn it on to save disk space (needs the PHP `zlib` extension, otherwise pages are stored as plain html):

```php
'compress' => [
    'enabled' => env('HTML_CACHE_COMPRESS', false),
    'level' => 6,              // 1 (fastest) to 9 (smallest)
    'serve_encoded' => true,   // see below
],
```

A compressed page is written next to where the plain file would be, with `.gz` appended (`{host}/blog/test/index.html.gz`). Path generators, `html-cache:clear` and the TTL work exactly the same; `max_size` is checked on the uncompressed page.

- **Switching is safe.** Both forms are always readable, so turning compression on or off keeps serving the files that already exist. A file is replaced (and its old form deleted) the next time its page is regenerated. Run `html-cache:clear` if you want the disk space back at once.
- **Served by PHP.** A client that accepts gzip gets the stored bytes as they are (`Content-Encoding: gzip`, `Vary: Accept-Encoding`), so a hit costs no CPU. Any other client gets the page decompressed. Set `serve_encoded` to `false` to always send plain html. The `ETag` differs between the two forms.
- **Served by the web server.** nginx must know about the `.gz` files. With `gzip_static` it sends them to clients that accept gzip, with no CPU cost:

```nginx
gzip_static on;
gunzip on;   # needs ngx_http_gunzip_module: lets clients without gzip read the .gz file too

map $args $page_cache_suffix {
    ""      "/page-cache/$host$uri/index.html";
    default "";
}

location / {
    try_files $page_cache_suffix $uri $uri/ /index.php?$query_string;
}
```

> `try_files` needs the plain file to exist. nginx does not look for a `.gz` file on its own there, so with compression on and **no plain file**, use `gzip_static always;` together with `gunzip on;` (both modules required), or leave compression off for pages that nginx serves. Test your setup before relying on it.

### Path generators

`path_generator` in the config decides how a page maps to a file. The default keeps the layout used so far, so nothing changes for an existing app.

| `path_generator` | `blog/test` is stored as | `/` |
|---|---|---|
| `default` | `{host}/blog/test/index.html` | `{host}/index.html` |
| `last_as_name` | `{host}/blog/test.html` | `{host}/index.html` |
| `last_as_name_with_prefix` | `{host}/ab/cd/blog/test.html` | `{host}/ab/cd/index.html` |

With `last_as_name_with_prefix` the `ab/cd` directories are the first characters of `md5('blog/test')`, which spreads a large site over many directories. Query variants always stay next to their page (`blog__q-<hash>.html`). The size of the prefix is configurable:

```php
'path_generator' => 'last_as_name_with_prefix',
'path_prefix' => ['levels' => 2, 'length' => 2],   // levels * length <= 32
```

Use your own layout by giving the class name of an implementation of `Ombimo\LaravelHtmlCache\Contracts\PathGenerator` (it is resolved from the container):

```php
'path_generator' => App\Cache\MyPathGenerator::class,
```

Things to know:

- Existing files are **not moved** when you switch. Run `php artisan html-cache:clear` before changing `path_generator`.
- Clearing only touches files the active generator could have written (`parse()` returns `null` for anything else), but in the `last_as_name*` layouts any `.html` file with a plain name below a host directory counts as a cache file, so do not keep your own files in the store.
- nginx can serve `default` and `last_as_name` directly (`try_files /page-cache/$host$uri.html /page-cache/$host$uri/index.html ...`). `last_as_name_with_prefix` needs PHP, because nginx cannot calculate the md5 prefix.

**Served by PHP** (default): the resolver reads the file. This works with any disk.

**Served by the web server**: point `path` into `public/` (e.g. `public_path('page-cache')`) and let nginx answer straight from disk, skipping PHP completely. The snippet below is a starting point, adapt it to your server and test it before relying on it:

```nginx
# only query-less URLs map 1:1 to a file; everything else goes to PHP
map $args $page_cache_suffix {
    ""      "/page-cache/$host$uri/index.html";
    default "";
}

location / {
    try_files $page_cache_suffix $uri $uri/ /index.php?$query_string;
}
```

> Files are written with `0644`/`0755` permissions so the web server can read them. Expiry is **not** enforced by nginx: schedule `html-cache:clear --expired` (see below) so stale files are removed.

## Expiry and clearing

Pages expire after the TTL of their route; the TTL is read from the config at request time, so changing it applies to files that already exist. A stale page is regenerated by the next request.

```bash
php artisan html-cache:clear                      # everything
php artisan html-cache:clear /artikel/foo         # one page, all query variants
php artisan html-cache:clear http://example.com/artikel/foo
php artisan html-cache:clear --pattern="artikel/*"
php artisan html-cache:clear --host=example.com   # only one host
php artisan html-cache:clear --expired            # expired pages + pages of routes no longer cached
```

Schedule the cleanup:

```php
Schedule::command('html-cache:clear --expired')->hourly();
```

From code:

```php
use Ombimo\LaravelHtmlCache\Facades\LaravelHtmlCache; // alias: HtmlCache

LaravelHtmlCache::forget('/artikel/foo');
LaravelHtmlCache::forgetMatching('artikel/*');
LaravelHtmlCache::prune();
LaravelHtmlCache::flush();
```

The commands only ever delete files the configured path generator could have written, never anything else in the store directory.

## Notes

- A cache hit is served before `TrustProxies`, `TrustHosts` and maintenance mode checks when the resolver is prepended. That is what makes it fast; use `hosts` to restrict what can be cached.
- Without `ResolveHtmlCache` the writer still works but rewrites the page on every request.

## Testing

```bash
composer test       # Pest
composer analyse    # Larastan
composer format     # Pint
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [Bimo Aji Pamungkas](https://github.com/ombimo)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
