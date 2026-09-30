# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

SmartBook is a WordPress plugin (library/book-catalog management: books, borrowing, QR/barcode labels,
import/export) built on PSR-4 autoloading, SOLID principles, and a hand-rolled dependency injection
container — not a typical loose-functions-in-includes/ WP plugin. Requires PHP >= 8.2 and WP >= 6.4.
Every PHP file uses `declare(strict_types=1)`.

## Commands

```bash
composer install          # install runtime + dev dependencies (required before the plugin will load —
                           # smartbook.php checks for vendor/autoload.php and shows an admin notice + bails if missing)
composer run lint         # phpcs --standard=phpcs.xml.dist (WordPress-Extra + WordPress-Docs + PHPCompatibilityWP for PHP 8.2+)
composer run lint:fix     # phpcbf --standard=phpcs.xml.dist, auto-fixes what it can
vendor/bin/phpcs --standard=phpcs.xml.dist app/Some/File.php   # lint a single file
php -l app/Some/File.php  # quick syntax check of a single file
```

There is no automated test suite (no PHPUnit config, no `tests/` directory) — verification is
`composer run lint` plus `php -l` on changed files, and manual verification in a running WP install
(activate/deactivate, exercise the changed admin page or hook).

The custom PHPCS rule `WordPress.NamingConventions.PrefixAllGlobals` enforces that every global-scope
symbol (functions in `app/Helpers/helpers.php`, constants, etc.) is prefixed `sb`/`SB`/`Sb`/`SmartBook`.

## Architecture

### Boot sequence

`smartbook.php` is the only file that runs at load time outside the autoloader. It defines the `SB_*`
constants, requires `vendor/autoload.php`, registers `register_activation_hook`/`register_deactivation_hook`
at the top level (required — WP does not detect these hooks if registered inside another hook callback),
and on `plugins_loaded` calls `Core\Plugin::instance()->boot()` inside a try/catch so one module's failure
can't white-screen the site (falls back to `error_log` + an admin notice).

`Core\Plugin` (singleton) owns a `Core\Container\Container` (custom reflection-based DI container, see
`app/Core/Contracts/ContainerInterface.php`) and a fixed, ordered list of service providers
(`Core\Plugin::PROVIDERS`). Boot happens in two passes across *all* providers before any hooks fire:

1. `register()` on every provider (in `PROVIDERS` order) — binds services into the container only,
   must never call WordPress hook functions.
2. `boot()` on every provider — attaches WordPress hooks, safe to depend on bindings from *any* other
   provider regardless of order, since all `register()` calls are guaranteed complete first.

Provider order in `Core\Plugin::PROVIDERS`: Logger → Settings → Assets → PostTypes → Taxonomies →
QrCode → Barcode → ImportExport → MetaBoxes → Frontend → Admin.

The container (`app/Core/Container/Container.php`) supports `bind()` (transient), `singleton()` (shared),
`instance()` (pre-built), and autowiring via `make()`: constructor parameters are resolved by type-hint
recursively, builtin/scalar parameters must have a default or be passed explicitly.

### Module pattern

Every feature area under `app/` (`Admin`, `Assets`, `PostTypes`, `Taxonomies`, `Services`, `MetaBoxes`,
`Frontend`, `Settings`) has its own `*ServiceProvider` implementing `Core\Contracts\ServiceProviderInterface`
(usually via `AbstractServiceProvider`, which no-ops both phases so a provider only overrides what it needs).
Classes that attach their own hooks implement `Core\Contracts\Hookable::register_hooks()`, called from
their provider's `boot()`. This is the pattern to follow for any new module: add a `Hookable` class, bind it
in a (new or existing) `ServiceProviderInterface`, call `register_hooks()` from `boot()`, and add the
provider to `Core\Plugin::PROVIDERS` in the right relative position if it has cross-module dependencies.

### Activation quirk (important when touching CPTs/taxonomies)

`init` (where post types/taxonomies normally register) has already fired by the time
`register_activation_hook`'s callback runs on a fresh activation. `Core\Activator::activate()` therefore
calls `(new BookPostType())->register()` and each taxonomy's `register()` **synchronously**, in addition
to the normal `init`-hooked registration, before calling `flush_rewrite_rules()`. If you add a new CPT or
taxonomy, wire it into `Activator` the same way or its rewrite rules won't exist until the *next*
activation.

### Post type, taxonomies, capabilities

`PostTypes\BookPostType` (slug `sb_book`) uses a dedicated `capability_type` (`sb_book`/`sb_books`) instead
of default post capabilities, so book permissions are independent of `edit_posts` etc. Because a custom
capability_type starts out granted to nobody, `Activator::grant_capabilities()` grants every
`BookPostType::capabilities()` cap to the `administrator` role on activation — any new capability must be
added to that map or admins get locked out.

Seven taxonomies (`Author`, `Genre`, `Publisher`, `Language`, `Series`, `Shelf`, `Collection`) all extend
`Taxonomies\AbstractTaxonomy`, which builds the full `register_taxonomy()` args/labels once; a concrete
taxonomy only implements `slug()`, `singular_name()`, `plural_name()`, `hierarchical()`. All taxonomies (and
the CPT) are registered with `show_in_menu => false` — `Admin\AdminMenu` adds explicit submenu entries
instead, so the visible admin menu is exactly the curated list, not whatever WP auto-generates.

### Settings

All configuration lives in a single autoloaded option (`sb_options`, via `Settings\Settings`) rather than
one option per field, to minimize autoloaded rows. Read access from anywhere (including outside the
container's reach, e.g. templates) goes through the global helper `sb_option( $key, $default )`. Boolean
settings are backed by checkboxes and sanitized specially: an absent key means "unchecked" (`false`), never
"fall back to default" — see the doc comment on `Settings::sanitize()` before changing that logic, since
getting it backwards makes an enabled checkbox impossible to ever turn off.

### Import/export

`Services\Import\FormatRegistry` holds one `FormatInterface` implementation per format (`CsvFormat`,
`JsonFormat`, `XmlFormat`, `BackupFormat`) behind string keys `csv`/`json`/`xml`/`backup`. `backup` is a
distinct key from `json` even though both use `.json` — a backup's enveloped shape is only ever selected
explicitly (Backup/Restore flow), never guessed from a file extension (`key_for_extension()` never returns
`backup`). `Services\Import\ImportRunner` is the shared chunked-processing engine used by two different
callers that both need the same session/result shape but different execution models:

- `Admin\ImportExportAjaxController` calls `start()`/`process_chunk()` repeatedly across AJAX round trips
  (progress bar, JS path).
- `Admin\Pages\ImportExportPage`'s admin-post handlers call `run_all()`, which loops the same two methods
  to completion in one request (no-JS fallback).

Sessions are tracked by a token (`ImportSession`) that outlives the run so the same token can produce a
downloadable error-log CSV afterward.

### QR codes / barcodes

`QrCodeServiceProvider` and the barcode equivalent wire generation into `save_post_sb_book` and cleanup
into `delete_post`, but only if the corresponding `Settings` flag (`enable_qr`/`enable_barcode`) is on —
when off, existing generated files are left alone (not deleted) so re-enabling resumes where it left off.
Manual regeneration goes through an `admin-post.php` action gated by `current_user_can` + `check_admin_referer`.

### Assets

`Assets\AdminAssetLoader` enqueues `sb-admin.css`/`sb-admin.js` only on SmartBook's own admin screens,
detected by screen id prefix `sb_` or via the `sb_admin_asset_screens` filter (for pages that need the
assets but don't have that prefix) — don't unconditionally enqueue on `admin_enqueue_scripts` for new admin
UI; add to that filter or rely on the `sb_` id prefix instead. `sb_asset_url()`/`sb_asset_version()` in
`app/Helpers/helpers.php` build asset URLs with mtime-based cache-busting.

### Global helpers

`app/Helpers/helpers.php` is loaded via composer's `files` autoload (not PSR-4) and declares functions in
the *global* namespace, each guarded by `function_exists()` and prefixed `sb_` (`sb_plugin()`,
`sb_container()`, `sb_logger()`, `sb_option()`, `sb_asset_url()`, `sb_asset_version()`,
`sb_format_currency()`, `sb_format_date()`). This is the intended way for templates/other code to reach the
container or settings without a `use` import.
