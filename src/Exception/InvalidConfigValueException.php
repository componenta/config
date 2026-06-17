<?php

declare(strict_types=1);

namespace Componenta\Config\Exception;

use InvalidArgumentException;

/**
 * Exception thrown when configuration value cannot be converted to requested type.
 */
class InvalidConfigValueException extends InvalidArgumentException implements ConfigExceptionInterface
{
    public function __construct(
        string $message,
        public readonly ?string $key = null,
        public readonly ?string $expectedType = null,
        public readonly ?string $actualType = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Create exception for type conversion failure.
     */
    public static function cannotConvert(string $key, string $expectedType, mixed $actualValue): self
    {
        $actualType = get_debug_type($actualValue);

        return new self(
            "Cannot convert configuration key '$key' to $expectedType (got $actualType)",
            $key,
            $expectedType,
            $actualType,
        );
    }
}
