<?php

namespace Ramadan\CustomFresh\Support;

class ConfigResolver
{
    /**
     * The connection whose overrides should be merged over the global config.
     *
     * @var string|null
     */
    protected ?string $connection;

    /**
     * Create a new config resolver instance.
     *
     * @param  string|null  $connection
     * @return void
     */
    public function __construct(?string $connection = null)
    {
        $this->connection = $connection;
    }

    /**
     * Get a config value, merging per-connection overrides when present.
     *
     * List values are appended; associative arrays are merged with the
     * connection-specific keys winning. Scalars from the connection replace
     * the global value.
     *
     * @param  string  $key
     * @param  mixed  $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null)
    {
        $global = config("custom-fresh.{$key}", $default);

        if ($this->connection === null || $this->connection === '') {
            return $global;
        }

        $specific = config("custom-fresh.connections.{$this->connection}.{$key}");

        if ($specific === null) {
            return $global;
        }

        if (is_array($global) && is_array($specific)) {
            if ($this->isList($global) && $this->isList($specific)) {
                return array_values(array_unique(array_merge($global, $specific)));
            }

            return array_merge($global, $specific);
        }

        return $specific;
    }

    /**
     * Determine whether the array is a sequential list.
     *
     * @param  array<mixed>  $value
     * @return bool
     */
    protected function isList(array $value)
    {
        return $value === [] || array_is_list($value);
    }
}
