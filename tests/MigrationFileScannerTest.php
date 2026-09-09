<?php

namespace Ramadan\CustomFresh\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Ramadan\CustomFresh\Support\MigrationFileScanner;

class MigrationFileScannerTest extends PHPUnitTestCase
{
    /**
     * @var array<int, string>
     */
    protected array $tempDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirectories as $directory) {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($directory);
        }

        parent::tearDown();
    }

    public function test_it_reads_schema_create_and_table_calls()
    {
        $directory = $this->tempDir();
        $file      = $directory . '/0001_01_01_000000_create_orders_table.php';

        file_put_contents($file, <<<'PHP'
<?php
use Illuminate\Support\Facades\Schema;
Schema::create('orders', function () {});
Schema::table('orders', function () {});
PHP);

        $scanner = new MigrationFileScanner;
        $files   = $scanner->collect([$directory]);

        $this->assertSame(['orders'], $scanner->tablesIn($file));
        $this->assertSame(['orders'], $scanner->createdTables($file));
        $this->assertTrue($scanner->createsAny(basename($file), ['orders']));
        $this->assertSame(['orders' => [basename($file)]], $scanner->indexByTable($files));
    }

    public function test_it_guesses_table_names_from_filenames()
    {
        $directory = $this->tempDir();
        $file      = $directory . '/0001_01_01_000001_create_invoices_table.php';

        file_put_contents($file, "<?php\n// no schema calls\n");

        $scanner = new MigrationFileScanner;

        $this->assertSame(['invoices'], $scanner->tablesIn($file));
    }

    public function test_it_guesses_alter_filenames()
    {
        $directory = $this->tempDir();
        $file      = $directory . '/0001_01_01_000002_add_status_to_invoices_table.php';

        file_put_contents($file, "<?php\n");

        $scanner = new MigrationFileScanner;

        $this->assertSame(['invoices'], $scanner->tablesIn($file));
        $this->assertSame([], $scanner->createdTables($file));
        $this->assertFalse($scanner->createsAny($file, ['invoices']));
    }

    public function test_it_collects_migrations_from_nested_directories()
    {
        $directory = $this->tempDir();
        $nested    = $directory . DIRECTORY_SEPARATOR . 'nested';
        mkdir($nested);

        $file = $nested . DIRECTORY_SEPARATOR . '0001_01_01_000000_create_widgets_table.php';

        file_put_contents($file, <<<'PHP'
<?php
use Illuminate\Support\Facades\Schema;
Schema::create('widgets', function () {});
PHP);

        $scanner = new MigrationFileScanner;
        $files   = $scanner->collect([$directory]);

        $this->assertCount(1, $files);
        $this->assertSame(['widgets'], $scanner->tablesIn($files[0]));
        $this->assertSame(['widgets'], $scanner->createdTables($files[0]));
    }

    public function test_it_reads_schema_connection_calls()
    {
        $directory = $this->tempDir();
        $file      = $directory . '/0001_01_01_000000_create_orders_table.php';

        file_put_contents($file, <<<'PHP'
<?php
use Illuminate\Support\Facades\Schema;
Schema::connection('tenant')->create('orders', function () {});
Schema::connection('tenant')->table('orders', function () {});
PHP);

        $scanner = new MigrationFileScanner;

        $this->assertSame(['orders'], $scanner->tablesIn($file));
        $this->assertSame(['orders'], $scanner->createdTables($file));
    }

    /**
     * Create a temporary directory for scanner fixtures.
     *
     * @return string
     */
    protected function tempDir()
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cf-scan-' . uniqid('', true);
        mkdir($directory);
        $this->tempDirectories[] = $directory;

        return $directory;
    }
}
