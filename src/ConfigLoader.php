<?php

declare(strict_types=1);

namespace Componenta\Config;

use Componenta\Config\Exception\ConfigException;
use Componenta\VarExport\Export;
use Componenta\VarExport\Config\ExportConfig;

/**
 * Configuration loader and exporter.
 *
 * Responsible for:
 *  - loading configuration data from providers
 *  - loading configuration from a pre-generated PHP cache file
 *  - exporting configuration into a PHP cache file
 *
 * This class does NOT decide whether to use cache or providers -
 * that responsibility is delegated to the application bootstrap logic.
 *
 * @example
 * ```php
 * // Load from providers (development)
 * $config = ConfigLoader::load(
 *     $environment,
 *     fn () => require 'config/app.php',
 *     fn () => require 'config/db.php',
 * );
 *
 * // Load from cache (production) with $_ENV population
 * $config = ConfigLoader::loadFromFile(
 *     'var/cache/config.php',
 *     populateEnv: true,
 * );
 *
 * // Export configuration during build/deploy
 * ConfigLoader::export($config, 'var/cache/config.php');
 * ```
 */
final class ConfigLoader
{
    /**
     * Load configuration from providers.
     *
     * Aggregates configuration arrays returned by providers using
     * recursive replacement (later providers override earlier ones).
     *
     * Providers must return an array or iterable.
     *
     * @param ?Environment $environment Environment container
     * @param callable ...$providers Configuration providers
     *
     * @throws ConfigException If a provider returns an invalid value
     */
    public static function load(?Environment $environment, callable ...$providers): Config
    {
        return new Config(self::merge($providers), $environment);
    }

    /**
     * Load configuration from a PHP cache file.
     *
     * The cache file must return an array with the following structure:
     *
     * @phpstan-type ConfigCache array{
     *     config: array,
     *     environment?: array
     * }
     *
     * @param string $file Path to cache file
     * @param bool $populateEnv Whether to populate $_ENV/$_SERVER from cached environment
     *
     * @return Config
     */
    public static function loadFromFile(string $file, bool $populateEnv = false): Config
    {
        try {
            $cached = include $file;

            if (!is_array($cached)) {
                throw new ConfigException('Invalid cache file format');
            }

            $environment = null;

            if (isset($cached['environment'])) {
                if ($populateEnv) {
                    populate_env($cached['environment']);
                }

                $environment = new Environment($cached['environment']);
            }

            return new Config($cached['config'] ?? [], $environment);

        } catch (ConfigException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ConfigException(
                'Failed to load configuration from cache: ' . $e->getMessage(),
            );
        }
    }

    /**
     * Export configuration to a PHP cache file.
     *
     * The resulting file can be loaded using {@see loadFromFile()}.
     *
     * @param Config $config Configuration instance
     * @param string $filename Destination file path
     *
     * @throws ConfigException If export fails
     */
    public static function export(Config $config, string $filename): void
    {
        self::exportToFile($filename, [
            'config' => $config->toArray(),
            'environment' => $config->environment?->toArray(),
        ]);
    }

    /**
     * Export raw configuration data to a PHP file.
     *
     * The file is written atomically using a temporary file
     * and invalidates OPcache if available.
     *
     * @param string $filename Destination file path
     * @param array $data Data to export
     *
     * @throws ConfigException If export fails
     */
    private static function exportToFile(string $filename, array $data): void
    {
        try {
            $directory = dirname($filename);

            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            $exported = Export::pretty(
                $data,
                ExportConfig::pretty()->withTrailingComma()
            );

            $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn $exported;\n";
            $tempFile = $filename . '.tmp.' . uniqid('', true);

            if (file_put_contents($tempFile, $content) === false) {
                throw new \RuntimeException('Failed to write temporary file');
            }

            if (!rename($tempFile, $filename)) {
                @unlink($tempFile);
                throw new \RuntimeException('Failed to replace cache file');
            }

            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($filename, true);
            }

        } catch (\Throwable $e) {
            throw new ConfigException(
                'Failed to export configuration: ' . $e->getMessage(),
            );
        }
    }

    /**
     * Merge configuration data from providers.
     *
     * Later providers override values from earlier ones.
     *
     * @param callable[] $providers
     *
     * @return array
     * @throws ConfigException If a provider returns a non-array value
     */
    private static function merge(array $providers): array
    {
        $merged = [];

        foreach ($providers as $provider) {
            $data = $provider();

            if (!is_array($data)) {
                if (is_iterable($data)) {
                    $data = iterator_to_array($data);
                } else {
                    throw new ConfigException(
                        'Configuration provider must return an array or iterable'
                    );
                }
            }

            if ($data === []) {
                continue;
            }

            $merged = config_merge($merged, $data);
        }

        return $merged;
    }
}
