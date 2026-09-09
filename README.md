# Custom Fresh

![Custom Fresh](/art/logo.png "Custom Fresh")

![Latest Version](https://img.shields.io/packagist/v/ramadan/custom-fresh?style=flat-square&logo=packagist)
![Total Downloads](https://img.shields.io/packagist/dt/ramadan/custom-fresh?style=flat-square)
![PHP](https://img.shields.io/badge/php-%5E8.2-777BB4?logo=php&style=flat-square)
![Laravel](https://img.shields.io/badge/laravel-%5E10.0%7C%5E11.0%7C%5E12.0%7C%5E13.0-FF2D20?logo=laravel&style=flat-square)
![License](https://img.shields.io/packagist/l/ramadan/custom-fresh?style=flat-square)

 - - -

Custom Fresh offers fine-grained control over migrations within your Laravel project, enabling you to select which tables will not be dropped when refreshing the database.

> [!TIP]
> Always consider upgrading the package to the [latest](https://github.com/mahmoudmohamedramadan/custom-fresh/releases/latest) version, which is the most stable release.

- [Installation](#installation)
- [Usage](#usage)
  - [Refreshing migrations](#refreshing-migrations)
  - [Glob patterns](#glob-patterns)
  - [Drop only some tables](#drop-only-some-tables)
  - [Keep tables without migrations](#keep-tables-without-migrations)
  - [Except](#except)
  - [Presets](#presets)
  - [Pending alters](#pending-alters)
  - [Related tables](#related-tables)
  - [Multiple connections](#multiple-connections)
  - [Dry run](#dry-run)
  - [List tables](#list-tables)
  - [Seeding](#seeding)
  - [Views and types](#views-and-types)
  - [Replace migrate:fresh](#replace-migratefresh)
  - [Configuration](#configuration)
  - [Events](#events)
  - [Example](#example)
- [Credits](#credits)
- [Support me](#support-me)

## Installation

Install the package by using [Composer](https://getcomposer.org/):

```SHELL
composer require ramadan/custom-fresh
```

(Optional) publish the config file:

```SHELL
php artisan vendor:publish --tag=custom-fresh-config
```

## Usage

After installing the package, you will see a new `fresh:custom` command.

> [!NOTE]
> Since `v1.2.0`, the package scans your migration files more accurately,
> including nested folders, custom `--path` locations, and package
> migration paths registered through Laravel.

### Refreshing migrations

You can exclude specific tables while refreshing the database inside your project:

```SHELL
php artisan fresh:custom users,foo
```

The same can be expressed with the `--keep` option (which can be combined with the positional argument):

```SHELL
php artisan fresh:custom --keep=users,personal_access_tokens
```

When nothing is passed and the config is empty, an interactive picker lists the discovered tables.

If a kept table is created in the same migration file as other tables (Laravel's default `users` / `password_reset_tokens` / `sessions` file), those sibling tables are preserved too.

> [!IMPORTANT]
> Do not forget always to use the `-h` of the command to check out all supported options.

### Glob patterns

Anything containing `*`, `?`, or `[…]` is expanded with `fnmatch` against the database tables, so you can preserve whole groups at once:

```SHELL
php artisan fresh:custom "users,oauth_*,telescope_*"
```

### Drop only some tables

You can invert the default and drop just a few tables, while everything else is preserved:

```SHELL
php artisan fresh:custom --drop=posts,comments
```

The `--drop` option can be combined with `--keep` or `--preset`. Explicit `--drop` always wins.

### Keep tables without migrations

You can preserve tables that have no migration file (common for Laravel 11+ `sessions`, `cache`, or `jobs`):

```SHELL
php artisan fresh:custom --keep=users --keep-raw=sessions,cache
```

The same list can be set in `keep_without_migrations` inside the config.

### Except

You can temporarily drop a table that is otherwise always kept:

```SHELL
php artisan fresh:custom --except=users
```

### Presets

You can group tables in the config and apply them by name:

```SHELL
php artisan fresh:custom --preset=auth
```

```PHP
'presets' => [
    'auth' => ['users', 'password_reset_tokens', 'sessions', 'personal_access_tokens'],
],
```

### Pending alters

By default, new alter migrations that touch a kept table still run, so `add_phone_to_users_table` is applied even when `users` is preserved.

Use `--freeze-schema` to mark every migration for kept tables as already run:

```SHELL
php artisan fresh:custom users --freeze-schema
```

### Related tables

Pass `--with-related` to also preserve tables linked by foreign keys:

```SHELL
php artisan fresh:custom --keep=posts --with-related
```

The command warns you when a kept table references a table that would be dropped (or the other way around).

### Multiple connections

Pass `--database=` to target a non-default connection. The connection is also forwarded to the `migrate` command:

```SHELL
php artisan fresh:custom users --database=tenant
```

Per-connection overrides can be set under the `connections` key in the config.

### Dry run

Use `--explain` to preview exactly what would happen without dropping a single table:

```SHELL
php artisan fresh:custom users --explain
```

It prints the resolved connection, the tables that would be preserved, the tables that would be dropped, the migration rows that would be re-inserted, and any pending alters that will still run.

The same plan can be printed as JSON:

```SHELL
php artisan fresh:custom users --explain --json
```

### List tables

Use `--list` to inspect the tables the scanner sees and the migration files that touch them:

```SHELL
php artisan fresh:custom --list
```

The same can be expressed as JSON with `--json`:

```SHELL
php artisan fresh:custom --list --json
```

### Seeding

Use `--seed` to re-run `DatabaseSeeder` after migrate. If you preserved tables with unique columns, that often inserts duplicates.

Use `--seed-fresh` to seed only the dropped tables, through the `table_seeders` map in the config:

```SHELL
php artisan fresh:custom --keep=users --seed-fresh
```

```PHP
'table_seeders' => [
    'posts' => Database\Seeders\PostSeeder::class,
],
```

### Views and types

Pass `--drop-views` and `--drop-types` to match Laravel's `migrate:fresh` when leftover views or PostgreSQL types would break the next run:

```SHELL
php artisan fresh:custom users --drop-views --drop-types
```

The `--drop-types` option is supported on PostgreSQL only.

### Replace migrate:fresh

Set `replace_migrate_fresh` to `true` in the config. Then `php artisan migrate:fresh` delegates to `fresh:custom` whenever `always_keep`, `patterns`, or `keep_without_migrations` is set. Otherwise Laravel's original command still runs.

### Configuration

Publishing the config (see above) gives you `config/custom-fresh.php`:

```PHP
return [
    'always_keep' => ['users', 'personal_access_tokens'],
    'patterns'    => ['oauth_*', 'telescope_*'],
    'keep_without_migrations' => ['sessions', 'cache'],
    'presets' => [
        'auth' => ['users', 'password_reset_tokens', 'sessions', 'personal_access_tokens'],
    ],
    'table_seeders' => [
        'posts' => Database\Seeders\PostSeeder::class,
    ],
    'connections' => [
        'tenant' => [
            'always_keep' => ['tenant_settings'],
        ],
    ],
    'confirm_in' => ['production', 'staging'],
    'replace_migrate_fresh' => false,
];
```

- **`always_keep`** — tables that are preserved on every run, even if you don't list them on the command line.
- **`patterns`** — glob patterns expanded against the database on every run.
- **`keep_without_migrations`** — tables preserved even when they have no migration file.
- **`presets`** — named groups applied with `--preset=`.
- **`table_seeders`** — dropped-table seeders used by `--seed-fresh`.
- **`connections`** — extra lists merged when `--database=` matches the key.
- **`confirm_in`** — environments where the command must ask for confirmation. Use `--force` to bypass.
- **`replace_migrate_fresh`** — let `migrate:fresh` honor the lists above.

### Events

Three events are dispatched during a run, perfect for backups, audit logs, or Slack notifications:

- `Ramadan\CustomFresh\Events\RefreshingDatabase` — fired before any destructive work, with the resolved preserve list and migration rows.
- `Ramadan\CustomFresh\Events\TablesDropped` — fired right after the drop step, with both the preserved and dropped tables.
- `Ramadan\CustomFresh\Events\DatabaseRefreshed` — fired after the underlying `migrate` finishes successfully.

### Example

![Custom Fresh CLI Command Example](/art/screenshot.png)

## Credits

- [Mahmoud Ramadan](https://github.com/mahmoudmohamedramadan)
- [Contributors](https://github.com/mahmoudmohamedramadan/custom-fresh/graphs/contributors)

## Support me

- [PayPal](https://paypal.com/paypalme/mmramadan496)

## License

The MIT License (MIT).
