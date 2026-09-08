<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Always Keep
    |--------------------------------------------------------------------------
    |
    | Tables listed here are always preserved when running "fresh:custom",
    | even when they are not passed to the command. Useful for tables you
    | never want to lose during local development (e.g. "users").
    |
    */

    'always_keep' => [
        // 'users',
        // 'personal_access_tokens',
    ],

    /*
    |--------------------------------------------------------------------------
    | Patterns
    |--------------------------------------------------------------------------
    |
    | Glob-style patterns (e.g. "oauth_*", "telescope_*") that are expanded
    | against the database tables on every run and merged with the explicit
    | argument. Anything matched here is treated as "preserve".
    |
    */

    'patterns' => [
        // 'oauth_*',
        // 'telescope_*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Keep Without Migrations
    |--------------------------------------------------------------------------
    |
    | Tables that should be preserved even when they have no migration file
    | (common for Laravel 11+ framework tables such as "sessions" or "cache").
    | The same list can be passed at runtime with "--keep-raw=".
    |
    */

    'keep_without_migrations' => [
        // 'sessions',
        // 'cache',
        // 'jobs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Presets
    |--------------------------------------------------------------------------
    |
    | Named groups of tables (or glob patterns) that can be applied with
    | "php artisan fresh:custom --preset=auth".
    |
    */

    'presets' => [
        // 'auth' => ['users', 'password_reset_tokens', 'sessions', 'personal_access_tokens'],
        // 'billing' => ['users', 'plans', 'subscriptions', 'subscription_items'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Seeders
    |--------------------------------------------------------------------------
    |
    | Map dropped tables to seeder classes. Used by "--seed-fresh" so kept
    | tables are not re-seeded (which often violates unique constraints).
    |
    */

    'table_seeders' => [
        // 'posts' => Database\Seeders\PostSeeder::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-Connection Overrides
    |--------------------------------------------------------------------------
    |
    | Merge extra always_keep / patterns / presets / table_seeders for a
    | specific connection on top of the global values above. Useful when
    | "--database=tenant" should keep a different set of tables.
    |
    */

    'connections' => [
        // 'tenant' => [
        //     'always_keep' => ['tenant_settings'],
        //     'patterns' => ['tenant_*'],
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Confirm In
    |--------------------------------------------------------------------------
    |
    | The list of environments where the command must ask for confirmation
    | before running. Use the "--force" option to bypass the prompt.
    |
    */

    'confirm_in' => [
        'production',
    ],

    /*
    |--------------------------------------------------------------------------
    | Replace migrate:fresh
    |--------------------------------------------------------------------------
    |
    | When true, "php artisan migrate:fresh" delegates to "fresh:custom"
    | whenever always_keep, patterns, or keep_without_migrations is set.
    | Otherwise Laravel's original migrate:fresh still runs.
    |
    */

    'replace_migrate_fresh' => false,

];
