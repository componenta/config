<?php

declare(strict_types=1);

namespace Componenta\Config\Reader;

use Componenta\Config\Exception\ConfigException;
use JsonException;

final class JsonFileReader implements FileReaderInterface
{
    public function readFile(string $file): ?array
    {
        if (!str_ends_with(strtolower($file), '.json')) {
            return null;
        }

        if (!is_file($file) || !is_readable($file)) {
            throw new ConfigException(sprintf(
                'JSON configuration file "%s" is not readable.',
                $file,
            ));
        }

        $content = file_get_contents($file);
        if ($content === false) {
            throw new ConfigException(sprintf(
                'Failed to read JSON configuration file "%s".',
                $file,
            ));
        }

        try {
            $data = json_decode(
                $content,
                true,
                512,
                JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
            );
        } catch (JsonException $e) {
            throw new ConfigException(
                sprintf('Failed to parse JSON configuration file "%s": %s', $file, $e->getMessage()),
                previous: $e,
            );
        }

        if (!is_array($data)) {
            throw new ConfigException(sprintf(
                'JSON configuration file "%s" must contain an object or array at the root.',
                $file,
            ));
        }

        return $data;
    }
}
