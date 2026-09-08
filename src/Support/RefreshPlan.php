<?php

namespace Ramadan\CustomFresh\Support;

class RefreshPlan
{
    /**
     * Create a new refresh plan instance.
     *
     * @param  array<int, string>  $preserved
     * @param  array<int, string>  $dropped
     * @param  array<int, string>  $migrations
     * @param  array<int, string>  $pendingAlters
     * @param  array<int, string>  $warnings
     * @param  array<int, string>  $notes
     * @param  bool  $dropOnly
     * @return void
     */
    public function __construct(
        public array $preserved = [],
        public array $dropped = [],
        public array $migrations = [],
        public array $pendingAlters = [],
        public array $warnings = [],
        public array $notes = [],
        public bool $dropOnly = false,
    ) {
    }

    /**
     * Determine whether the plan has no tables to preserve or drop.
     *
     * @return bool
     */
    public function isEmpty()
    {
        return empty($this->preserved) && empty($this->dropped);
    }

    /**
     * Convert the plan to an array suitable for JSON output.
     *
     * @param  string  $connection
     * @param  string  $database
     * @return array<string, mixed>
     */
    public function toArray(string $connection = '', string $database = '')
    {
        return [
            'connection'     => $connection,
            'database'       => $database,
            'preserve'       => $this->preserved,
            'drop'           => $this->dropped,
            'migrations'     => $this->migrations,
            'pending_alters' => $this->pendingAlters,
            'warnings'       => $this->warnings,
            'notes'          => $this->notes,
        ];
    }
}
