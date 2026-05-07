<?php

namespace Ramadan\CustomFresh\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramadan\CustomFresh\Console\Confirmable;
use Ramadan\CustomFresh\Events\DatabaseRefreshed;
use Ramadan\CustomFresh\Events\RefreshingDatabase;
use Ramadan\CustomFresh\Events\TablesDropped;
use Ramadan\CustomFresh\Support\MigrationFileScanner;
use Throwable;

class CustomFreshCommand extends Command
{
    use Confirmable;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fresh:custom
                {tables? : Tables to preserve (comma-separated, supports glob patterns like "oauth_*")}
                {--keep= : Alternative to the positional argument; comma-separated list of tables/patterns to preserve}
                {--database= : The database connection to use}
                {--force : Force the operation to run when in production}
                {--path=* : The path(s) to the migrations files to be executed}
                {--realpath : Indicate any provided migration file paths are pre-resolved absolute paths}
                {--schema-path= : The path to a schema dump file}
                {--pretend : Dump the SQL queries that would be run}
                {--seed : Indicates if the seed task should be re-run}
                {--seeder= : The class name of the root seeder}
                {--step : Force the migrations to be run so they can be rolled back individually}
                {--graceful : Return a successful exit code even if an error occurs}
                {--explain : Show what would happen without dropping or migrating anything}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh the database while preserving the specified tables.';

    /**
     * The database connection instance.
     *
     * @var \Illuminate\Database\Connection|null
     */
    protected $connection;

    /**
     * The schema grammar instance.
     *
     * @var \Illuminate\Database\Schema\Grammars\Grammar|null
     */
    protected $grammar;

    /**
     * All table names in the target database.
     *
     * @var array<int, string>
     */
    protected array $tables = [];

    /**
     * Migration filenames indexed by table.
     *
     * @var array<string, array<int, string>>
     */
    protected array $migrationsByTable = [];

    /**
     * The tables that own migration files.
     *
     * @var array<int, string>
     */
    protected array $tablesOwningMigrations = [];

    /**
     * Migration scanner used to map files to the tables they touch.
     *
     * @var \Ramadan\CustomFresh\Support\MigrationFileScanner
     */
    protected MigrationFileScanner $scanner;

    /**
     * The list of tables that were dropped.
     *
     * @var array<int, string>
     */
    protected array $lastDroppedCache = [];

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        try {
            return $this->runRefresh();
        } catch (Throwable $e) {
            if (! $this->option('graceful')) {
                throw $e;
            }

            $this->components->error($e->getMessage());

            return self::SUCCESS;
        }
    }

    /**
     * Run the actual refresh workflow once the user has confirmed.
     *
     * @return int
     */
    protected function runRefresh()
    {
        $this->bootResources();

        $requested = $this->resolveRequestedTables();

        if (empty($requested)) {
            $this->components->warn(
                'No tables to preserve were resolved. '
                    . 'Pass tables/patterns via the argument or "--keep=", '
                    . 'set "always_keep"/"patterns" in config/custom-fresh.php, '
                    . 'or use "php artisan migrate:fresh" for a full reset.'
            );

            return self::FAILURE;
        }

        $databaseMap = $this->buildDatabaseMap($requested);

        $migrations = $databaseMap['migrations'];
        $tables     = $databaseMap['tables'];

        if ($this->option('explain')) {
            return $this->explainPlan($tables, $migrations);
        }

        event(new RefreshingDatabase($tables, $migrations, $this->connectionName()));

        $this->components->task('Dropping the tables', function () use ($migrations, $tables) {
            $this->refreshMigrationsTable($migrations);
            $this->dropUnmanagedTables($tables);
        });

        event(new TablesDropped($tables, $this->lastDroppedTables(), $this->connectionName()));

        $this->runMigrateCommand();

        event(new DatabaseRefreshed($tables, $this->connectionName()));

        return self::SUCCESS;
    }

    /**
     * Lazily prepare the database/file-system state needed by the command.
     *
     * @return void
     */
    protected function bootResources()
    {
        $connectionName = $this->connectionName();

        $this->connection = $connectionName
            ? Schema::connection($connectionName)->getConnection()
            : Schema::getConnection();

        $this->grammar = $this->connection->getSchemaGrammar();

        $this->tables = $this->extractTableNames($this->processTables(), 'name');

        $this->scanner = new MigrationFileScanner;

        $this->migrationsByTable = $this->scanner->indexByTable(
            $this->scanner->collect($this->getMigrationPaths())
        );

        $this->tablesOwningMigrations = array_values(array_intersect(
            $this->tables,
            array_keys($this->migrationsByTable)
        ));
    }

    /**
     * Resolve the final list of tables the user wants to keep.
     *
     * @return array<int, string>
     */
    protected function resolveRequestedTables()
    {
        $positional = (string) ($this->argument('tables') ?? '');
        $option     = (string) ($this->option('keep') ?? '');
        $configured = (array) config('custom-fresh.always_keep', []);
        $patterns   = (array) config('custom-fresh.patterns', []);

        $items = array_merge(
            $this->splitList($positional),
            $this->splitList($option),
            array_map('strval', $configured),
            array_map('strval', $patterns)
        );

        $resolved = [];

        foreach (array_unique(array_filter($items)) as $item) {
            if ($this->isGlob($item)) {
                foreach ($this->tables as $table) {
                    if (fnmatch($item, $table)) {
                        $resolved[] = $table;
                    }
                }
                continue;
            }

            $resolved[] = $item;
        }

        return array_values(array_unique($resolved));
    }

    /**
     * Build the preserve plan for tables to keep and migration rows to pre-insert.
     *
     * @param  array<int, string>  $requested
     * @return array{tables: array<int, string>, migrations: array<int, string>}
     */
    protected function buildDatabaseMap(array $requested)
    {
        $map = ['tables' => [], 'migrations' => []];

        foreach ($requested as $table) {
            if (! array_key_exists($table, $this->migrationsByTable)) {
                // Offer ONLY tables that own migration files, so the user
                // cannot accidentally pick something like "sessions" on
                // Laravel v11 (no migration file shipped) -- preserving
                // such a table without inserting a corresponding
                // migrations row would let a later migration re-create it
                // and crash the migrate step with a "table already
                // exists" exception.
                $candidates = array_values(array_diff(
                    $this->tablesOwningMigrations,
                    $map['tables'],
                    ['migrations']
                ));

                if (empty($candidates)) {
                    $this->components->warn("No migration matches table [{$table}]. Skipping.");
                    continue;
                }

                if (! $this->input->isInteractive()) {
                    $this->components->warn("Skipping unknown table [{$table}] (--no-interaction).");
                    continue;
                }

                $table = $this->choice("Choose the correct table instead ({$table})", $candidates);
            }

            $migrations = $this->migrationsByTable[$table] ?? [];

            if (empty($migrations)) {
                continue;
            }

            $map['tables'][]   = $table;
            $map['migrations'] = array_merge($map['migrations'], $migrations);
        }

        $map['tables']     = array_values(array_unique(array_filter($map['tables'])));
        $map['migrations'] = array_values(array_unique(array_filter($map['migrations'])));

        return $map;
    }

    /**
     * Reset the migrations table and pre-insert the rows for every preserved migration.
     *
     * @param  array<int, string>  $migrations
     * @return void
     */
    protected function refreshMigrationsTable(array $migrations)
    {
        $connection = $this->connectionName();

        Schema::connection($connection)->disableForeignKeyConstraints();

        try {
            DB::connection($connection)->table('migrations')->delete();

            if (! empty($migrations)) {
                $records = array_map(static function ($migration) {
                    return [
                        'migration' => pathinfo($migration, PATHINFO_FILENAME),
                        'batch'     => 1,
                    ];
                }, $migrations);

                DB::connection($connection)->table('migrations')->insert($records);
            }
        } finally {
            Schema::connection($connection)->enableForeignKeyConstraints();
        }
    }

    /**
     * Drop every table except the preserved ones (and "migrations").
     *
     * @param  array<int, string>  $keep
     * @return void
     */
    protected function dropUnmanagedTables(array $keep)
    {
        $connection = $this->connectionName();

        $toDrop = array_values(array_diff(
            $this->tables,
            array_merge($keep, ['migrations'])
        ));

        $this->lastDroppedCache = $toDrop;

        Schema::connection($connection)->disableForeignKeyConstraints();

        try {
            foreach ($toDrop as $table) {
                Schema::connection($connection)->dropIfExists($table);
            }
        } finally {
            Schema::connection($connection)->enableForeignKeyConstraints();
        }
    }

    /**
     * Get the list of tables that were dropped.
     *
     * @return array<int, string>
     */
    protected function lastDroppedTables()
    {
        return $this->lastDroppedCache;
    }

    /**
     * Render a human-friendly summary of what would happen, without touching the database.
     *
     * @param  array<int, string>  $keep
     * @param  array<int, string>  $migrations
     * @return int
     */
    protected function explainPlan(array $keep, array $migrations)
    {
        $drop = array_values(array_diff(
            $this->tables,
            array_merge($keep, ['migrations'])
        ));

        $this->components->info('Custom Fresh — dry run (no changes will be applied)');

        $this->components->twoColumnDetail(
            '<fg=cyan>Connection</>',
            $this->connection->getName()
        );
        $this->components->twoColumnDetail(
            '<fg=green>Tables to preserve</>',
            empty($keep) ? '<fg=gray>none</>' : implode(', ', $keep)
        );
        $this->components->twoColumnDetail(
            '<fg=red>Tables to drop</>',
            empty($drop) ? '<fg=gray>none</>' : implode(', ', $drop)
        );
        $this->components->twoColumnDetail(
            '<fg=yellow>Preserved migration rows</>',
            (string) count($migrations)
        );

        if (! empty($migrations)) {
            $this->components->bulletList($migrations);
        }

        return self::SUCCESS;
    }

    /**
     * Resolve the list of migration paths that should be scanned.
     *
     * @return array<int, string>
     */
    protected function getMigrationPaths()
    {
        $paths = [database_path('migrations')];

        foreach ((array) $this->option('path') as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            $paths[] = $this->option('realpath') ? $path : base_path($path);
        }

        if ($this->getLaravel()->bound('migrator')) {
            $migrator = $this->getLaravel()->make('migrator');

            if (method_exists($migrator, 'paths')) {
                $paths = array_merge($paths, $migrator->paths());
            }
        }

        return array_values(array_unique(array_filter($paths)));
    }

    /**
     * Split a comma-separated string into a clean list of items.
     *
     * @param  string  $value
     * @return array<int, string>
     */
    protected function splitList(string $value)
    {
        return array_values(array_filter(array_map(
            static fn($item) => trim((string) $item),
            explode(',', $value)
        ), static fn($item) => $item !== ''));
    }

    /**
     * Determine whether the given pattern should be matched as a glob.
     *
     * @param  string  $value
     * @return bool
     */
    protected function isGlob(string $value)
    {
        return (bool) preg_match('/[\\*\\?\\[]/', $value);
    }

    /**
     * Get the resolved connection name, or null for the default connection.
     *
     * @return string|null
     */
    protected function connectionName()
    {
        $name = $this->option('database');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Process the results of a tables query.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function processTables()
    {
        return $this->connection->getPostProcessor()->processTables(
            $this->connection->selectFromWriteConnection(
                $this->grammar->compileTables($this->connection->getDatabaseName())
            )
        );
    }

    /**
     * Extract the table names from a result set using the given column key.
     *
     * @param  array<int, array<string, mixed>>  $tables
     * @param  string|int  $columnKey
     * @return array<int, string>
     */
    protected function extractTableNames(array $tables, string|int $columnKey = 0)
    {
        return array_column($tables, $columnKey);
    }

    /**
     * Run the "migrate" command with the options passed through to it.
     *
     * @return void
     */
    protected function runMigrateCommand()
    {
        $arguments = [
            '--force'       => true,
            '--path'        => $this->option('path'),
            '--realpath'    => $this->option('realpath'),
            '--schema-path' => $this->option('schema-path'),
            '--pretend'     => $this->option('pretend'),
            '--seed'        => $this->option('seed'),
            '--seeder'      => $this->option('seeder'),
            '--step'        => $this->option('step'),
        ];

        if ($connection = $this->connectionName()) {
            $arguments['--database'] = $connection;
        }

        $this->call('migrate', $arguments);
    }
}
