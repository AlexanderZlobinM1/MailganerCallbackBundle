# Mailganer plugins for Mautic

One source tree builds two independent plugins for Mautic 5, 6 and 7:

| Package | Features | Source additions |
| --- | --- | --- |
| MailganerCallbackBundle | Callbacks for SMTP delivery | `src/callback` |
| MailganerBundle | API delivery, concurrency, packages, speed control and callbacks | `src/api` |

`src/shared` is the sole source for callback parsing, DNC attribution, shared
settings, the integration form, translations and regression tests. Variant
files may not override shared paths. Sending controls and supported transport
schemes are declared by each variant's `Variant.php`.

## Install

Download the named ZIP asset from a GitHub release or install through MCC.
Extract the included bundle directory into Mautic's `plugins` directory
(`docroot/plugins` for Composer installations), then run
`php bin/console mautic:plugins:reload` and clear the cache.
The GitHub source archive is a development tree, not an installable plugin.
Each release ZIP contains its own PHP, assets, Composer metadata and tests;
neither plugin needs the other plugin or a separately installed shared library.
Install only one variant at a time. Existing native settings remain transferable.

- [Callback configuration](src/callback/README.md)
- [API configuration](src/api/README.md)

## Build and test

Run `python3 scripts/build.py --output /absolute/path/to/a/new/output-directory`.
This emits both bundle directories, versioned ZIPs and `SHA256SUMS` outside
the repository. `packages.json` owns both release versions. The build substitutes
explicit package identity tokens only; it never synchronizes or rewrites PHP
logic between source trees. Repeated builds produce byte-identical archives.

Run `python3 -m unittest discover -s build_tests`. Inside each generated bundle,
run `composer install` and `vendor/bin/phpunit`. CI builds and tests both bundles
independently on PHP 8.2/8.4 with Mautic 5/6/7. Tags beginning with `release-`
publish the tested archives as release assets.

Common changes belong in `src/shared`; API-only sending changes belong in
`src/api`. Do not commit generated packages or vendor trees. MCC must point
to the versioned release ZIP, never a subdirectory or GitHub source archive.

Maintained by Alexander Zlobin / [Sales Snap](https://sales-snap.ru).
