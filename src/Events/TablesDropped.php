<?php

namespace Ramadan\CustomFresh\Events;

class TablesDropped
{
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
     * The database connection name (or null for the default).
     *
     * @var string|null
     */
    public ?string $connection;

    /**
     * Create a new TablesDropped event instance.
     *
     * @param  array  $preserved
     * @param  array  $dropped
     * @param  string|null  $connection
     * @return void
     */
    public function __construct(array $preserved, array $dropped, ?string $connection = null)
    {
        $this->preserved  = $preserved;
        $this->dropped    = $dropped;
        $this->connection = $connection;
    }
}
