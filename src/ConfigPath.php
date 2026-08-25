<?php

declare(strict_types=1);

namespace Componenta\Config;

use Componenta\Arrayable\Arrayable;
use InvalidArgumentException;

/**
 * Value object representing a non-empty configuration path with dot notation.
 *
 * When used with Config, the path is split by dots to access nested values.
 * Use plain string keys for literal access (keys that contain dots).
 */
final readonly class ConfigPath implements \Stringable, Arrayable
{
    /** @var non-empty-list<string> */
    private array $segments;

    public function __construct(
        public string $value,
    ) {
        if ($value === '') {
            throw new InvalidArgumentException('Config path must not be empty.');
        }

        $segments = explode('.', $value);
        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new InvalidArgumentException(
                    'Config path segments must not be empty.',
                );
            }
        }

        $this->segments = $segments;
    }

    /** @return non-empty-list<string> */
    public function toArray(): array
    {
        return $this->segments;
    }

    public function first(): string
    {
        return $this->segments[0];
    }

    public function last(): string
    {
        return $this->segments[array_key_last($this->segments)];
    }

    public function isNested(): bool
    {
        return count($this->segments) > 1;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
