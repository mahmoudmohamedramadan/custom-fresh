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
    protected $signature = 'fresh:custom {table : The table(s) that you don\'t want to fresh}
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

        if (empty(array_map("current", DB::select("SHOW TABLES")))) {
            $this->components->task(
                'Migrating your database schema',
                fn () => $this->call('migrate', ['--force' => true])
            );
        }

        $tableNames = explode(",", $this->argument("table"));

        $fullMigrationFilesInfo = $this->getMigrationFileNames($tableNames);

        $this->components->info('Preparing database.');

        $this->components->task('Dropping the tables', $this->dropTables(
            $fullMigrationFilesInfo["correctTableNames"],
            $fullMigrationFilesInfo["migrationFileNames"]
        ));

        $this->call('migrate', ['--force' => true]);

        return 0;
    }

    /**
     * Get an array of the correct table names with migration file names.
     *
     * @param  array  $tableNames
     * @return array
     */
    private function getMigrationFileNames(array $tableNames)
    {
        $migrationPath          = database_path('migrations');
        $fullMigrationFilesInfo = ["migrationFileNames" => [], "correctTableNames" => []];

        foreach (array_filter($tableNames) as $index => $table) {
            $fullMigrationFilesInfo = $this->checkMigrationFileExistence(
                $migrationPath,
                $table,
                $fullMigrationFilesInfo
            );

            if (empty($fullMigrationFilesInfo["correctTableNames"][$index])) {
                $choiceValue = $this->choice(
                    "Choose the correct table instead ({$table})",
                    array_diff(
                        array_map("current", DB::select("SHOW TABLES")),
                        array_merge($fullMigrationFilesInfo["correctTableNames"], ["migrations"])
                    )
                );

                $fullMigrationFilesInfo = $this->checkMigrationFileExistence(
                    $migrationPath,
                    $choiceValue,
                    $fullMigrationFilesInfo
                );
            }
        }

        return $fullMigrationFilesInfo;
    }

    /**
     * Drop all tables except the given array of table names from the database.
     *
     * @param  array  $tableNames
     * @param  array  $migrationFileNames
     * @return void
     */
    private function dropTables(array $tableNames, array $migrationFileNames)
    {
        DB::table("migrations")->truncate();

        foreach ($migrationFileNames as $migration) {
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
     * @param  string  $migrationPath
     * @param  string  $table
     * @param  array   $fullMigrationFilesInfo
     * @return array
     */
    private function checkMigrationFileExistence(
        string $migrationPath,
        string $table,
        array $fullMigrationFilesInfo
    ) {
        if (!empty($migrationFileName = glob("{$migrationPath}/*_create_{$table}_table.php"))) {
            array_push($fullMigrationFilesInfo["correctTableNames"], $table);
            array_push(
                $fullMigrationFilesInfo["migrationFileNames"],
                basename($migrationFileName[0])
            );
        } elseif (!empty($migrationFileName = glob("{$migrationPath}/*_create_{$table}.php"))) {
            array_push($fullMigrationFilesInfo["correctTableNames"], $table);
            array_push(
                $fullMigrationFilesInfo["migrationFileNames"],
                basename($migrationFileName[0])
            );
        }

        if (!empty($migrationFileName = glob("{$migrationPath}/*_to_{$table}_table.php"))) {
            array_push(
                $fullMigrationFilesInfo["migrationFileNames"],
                basename($migrationFileName[0])
            );
        }

        if (!empty($migrationFileName = glob("{$migrationPath}/*_from_{$table}_table.php"))) {
            array_push(
                $fullMigrationFilesInfo["migrationFileNames"],
                basename($migrationFileName[0])
            );
        }

        if (!empty($migrationFileName = glob("{$migrationPath}/*_in_{$table}_table.php"))) {
            array_push(
                $fullMigrationFilesInfo["migrationFileNames"],
                basename($migrationFileName[0])
            );
        }

        return $fullMigrationFilesInfo;
    }
}
