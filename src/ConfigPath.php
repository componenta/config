<?php

declare(strict_types=1);

namespace Componenta\Config;

use Componenta\Arrayable\Arrayable;

/**
 * Value object representing a configuration path with dot notation.
 *
 * When used with Config, the path is split by dots to access nested values.
 * Use plain string keys for literal access (keys that contain dots).
 *
 * @example
 * ```php
 * // Dot notation: $data['database']['connections']['mysql']
 * $config->get(new ConfigPath('database.connections.mysql'));
 *
 * // Literal key: $data['database.connections.mysql']
 * $config->get('database.connections.mysql');
 * ```
 */
readonly class ConfigPath implements \Stringable, Arrayable
{
    /**
     * @param string $value The configuration path in dot notation.
     */
    public function __construct(
        public string $value,
    ) {}

    /**
     * Splits path into segments by dot separator.
     *
     * @return string[]
     */
    public function toArray(): array
    {
        return explode('.', $this->value);
    }

    /**
     * Get the first segment of the path.
     */
    public function first(): string
    {
        return $this->toArray()[0];
    }

    /**
     * Get the last segment of the path.
     */
    public function last(): string
    {
        $segments = $this->toArray();
        return $segments[array_key_last($segments)];
    }

    /**
     * Check if path has multiple segments.
     */
    public function isNested(): bool
    {
        return str_contains($this->value, '.');
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
