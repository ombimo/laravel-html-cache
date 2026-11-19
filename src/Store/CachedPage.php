<?php

namespace Ombimo\LaravelHtmlCache\Store;

/**
 * A stored page. $body is the html, or the gzip bytes of it when $gzipped.
 */
final readonly class CachedPage
{
    public function __construct(
        public string $body,
        public int $lastModified,
        public bool $gzipped = false,
    ) {}

    public function etag(): string
    {
        return md5($this->lastModified.'-'.strlen($this->body).($this->gzipped ? '-gz' : ''));
    }
}
