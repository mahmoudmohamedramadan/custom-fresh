<?php

namespace Ramadan\CustomFresh\Support;

use InvalidArgumentException;

class RefreshPlanBuilder
{
    /**
     * Migration scanner used to map files to the tables they touch.
     *
     * @var \Ramadan\CustomFresh\Support\MigrationFileScanner
     */
    protected MigrationFileScanner $scanner;

    /**
     * Config resolver for the current connection.
     *
     * @var \Ramadan\CustomFresh\Support\ConfigResolver
     */
    protected ConfigResolver $config;

    /**
     * Advisor used to detect broken foreign keys.
     *
     * @var \Ramadan\CustomFresh\Support\ForeignKeyAdvisor
     */
    protected ForeignKeyAdvisor $foreignKeys;

    /**
     * All table names in the target database.
     *
     * @var array<int, string>
     */
    protected array $tables = [];

    /**
     * Migration filenames indexed by table.
     *
     * @var array<string, array<int, string>>
     */
    protected array $migrationsByTable = [];

    /**
     * Already-applied migration names (filename without extension).
     *
     * @var array<int, string>
     */
    protected array $appliedMigrations = [];

    /**
     * Create a new refresh plan builder instance.
     *
     * @param  \Ramadan\CustomFresh\Support\MigrationFileScanner  $scanner
     * @param  \Ramadan\CustomFresh\Support\ConfigResolver  $config
     * @param  \Ramadan\CustomFresh\Support\ForeignKeyAdvisor  $foreignKeys
     * @param  array<int, string>  $tables
     * @param  array<string, array<int, string>>  $migrationsByTable
     * @param  array<int, string>  $appliedMigrations
     * @return void
     */
    public function __construct(
        MigrationFileScanner $scanner,
        ConfigResolver $config,
        ForeignKeyAdvisor $foreignKeys,
        array $tables,
        array $migrationsByTable,
        array $appliedMigrations = [],
    ) {
        $this->scanner           = $scanner;
        $this->config            = $config;
        $this->foreignKeys       = $foreignKeys;
        $this->tables            = array_values($tables);
        $this->migrationsByTable = $migrationsByTable;
        $this->appliedMigrations = $appliedMigrations;
    }

    /**
     * Build the preserve / drop plan from CLI input and config.
     *
     * @param  array<string, mixed>  $input
     * @return \Ramadan\CustomFresh\Support\RefreshPlan
     */
    public function build(array $input)
    {
        $notes    = [];
        $warnings = [];

        $presetTables = $this->expandPresets((array) ($input['presets'] ?? []));

        $explicitKeep = ListUtil::expand(array_merge(
            (array) ($input['keep'] ?? []),
            $presetTables,
        ), $this->tables);

        $configuredKeep = ListUtil::expand(array_merge(
            array_map('strval', (array) $this->config->get('always_keep', [])),
            array_map('strval', (array) $this->config->get('patterns', [])),
        ), $this->tables);

        $keepRaw = ListUtil::expand(array_merge(
            (array) ($input['keepRaw'] ?? []),
            array_map('strval', (array) $this->config->get('keep_without_migrations', [])),
        ), $this->tables);

        $except = ListUtil::expand((array) ($input['except'] ?? []), $this->tables);
        $drop   = ListUtil::expand((array) ($input['drop'] ?? []), $this->tables);

        $dropOnly = ! empty($drop) && empty($explicitKeep);

        if ($dropOnly) {
            $preserved = array_values(array_diff($this->tables, $drop, $except, ['migrations']));
        } else {
            $preserved = array_values(array_unique(array_merge($explicitKeep, $configuredKeep, $keepRaw)));
            $preserved = array_values(array_diff($preserved, $drop, $except));
        }

        if (empty($preserved) && empty($drop) && ! empty($input['pickTables']) && is_callable($input['pickTables'])) {
            $candidates = $this->pickerCandidates();

            if (! empty($candidates)) {
                $picked    = array_values(array_filter((array) $input['pickTables']($candidates)));
                $preserved = ListUtil::expand($picked, $this->tables);
            }
        }

        $resolved = $this->resolvePreserved(
            $preserved,
            $keepRaw,
            $dropOnly,
            ! empty($input['interactive']),
            is_callable($input['pickReplacement'] ?? null) ? $input['pickReplacement'] : null,
            $notes,
            $warnings
        );

        $preserved = $resolved['tables'];
        $notes     = array_merge($notes, $resolved['notes']);
        $warnings  = array_merge($warnings, $resolved['warnings']);

        $expanded = $this->expandDropSharedMigrations($drop, $preserved, $explicitKeep, $input);

        if ($expanded['cancelled']) {
            return new RefreshPlan(cancelled: true);
        }

        $drop = $expanded['drop'];

        if ($dropOnly) {
            $preserved = array_values(array_diff($this->tables, $drop, $except, ['migrations']));
        } else {
            $preserved = array_values(array_diff($preserved, $drop));
        }

        if (empty($preserved) && ! $dropOnly) {
            return new RefreshPlan(
                warnings: array_values(array_unique($warnings)),
                notes: array_values(array_unique($notes)),
            );
        }

        $requestedDrop = array_values(array_unique(array_merge($drop, $except)));

        $expandSiblings = function () use (&$preserved, &$notes, &$warnings, $requestedDrop) {
            if (empty($preserved)) {
                return;
            }

            $siblings  = $this->expandCreateSiblings($preserved, $requestedDrop);
            $preserved = $siblings['preserved'];
            $notes     = array_merge($notes, $siblings['notes']);
            $warnings  = array_merge($warnings, $siblings['warnings']);
        };

        $applyRelated = function (string $method) use ($dropOnly, $keepRaw, &$preserved, &$notes, &$warnings) {
            if (empty($preserved)) {
                return;
            }

            if ($dropOnly && $method === 'expandRelated') {
                return;
            }

            $related   = $this->foreignKeys->{$method}($preserved, $this->tables, ['migrations']);
            $preserved = $related['preserved'];
            $notes     = array_merge($notes, $related['notes']);
            $warnings  = array_merge($warnings, $related['warnings']);

            $resolved  = $this->resolvePreserved(
                $preserved,
                $keepRaw,
                true,
                false,
                null,
                $notes,
                $warnings
            );
            $preserved = $resolved['tables'];
        };

        $expandSiblings();

        // Parents of kept children are always kept so foreign keys stay
        // intact. --drop-referenced only skips keeping dependents.
        $applyRelated(! empty($input['dropReferenced']) ? 'expandReferenced' : 'expandRelated');

        $expandSiblings();
        $applyRelated('expandReferenced');

        $dropped = array_values(array_diff($this->tables, $preserved, ['migrations']));

        $migrationPlan = $this->planMigrations($preserved, ! empty($input['freezeSchema']));

        $relations = $this->foreignKeys->describeRelations($preserved, $dropped);
        $notes     = array_merge($notes, $relations['notes']);
        $warnings  = array_merge($warnings, $relations['warnings']);

        return new RefreshPlan(
            preserved: $preserved,
            dropped: $dropped,
            migrations: $migrationPlan['restore'],
            pendingAlters: $migrationPlan['pending'],
            warnings: array_values(array_unique($warnings)),
            notes: array_values(array_unique($notes)),
            dropOnly: $dropOnly,
        );
    }

    /**
     * Expand named presets from config into table names / patterns.
     *
     * @param  array<int, string>  $names
     * @return array<int, string>
     *
     * @throws \InvalidArgumentException
     */
    protected function expandPresets(array $names)
    {
        $presets = (array) $this->config->get('presets', []);
        $tables  = [];

        foreach ($names as $name) {
            $name = (string) $name;

            if ($name === '') {
                continue;
            }

            if (! array_key_exists($name, $presets)) {
                $available = empty($presets) ? 'none configured' : implode(', ', array_keys($presets));

                throw new InvalidArgumentException(
                    "Unknown custom-fresh preset [{$name}]. Available presets: {$available}."
                );
            }

            $tables = array_merge($tables, array_map('strval', (array) $presets[$name]));
        }

        return $tables;
    }

    /**
     * Tables offered by the interactive picker.
     *
     * @return array<int, string>
     */
    protected function pickerCandidates()
    {
        $owning = array_values(array_intersect($this->tables, array_keys($this->migrationsByTable)));
        $raw    = ListUtil::expand(
            array_map('strval', (array) $this->config->get('keep_without_migrations', [])),
            $this->tables
        );

        return array_values(array_diff(array_unique(array_merge($owning, $raw)), ['migrations']));
    }

    /**
     * Filter the preserve list down to tables that can actually be kept.
     *
     * @param  array<int, string>  $requested
     * @param  array<int, string>  $keepRaw
     * @param  bool  $allowMissingMigrations
     * @param  bool  $interactive
     * @param  callable|null  $pickReplacement
     * @param  array<int, string>  $notes
     * @param  array<int, string>  $warnings
     * @return array{tables: array<int, string>, notes: array<int, string>, warnings: array<int, string>}
     */
    protected function resolvePreserved(
        array $requested,
        array $keepRaw,
        bool $allowMissingMigrations,
        bool $interactive,
        ?callable $pickReplacement,
        array $notes,
        array $warnings
    ) {
        $preserved = [];
        $existing  = array_flip($this->tables);

        foreach (array_values(array_unique($requested)) as $table) {
            if ($table === 'migrations') {
                continue;
            }

            if (! isset($existing[$table])) {
                $warnings[] = "Table [{$table}] does not exist.";
                continue;
            }

            if (array_key_exists($table, $this->migrationsByTable)) {
                $preserved[] = $table;
                continue;
            }

            if ($allowMissingMigrations || in_array($table, $keepRaw, true)) {
                $preserved[] = $table;
                continue;
            }

            $candidates = array_values(array_diff(
                array_intersect($this->tables, array_keys($this->migrationsByTable)),
                $preserved,
                ['migrations']
            ));

            if (empty($candidates)) {
                $warnings[] = "No migration matches table [{$table}]. Skipping. Use --keep-raw={$table} to preserve it anyway.";
                continue;
            }

            if (! $interactive || $pickReplacement === null) {
                $warnings[] = "Skipping unknown table [{$table}] (--no-interaction). Use --keep-raw={$table} to preserve it.";
                continue;
            }

            $chosen = (string) $pickReplacement($table, $candidates);

            if ($chosen !== '' && isset($existing[$chosen])) {
                $preserved[] = $chosen;
            }
        }

        return [
            'tables'   => array_values(array_unique($preserved)),
            'notes'    => $notes,
            'warnings' => $warnings,
        ];
    }

    /**
     * Ask to drop every table that shares a create migration with a --drop table.
     *
     * Children of that group are included when they were not explicitly kept,
     * so a confirmed drop is not undone by a leftover foreign key.
     *
     * @param  array<int, string>  $drop
     * @param  array<int, string>  $preserved
     * @param  array<int, string>  $explicitKeep
     * @param  array<string, mixed>  $input
     * @return array{drop: array<int, string>, cancelled: bool}
     */
    protected function expandDropSharedMigrations(
        array $drop,
        array $preserved,
        array $explicitKeep,
        array $input
    ) {
        $confirm = is_callable($input['confirmDropRelated'] ?? null)
            ? $input['confirmDropRelated']
            : null;

        if ($drop === [] || $confirm === null) {
            return ['drop' => $drop, 'cancelled' => false];
        }

        foreach (array_values(array_unique($drop)) as $table) {
            $group  = $this->createGroup($table);
            $shared = array_values(array_diff($group, [$table]));
            sort($shared);

            if ($shared === []) {
                continue;
            }

            $related = array_values(array_diff(
                $this->relatedTablesForDrop($group, $preserved, $explicitKeep),
                [$table]
            ));
            sort($related);

            if ($related === []) {
                continue;
            }

            $nextDrop = array_values(array_unique(array_merge($drop, $group, $related)));
            $nextKeep = array_values(array_diff($preserved, $nextDrop));
            $pulled   = $this->foreignKeys->expandReferenced(
                $nextKeep,
                $this->tables,
                ['migrations']
            );

            if (in_array($table, $pulled['preserved'], true)) {
                continue;
            }

            $accepted = $confirm($table, $shared, $related);

            if ($accepted === true) {
                $drop      = $nextDrop;
                $preserved = $nextKeep;
                continue;
            }

            if ($accepted === false) {
                return ['drop' => $drop, 'cancelled' => true];
            }
        }

        return [
            'drop'      => array_values(array_unique($drop)),
            'cancelled' => false,
        ];
    }

    /**
     * Tables created by the same Schema::create migration as the given table.
     *
     * @param  string  $table
     * @return array<int, string>
     */
    protected function createGroup(string $table)
    {
        $group = [$table];
        $known = array_flip($this->tables);

        foreach ($this->migrationsByTable[$table] ?? [] as $migration) {
            $created = $this->scanner->createdTables($migration);

            if (! in_array($table, $created, true)) {
                continue;
            }

            foreach ($created as $sibling) {
                if (isset($known[$sibling])) {
                    $group[] = $sibling;
                }
            }
        }

        return array_values(array_unique($group));
    }

    /**
     * Sibling and dependent tables that should be dropped with a create group.
     *
     * @param  array<int, string>  $group
     * @param  array<int, string>  $preserved
     * @param  array<int, string>  $explicitKeep
     * @return array<int, string>
     */
    protected function relatedTablesForDrop(array $group, array $preserved, array $explicitKeep)
    {
        $skip    = array_flip($explicitKeep);
        $related = [];

        foreach ($group as $table) {
            if ($table !== 'migrations' && ! isset($skip[$table])) {
                $related[] = $table;
            }
        }

        $dependents = $this->foreignKeys->expandDependents(
            $group,
            $this->tables,
            ['migrations']
        );

        foreach ($dependents['preserved'] as $table) {
            if (
                $table === 'migrations'
                || isset($skip[$table])
            ) {
                continue;
            }

            if (
                in_array($table, $preserved, true)
                || in_array($table, $group, true)
            ) {
                $related[] = $table;
            }
        }

        return array_values(array_unique($related));
    }

    /**
     * Keep every table created by the same Schema::create migration as a preserved table.
     *
     * Those create migrations are marked as already run, so dropping a sibling
     * would leave it missing for later foreign keys (e.g. keeping "sessions"
     * from Laravel's users migration and then recreating "posts").
     *
     * @param  array<int, string>  $preserved
     * @param  array<int, string>  $requestedDrop
     * @return array{preserved: array<int, string>, notes: array<int, string>, warnings: array<int, string>}
     */
    protected function expandCreateSiblings(array $preserved, array $requestedDrop = [])
    {
        $keep     = array_values(array_unique($preserved));
        $known    = array_flip($this->tables);
        $blocked  = array_flip($requestedDrop);
        $notes    = [];
        $warnings = [];
        $safety   = 0;

        do {
            $added   = 0;
            $current = $keep;

            foreach ($current as $table) {
                foreach ($this->migrationsByTable[$table] ?? [] as $migration) {
                    $created = $this->scanner->createdTables($migration);

                    if (! in_array($table, $created, true)) {
                        continue;
                    }

                    foreach ($created as $sibling) {
                        if (
                            $sibling === $table
                            || in_array($sibling, $keep, true)
                            || ! isset($known[$sibling])
                        ) {
                            continue;
                        }

                        $keep[] = $sibling;

                        if (isset($blocked[$sibling])) {
                            $warnings[] = "Cannot drop [{$sibling}]; it shares a create migration with [{$table}].";
                        } else {
                            $notes[] = "Also preserving [{$sibling}] because it shares a create migration with [{$table}].";
                        }

                        $added++;
                    }
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
     * Decide which migration rows to restore and which pending alters still run.
     *
     * @param  array<int, string>  $preserved
     * @param  bool  $freezeSchema
     * @return array{restore: array<int, string>, pending: array<int, string>}
     */
    protected function planMigrations(array $preserved, bool $freezeSchema)
    {
        $touching = [];

        foreach ($preserved as $table) {
            foreach ($this->migrationsByTable[$table] ?? [] as $migration) {
                $touching[] = $migration;
            }
        }

        $touching = array_values(array_unique($touching));
        $restore  = [];
        $pending  = [];
        $applied  = array_flip($this->appliedMigrations);

        foreach ($touching as $migration) {
            $name = pathinfo($migration, PATHINFO_FILENAME);

            if ($freezeSchema || isset($applied[$name]) || $this->scanner->createsAny($migration, $preserved)) {
                $restore[] = $migration;
                continue;
            }

            $pending[] = $migration;
        }

        sort($restore);
        sort($pending);

        return [
            'restore' => array_values(array_unique($restore)),
            'pending' => array_values(array_unique($pending)),
        ];
    }
}
