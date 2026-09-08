<?php

namespace Ramadan\CustomFresh\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Ramadan\CustomFresh\Console\Confirmable;
use Ramadan\CustomFresh\Events\DatabaseRefreshed;
use Ramadan\CustomFresh\Events\RefreshingDatabase;
use Ramadan\CustomFresh\Events\TablesDropped;
use Ramadan\CustomFresh\Support\ConfigResolver;
use Ramadan\CustomFresh\Support\ForeignKeyAdvisor;
use Ramadan\CustomFresh\Support\ListUtil;
use Ramadan\CustomFresh\Support\MigrationFileScanner;
use Ramadan\CustomFresh\Support\RefreshPlan;
use Ramadan\CustomFresh\Support\RefreshPlanBuilder;
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
                {--keep-raw= : Preserve tables even when they have no migration files}
                {--except= : Tables/patterns to drop even if they appear in always_keep or --keep}
                {--drop= : Drop only these tables (everything else is preserved)}
                {--preset= : Named preset(s) from config/custom-fresh.php}
                {--freeze-schema : Mark every migration for kept tables as run (skip pending alters)}
                {--with-related : Also preserve tables linked by foreign keys}
                {--explain : Show what would happen without dropping or migrating anything}
                {--list : List discovered tables and the migration files that touch them}
                {--json : Output --explain / --list as JSON}
                {--seed-fresh : Seed only dropped tables using config "table_seeders"}
                {--drop-views : Drop all views during the refresh}
                {--drop-types : Drop all types during the refresh (PostgreSQL)}
                {--database= : The database connection to use}
                {--force : Force the operation to run when in production}
                {--path=* : The path(s) to the migrations files to be executed}
                {--realpath : Indicate any provided migration file paths are pre-resolved absolute paths}
                {--schema-path= : The path to a schema dump file}
                {--pretend : Dump the SQL queries that would be run}
                {--seed : Indicates if the seed task should be re-run}
                {--seeder= : The class name of the root seeder}
                {--step : Force the migrations to be run so they can be rolled back individually}
                {--graceful : Return a successful exit code even if an error occurs}';

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
     * Config resolver for the current connection.
     *
     * @var \Ramadan\CustomFresh\Support\ConfigResolver
     */
    protected ConfigResolver $configResolver;

    /**
     * The resolved refresh plan.
     *
     * @var \Ramadan\CustomFresh\Support\RefreshPlan|null
     */
    protected ?RefreshPlan $plan = null;

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        try {
            $this->bootResources();

            if ($this->option('list')) {
                return $this->renderList();
            }

            if (! $this->option('explain') && ! $this->confirmToProceed()) {
                return self::FAILURE;
            }

            return $this->runRefresh();
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return $this->option('graceful') ? self::SUCCESS : self::FAILURE;
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
        // The plan also keeps tables that share a Schema::create migration
        // with a preserved table (e.g. users + sessions) so later foreign
        // keys are not left pointing at a dropped sibling.
        $this->plan = $this->makePlanBuilder()->build($this->planInput());

        if ($this->plan->isEmpty()) {
            $this->components->warn(
                'No tables to preserve or drop were resolved. '
                    . 'Pass tables via the argument, "--keep=", "--drop=", or "--preset=", '
                    . 'set "always_keep"/"patterns" in config/custom-fresh.php, '
                    . 'or use "php artisan migrate:fresh" for a full reset.'
            );

            return self::FAILURE;
        }

        if ($this->option('explain')) {
            return $this->explainPlan($this->plan);
        }

        $this->renderMessages($this->plan);

        if ($this->option('seed') && ! $this->option('seed-fresh') && ! empty($this->plan->preserved)) {
            $this->components->warn(
                'Preserved tables still contain data. DatabaseSeeder may insert duplicates. '
                    . 'Use "--seed-fresh" with config "table_seeders", or make seeders idempotent.'
            );
        }

        $connectionName = $this->getConnectionName();
        $databaseName   = $this->getDatabaseName();
        $migrations     = $this->plan->migrations;
        $tables         = $this->plan->preserved;

        event(new RefreshingDatabase($connectionName, $databaseName, $migrations, $tables));

        $this->dropViewsAndTypes(true);

        $this->components->task('Dropping the tables', function () use ($migrations, $tables) {
            $this->refreshMigrationsTable($migrations);
            $this->dropUnmanagedTables($tables);
        });

        $this->dropViewsAndTypes(false);

        event(new TablesDropped($connectionName, $databaseName, $tables, $this->plan->dropped));

        $this->runMigrateCommand();

        $this->seedDroppedTables();

        event(new DatabaseRefreshed($connectionName, $databaseName, $tables));

        return self::SUCCESS;
    }

    /**
     * Lazily prepare the database/file-system state needed by the command.
     *
     * @return void
     */
    protected function bootResources()
    {
        $requested = $this->option('database');

        $this->connection = $requested
            ? Schema::connection($requested)->getConnection()
            : Schema::getConnection();

        $this->grammar = $this->connection->getSchemaGrammar();

        $this->configResolver = new ConfigResolver($this->getConnectionName());

        $this->tables = $this->extractTableNames($this->getTables(), 'name');

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
     * Create a plan builder for the booted connection.
     *
     * @return \Ramadan\CustomFresh\Support\RefreshPlanBuilder
     */
    protected function makePlanBuilder()
    {
        return new RefreshPlanBuilder(
            $this->scanner,
            $this->configResolver,
            new ForeignKeyAdvisor($this->getConnectionName()),
            $this->tables,
            $this->migrationsByTable,
            $this->appliedMigrationNames()
        );
    }

    /**
     * Collect CLI input for the plan builder.
     *
     * @return array<string, mixed>
     */
    protected function planInput()
    {
        return [
            'keep'            => array_merge(
                ListUtil::split((string) ($this->argument('tables') ?? '')),
                ListUtil::split((string) ($this->option('keep') ?? ''))
            ),
            'keepRaw'         => ListUtil::split((string) ($this->option('keep-raw') ?? '')),
            'except'          => ListUtil::split((string) ($this->option('except') ?? '')),
            'drop'            => ListUtil::split((string) ($this->option('drop') ?? '')),
            'presets'         => ListUtil::split((string) ($this->option('preset') ?? '')),
            'freezeSchema'    => (bool) $this->option('freeze-schema'),
            'withRelated'     => (bool) $this->option('with-related'),
            'interactive'     => $this->input->isInteractive(),
            'pickTables'      => fn(array $candidates) => $this->promptTablesToKeep($candidates),
            'pickReplacement' => fn(string $invalid, array $candidates) => $this->choice(
                "Choose the correct table instead ({$invalid})",
                $candidates
            ),
        ];
    }

    /**
     * Ask which tables should be preserved when nothing was specified.
     *
     * @param  array<int, string>  $candidates
     * @return array<int, string>
     */
    protected function promptTablesToKeep(array $candidates)
    {
        if (! $this->input->isInteractive() || empty($candidates)) {
            return [];
        }

        if (function_exists('\Laravel\Prompts\multiselect')) {
            return array_values(\Laravel\Prompts\multiselect(
                label: 'Which tables should be preserved?',
                options: array_combine($candidates, $candidates),
                hint: 'Space to select, Enter to confirm.',
                required: false,
            ));
        }

        $selected = $this->choice(
            'Which tables should be preserved? (comma-separated indices if prompted)',
            $candidates,
            multiple: true
        );

        return array_values((array) $selected);
    }

    /**
     * Reset the migrations table and pre-insert the rows for every preserved migration.
     *
     * @param  array<int, string>  $migrations
     * @return void
     */
    protected function refreshMigrationsTable(array $migrations)
    {
        $connection = $this->getConnectionName();

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
        $connection = $this->getConnectionName();

        $toDrop = $this->plan?->dropped ?? array_values(array_diff(
            $this->tables,
            array_merge($keep, ['migrations'])
        ));

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
     * Drop views before tables, and types after tables, when requested.
     *
     * @param  bool  $views
     * @return void
     */
    protected function dropViewsAndTypes(bool $views)
    {
        $connection = $this->getConnectionName();
        $schema     = Schema::connection($connection);
        $driver     = $this->connection->getDriverName();

        if ($views && $this->option('drop-views') && method_exists($schema, 'dropAllViews')) {
            $this->components->task('Dropping views', fn() => $schema->dropAllViews());
        }

        if (! $views && $this->option('drop-types') && method_exists($schema, 'dropAllTypes')) {
            if (in_array($driver, ['pgsql', 'postgres'], true)) {
                $this->components->task('Dropping types', fn() => $schema->dropAllTypes());
            } else {
                $this->components->warn("The \"--drop-types\" option is not supported by the [{$driver}] driver.");
            }
        }
    }

    /**
     * Seed dropped tables using the configured table seeder map.
     *
     * @return void
     */
    protected function seedDroppedTables()
    {
        if (! $this->option('seed-fresh')) {
            return;
        }

        $map     = (array) $this->configResolver->get('table_seeders', []);
        $dropped = $this->plan?->dropped ?? [];
        $ran     = 0;

        foreach ($dropped as $table) {
            if (! isset($map[$table]) || ! is_string($map[$table]) || $map[$table] === '') {
                continue;
            }

            $this->call('db:seed', [
                '--class'    => $map[$table],
                '--force'    => true,
                '--database' => $this->getConnectionName(),
            ]);

            $ran++;
        }

        if ($ran === 0) {
            $this->components->warn(
                'No "table_seeders" matched the dropped tables. '
                    . 'Add mappings in config/custom-fresh.php.'
            );
        }
    }

    /**
     * Render a human-friendly or JSON summary of what would happen.
     *
     * @param  \Ramadan\CustomFresh\Support\RefreshPlan  $plan
     * @return int
     */
    protected function explainPlan(RefreshPlan $plan)
    {
        if ($this->option('json')) {
            $this->output->writeln(json_encode(
                $plan->toArray($this->getConnectionName(), $this->getDatabaseName()),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ));

            return self::SUCCESS;
        }

        $this->components->info('Custom Fresh — dry run (no changes will be applied)');

        $this->components->twoColumnDetail(
            '<fg=cyan>Connection</>',
            $this->getConnectionName()
        );
        $this->components->twoColumnDetail(
            '<fg=cyan>Database</>',
            $this->getDatabaseName()
        );
        $this->components->twoColumnDetail(
            '<fg=green>Tables to preserve</>',
            empty($plan->preserved) ? '<fg=gray>none</>' : implode(', ', $plan->preserved)
        );
        $this->components->twoColumnDetail(
            '<fg=red>Tables to drop</>',
            empty($plan->dropped) ? '<fg=gray>none</>' : implode(', ', $plan->dropped)
        );
        $this->components->twoColumnDetail(
            '<fg=yellow>Preserved migration rows</>',
            (string) count($plan->migrations)
        );
        $this->components->twoColumnDetail(
            '<fg=magenta>Pending alters on kept tables</>',
            empty($plan->pendingAlters) ? '<fg=gray>none</>' : (string) count($plan->pendingAlters)
        );

        if (! empty($plan->migrations)) {
            $this->components->bulletList($plan->migrations);
        }

        if (! empty($plan->pendingAlters)) {
            $this->newLine();
            $this->components->info('These migrations still touch kept tables and will run:');
            $this->components->bulletList($plan->pendingAlters);
        }

        $this->renderMessages($plan);

        return self::SUCCESS;
    }

    /**
     * Print the table-to-migration map.
     *
     * @return int
     */
    protected function renderList()
    {
        $map = [];

        foreach ($this->tables as $table) {
            if ($table === 'migrations') {
                continue;
            }

            $map[$table] = $this->migrationsByTable[$table] ?? [];
        }

        ksort($map);

        if ($this->option('json')) {
            $this->output->writeln(json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info('Custom Fresh — discovered tables');

        foreach ($map as $table => $migrations) {
            $this->components->twoColumnDetail(
                $table,
                empty($migrations) ? '<fg=gray>no migration</>' : implode(', ', $migrations)
            );
        }

        return self::SUCCESS;
    }

    /**
     * Print plan notes and warnings.
     *
     * @param  \Ramadan\CustomFresh\Support\RefreshPlan  $plan
     * @return void
     */
    protected function renderMessages(RefreshPlan $plan)
    {
        foreach ($plan->notes as $note) {
            $this->components->info($note);
        }

        foreach ($plan->warnings as $warning) {
            $this->components->warn($warning);
        }
    }

    /**
     * Already-applied migration names from the migrations table.
     *
     * @return array<int, string>
     */
    protected function appliedMigrationNames()
    {
        $connection = $this->getConnectionName();

        if (! Schema::connection($connection)->hasTable('migrations')) {
            return [];
        }

        return DB::connection($connection)
            ->table('migrations')
            ->pluck('migration')
            ->map(static fn($name) => (string) $name)
            ->all();
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
     * Get the resolved connection name.
     *
     * @return string
     */
    protected function getConnectionName()
    {
        return $this->connection->getName();
    }

    /**
     * Get the resolved database name.
     *
     * @return string
     */
    protected function getDatabaseName()
    {
        return $this->connection->getDatabaseName();
    }

    /**
     * Retrieve all tables from the connection.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getTables()
    {
        $schema = Schema::connection($this->getConnectionName());

        if (version_compare($this->laravel->version(), '12.0.0', '>=')) {
            return $schema->getTables($schema->getCurrentSchemaListing());
        }

        return $schema->getTables();
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
            '--database'    => $this->getConnectionName(),
            '--force'       => true,
            '--path'        => $this->option('path'),
            '--realpath'    => $this->option('realpath'),
            '--schema-path' => $this->option('schema-path'),
            '--pretend'     => $this->option('pretend'),
            '--seed'        => $this->option('seed') && ! $this->option('seed-fresh'),
            '--seeder'      => $this->option('seeder'),
            '--step'        => $this->option('step'),
        ];

        $this->call('migrate', $arguments);
    }
}
