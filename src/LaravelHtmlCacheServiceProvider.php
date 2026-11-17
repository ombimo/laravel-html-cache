<?php

namespace Ombimo\LaravelHtmlCache;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Ombimo\LaravelHtmlCache\Commands\ClearCommand;
use Ombimo\LaravelHtmlCache\Http\Middleware\ResolveHtmlCache;
use Ombimo\LaravelHtmlCache\Http\Middleware\WriteHtmlCache;
use Ombimo\LaravelHtmlCache\Store\FilesystemStore;
use Ombimo\LaravelHtmlCache\Store\Store;
use Ombimo\LaravelHtmlCache\Support\KeyGenerator;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelHtmlCacheServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-html-cache')
            ->hasConfigFile('html-cache')
            ->hasCommand(ClearCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Store::class, fn (Application $app) => $this->makeStore($app));
        $this->app->singleton(KeyGenerator::class);
        $this->app->singleton(LaravelHtmlCache::class);
    }

    public function packageBooted(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');

        $router->aliasMiddleware('html-cache.resolve', ResolveHtmlCache::class);
        $router->aliasMiddleware('html-cache.write', WriteHtmlCache::class);
    }

    private function makeStore(Application $app): Store
    {
        $config = $app->make('config');
        $disk = $config->get('html-cache.store.disk');

        if (is_string($disk) && $disk !== '') {
            return new FilesystemStore(
                $app->make('filesystem')->disk($disk),
                atomic: $config->get("filesystems.disks.{$disk}.driver") === 'local',
            );
        }

        return new FilesystemStore($app->make('filesystem')->build([
            'driver' => 'local',
            'root' => (string) $config->get('html-cache.store.path', storage_path('app/html-cache')),
            // Public permissions (0755 / 0644) so a web server can read the files.
            'visibility' => 'public',
            'throw' => false,
        ]));
    }
}
