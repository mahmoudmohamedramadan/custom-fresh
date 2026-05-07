# Release Notes for 1.x

## [Unreleased](https://github.com/mahmoudmohamedramadan/custom-fresh/compare/v1.2.0-alpha.1...1.x)

## [v1.2.0-alpha.1](https://github.com/mahmoudmohamedramadan/custom-fresh/releases/tag/v1.2.0-alpha.1)

- [1.x] Defers the database/filesystem bootstrap from `__construct` to `handle()` so the package no longer hits the database on every artisan invocation.
- [1.x] Replaces the filename heuristic with real `Schema::create` / `Schema::table` / `Schema::drop*` / `Schema::rename` parsing, captures **every** matching alter migration (fixes the previous `[0]`-only bug), and recursively scans nested migration folders, `--path` paths, and migrator-registered paths.
- [1.x] Adds the `--database=` option and forwards it to the underlying `migrate` call.
- [1.x] Adds the `--keep=` flag as an alias for the positional argument and supports glob patterns (e.g., `oauth_*`).
- [1.x] Adds the `--explain` dry-run mode that previews the work without touching the database.
- [1.x] Adds a publishable config file (`custom-fresh.php`) with `always_keep`, `patterns`, and `confirm_in` keys.
- [1.x] Adds the `RefreshingDatabase`, `TablesDropped`, and `DatabaseRefreshed` lifecycle events.
- [1.x] Wraps the `migrations` table reset inside the foreign-key-disabled block, uses `pathinfo` instead of `substr` to derive migration names, returns `Command::SUCCESS` / `Command::FAILURE` constants, and rewords the argument description.

## [v1.1.9](https://github.com/mahmoudmohamedramadan/custom-fresh/releases/tag/v1.1.9)

- [1.x] Adds compatibility to support **Laravel v13** and introduces a refreshed package logo.

## [v1.1.8](https://github.com/mahmoudmohamedramadan/custom-fresh/releases/tag/v1.1.8)

- [1.x] Adds compatibility to support **Laravel v12**.

## [v1.1.7](https://github.com/mahmoudmohamedramadan/custom-fresh/releases/tag/v1.1.7)

- [1.x] Adds the `graceful` option to the command.
- [1.x] Refactors the code.

## [v1.1.6](https://github.com/mahmoudmohamedramadan/custom-fresh/releases/tag/v1.1.6)

- [1.x] Adds the ability to pass more options to the command.

## [v1.1.5](https://github.com/mahmoudmohamedramadan/custom-fresh/releases/tag/v1.1.5)

- [1.x] Refactors the code.
- [1.x] Re-enables the foreign key constraints after dropping the tables.
- [1.x] Fixes the issue where an exception was thrown for tables that lack migration files.

## [v1.1.4](https://github.com/mahmoudmohamedramadan/custom-fresh/releases/tag/v1.1.4)

- [1.x] Adds the `laravel/framework` to the `require` key.
- [1.x] Adds the `ext-pdo` to the `require` key.
- [1.x] Updates the approach of getting the database tables.
- [1.x] Removes the `doctrine/dbal` from the `require` key.
- [1.x] Removes the `illuminate/support` from the `require` key.
- [1.x] Removes the `illuminate/console` from the `require` key.
- [1.x] Fixes the compatibility issue between `Laravel v11` and `Doctrine`.

## [v1.1.3](https://github.com/mahmoudmohamedramadan/custom-fresh/releases/tag/v1.1.3)

- [1.x] Updates the `authors` key.
- [1.x] Removes the `illuminate/database` from the `require` key.
- [1.x] Updates the `funding` key.

## [v1.1.2](https://github.com/mahmoudmohamedramadan/custom-fresh/releases/tag/v1.1.2) - [#9](https://github.com/mahmoudmohamedramadan/custom-fresh/pull/9)

- [1.x] Improves the comments.

## [v1.1.1](https://github.com/mahmoudmohamedramadan/custom-fresh/releases/tag/v1.1.1) - [#8](https://github.com/mahmoudmohamedramadan/custom-fresh/pull/8)

- [1.x] Removes the additional components.
- [1.x] Improves the comments.

## [v1.1.0](https://github.com/mahmoudmohamedramadan/custom-fresh/releases/tag/v1.1.0) - [#7](https://github.com/mahmoudmohamedramadan/custom-fresh/pull/7)

- [1.x] Improves the way of guessing the database info.
- [1.x] Updates the existing comments above each functionality.
- [1.x] Adds a new comment section above the functionality of dropping the tables.

## [v1.0.9](https://github.com/mahmoudmohamedramadan/custom-fresh/releases/tag/v1.0.9) - [#3](https://github.com/mahmoudmohamedramadan/custom-fresh/pull/3)

- [1.x] Updates the DocBlocks.
- [1.x] Refactors the code.
- [1.x] Fixes the issue of overriding the tables that should not be dropped.

## [v1.0.8](https://github.com/mahmoudmohamedramadan/custom-fresh/releases/tag/v1.0.8) - [#2](https://github.com/mahmoudmohamedramadan/custom-fresh/pull/2)

- [1.x] Updates the DocBlocks.
- [1.x] Refactors the code.
- [1.x] Fixes the old and faulty approach of retrieving the database tables.

## [v1.0.8 (alpha.1)](https://github.com/mahmoudmohamedramadan/custom-fresh/releases/tag/v1.0.8-alpha.1) - [#1](https://github.com/mahmoudmohamedramadan/custom-fresh/pull/1)

- [1.x] Adds the `doctrine/dbal` to the `require` key.
- [1.x] Adds the `illuminate/database` to the `require` key.
- [1.x] Upgrades the `require` key list.
- [1.x] Refactors the code.
- [1.x] Fixes the issue of retrieving the list of tables in different databases.
