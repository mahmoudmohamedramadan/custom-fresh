<?php

namespace Ramadan\CustomFresh\Events;

class DatabaseRefreshed
{
    /**
     * The tables that were preserved.
     *
     * @var array
     */
    public array $preserved;

    /**
     * The database connection name (or null for the default).
     *
     * @var string|null
     */
    public ?string $connection;

    /**
     * Create a new DatabaseRefreshed event instance.
     *
     * @param  array  $preserved
     * @param  string|null  $connection
     * @return void
     */
    public function __construct(array $preserved, ?string $connection = null)
    {
        $this->preserved  = $preserved;
        $this->connection = $connection;
    }
}
