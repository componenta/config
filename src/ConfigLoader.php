<?php

declare(strict_types=1);

namespace Componenta\Config;

use Closure;
use Componenta\Config\Exception\ConfigException;
use Componenta\Config\Internal\ConfigCacheObjectExporter;
use Componenta\VarExport\Config\ClosureExportPolicy;
use Componenta\VarExport\Config\ClosureUseMode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Config\SourcePathPolicy;
use Componenta\VarExport\VarExporter;
use SplObjectStorage;
use Throwable;
use UnitEnum;

/** Builds runtime Config snapshots and persists portable config cache data. */
final class ConfigLoader
{
    public const int CACHE_VERSION = 5;

    /** @var array<string, true> */
    private const array CACHE_KEYS = [
        'version' => true,
        'config' => true,
    ];

    public static function load(Environment $environment, callable ...$providers): Config
    {
        return new Config(self::merge($providers), $environment);
    }

    public static function loadFromFile(string $file, Environment $environment): Config
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

            return new Config(self::configFromCache($cached), $environment);
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
        $persistent = $config->toArray();
        unset($persistent[ConfigKey::DEPENDENCIES]);

        self::exportToFile($filename, [
            'version' => self::CACHE_VERSION,
            'config' => $persistent,
        ]);
    }

    /** @param array<array-key, mixed> $cache @return array<array-key, mixed> */
    private static function configFromCache(array $cache): array
    {
        foreach ($cache as $key => $_value) {
            if (!is_string($key) || !isset(self::CACHE_KEYS[$key])) {
                throw new ConfigException(sprintf(
                    'Unsupported configuration cache envelope key "%s".',
                    (string) $key,
                ));
            }
        }

        if (($cache['version'] ?? null) !== self::CACHE_VERSION) {
            throw new ConfigException(sprintf(
                'Unsupported configuration cache version; expected %d.',
                self::CACHE_VERSION,
            ));
        }

        if (!array_key_exists('config', $cache) || !is_array($cache['config'])) {
            throw new ConfigException('Configuration cache "config" entry must be an array.');
        }

        $config = $cache['config'];
        if (array_key_exists(ConfigKey::DEPENDENCIES, $config)) {
            throw new ConfigException(sprintf(
                'Configuration cache must not contain reserved DI root "%s".',
                ConfigKey::DEPENDENCIES,
            ));
        }

        return $config;
    }

    /** @param array<array-key, mixed> $data */
    private static function exportToFile(string $filename, array $data): void
    {
        $temporary = null;

        try {
            /** @var SplObjectStorage<object, string> $seen */
            $seen = new SplObjectStorage();
            self::assertNoSharedObjectIdentity($data, $seen);

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

            $exportConfig = ExportConfig::pretty()
                ->withTrailingComma()
                ->withClosureUseMode(ClosureUseMode::Inline)
                ->withClosureExportPolicy(ClosureExportPolicy::PortableExpression)
                ->withSourcePathPolicy(SourcePathPolicy::Reject);

            $exported = (new VarExporter(
                $exportConfig,
                objectExporter: new ConfigCacheObjectExporter($exportConfig),
            ))->export($data);

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

    /**
     * VarExport intentionally uses value semantics and does not preserve repeated
     * object identity. Config rejects shared object/Closure identity rather than
     * silently changing LazyValue caches, Closure static state, or strict ===
     * relationships between provider mode and persistent-cache mode.
     *
     * @param SplObjectStorage<object, string> $seen
     * @param list<int|string> $path
     */
    private static function assertNoSharedObjectIdentity(
        mixed $value,
        SplObjectStorage $seen,
        array $path = [],
        int $depth = 0,
    ): void {
        if ($depth > ExportConfig::DEFAULT_MAX_DEPTH) {
            throw new \RuntimeException(sprintf(
                'Maximum nesting depth of %d exceeded during configuration cache preflight at %s.',
                ExportConfig::DEFAULT_MAX_DEPTH,
                self::formatPath($path),
            ));
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (\ReflectionReference::fromArrayElement($value, $key) !== null) {
                    throw new \RuntimeException(sprintf(
                        'Configuration cache contains a PHP array reference at %s.',
                        self::formatPath([...$path, $key]),
                    ));
                }

                self::assertNoSharedObjectIdentity($item, $seen, [...$path, $key], $depth + 1);
            }

            return;
        }

        if (!is_object($value) || $value instanceof UnitEnum) {
            return;
        }

        $location = self::formatPath($path);
        if ($seen->contains($value)) {
            throw new \RuntimeException(sprintf(
                'Configuration cache cannot preserve shared object identity between %s and %s. '
                . 'Use distinct value objects or an explicit cache representation.',
                $seen[$value],
                $location,
            ));
        }

        $seen[$value] = $location;

        if ($value instanceof ConfigEntry) {
            self::assertNoSharedObjectIdentity($value->key, $seen, [...$path, 'key'], $depth + 1);
            self::assertNoSharedObjectIdentity($value->default, $seen, [...$path, 'default'], $depth + 1);

            return;
        }

        if ($value instanceof EnvironmentEntry) {
            self::assertNoSharedObjectIdentity($value->key, $seen, [...$path, 'key'], $depth + 1);
            self::assertNoSharedObjectIdentity($value->default, $seen, [...$path, 'default'], $depth + 1);

            return;
        }

        if ($value instanceof LazyValue) {
            self::assertNoSharedObjectIdentity($value->callback(), $seen, [...$path, 'callback'], $depth + 1);

            return;
        }

        if ($value instanceof Closure) {
            $boundObject = (new \ReflectionFunction($value))->getClosureThis();
            if ($boundObject !== null) {
                self::assertNoSharedObjectIdentity(
                    $boundObject,
                    $seen,
                    [...$path, 'boundThis'],
                    $depth + 1,
                );
            }

            return;
        }
    }

    /** @param list<int|string> $path */
    private static function formatPath(array $path): string
    {
        if ($path === []) {
            return 'root';
        }

        $result = '$config';
        foreach ($path as $segment) {
            $result .= is_int($segment)
                ? sprintf('[%d]', $segment)
                : sprintf('[%s]', var_export($segment, true));
        }

        return $result;
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
