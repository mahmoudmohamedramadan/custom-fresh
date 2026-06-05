<?php

namespace Ramadan\CustomFresh\Events;

class DatabaseRefreshed
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
     * The tables that were preserved.
     *
     * @var array
     */
    public array $preserved;

    /**
     * Create a new DatabaseRefreshed event instance.
     *
     * @param  string  $connection
     * @param  string  $database
     * @param  array  $preserved
     * @return void
     */
    public function __construct(string $connection, string $database, array $preserved)
    {
        $this->connection = $connection;
        $this->database   = $database;
        $this->preserved  = $preserved;
    }
}
