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
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if (!$this->confirmToProceed()) {
            return 1;
        }

        $this->components->task(
            'Migrating your database schema',
            fn () => $this->call('migrate', ['--force' => true])
        );

        $database = $this->getDatabaseInfo(explode(",", $this->argument("table")));

        $this->components->info('Preparing database.');

        $this->components->task('Dropping the tables', $this->dropTables(
            $this->collectMigrations($database),
            $this->collectTables($database)
        ));

        $this->call('migrate', ['--force' => true]);

        return 0;
    }

    /**
     * Get the correct table names with their migrations.
     *
     * @param  array  $tablesNeededToDrop
     * @return array
     */
    protected function getDatabaseInfo(array $tablesNeededToDrop)
    {
        // At first, we will filter the given array of tables to go through each one
        // verifying that the passed table has a migration and then, we will check if
        // the `tables` key has been set by the `guessDatabaseInfo` method or not
        // because if it is set, it means the table is there
        // or we will ask the developer to choose the correct table instead.
        foreach (array_filter($tablesNeededToDrop) as $index => $table) {
            $info = $this->guessDatabaseInfo($table);

            $database["migrations"][] = array_values($info["migrations"]);
            $database["tables"][]     = array_values($info["tables"]);

            if (empty($database["tables"][$index])) {
                $value = $this->choice(
                    "Choose the correct table instead ({$table})",
                    array_diff(
                        $this->getTables(),
                        array_merge($this->collectTables($database), ["migrations"])
                    )
                );

                // In case the table is not there, we will recall the method again
                // to update the invalid database info.
                $info = $this->guessDatabaseInfo($value);

                $database["migrations"][$index] = array_values($info["migrations"]);
                $database["tables"][$index]     = array_values($info["tables"]);
            }
        }

        return $database;
    }

    /**
     * Try to guess the database info based on the table name.
     *
     * @param  string  $table
     * @return array
     */
    protected function guessDatabaseInfo(string $table)
    {
        $migrationsPath = database_path('migrations');
        $database       = ["migrations" => [], "tables" => []];

        if (!empty($migration = glob("{$migrationsPath}/*_create_{$table}_table.php"))) {
            array_push($database["tables"], $table);
            array_push($database["migrations"], basename($migration[0]));
        } elseif (!empty($migration = glob("{$migrationsPath}/*_create_{$table}.php"))) {
            array_push($database["tables"], $table);
            array_push($database["migrations"], basename($migration[0]));
        }

        if (!empty($migration = glob("{$migrationsPath}/*_{to,from,in}_{$table}_table.php", GLOB_BRACE))) {
            array_push($database["migrations"], basename($migration[0]));
        } elseif (!empty($migration = glob("{$migrationsPath}/*_{to,from,in}_{$table}.php", GLOB_BRACE))) {
            array_push($database["migrations"], basename($migration[0]));
        }

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
        // After we have guessed the correct table names with their migrations, we will
        // truncate the `migrations` table and then, insert the migrations that should not
        // be dropped to not migrate these tables.
        DB::table("migrations")->truncate();

        foreach ($migrations as $migration) {
            DB::table("migrations")
                ->insert(["migration" => substr_replace($migration, "", -4), "batch" => 1]);
        }

        $tablesShouldBeDropped = array_diff(
            $this->getTables(),
            array_merge(array_filter($tables), ["migrations"])
        );

        Schema::disableForeignKeyConstraints();

        foreach ($tablesShouldBeDropped as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * Get all listed tables in the database.
     *
     * @return array
     */
    protected function getTables()
    {
        return DB::connection()->getDoctrineSchemaManager()->listTableNames();
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
}
