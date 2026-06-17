<?php

declare(strict_types=1);

namespace Componenta\Config\Reader;

use Componenta\Config\Exception\ConfigException;

/**
 * JSON file reader for configuration files.
 *
 * Returns null for non-`.json` paths (signal "not my extension") and for
 * unreadable files. Malformed JSON is reported via {@see ConfigException}
 * so a typo in a config file fails loud rather than silently producing an
 * empty config - null would otherwise conflate three different conditions.
 */
final class JsonFileReader implements FileReaderInterface
{
    public function readFile(string $file): ?array
    {
        if (!str_ends_with($file, '.json')) {
            return null;
        }

        $content = @file_get_contents($file);

        if ($content === false) {
            return null;
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ConfigException(sprintf(
                'Failed to parse JSON config "%s": %s',
                $file,
                $e->getMessage(),
            ), previous: $e);
        }

        return is_array($data) ? $data : null;
    }
}
