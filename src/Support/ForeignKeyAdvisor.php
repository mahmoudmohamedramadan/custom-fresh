<?php

namespace Ramadan\CustomFresh\Support;

use Illuminate\Support\Facades\Schema;
use Throwable;

class ForeignKeyAdvisor
{
    /**
     * The database connection name.
     *
     * @var string
     */
    protected string $connection;

    /**
     * Create a new foreign key advisor instance.
     *
     * @param  string  $connection
     * @return void
     */
    public function __construct(string $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Collect foreign-key edges for the given tables.
     *
     * Each edge is `['from' => child, 'to' => parent]`.
     *
     * @param  array<int, string>  $tables
     * @return array<int, array{from: string, to: string}>
     */
    public function edges(array $tables)
    {
        $schema = Schema::connection($this->connection);

        if (! method_exists($schema, 'getForeignKeys')) {
            return [];
        }

        $edges = [];

        foreach ($tables as $table) {
            try {
                $keys = $schema->getForeignKeys($table);
            } catch (Throwable) {
                continue;
            }

            foreach ((array) $keys as $key) {
                $parent = $key['foreign_table']
                    ?? $key['foreignTable']
                    ?? $key['foreign_table_name']
                    ?? $key['table']
                    ?? null;

                if (! is_string($parent) || $parent === '') {
                    continue;
                }

                $edges[] = [
                    'from' => $table,
                    'to'   => $parent,
                ];
            }
        }

        return $edges;
    }

    /**
     * Describe relations that would break if the drop list is applied.
     *
     * @param  array<int, string>  $preserved
     * @param  array<int, string>  $dropped
     * @return array<int, string>
     */
    public function warnings(array $preserved, array $dropped)
    {
        $droppedLookup = array_flip($dropped);
        $messages      = [];

        foreach ($this->edges(array_values(array_unique(array_merge($preserved, $dropped)))) as $edge) {
            $childKept  = in_array($edge['from'], $preserved, true);
            $parentDrop = isset($droppedLookup[$edge['to']]);
            $parentKept = in_array($edge['to'], $preserved, true);
            $childDrop  = isset($droppedLookup[$edge['from']]);

            if ($childKept && $parentDrop) {
                $messages[] = "Preserved table [{$edge['from']}] references [{$edge['to']}], which will be dropped.";
            }

            if ($parentKept && $childDrop) {
                $messages[] = "Dropped table [{$edge['from']}] references preserved table [{$edge['to']}].";
            }
        }

        return array_values(array_unique($messages));
    }

    /**
     * Expand the preserve list with tables linked by foreign keys.
     *
     * @param  array<int, string>  $preserved
     * @param  array<int, string>  $all
     * @return array{preserved: array<int, string>, notes: array<int, string>}
     */
    public function expandRelated(array $preserved, array $all)
    {
        $known  = array_flip($all);
        $keep   = array_values(array_unique($preserved));
        $notes  = [];
        $edges  = $this->edges($all);
        $safety = 0;

        do {
            $added = 0;

            foreach ($edges as $edge) {
                $hasFrom = in_array($edge['from'], $keep, true);
                $hasTo   = in_array($edge['to'], $keep, true);

                if ($hasFrom && ! $hasTo && isset($known[$edge['to']])) {
                    $keep[] = $edge['to'];
                    $notes[] = "Also preserving [{$edge['to']}] because [{$edge['from']}] references it.";
                    $added++;
                }

                if ($hasTo && ! $hasFrom && isset($known[$edge['from']])) {
                    $keep[] = $edge['from'];
                    $notes[] = "Also preserving [{$edge['from']}] because it references [{$edge['to']}].";
                    $added++;
                }
            }

            $safety++;
        } while ($added > 0 && $safety < 20);

        return [
            'preserved' => array_values(array_unique($keep)),
            'notes'     => array_values(array_unique($notes)),
        ];
    }
}
