# Custom Fresh

![Custom Fresh](/art/logo.png "Custom Fresh")

![Latest Version](https://img.shields.io/packagist/v/ramadan/custom-fresh?style=flat-square&logo=packagist)
![Total Downloads](https://img.shields.io/packagist/dt/ramadan/custom-fresh?style=flat-square)
![PHP](https://img.shields.io/badge/php-%5E8.2-777BB4?logo=php&style=flat-square)
![Laravel](https://img.shields.io/badge/laravel-%5E10.0%7C%5E11.0%7C%5E12.0%7C%5E13.0-FF2D20?logo=laravel&style=flat-square)
![License](https://img.shields.io/packagist/l/ramadan/custom-fresh?style=flat-square)

 - - -

Custom Fresh offers fine-grained control over migrations within your Laravel project, enabling you to select which tables will not be dropped when refreshing the database.

> [!WARNING]
> Always consider upgrading the package to the [latest](https://github.com/mahmoudmohamedramadan/custom-fresh/releases/latest) version, which is the most stable release.

- [Installation](#installation)
- [Usage](#usage)
  - [Refreshing migrations](#refreshing-migrations)
  - [Glob patterns](#glob-patterns)
  - [Multiple connections](#multiple-connections)
  - [Dry run](#dry-run)
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

> [!IMPORTANT]
> Do not forget always to use the `-h` of the command to check out all supported options.

### Glob patterns

Anything containing `*`, `?`, or `[…]` is expanded with `fnmatch` against the database tables, so you can preserve whole groups at once:

```SHELL
php artisan fresh:custom "users,oauth_*,telescope_*"
```

### Multiple connections

Pass `--database=` to target a non-default connection. The connection is also forwarded to the `migrate` command:

```SHELL
php artisan fresh:custom users --database=tenant
```

### Dry run

Use `--explain` to preview exactly what would happen without dropping a single table:

```SHELL
php artisan fresh:custom users --explain
```

It prints the resolved connection, the tables that would be preserved, the tables that would be dropped, and the migration rows that would be re-inserted into the `migrations` table.

### Configuration

Publishing the config (see above) gives you `config/custom-fresh.php`:

```PHP
return [
    'always_keep' => ['users', 'personal_access_tokens'],
    'patterns'    => ['oauth_*', 'telescope_*'],
    'confirm_in'  => ['production', 'staging'],
];
```

- **`always_keep`** — tables that are preserved on every run, even if you don't list them on the command line.
- **`patterns`** — glob patterns expanded against the database on every run.
- **`confirm_in`** — environments where the command must ask for confirmation. Use `--force` to bypass.

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
