<?php

namespace Ramadan\CustomFresh\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class MigrationFileScanner
{
    /**
     * Cache of parsed tables by absolute file path.
     *
     * @var array<string, array<int, string>>
     */
    protected array $cache = [];

    /**
     * Recursively collect all *.php migration files under the given paths.
     *
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    public function collect(array $paths)
    {
        $files = [];

        foreach (array_unique(array_filter($paths)) as $path) {
            if (! is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }

    /**
     * Return table names referenced by Schema calls or fall back to filename heuristics.
     *
     * @param  string  $file
     * @return array<int, string>
     */
    public function tablesIn(string $file)
    {
        if (array_key_exists($file, $this->cache)) {
            return $this->cache[$file];
        }

        $tables   = [];
        $contents = @file_get_contents($file);

        if ($contents !== false && $contents !== '') {
            $pattern = '/Schema(?:::|\\s*->)\\s*(?:connection\\s*\\([^\\)]*\\)\\s*->\\s*)?'
                . '(?:create|table|drop|dropIfExists|rename|hasTable)'
                . '\\s*\\(\\s*[\'"]([A-Za-z0-9_]+)[\'"]/i';

            if (preg_match_all($pattern, $contents, $matches)) {
                $tables = array_values(array_unique($matches[1]));
            }
        }

        if (empty($tables)) {
            $tables = $this->guessFromFilename(basename($file));
        }

        return $this->cache[$file] = $tables;
    }

    /**
     * Guess table names from standard Laravel migration filenames when Schema calls are absent.
     *
     * @param  string  $basename
     * @return array<int, string>
     */
    protected function guessFromFilename(string $basename)
    {
        $name = preg_replace('/^\\d{4}_\\d{2}_\\d{2}_\\d+_/', '', $basename);
        $name = preg_replace('/\\.php$/', '', (string) $name);

        if (preg_match('/^create_(.+?)_table$/', (string) $name, $m)) {
            return [$m[1]];
        }

        if (preg_match('/^(?:add|remove|drop|change|update|alter|modify)_.+?_(?:to|from|in|on)_(.+?)(?:_table)?$/', (string) $name, $m)) {
            return [$m[1]];
        }

        return [];
    }

    /**
     * Map each migration file to the table names it touches.
     *
     * @param  array<int, string>  $files
     * @return array<string, array<int, string>>
     */
    public function indexByTable(array $files)
    {
        $index = [];

        foreach ($files as $file) {
            $base = basename($file);

            foreach ($this->tablesIn($file) as $table) {
                $index[$table][] = $base;
            }
        }

        foreach ($index as $table => $list) {
            $index[$table] = array_values(array_unique($list));
            sort($index[$table]);
        }

        return $index;
    }
}
