# glueful/thallo-core

Thallo — the application. Installed into `vendor/` by the Thallo skeleton
(`composer create-project glueful/thallo`); upgraded with `composer update && php glueful thallo:provision`.

Development happens in the Thallo monorepo (`glueful/thallo`); this repository is a read-only
split of its `core/` directory, published with the same version as the skeleton and the packs.

## Contributing

This repository is a read-only mirror, published from
[glueful/thallo](https://github.com/glueful/thallo) on every release; its `main` is overwritten
by the next split, so nothing can land here. Issues and pull requests belong in glueful/thallo,
where this code lives at `core/`.
