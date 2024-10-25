<?php

namespace Ramadan\CustomFresh\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramadan\CustomFresh\Console\Confirmable;

class CustomFreshCommand extends Command
{
    use Confirmable;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fresh:custom {table : The table(s) that you do not want to fresh}
                {--force : Force the operation to run when in production}';

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
    protected $tablesOwnMigration;

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

        $this->tables = array_column($this->processTables(), "name");

        // We will check for the existence of migrations to only show the tables that own migration files
        // fading away the issue of skip dropping a table and re-migrate it which leads to throwing an exception
        // For instance, the "sessions" table does not have a migration in Laravel v.11.
        $this->tablesOwnMigration = $this->tablesOwnMigration($this->getTables());
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

        $database = $this->getMigrationsWithTableNames(explode(",", $this->argument("table")));

        $this->components->task('Dropping the tables', $this->dropTables(
            array_filter($this->collectMigrations($database)),
            array_filter($this->collectTables($database))
        ));

        $this->call('migrate', ['--force' => true]);

        return 0;
    }

    /**
     * Get the migrations with their correct table names.
     *
     * @param  array  $tablesNeededToDrop
     * @return array
     */
    protected function getMigrationsWithTableNames(array $tablesNeededToDrop)
    {
        // At first, we will filter the given array of tables to go through each one
        // verifying that it has a migration. Then, we will check if the "tables" key
        // has been set by the "getDatabaseMapping" method because if it is not set,
        // we will ask the developer to choose the correct table instead.
        foreach (array_filter($tablesNeededToDrop) as $index => $table) {
            $mapping = $this->getDatabaseMapping($table);

            $database["migrations"][] = array_values($mapping["migrations"]);
            $database["tables"][]     = array_values($mapping["tables"]);

            if (empty($database["tables"][$index])) {
                $value = $this->choice(
                    "Choose the correct table instead ({$table})",
                    array_diff(
                        $this->getTablesOwnMigration(),
                        array_merge($this->collectTables($database), ["migrations"])
                    )
                );

                // We will re-invoke the method to update the invalid database details.
                $mapping = $this->getDatabaseMapping($value);

                $database["migrations"][$index] = array_values($mapping["migrations"]);
                $database["tables"][$index]     = array_values($mapping["tables"]);
            }
        }

        return $database;
    }

    /**
     * Get a database map of the given table name and its migrations.
     *
     * @param  string  $table
     * @return array
     */
    protected function getDatabaseMapping(string $table)
    {
        $migrationsPath = database_path('migrations');
        $database       = ["migrations" => [], "tables" => []];

        if (
            !empty($migration = glob("{$migrationsPath}/*_create_{$table}_table.php")) ||
            !empty($migration = glob("{$migrationsPath}/*_create_{$table}.php"))
        ) {
            array_push($database["tables"], $table);
            array_push($database["migrations"], basename($migration[0]));
        }

        if (
            !empty($migration = glob("{$migrationsPath}/*_{to,from,in}_{$table}_table.php", GLOB_BRACE)) ||
            !empty($migration = glob("{$migrationsPath}/*_{to,from,in}_{$table}.php", GLOB_BRACE))
        ) {
            array_push($database["migrations"], basename($migration[0]));
        }

        return $database;
    }

    /**
     * Get a final database map of the given table when it does not have its migration.
     * For instance, the "sessions" table is migrated within the "users" migration file in Laravel v11.
     *
     * @param  array  $database
     * @param  string  $table
     * @return array
     */
    protected function finalizeDatabaseMapping(array $database, string $table)
    {
        if (!empty($database["migrations"]) && !empty($database["tables"])) {
            return $database;
        }

        array_push($database["migrations"], null);
        array_push($database["tables"], $table);

        return $database;
    }

    /**
     * Drop all tables except the given array of table names.
     *
     * @param  array  $migrations
     * @param  array  $tables
     * @return void
     */
    protected function dropTables(array $migrations, array $tables)
    {
        // After we have mapped the correct table names with their migrations, we will
        // truncate the "migrations" table and then, insert the migrations that should not
        // be dropped to not migrate these tables.
        DB::table("migrations")->truncate();

        foreach ($migrations as $migration) {
            DB::table("migrations")
                ->insert(["migration" => substr_replace($migration, "", -4), "batch" => 1]);
        }

        $tablesShouldBeDropped = array_diff(
            $this->getTables(),
            array_merge($tables, ["migrations"])
        );

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
    protected function getTablesOwnMigration()
    {
        return $this->tablesOwnMigration;
    }

    /**
     * Get the listed tables that should not be dropped.
     *
     * @param  array  $database
     * @return array
     */
    protected function collectTables(array $database)
    {
        return array_column($database["tables"], 0);
    }

    /**
     * Get the listed migrations that should not be dropped.
     *
     * @param  array  $database
     * @return array
     */
    protected function collectMigrations(array $database)
    {
        return array_reduce($database["migrations"], function ($carry, $migration) {
            if (is_array($migration)) {
                return array_merge($carry, $migration);
            }

            return array_merge($carry, [$migration]);
        }, []);
    }

    /**
     * Filter the given array of tables to only those that own migrations.
     *
     * @param  array  $tables
     * @return array
     */
    protected function tablesOwnMigration(array $tables)
    {
        return array_values(array_filter($tables, function ($table) {
            return !empty($this->getDatabaseMapping($table)["migrations"]);
        }));
    }
}
