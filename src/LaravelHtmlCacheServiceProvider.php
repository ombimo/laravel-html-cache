<?php

namespace Ombimo\LaravelHtmlCache;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use InvalidArgumentException;
use Ombimo\LaravelHtmlCache\Commands\ClearCommand;
use Ombimo\LaravelHtmlCache\Contracts\PathGenerator;
use Ombimo\LaravelHtmlCache\Http\Middleware\ResolveHtmlCache;
use Ombimo\LaravelHtmlCache\Http\Middleware\WriteHtmlCache;
use Ombimo\LaravelHtmlCache\Paths\DefaultPathGenerator;
use Ombimo\LaravelHtmlCache\Paths\LastAsNamePathGenerator;
use Ombimo\LaravelHtmlCache\Paths\PrefixedLastAsNamePathGenerator;
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
        $this->app->singleton(PathGenerator::class, fn (Application $app) => $this->makePathGenerator($app));
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

    /**
     * "path_generator" is one of the built in names, or the class name of a
     * PathGenerator (resolved from the container, so it can inject what it needs).
     */
    private function makePathGenerator(Application $app): PathGenerator
    {
        $config = $app->make('config');
        $name = $config->get('html-cache.path_generator', 'default');

        $levels = $config->get('html-cache.path_prefix.levels', 2);
        $length = $config->get('html-cache.path_prefix.length', 2);
        $prefixed = fn () => new PrefixedLastAsNamePathGenerator(
            is_numeric($levels) ? (int) $levels : 2,
            is_numeric($length) ? (int) $length : 2,
        );

        if (! is_string($name) || $name === '') {
            throw new InvalidArgumentException('html-cache.path_generator must be "default", "last_as_name", "last_as_name_with_prefix" or a class implementing '.PathGenerator::class.'.');
        }

        return match ($name) {
            'default', DefaultPathGenerator::class => new DefaultPathGenerator,
            'last_as_name', LastAsNamePathGenerator::class => new LastAsNamePathGenerator,
            'last_as_name_with_prefix', PrefixedLastAsNamePathGenerator::class => $prefixed(),
            default => $this->makeCustomPathGenerator($app, $name),
        };
    }

    private function makeCustomPathGenerator(Application $app, string $class): PathGenerator
    {
        if (! class_exists($class)) {
            throw new InvalidArgumentException("html-cache.path_generator: unknown generator \"{$class}\".");
        }

        $generator = $app->make($class);

        if (! $generator instanceof PathGenerator) {
            throw new InvalidArgumentException("html-cache.path_generator: {$class} must implement ".PathGenerator::class.'.');
        }

        return $generator;
    }

    private function makeStore(Application $app): Store
    {
        $config = $app->make('config');
        $disk = $config->get('html-cache.store.disk');
        $gzipLevel = LaravelHtmlCache::level($config->get('html-cache.compress'));

        if (is_string($disk) && $disk !== '') {
            return new FilesystemStore(
                $app->make('filesystem')->disk($disk),
                atomic: $config->get("filesystems.disks.{$disk}.driver") === 'local',
                gzipLevel: $gzipLevel,
            );
        }

        return new FilesystemStore($app->make('filesystem')->build([
            'driver' => 'local',
            'root' => (string) $config->get('html-cache.store.path', storage_path('app/html-cache')),
            // Public permissions (0755 / 0644) so a web server can read the files.
            'visibility' => 'public',
            'throw' => false,
        ]), gzipLevel: $gzipLevel);
    }
}
