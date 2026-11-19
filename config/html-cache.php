<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch. When false both middleware pass every request through.
    |
    */
    'enabled' => env('HTML_CACHE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Store
    |--------------------------------------------------------------------------
    |
    | Where the rendered HTML is written.
    |
    | - "disk": name of a filesystem disk from config/filesystems.php. Leave it
    |   null to use "path" as the root of a local disk instead.
    | - "path": absolute local directory. Point it inside public_path() (for
    |   example public_path('page-cache')) to let nginx / Apache serve the files
    |   without booting PHP. See the README for the web server rules.
    |
    */
    'store' => [
        'disk' => env('HTML_CACHE_DISK'),
        'path' => env('HTML_CACHE_PATH', storage_path('app/html-cache')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compression
    |--------------------------------------------------------------------------
    |
    | Store pages gzipped to save disk space (HTML usually shrinks by 70-85%).
    | A page is written as "<file>.html.gz" instead of "<file>.html". Pages that
    | are already stored keep working whichever way you set this, a file is
    | simply replaced the next time its page is regenerated.
    |
    | - "enabled":       gzip new pages. Needs the PHP zlib extension; without
    |                    it pages are stored as plain html.
    | - "level":         1 (fastest) to 9 (smallest). 6 is a good balance.
    | - "serve_encoded": when PHP serves a hit and the client accepts gzip, send
    |                    the stored bytes as they are (Content-Encoding: gzip)
    |                    instead of decompressing them. false always sends
    |                    plain html.
    |
    | A web server that serves the files itself needs to know about the .gz
    | files, see the README (nginx `gzip_static`).
    |
    */
    'compress' => [
        'enabled' => env('HTML_CACHE_COMPRESS', false),
        'level' => 6,
        'serve_encoded' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Path generator
    |--------------------------------------------------------------------------
    |
    | How a page maps to a file inside the store.
    |
    |   "default"                   blog/test => example.com/blog/test/index.html
    |   "last_as_name"              blog/test => example.com/blog/test.html
    |   "last_as_name_with_prefix"  blog/test => example.com/ab/cd/blog/test.html
    |                               ("ab/cd" is taken from md5("blog/test"), it
    |                               spreads the files over many directories)
    |
    | Or the class name of your own implementation of
    | Ombimo\LaravelHtmlCache\Contracts\PathGenerator.
    |
    | Existing files are not moved when you change it: run
    | `php artisan html-cache:clear` BEFORE switching.
    |
    | Only "last_as_name" (and "default") can be served straight by the web server,
    | because it cannot calculate the md5 prefix.
    |
    */
    'path_generator' => env('HTML_CACHE_PATH_GENERATOR', 'default'),

    /*
    | Used by "last_as_name_with_prefix": "levels" directories of "length"
    | characters of the md5 hash (levels * length may not exceed 32).
    */
    'path_prefix' => [
        'levels' => 2,
        'length' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default TTL
    |--------------------------------------------------------------------------
    |
    | Seconds a cached page stays fresh when a route does not set its own TTL.
    | null keeps pages until they are cleared with `php artisan html-cache:clear`.
    |
    */
    'default_ttl' => 3600,

    /*
    |--------------------------------------------------------------------------
    | Cached routes
    |--------------------------------------------------------------------------
    |
    | Request path patterns (Str::is wildcards, or a "~regex~" such as
    | "~^venue/\d+/[^/]+$~", no leading slash, "/" is the home
    | page). The first matching pattern wins. Accepted values:
    |
    |   'artikel/*' => 3600,                               TTL in seconds
    |   'faq'       => null,                               never expires
    |   'about'     => true,                               use default_ttl
    |   'artikel'   => ['ttl' => 600, 'query' => [...]],   full form
    |   'event/*',                                         (list item) default_ttl
    |
    | "query" lists the query parameters that are part of the cache key. A plain
    | name accepts any short value, "name" => "~regex~" restricts the value:
    |
    |   'query' => ['page' => '~^[1-9]\d{0,2}$~']
    |
    | Matching is done on the request path, before routing runs, so route names
    | cannot be used.
    |
    */
    'routes' => [
        // '/' => 600,
        // 'artikel/*' => 3600,
        // 'artikel' => ['ttl' => 600, 'query' => ['page' => '~^[1-9]\d{0,2}$~']],
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded routes
    |--------------------------------------------------------------------------
    |
    | Path patterns that are never cached, even when they match "routes".
    |
    */
    'except' => [
        // 'search',
    ],

    /*
    |--------------------------------------------------------------------------
    | Query string handling
    |--------------------------------------------------------------------------
    |
    | "ignore": parameters (patterns) that never change the page, e.g. tracking
    | codes. They are dropped from the cache key.
    |
    | "unknown": what to do with any other parameter that the route does not
    | list in its own "query" allowlist.
    |   "bypass" (default): do not read from or write to the cache for that
    |                       request. Safe: nobody can create endless variants.
    |   "ignore":           drop it and treat the request as the plain page.
    |
    */
    'query' => [
        'ignore' => ['utm_*', 'fbclid', 'gclid', 'msclkid', 'mc_*', '_ga', '_gl'],
        'unknown' => 'bypass',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed hosts
    |--------------------------------------------------------------------------
    |
    | The host is part of the cache key. Without a list, any Host header that
    | reaches the app can create its own copy of every page. Set the host
    | patterns you serve (e.g. ['example.com', 'www.example.com']). Requests
    | for other hosts bypass the cache. null accepts every host.
    |
    */
    'hosts' => null,

    /*
    |--------------------------------------------------------------------------
    | Bypass
    |--------------------------------------------------------------------------
    |
    | Requests carrying one of these skip the cache completely (handy for
    | previewing unpublished content). Only presence is checked, the value is
    | ignored, so use an unguessable name.
    |
    */
    'bypass' => [
        'header' => 'X-Html-Cache-Bypass',
        'cookies' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Storing rules
    |--------------------------------------------------------------------------
    |
    | "statuses": response status codes that may be stored.
    | "cookies":  what to do with a response that sets cookies.
    |    "skip" (default): do not store it, it holds per visitor state.
    |    "strip":          store the body anyway, never store the cookies.
    | "max_size": largest body (bytes) that is stored.
    |
    */
    'statuses' => [200],
    'cookies' => 'skip',
    'max_size' => 2 * 1024 * 1024,

    /*
    |--------------------------------------------------------------------------
    | Response headers
    |--------------------------------------------------------------------------
    |
    | "debug":         header reporting HIT / MISS / BYPASS. null disables it.
    | "cache_control": Cache-Control value for cached responses served by PHP,
    |                  e.g. 'public, max-age=0, s-maxage=600'. null leaves the
    |                  framework default.
    |
    */
    'headers' => [
        'debug' => 'X-Html-Cache',
        'cache_control' => null,
    ],

];
