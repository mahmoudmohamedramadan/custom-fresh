<?php

namespace Ramadan\CustomFresh\Events;

class RefreshingDatabase
{
    /**
     * The tables that will be preserved.
     *
     * @var array
     */
    public array $preserved;

    /**
     * The migration filenames that will be marked as already-run.
     *
     * @var array
     */
    public array $migrations;

    /**
     * The database connection name (or null for the default).
     *
     * @var string|null
     */
    public ?string $connection;

    /**
     * Create a new RefreshingDatabase event instance.
     *
     * @param  array  $preserved
     * @param  array  $migrations
     * @param  string|null  $connection
     * @return void
     */
    public function __construct(array $preserved, array $migrations, ?string $connection = null)
    {
        $this->preserved  = $preserved;
        $this->migrations = $migrations;
        $this->connection = $connection;
    }
}
