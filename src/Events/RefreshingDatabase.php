<?php

namespace Ramadan\CustomFresh\Events;

class RefreshingDatabase
{
    /**
     * The database connection name.
     *
     * @var string
     */
    public string $connection;

    /**
     * The database name.
     *
     * @var string
     */
    public string $database;

    /**
     * The migration filenames that will be marked as already-run.
     *
     * @var array
     */
    public array $migrations;

    /**
     * The tables that will be preserved.
     *
     * @var array
     */
    public array $preserved;

    /**
     * Create a new RefreshingDatabase event instance.
     *
     * @param  string  $connection
     * @param  string  $database
     * @param  array  $migrations
     * @param  array  $preserved
     * @return void
     */
    public function __construct(string $connection, string $database, array $migrations, array $preserved)
    {
        $this->connection = $connection;
        $this->database   = $database;
        $this->migrations = $migrations;
        $this->preserved  = $preserved;
    }
}
