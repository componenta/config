<?php

declare(strict_types=1);

namespace Componenta\Config\Reader;

/**
 * PHP file reader for configuration files.
 *
 * Includes PHP files that return arrays.
 */
final class PhpFileReader implements FileReaderInterface
{
    public function readFile(string $file): ?array
    {
        if (!str_ends_with($file, '.php')) {
            return null;
        }

        if (!file_exists($file) || !is_readable($file)) {
            return null;
        }

        $data = include $file;

        return is_array($data) ? $data : null;
    }
}
