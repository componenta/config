<?php

declare(strict_types=1);

namespace Componenta\Config\Reader;

use Componenta\Config\Exception\ConfigException;
use Throwable;

final class PhpFileReader implements FileReaderInterface
{
    /** @return array<array-key, mixed>|null */
    public function readFile(string $file): ?array
    {
        if (!str_ends_with(strtolower($file), '.php')) {
            return null;
        }

        if (!is_file($file) || !is_readable($file)) {
            throw new ConfigException(sprintf(
                'PHP configuration file "%s" is not readable.',
                $file,
            ));
        }

        try {
            $data = include $file;
        } catch (Throwable $e) {
            throw new ConfigException(
                sprintf('Failed to load PHP configuration file "%s": %s', $file, $e->getMessage()),
                previous: $e,
            );
        }

        if (!is_array($data)) {
            throw new ConfigException(sprintf(
                'PHP configuration file "%s" must return an array.',
                $file,
            ));
        }

        return $data;
    }
}
