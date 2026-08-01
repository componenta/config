<?php

declare(strict_types=1);

namespace Componenta\Config;

use Componenta\Config\Exception\ConfigException;

final class ConfigShardCache
{
    /** @var array<string, mixed> */
    private static array $values = [];

    private function __construct() {}

    public static function load(string $file): mixed
    {
        if (array_key_exists($file, self::$values)) {
            return self::$values[$file];
        }

        if (!is_file($file)) {
            throw new ConfigException(sprintf('Configuration shard does not exist: %s', $file));
        }

        try {
            return self::$values[$file] = require $file;
        } catch (ConfigException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new ConfigException(sprintf(
                'Failed to load configuration shard "%s": %s',
                $file,
                $exception->getMessage(),
            ));
        }
    }
}
