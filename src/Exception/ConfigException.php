<?php

declare(strict_types=1);

namespace Componenta\Config\Exception;

use RuntimeException;
use Throwable;

/**
 * Exception thrown when configuration key is missing or null.
 */
class ConfigException extends RuntimeException implements ConfigExceptionInterface
{
    public function __construct(
        string $message,
        public readonly ?string $key = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Create exception for missing configuration key.
     */
    public static function missingKey(string $key): self
    {
        return new self(
            "Configuration key '$key' is missing",
            $key,
        );
    }

    /**
     * Create exception for null configuration value.
     */
    public static function nullValue(string $key): self
    {
        return new self(
            "Configuration key '$key' is null",
            $key,
        );
    }
}
