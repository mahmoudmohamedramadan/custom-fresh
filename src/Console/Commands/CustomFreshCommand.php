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

        // At first, we will check if the database does not contain any table
        // then run the `migrate` command.
        if (empty($this->getTables())) {
            $this->components->task(
                'Migrating your database schema',
                fn () => $this->call('migrate', ['--force' => true])
            );
        }

        $migrationsInfo = $this->getMigrationsInfo(
            explode(",", $this->argument("table"))
        );

        $this->components->info('Preparing database.');

        $this->components->task('Dropping the tables', $this->dropTables(
            $migrationsInfo["tableNames"],
            $migrationsInfo["migrationNames"]
        ));

        $this->call('migrate', ['--force' => true]);

        return 0;
    }

    /**
     * Get an array of all the tables in the database.
     * 
     * @return array
     */
    protected function getTables()
    {
        return DB::connection()->getDoctrineSchemaManager()->listTableNames();
    }

    /**
     * Get an array of the migration file names and correct table names.
     *
     * @param  array  $tablesToBeDropped
     * @return array
     */
    protected function getMigrationsInfo(array $tablesToBeDropped)
    {
        $migrationsPath = database_path('migrations');
        $migrationsInfo = ["migrationNames" => [], "tableNames" => []];

        // In addition, we will filter the given array of tables, and then iterate over
        // each one and check if the passed table exists to get its relevant migrations.
        foreach (array_filter($tablesToBeDropped) as $index => $table) {
            $migrationsInfo = $this->checkMigrationFileExistence(
                $migrationsPath,
                $table,
                $migrationsInfo
            );

            if (empty($migrationsInfo["tableNames"][$index])) {
                $choiceValue = $this->choice(
                    "Choose the correct table instead ({$table})",
                    array_diff(
                        $this->getTables(),
                        array_merge($migrationsInfo["tableNames"], ["migrations"])
                    )
                );

                $migrationsInfo = $this->checkMigrationFileExistence(
                    $migrationsPath,
                    $choiceValue,
                    $migrationsInfo
                );
            }
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

        $droppedTables = array_map("current", DB::select("SHOW TABLES"));
        $droppedTables = array_diff(
            $droppedTables,
            array_merge(array_filter($tableNames), ["migrations"])
        );

        Schema::disableForeignKeyConstraints();

        foreach ($droppedTables as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * Check if the given migration file exists.
     *
     * @param  string  $migrationsPath
     * @param  string  $table
     * @param  array   $migrationsInfo
     * @return array
     */
    protected function checkMigrationFileExistence(
        string $migrationsPath,
        string $table,
        array $migrationsInfo
    ) {
        if (!empty($migrationFileName = glob("{$migrationsPath}/*_create_{$table}_table.php"))) {
            array_push($migrationsInfo["tableNames"], $table);
            array_push(
                $migrationsInfo["migrationNames"],
                basename($migrationFileName[0])
            );
        } elseif (!empty($migrationFileName = glob("{$migrationsPath}/*_create_{$table}.php"))) {
            array_push($migrationsInfo["tableNames"], $table);
            array_push(
                $migrationsInfo["migrationNames"],
                basename($migrationFileName[0])
            );
        }

        if (!empty($migrationFileName = glob("{$migrationsPath}/*_to_{$table}_table.php"))) {
            array_push(
                $migrationsInfo["migrationNames"],
                basename($migrationFileName[0])
            );
        }

        if (!empty($migrationFileName = glob("{$migrationsPath}/*_from_{$table}_table.php"))) {
            array_push(
                $migrationsInfo["migrationNames"],
                basename($migrationFileName[0])
            );
        }

        if (!empty($migrationFileName = glob("{$migrationsPath}/*_in_{$table}_table.php"))) {
            array_push(
                $migrationsInfo["migrationNames"],
                basename($migrationFileName[0])
            );
        }

        return $migrationsInfo;
    }
}
