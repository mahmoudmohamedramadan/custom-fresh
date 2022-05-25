<?php

namespace Ramadan\CustomFresh\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
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
    protected $signature = 'fresh:custom {table* : The table(s) that you don\'t want to fresh}
                {--force : Force the operation to run when in production}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create exceptions for the given table names during refreshing the database';

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

        $fullMigrationFilesInfo = $this->getMigrationFileNames($this->argument("table"));

        $this->dropTables($fullMigrationFilesInfo["correctTableNames"], $fullMigrationFilesInfo["migrationFileNames"]);

        return 0;
    }

    /**
     * Get an array of correct table names and migration file names.
     *
     * @param  array  $tableNames
     * @return array
     */
    private function getMigrationFileNames(array $tableNames)
    {
        $migrationPath          = database_path('migrations');
        $fullMigrationFilesInfo = ["migrationFileNames" => [], "correctTableNames" => []];

        foreach (array_filter($tableNames) as $index => $table) {
            $this->checkMigrationFileExistence($migrationPath, $table, $fullMigrationFilesInfo);

            if (empty($fullMigrationFilesInfo["correctTableNames"][$index])) {
                $this->error("The {$table} table does not exist.");

                $choiceValue = $this->choice(
                    "Please choose the table that you want",
                    array_diff(
                        array_map("current", DB::select("SHOW TABLES")),
                        array_merge($fullMigrationFilesInfo["correctTableNames"], ["migrations"])
                    )
                );

                $this->checkMigrationFileExistence($migrationPath, $choiceValue, $fullMigrationFilesInfo);
            }
        }

        return $fullMigrationFilesInfo;
    }

    /**
     * Drop all tables except the given array of table name from the database.
     *
     * @param  array  $tableNames
     * @param  array  $migrationFileNames
     * @return void
     */
    private function dropTables(array $tableNames, array $migrationFileNames)
    {
        DB::table("migrations")->truncate();

        foreach ($migrationFileNames as $migration) {
            DB::table("migrations")->insert(["migration" => substr_replace($migration, "", -4), "batch" => 1]);
        }

        $droppedTables = array_map("current", DB::select("SHOW TABLES"));
        $droppedTables = array_diff($droppedTables, array_merge(array_filter($tableNames), ["migrations"]));

        foreach ($droppedTables as $table) {
            Schema::dropIfExists($table);

            $this->info("The {$table} table was dropped successfully.");
        }

        Artisan::call("migrate --force");

        $this->info("The migration files were migrated successfully.");
    }

    /**
     * Check if the migration file is exist.
     *
     * @param  string  $migrationPath
     * @param  string  $table
     * @param  array   $fullMigrationFilesInfo
     * @return void
     */
    private function checkMigrationFileExistence(string $migrationPath, string $table, array &$fullMigrationFilesInfo)
    {
        if (!empty($migrationFileName = glob("{$migrationPath}/*_create_{$table}_table.php"))) {
            array_push($fullMigrationFilesInfo["correctTableNames"], $table);
            array_push($fullMigrationFilesInfo["migrationFileNames"], basename($migrationFileName[0]));
        } elseif (!empty($migrationFileName = glob("{$migrationPath}/*_create_{$table}.php"))) {
            array_push($fullMigrationFilesInfo["correctTableNames"], $table);
            array_push($fullMigrationFilesInfo["migrationFileNames"], basename($migrationFileName[0]));
        }

        if (!empty($migrationFileName = glob("{$migrationPath}/*_to_{$table}_table.php"))) {
            array_push($fullMigrationFilesInfo["migrationFileNames"], basename($migrationFileName[0]));
        }

        if (!empty($migrationFileName = glob("{$migrationPath}/*_from_{$table}_table.php"))) {
            array_push($fullMigrationFilesInfo["migrationFileNames"], basename($migrationFileName[0]));
        }

        if (!empty($migrationFileName = glob("{$migrationPath}/*_in_{$table}_table.php"))) {
            array_push($fullMigrationFilesInfo["migrationFileNames"], basename($migrationFileName[0]));
        }
    }
}
