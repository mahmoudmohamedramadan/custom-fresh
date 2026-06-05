<?php

namespace Ramadan\CustomFresh\Events;

class TablesDropped
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
     * The tables that were dropped.
     *
     * @var array
     */
    public array $dropped;

    /**
     * Create a new TablesDropped event instance.
     *
     * @param  string  $connection
     * @param  string  $database
     * @param  array  $preserved
     * @param  array  $dropped
     * @return void
     */
    public function __construct(string $connection, string $database, array $preserved, array $dropped)
    {
        $this->connection = $connection;
        $this->database   = $database;
        $this->preserved  = $preserved;
        $this->dropped    = $dropped;
    }
}
