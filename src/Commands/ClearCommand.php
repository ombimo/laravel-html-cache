<?php

namespace Ombimo\LaravelHtmlCache\Commands;

use Illuminate\Console\Command;
use Ombimo\LaravelHtmlCache\LaravelHtmlCache;

class ClearCommand extends Command
{
    protected $signature = 'html-cache:clear
        {paths?* : Pages to forget, e.g. /artikel/foo (every query variant is removed)}
        {--pattern=* : Forget every page matching a pattern, e.g. "artikel/*"}
        {--host= : Only touch pages of this host}
        {--expired : Only delete expired pages and pages of routes that are no longer cached}';

    protected $description = 'Clear the cached HTML pages';

    public function handle(LaravelHtmlCache $cache): int
    {
        $host = $this->option('host') ?: null;
        $paths = (array) $this->argument('paths');
        $patterns = (array) $this->option('pattern');

        if ($this->option('expired')) {
            $this->components->info("Deleted {$cache->prune()} expired cache file(s).");

            return self::SUCCESS;
        }

        if ($paths === [] && $patterns === []) {
            $deleted = $host === null ? $cache->flush() : $cache->forgetMatching('*', $host);
            $this->components->info("Deleted {$deleted} cache file(s).");

            return self::SUCCESS;
        }

        $deleted = 0;

        foreach ($paths as $path) {
            $deleted += $cache->forget((string) $path, $host);
        }

        foreach ($patterns as $pattern) {
            $deleted += $cache->forgetMatching((string) $pattern, $host);
        }

        $this->components->info("Deleted {$deleted} cache file(s).");

        return self::SUCCESS;
    }
}
