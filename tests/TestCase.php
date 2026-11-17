<?php

namespace Ombimo\LaravelHtmlCache\Tests;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Ombimo\LaravelHtmlCache\Http\Middleware\ResolveHtmlCache;
use Ombimo\LaravelHtmlCache\Http\Middleware\WriteHtmlCache;
use Ombimo\LaravelHtmlCache\LaravelHtmlCacheServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    /** Number of times a test route actually ran. */
    public static int $hits = 0;

    public string $cachePath;

    protected function setUp(): void
    {
        $this->cachePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'html-cache-tests-'.bin2hex(random_bytes(6));
        self::$hits = 0;

        parent::setUp();

        // Install the middleware the way an application would: in the global stack.
        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);
        $kernel->prependMiddleware(ResolveHtmlCache::class);
        $kernel->pushMiddleware(WriteHtmlCache::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cachePath);

        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelHtmlCacheServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('html-cache.store.path', $this->cachePath);
        $app['config']->set('html-cache.store.disk', null);
        $app['config']->set('html-cache.routes', [
            '/' => 600,
            'artikel/*' => 3600,
            'artikel' => ['ttl' => 600, 'query' => ['page' => '~^[1-9]\d{0,2}$~']],
            'forever' => null,
            'cookie' => 60,
            'json' => 60,
            'redirect' => 60,
            'missing' => 60,
            'big' => 60,
            'private/*' => 60,
        ]);
        $app['config']->set('html-cache.except', ['private/*']);
        $app['config']->set('html-cache.max_size', 1000);
    }

    protected function defineRoutes($router)
    {
        Route::get('/', fn () => $this->page('home'));
        Route::get('artikel', fn () => $this->page('index '.request('page', '1')));
        Route::get('artikel/{slug}', fn (string $slug) => $this->page("artikel {$slug}"));
        Route::get('forever', fn () => $this->page('forever'));
        Route::get('cookie', fn () => $this->page('cookie')->withCookie(cookie('visitor', '1')));
        Route::get('json', fn () => response()->json(['ok' => true]));
        Route::get('redirect', fn () => redirect('/'));
        Route::get('missing', fn () => response($this->page('missing'), 404));
        Route::get('big', fn () => $this->page(str_repeat('x', 2000)));
        Route::get('private/page', fn () => $this->page('private'));
        Route::get('uncached', fn () => $this->page('uncached'));
        Route::post('artikel/{slug}', fn () => $this->page('posted'));
    }

    protected function page(string $body): Response
    {
        self::$hits++;

        return response("<html><body>{$body}</body></html>");
    }
}
