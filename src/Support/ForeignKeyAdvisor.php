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
     * Collect foreign-key constraints for the given tables.
     *
     * Each constraint is `['from' => child, 'to' => parent, 'name' => ?string, 'columns' => list]`.
     *
     * @param  array<int, string>  $tables
     * @return array<int, array{from: string, to: string, name: string|null, columns: array<int, string>}>
     */
    public function constraints(array $tables)
    {
        $schema = Schema::connection($this->connection);

        if (! method_exists($schema, 'getForeignKeys')) {
            return [];
        }

        $constraints = [];

        foreach (array_values(array_unique($tables)) as $table) {
            try {
                $keys = $schema->getForeignKeys($table);
            } catch (Throwable) {
                continue;
            }

            foreach ((array) $keys as $key) {
                $constraint = $this->normalizeConstraint($table, (array) $key);

                if ($constraint !== null) {
                    $constraints[] = $constraint;
                }
            }
        }

        return $constraints;
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
        return array_map(static function (array $constraint) {
            return [
                'from' => $constraint['from'],
                'to'   => $constraint['to'],
            ];
        }, $this->constraints($tables));
    }

    /**
     * Foreign keys on preserved tables that point at tables about to be dropped.
     *
     * Those parent tables must stay. Dropping them would remove the foreign
     * key from the kept child (PostgreSQL) or leave an invalid constraint.
     *
     * @param  array<int, string>  $preserved
     * @param  array<int, string>  $dropped
     * @return array<int, array{from: string, to: string, name: string|null, columns: array<int, string>}>
     */
    public function blockingConstraints(array $preserved, array $dropped)
    {
        $droppedLookup = array_flip($dropped);
        $blocking      = [];

        foreach ($this->constraints($preserved) as $constraint) {
            if ($constraint['from'] === $constraint['to']) {
                continue;
            }

            if (! isset($droppedLookup[$constraint['to']])) {
                continue;
            }

            if (! in_array($constraint['from'], $preserved, true)) {
                continue;
            }

            $blocking[] = $constraint;
        }

        return $blocking;
    }

    /**
     * Order tables so children are dropped before the parents they reference.
     *
     * @param  array<int, string>  $tables
     * @return array<int, string>
     */
    public function sortDropOrder(array $tables)
    {
        $remaining = array_values(array_unique($tables));
        $set       = array_flip($remaining);
        $edges     = [];

        foreach ($this->edges($remaining) as $edge) {
            if ($edge['from'] === $edge['to']) {
                continue;
            }

            if (! isset($set[$edge['from']], $set[$edge['to']])) {
                continue;
            }

            $edges[] = $edge;
        }

        $ordered = [];
        $safety  = 0;

        while ($remaining !== [] && $safety < 1000) {
            $safety++;
            $progress = false;

            foreach ($remaining as $index => $table) {
                $hasRemainingChild = false;

                foreach ($edges as $edge) {
                    if ($edge['to'] === $table && in_array($edge['from'], $remaining, true)) {
                        $hasRemainingChild = true;
                        break;
                    }
                }

                if ($hasRemainingChild) {
                    continue;
                }

                $ordered[] = $table;
                unset($remaining[$index]);
                $remaining = array_values($remaining);
                $progress  = true;
                break;
            }

            if (! $progress) {
                foreach ($remaining as $table) {
                    $ordered[] = $table;
                }

                break;
            }
        }

        return $ordered;
    }

    /**
     * Describe foreign-key relations between preserved and dropped tables.
     *
     * Dropping a child of a kept parent is expected (INFO). A kept child
     * that still references a dropped parent is unsafe (WARN).
     *
     * @param  array<int, string>  $preserved
     * @param  array<int, string>  $dropped
     * @return array{notes: array<int, string>, warnings: array<int, string>}
     */
    public function describeRelations(array $preserved, array $dropped)
    {
        $droppedLookup = array_flip($dropped);
        $notes         = [];
        $warnings      = [];

        foreach ($this->edges(array_values(array_unique(array_merge($preserved, $dropped)))) as $edge) {
            $childKept  = in_array($edge['from'], $preserved, true);
            $parentDrop = isset($droppedLookup[$edge['to']]);
            $parentKept = in_array($edge['to'], $preserved, true);
            $childDrop  = isset($droppedLookup[$edge['from']]);

            if ($childKept && $parentDrop) {
                $warnings[] = "Preserved table [{$edge['from']}] references [{$edge['to']}], which will be dropped.";
            }

            if ($parentKept && $childDrop) {
                $notes[] = "Dropped table [{$edge['from']}] references preserved table [{$edge['to']}].";
            }
        }

        return [
            'notes'    => array_values(array_unique($notes)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Expand the preserve list with parent tables referenced by kept tables.
     *
     * @param  array<int, string>  $preserved
     * @param  array<int, string>  $all
     * @param  array<int, string>  $exclude
     * @return array{preserved: array<int, string>, notes: array<int, string>, warnings: array<int, string>}
     */
    public function expandReferenced(array $preserved, array $all, array $exclude = [])
    {
        return $this->expandEdges($preserved, $all, $exclude, 'referenced');
    }

    /**
     * Expand the preserve list with child tables that reference kept tables.
     *
     * @param  array<int, string>  $preserved
     * @param  array<int, string>  $all
     * @param  array<int, string>  $exclude
     * @return array{preserved: array<int, string>, notes: array<int, string>, warnings: array<int, string>}
     */
    public function expandDependents(array $preserved, array $all, array $exclude = [])
    {
        return $this->expandEdges($preserved, $all, $exclude, 'dependents');
    }

    /**
     * Expand the preserve list with tables linked by foreign keys in both directions.
     *
     * @param  array<int, string>  $preserved
     * @param  array<int, string>  $all
     * @param  array<int, string>  $exclude
     * @return array{preserved: array<int, string>, notes: array<int, string>, warnings: array<int, string>}
     */
    public function expandRelated(array $preserved, array $all, array $exclude = [])
    {
        return $this->expandEdges($preserved, $all, $exclude, 'both');
    }

    /**
     * Walk foreign-key edges and add related tables to the preserve list.
     *
     * @param  array<int, string>  $preserved
     * @param  array<int, string>  $all
     * @param  array<int, string>  $exclude
     * @param  string  $direction  referenced|dependents|both
     * @return array{preserved: array<int, string>, notes: array<int, string>, warnings: array<int, string>}
     */
    protected function expandEdges(array $preserved, array $all, array $exclude, string $direction)
    {
        $known    = array_flip($all);
        $skip     = array_flip($exclude);
        $keep     = array_values(array_unique($preserved));
        $notes    = [];
        $warnings = [];
        $edges    = $this->edges($all);
        $safety   = 0;
        $parents  = $direction === 'referenced' || $direction === 'both';
        $children = $direction === 'dependents' || $direction === 'both';

        do {
            $added = 0;

            foreach ($edges as $edge) {
                $hasFrom = in_array($edge['from'], $keep, true);
                $hasTo   = in_array($edge['to'], $keep, true);

                if (
                    $parents
                    && $hasFrom
                    && ! $hasTo
                    && isset($known[$edge['to']])
                    && ! isset($skip[$edge['to']])
                ) {
                    $keep[] = $edge['to'];

                    if ($direction === 'referenced') {
                        $warnings[] = "Preserved table [{$edge['from']}] references [{$edge['to']}], which cannot be dropped.";
                    } else {
                        $notes[] = "Also preserving [{$edge['to']}] because [{$edge['from']}] references it.";
                    }

                    $added++;
                }

                if (
                    $children
                    && $hasTo
                    && ! $hasFrom
                    && isset($known[$edge['from']])
                    && ! isset($skip[$edge['from']])
                ) {
                    $keep[]  = $edge['from'];
                    $notes[] = "Also preserving [{$edge['from']}] because it references [{$edge['to']}].";
                    $added++;
                }
            }

            $safety++;
        } while ($added > 0 && $safety < 20);

        return [
            'preserved' => array_values(array_unique($keep)),
            'notes'     => array_values(array_unique($notes)),
            'warnings'  => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Normalize a driver-specific foreign-key row.
     *
     * @param  string  $table
     * @param  array<string, mixed>  $key
     * @return array{from: string, to: string, name: string|null, columns: array<int, string>}|null
     */
    protected function normalizeConstraint(string $table, array $key)
    {
        $parent = $key['foreign_table']
            ?? $key['foreignTable']
            ?? $key['foreign_table_name']
            ?? $key['table']
            ?? null;

        if (! is_string($parent) || $parent === '') {
            return null;
        }

        $name = $key['name'] ?? $key['index'] ?? $key['constraint'] ?? null;

        if (! is_string($name) || $name === '') {
            $name = null;
        }

        $columns = $key['columns'] ?? $key['column'] ?? [];

        if (is_string($columns)) {
            $columns = explode(',', $columns);
        }

        $columns = array_values(array_filter(array_map(
            static fn($column) => trim((string) $column),
            (array) $columns
        ), static fn($column) => $column !== ''));

        return [
            'from'    => $table,
            'to'      => $parent,
            'name'    => $name,
            'columns' => $columns,
        ];
    }
}
