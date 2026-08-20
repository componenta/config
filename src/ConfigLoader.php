<?php

declare(strict_types=1);

namespace Componenta\Config;

use Componenta\Config\Exception\ConfigException;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Export;
use Throwable;

/**
 * Builds Config instances from providers and persists fully exportable config
 * snapshots as PHP cache files.
 */
final class ConfigLoader
{
    public static function load(?Environment $environment, callable ...$providers): Config
    {
        return new Config(self::merge($providers), $environment);
    }

    public static function loadFromFile(string $file, bool $populateEnv = false): Config
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new ConfigException(sprintf(
                'Configuration cache file "%s" is not readable.',
                $file,
            ));
        }

        try {
            $cached = include $file;

            if (!is_array($cached)) {
                throw new ConfigException('Invalid configuration cache format.');
            }

            $data = $cached['config'] ?? [];
            if (!is_array($data)) {
                throw new ConfigException('Configuration cache "config" entry must be an array.');
            }

            $environmentData = $cached['environment'] ?? null;
            if ($environmentData !== null && !is_array($environmentData)) {
                throw new ConfigException(
                    'Configuration cache "environment" entry must be an array or null.',
                );
            }

            if ($populateEnv && $environmentData !== null) {
                /** @var array<string, mixed> $environmentData */
                populate_env($environmentData);
            }

            return new Config(
                $data,
                $environmentData === null ? null : new Environment($environmentData),
            );
        } catch (ConfigException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ConfigException(
                sprintf('Failed to load configuration cache "%s": %s', $file, $e->getMessage()),
                previous: $e,
            );
        }
    }

    public static function export(Config $config, string $filename): void
    {
        self::exportToFile($filename, [
            'config' => $config->toArray(),
            'environment' => $config->environment?->toArray(),
        ]);
    }

    /** @param array<array-key, mixed> $data */
    private static function exportToFile(string $filename, array $data): void
    {
        $temporary = null;

        try {
            $directory = dirname($filename);

            if (!is_dir($directory)
                && !mkdir($directory, 0755, true)
                && !is_dir($directory)
            ) {
                throw new \RuntimeException(sprintf(
                    'Cannot create cache directory "%s".',
                    $directory,
                ));
            }

            $exported = Export::pretty(
                $data,
                ExportConfig::pretty()->withTrailingComma(),
            );

            $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn {$exported};\n";
            $temporary = self::temporaryPath($filename);
            $stream = fopen($temporary, 'xb');

            if ($stream === false) {
                throw new \RuntimeException('Cannot create temporary cache file.');
            }

            try {
                self::writeAll($stream, $content);

                if (!fflush($stream)) {
                    throw new \RuntimeException('Cannot flush temporary cache file.');
                }

                if (function_exists('fsync') && !fsync($stream)) {
                    throw new \RuntimeException('Cannot synchronise temporary cache file.');
                }
            } finally {
                fclose($stream);
            }

            self::lint($temporary);

            $wasOpcodeCached = is_file($filename)
                && function_exists('opcache_is_script_cached')
                && @opcache_is_script_cached($filename);

            if ($wasOpcodeCached
                && (!function_exists('opcache_invalidate') || !@opcache_invalidate($filename, true))
            ) {
                throw new \RuntimeException(sprintf(
                    'Cannot replace cached configuration "%s" because its OPcache entry could not be invalidated.',
                    $filename,
                ));
            }

            if (!@rename($temporary, $filename)) {
                throw new \RuntimeException('Cannot activate configuration cache file.');
            }

            $temporary = null;

            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($filename, true);
            }
        } catch (Throwable $e) {
            if ($temporary !== null) {
                @unlink($temporary);
            }

            if ($e instanceof ConfigException) {
                throw $e;
            }

            throw new ConfigException(
                'Failed to export configuration: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    private static function lint(string $file): void
    {
        if (!function_exists('proc_open')) {
            throw new ConfigException(
                'Configuration cache cannot be validated because proc_open() is unavailable.',
            );
        }

        $pipes = [];
        $process = @proc_open(
            [PHP_BINARY, '-n', '-d', 'memory_limit=-1', '-l', $file],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            options: ['bypass_shell' => true],
        );

        $stdin = $pipes[0] ?? null;
        $stdoutPipe = $pipes[1] ?? null;
        $stderrPipe = $pipes[2] ?? null;

        if (!is_resource($process)
            || !is_resource($stdin)
            || !is_resource($stdoutPipe)
            || !is_resource($stderrPipe)
        ) {
            throw new ConfigException(
                'Cannot start PHP syntax validation for a configuration cache artifact.',
            );
        }

        @fclose($stdin);
        $stdout = @stream_get_contents($stdoutPipe);
        $stderr = @stream_get_contents($stderrPipe);
        @fclose($stdoutPipe);
        @fclose($stderrPipe);
        $status = @proc_close($process);

        if ($status !== 0) {
            $output = trim(
                (is_string($stdout) ? $stdout : '')
                . "\n"
                . (is_string($stderr) ? $stderr : ''),
            );

            throw new ConfigException(
                "Configuration cache failed PHP syntax validation:\n" . $output,
            );
        }
    }

    /** @param list<callable> $providers @return array<array-key, mixed> */
    private static function merge(array $providers): array
    {
        $merged = [];

        foreach ($providers as $provider) {
            $data = $provider();

            if (!is_array($data)) {
                if (!is_iterable($data)) {
                    throw new ConfigException(sprintf(
                        'Configuration provider must return an array or iterable; got %s.',
                        get_debug_type($data),
                    ));
                }

                $data = iterator_to_array($data);
            }

            if ($data !== []) {
                $merged = config_merge($merged, $data);
            }
        }

        return $merged;
    }

    private static function temporaryPath(string $filename): string
    {
        return sprintf(
            '%s.tmp.%s.%s',
            $filename,
            getmypid(),
            bin2hex(random_bytes(8)),
        );
    }

    /** @param resource $stream */
    private static function writeAll($stream, string $content): void
    {
        $offset = 0;
        $length = strlen($content);

        while ($offset < $length) {
            $written = fwrite($stream, substr($content, $offset));

            if ($written === false || $written === 0) {
                throw new \RuntimeException('Cannot write temporary cache file.');
            }

            $offset += $written;
        }
    }
}
