<?php

namespace Ramadan\CustomFresh\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramadan\CustomFresh\Console\Confirmable;
use Throwable;

class CustomFreshCommand extends Command
{
    use Confirmable;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fresh:custom {table : The table(s) that you do not want to fresh}
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
    protected $description = 'Create exceptions for the given table names while refreshing the database';

    /**
     * The database connection instance.
     *
     * @var \Illuminate\Database\Connection
     */
    protected $connection;

    /**
     * The schema grammar instance.
     *
     * @var \Illuminate\Database\Schema\Grammars\Grammar
     */
    protected $grammar;

    /**
     * The database tables.
     *
     * @var array
     */
    protected $tables;

    /**
     * The database tables that own migration files.
     *
     * @var array
     */
    protected $tablesOwningMigrations;

    /**
     * Create a new custom fresh command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->connection = Schema::getConnection();
        $this->grammar    = $this->connection->getSchemaGrammar();

        $this->tables = $this->extractTableNames($this->processTables(), "name");

        // We will only show the tables owning migration files, to fade away the issue of skip-dropping a
        // table and re-migrating it within a specific migration file, which leads to throwing an exception
        // For instance, the "sessions" table does not have its migration file in Laravel v11.
        $this->tablesOwningMigrations = $this->filterTablesOwningMigrations($this->getTables());
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if (!$this->confirmToProceed()) {
            return 1;
        }

        $databaseMap = $this->getDatabaseMap(explode(",", $this->argument("table")));

        $migrations = array_filter($this->flattenMigrations($databaseMap["migrations"]));
        $tables     = array_filter($this->extractTableNames($databaseMap["tables"]));

        $this->components->task('Dropping the tables', function () use ($migrations, $tables) {
            $this->truncateMigrationsTable();
            $this->insertMigrations($migrations);
            $this->dropUnmanagedTables($tables);
        });

        if ($this->laravel->version() < '11') {
            try {
                $this->runMigrateCommand();
            } catch (Throwable $e) {
                if ($this->option('graceful')) {
                    $this->components->warn($e->getMessage());

                    return 0;
                }

                throw $e;
            }
        } else {
            $this->runMigrateCommand();
        }

        return 0;
    }

    /**
     * Get the migrations with their correct table names.
     *
     * @param  array  $tablesNeededToDrop
     * @return array
     */
    protected function getDatabaseMap(array $tablesNeededToDrop)
    {
        // At first, we will filter the given array of tables to go through each one
        // verifying that it has a migration. Then, we will check if the "tables" key
        // has been set by the "guessTableMigrations" method because if it is not set,
        // we will ask the developer to choose the correct table instead.
        foreach (array_filter($tablesNeededToDrop) as $index => $table) {
            $migrationsMap = $this->guessTableMigrations($table);

            $databaseMap["migrations"][] = array_values($migrationsMap["migrations"]);
            $databaseMap["tables"][]     = array_values($migrationsMap["tables"]);

            if (empty($databaseMap["tables"][$index])) {
                $value = $this->choice(
                    "Choose the correct table instead ({$table})",
                    array_diff(
                        $this->getTablesOwningMigrations(),
                        array_merge($this->extractTableNames($databaseMap["tables"]), ["migrations"])
                    )
                );

                // We will re-invoke the method to update the invalid database details
                $migrationsMap = $this->guessTableMigrations($value);

                $databaseMap["migrations"][$index] = array_values($migrationsMap["migrations"]);
                $databaseMap["tables"][$index]     = array_values($migrationsMap["tables"]);
            }
        }

        return $databaseMap;
    }

    /**
     * Guess the migrations of the given table name.
     *
     * @param  string  $table
     * @return array
     */
    protected function guessTableMigrations(string $table)
    {
        $migrationsPath = database_path('migrations');
        $migrationsMap  = ["migrations" => [], "tables" => []];

        if (
            !empty($migration = glob("{$migrationsPath}/*_create_{$table}_table.php")) ||
            !empty($migration = glob("{$migrationsPath}/*_create_{$table}.php"))
        ) {
            array_push($migrationsMap["tables"], $table);
            array_push($migrationsMap["migrations"], basename($migration[0]));
        }

        if (
            !empty($migration = glob("{$migrationsPath}/*_{to,from,in}_{$table}_table.php", GLOB_BRACE)) ||
            !empty($migration = glob("{$migrationsPath}/*_{to,from,in}_{$table}.php", GLOB_BRACE))
        ) {
            array_push($migrationsMap["migrations"], basename($migration[0]));
        }

        return $migrationsMap;
    }

    /**
     * Truncate the "migrations" table.
     *
     * @return void
     */
    protected function truncateMigrationsTable()
    {
        DB::table("migrations")->truncate();
    }

    /**
     * Insert the given migrations into the "migrations" table.
     *
     * @param  array  $migrations
     * @return void
     */
    public function insertMigrations(array $migrations)
    {
        $migrationRecords = array_map(function ($migration) {
            return ["migration" => substr($migration, 0, -4), "batch" => 1];
        }, $migrations);

        DB::table("migrations")->insert($migrationRecords);
    }

    /**
     * Drop all tables except the given array of table names.
     *
     * @param  array  $tables
     * @return void
     */
    protected function dropUnmanagedTables(array $tables)
    {
        // We will get all database tables including the ones that do not own migration files,
        // to eliminate the issue of migrating a table that already exists in the database.
        $tablesShouldBeDropped = array_diff($this->getTables(), array_merge($tables, ["migrations"]));

        Schema::disableForeignKeyConstraints();
        foreach ($tablesShouldBeDropped as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
    }

    /**
     * Process the results of a tables query.
     *
     * @return array
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
     * Get all listed database tables.
     *
     * @return array
     */
    protected function getTables()
    {
        return $this->tables;
    }

    /**
     * Get the database tables that own migration files.
     *
     * @return array
     */
    protected function getTablesOwningMigrations()
    {
        return $this->tablesOwningMigrations;
    }

    /**
     * Extract the table names from the array using the given column key.
     *
     * @param  array  $tables
     * @param  string|int  $columnKey
     * @return array
     */
    protected function extractTableNames(array $tables, string|int $columnKey = 0)
    {
        return array_column($tables, $columnKey);
    }

    /**
     * Flatten the migrations.
     *
     * @param  array  $migrations
     * @return array
     */
    protected function flattenMigrations(array $migrations)
    {
        return array_reduce($migrations, function ($carry, $migration) {
            if (is_array($migration)) {
                return array_merge($carry, $migration);
            }

            return array_merge($carry, [$migration]);
        }, []);
    }

    /**
     * Filter the given array of tables to only those that own migration files.
     *
     * @param  array  $tables
     * @return array
     */
    protected function filterTablesOwningMigrations(array $tables)
    {
        return array_values(array_filter($tables, function ($table) {
            return !empty($this->guessTableMigrations($table)["migrations"]);
        }));
    }

    /**
     * Run the "migrate" command.
     *
     * @return void
     */
    protected function runMigrateCommand()
    {
        $options = [
            '--force'       => true,
            '--path'        => $this->option('path'),
            '--realpath'    => $this->option('realpath'),
            '--schema-path' => $this->option('schema-path'),
            '--pretend'     => $this->option('pretend'),
            '--seed'        => $this->option('seed'),
            '--seeder'      => $this->option('seeder'),
            '--step'        => $this->option('step'),
        ];

        $this->call('migrate', $this->laravel->version() < '11' ? $options : array_merge($options, ['--graceful' => true]));
    }
}
