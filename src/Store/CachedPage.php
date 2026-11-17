<?php

namespace Ombimo\LaravelHtmlCache\Store;

final readonly class CachedPage
{
    public function __construct(
        public string $html,
        public int $lastModified,
    ) {}

    public function etag(): string
    {
        return md5($this->lastModified.'-'.strlen($this->html));
    }
}
