<?php

declare(strict_types=1);

namespace Componenta\Config\Exception;

use Throwable;

final class ConfigResolutionException extends ConfigException
{
    /** @param non-empty-list<string> $path */
    public function __construct(
        string $message,
        public readonly array $path,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /** @param non-empty-list<string> $path */
    public static function cycle(array $path): self
    {
        return new self(
            'Circular configuration resolution detected: ' . implode(' -> ', $path) . '.',
            $path,
        );
    }

    public static function lazyValueCycle(): self
    {
        return new self(
            'Circular LazyValue resolution detected for the same runtime context and Fiber.',
            ['LazyValue'],
        );
    }

    /** @param non-empty-list<string> $path */
    public static function descriptorCycle(array $path): self
    {
        return new self(
            'Circular descriptor result detected: ' . implode(' -> ', $path) . '.',
            $path,
        );
    }

    /** @param non-empty-list<string> $path */
    public static function arrayCycle(array $path): self
    {
        return new self(
            'Circular configuration array detected at ' . implode('.', $path) . '.',
            $path,
        );
    }

    /** @param non-empty-list<string> $path */
    public static function maximumDepth(array $path, int $maximum): self
    {
        return new self(
            sprintf(
                'Configuration resolution exceeds maximum depth of %d at %s.',
                $maximum,
                implode('.', $path),
            ),
            $path,
        );
    }
}
