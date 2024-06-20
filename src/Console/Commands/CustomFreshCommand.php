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

        $migrations = $this->getMigrations(
            explode(",", $this->argument("table"))
        );

        $this->components->info('Preparing database.');

        $this->components->task('Dropping the tables', $this->dropTables(
            $migrations["tableNames"],
            $migrations["migrationNames"]
        ));

        $this->call('migrate', ['--force' => true]);

        return 0;
    }

    /**
     * Get the listed tables in the database.
     * 
     * @return array
     */
    protected function getTables()
    {
        return DB::connection()->getDoctrineSchemaManager()->listTableNames();
    }

    /**
     * Get the correct table names with their migration.
     *
     * @param  array  $tablesToBeDropped
     * @return array
     */
    protected function getMigrations(array $tablesToBeDropped)
    {
        $migrationsPath = database_path('migrations');

        // At first, we will filter the given array of tables, and then iterate over
        // each one to check if the passed table has a migration then we will check if
        // the `tableNames` has been set by the `getMigrationsInfo` method or not
        // because if it is set, it means the table is there
        // or we will ask the developer to choose the correct table instead.
        foreach (array_filter($tablesToBeDropped) as $index => $table) {
            $migrationsInfo = $this->getMigrationsInfo($migrationsPath, $table);

            if (empty($migrationsInfo["tableNames"][$index])) {
                $value = $this->choice(
                    "Choose the correct table instead ({$table})",
                    array_diff(
                        $this->getTables(),
                        array_merge($migrationsInfo["tableNames"], ["migrations"])
                    )
                );

                $migrationsInfo = $this->getMigrationsInfo($migrationsPath, $value);
            }
        }

        return $migrationsInfo;
    }

    /**
     * Get the migrations that match the given table.
     *
     * @param  string  $migrationsPath
     * @param  string  $table
     * @return array
     */
    protected function getMigrationsInfo(string $migrationsPath, string $table)
    {
        $migrationsInfo = ["migrationNames" => [], "tableNames" => []];

        if (!empty($migrationName = glob("{$migrationsPath}/*_create_{$table}_table.php"))) {
            array_push($migrationsInfo["tableNames"], $table);
            array_push($migrationsInfo["migrationNames"], basename($migrationName[0]));
        } elseif (!empty($migrationName = glob("{$migrationsPath}/*_create_{$table}.php"))) {
            array_push($migrationsInfo["tableNames"], $table);
            array_push($migrationsInfo["migrationNames"], basename($migrationName[0]));
        }

        if (!empty($migrationName = glob("{$migrationsPath}/*_to_{$table}_table.php"))) {
            array_push($migrationsInfo["migrationNames"], basename($migrationName[0]));
        }

        if (!empty($migrationName = glob("{$migrationsPath}/*_from_{$table}_table.php"))) {
            array_push($migrationsInfo["migrationNames"], basename($migrationName[0]));
        }

        if (!empty($migrationName = glob("{$migrationsPath}/*_in_{$table}_table.php"))) {
            array_push($migrationsInfo["migrationNames"], basename($migrationName[0]));
        }

        return $migrationsInfo;
    }

    /**
     * Drop all tables except the given array of table names.
     *
     * @param  array  $tableNames
     * @param  array  $migrationNames
     * @return void
     */
    protected function dropTables(array $tableNames, array $migrationNames)
    {
        DB::table("migrations")->truncate();

        foreach ($migrationNames as $migration) {
            DB::table("migrations")
                ->insert(["migration" => substr_replace($migration, "", -4), "batch" => 1]);
        }

        $droppedTables = array_diff(
            $this->getTables(),
            array_merge(array_filter($tableNames), ["migrations"])
        );

        Schema::disableForeignKeyConstraints();

        foreach ($droppedTables as $table) {
            Schema::dropIfExists($table);
        }
    }
}
