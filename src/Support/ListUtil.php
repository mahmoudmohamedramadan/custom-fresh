<?php

namespace Ramadan\CustomFresh\Support;

class ListUtil
{
    /**
     * Split a comma-separated string into a clean list of items.
     *
     * @param  string  $value
     * @return array<int, string>
     */
    public static function split(string $value)
    {
        return array_values(array_filter(array_map(
            static fn($item) => trim((string) $item),
            explode(',', $value)
        ), static fn($item) => $item !== ''));
    }

    /**
     * Determine whether the given pattern should be matched as a glob.
     *
     * @param  string  $value
     * @return bool
     */
    public static function isGlob(string $value)
    {
        return (bool) preg_match('/[\\*\\?\\[]/', $value);
    }

    /**
     * Expand literal names and glob patterns against a list of tables.
     *
     * @param  array<int, string>  $items
     * @param  array<int, string>  $tables
     * @return array<int, string>
     */
    public static function expand(array $items, array $tables)
    {
        $resolved = [];

        foreach (array_unique(array_filter($items, static fn($item) => $item !== null && $item !== '')) as $item) {
            $item = (string) $item;

            if (static::isGlob($item)) {
                foreach ($tables as $table) {
                    if (fnmatch($item, (string) $table)) {
                        $resolved[] = (string) $table;
                    }
                }

                continue;
            }

            $resolved[] = $item;
        }

        return array_values(array_unique($resolved));
    }
}
