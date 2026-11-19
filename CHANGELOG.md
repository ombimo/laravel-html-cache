# Changelog

All notable changes to `laravel-html-cache` will be documented in this file.

## Unreleased

- Initial version: `ResolveHtmlCache` and `WriteHtmlCache` middleware, route based config, disk or public path storage, `html-cache:clear` command.

- Optional gzip storage (`compress`): pages are written as `<file>.html.gz`, read transparently, and sent to clients that accept gzip without decompressing.

- Configurable path generators (`path_generator`): `default` (unchanged), `last_as_name`, `last_as_name_with_prefix` or your own `PathGenerator` class.
