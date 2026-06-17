<?php

declare(strict_types=1);

namespace Componenta\Config\Exception;

use RuntimeException;

/**
 * Exception thrown when environment loading fails.
 */
class EnvLoaderException extends RuntimeException implements ConfigExceptionInterface
{
    /**
     * Create exception for missing .env files.
     *
     * @param string[] $paths Searched paths
     * @param string[] $files Searched filenames
     */
    public static function filesNotFound(array $paths, array $files): self
    {
        return new self(
            'No .env files found in paths: ' . implode(', ', $paths) .
            ' (looking for: ' . implode(', ', $files) . ')'
        );
    }

    /**
     * Create exception for missing required variables.
     *
     * @param string[] $missing Missing variable names
     */
    public static function requiredVariablesMissing(array $missing): self
    {
        return new self(
            'Required environment variables are missing: ' . implode(', ', $missing)
        );
    }

    /**
     * Create exception for parse errors.
     */
    public static function parseError(string $file, int $line, string $content): self
    {
        return new self(
            "Invalid .env format on line $line in file '$file': $content"
        );
    }

    /**
     * Create exception for file access errors.
     */
    public static function fileNotReadable(string $file): self
    {
        return new self("Cannot read environment file '$file'");
    }
}
