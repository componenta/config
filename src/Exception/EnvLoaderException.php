<?php

declare(strict_types=1);

namespace Componenta\Config\Exception;

use RuntimeException;

/** Exception thrown when runtime environment loading fails. */
class EnvLoaderException extends RuntimeException implements ConfigExceptionInterface
{
    /** @param list<string> $missing Missing variable names */
    public static function requiredVariablesMissing(array $missing): self
    {
        return new self(
            'Required environment variables are missing: ' . implode(', ', $missing),
        );
    }

    public static function parseError(string $file, int $line, ?string $reason = null): self
    {
        $message = "Invalid .env format on line $line in file '$file'";

        if ($reason !== null && $reason !== '') {
            $message .= ': ' . $reason;
        }

        return new self($message);
    }

    public static function fileNotReadable(string $file): self
    {
        return new self("Cannot read environment file '$file'");
    }
}
