<?php

declare(strict_types=1);

namespace Componenta\Config\Exception;

use RuntimeException;
use Throwable;

/** Exception thrown when required configuration data is unavailable or invalid. */
class ConfigException extends RuntimeException implements ConfigExceptionInterface
{
    public function __construct(
        string $message,
        public readonly ?string $key = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function missingKey(string $key): self
    {
        return new self(
            "Configuration key '$key' is missing",
            $key,
        );
    }
}
