<?php

declare(strict_types=1);

namespace Componenta\Config\Loader;

use Componenta\Config\Environment;
use Componenta\Config\Exception\EnvLoaderException;
use InvalidArgumentException;

use function Componenta\Config\populate_env;

/**
 * Loads explicitly named dotenv files from one or more directories.
 *
 * Default precedence is `.env` followed by `.env.local`. Later files override
 * earlier file values; existing runtime values remain authoritative unless
 * load(override: true) is requested.
 */
final class EnvLoader implements EnvLoaderInterface
{
    private const array DEFAULT_FILENAMES = ['.env', '.env.local'];

    /** @var list<string> */
    private readonly array $paths;

    /** @var list<string> */
    private readonly array $required;

    /** @var list<string> */
    private readonly array $filenames;

    /**
     * @param string|list<string> $paths
     * @param list<string>|null $required
     * @param list<string>|null $filenames Exact basenames in precedence order.
     */
    public function __construct(
        string|array $paths,
        ?array $required = null,
        ?array $filenames = null,
    ) {
        $this->paths = $this->normalizePaths((array) $paths);
        $this->required = $this->normalizeRequired($required ?? []);
        $this->filenames = $this->normalizeFilenames($filenames ?? self::DEFAULT_FILENAMES);
    }

    /** @return array<string, string>|null */
    public function read(): ?array
    {
        $loaded = [];
        $found = false;

        foreach ($this->paths as $path) {
            foreach ($this->files($path) as $filePath) {
                $content = file_get_contents($filePath);
                if ($content === false) {
                    throw EnvLoaderException::fileNotReadable($filePath);
                }

                $found = true;
                $loaded = [...$loaded, ...$this->parseContent($content, $filePath)];
            }
        }

        return $found ? $loaded : null;
    }

    public function load(bool $override = false): Environment
    {
        $loaded = $this->read() ?? [];

        if ($loaded !== []) {
            populate_env($loaded, $override);
        }

        $environment = Environment::fromGlobals();
        $this->validateRequired($environment->toArray());

        return $environment;
    }

    /** @return list<string> */
    private function files(string $path): array
    {
        $files = [];

        foreach ($this->filenames as $filename) {
            $candidate = $path . DIRECTORY_SEPARATOR . $filename;
            if (is_file($candidate)) {
                $files[] = $candidate;
            }
        }

        return $files;
    }

    /** @param list<mixed> $paths @return list<string> */
    private function normalizePaths(array $paths): array
    {
        $normalized = [];

        foreach ($paths as $path) {
            if (!is_string($path) || $path === '' || str_contains($path, "\0")) {
                throw new InvalidArgumentException(
                    'Environment paths must be non-empty strings without null bytes.',
                );
            }

            if (!in_array($path, $normalized, true)) {
                $normalized[] = $path;
            }
        }

        return $normalized;
    }

    /** @param list<mixed> $required @return list<string> */
    private function normalizeRequired(array $required): array
    {
        $normalized = [];

        foreach ($required as $name) {
            if (!is_string($name)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1
            ) {
                throw new InvalidArgumentException(
                    'Required environment variable names must be valid identifiers.',
                );
            }

            if (!in_array($name, $normalized, true)) {
                $normalized[] = $name;
            }
        }

        return $normalized;
    }

    /** @param list<mixed> $filenames @return list<string> */
    private function normalizeFilenames(array $filenames): array
    {
        $normalized = [];

        foreach ($filenames as $filename) {
            if (!is_string($filename)
                || $filename === ''
                || basename($filename) !== $filename
                || str_contains($filename, "\0")
            ) {
                throw new InvalidArgumentException(
                    'Environment filenames must be non-empty basenames.',
                );
            }

            if (!in_array($filename, $normalized, true)) {
                $normalized[] = $filename;
            }
        }

        return $normalized;
    }

    /** @return array<string, string> */
    private function parseContent(string $content, string $filePath): array
    {
        $vars = [];

        foreach (preg_split('/\R/', $content) ?: [] as $lineNumber => $rawLine) {
            $line = trim($rawLine);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                throw EnvLoaderException::parseError($filePath, $lineNumber + 1, $line);
            }

            $key = trim(substr($line, 0, $pos));
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) !== 1) {
                throw EnvLoaderException::parseError($filePath, $lineNumber + 1, $line);
            }

            $vars[$key] = $this->parseValue(
                trim(substr($line, $pos + 1)),
                $filePath,
                $lineNumber + 1,
            );
        }

        return $vars;
    }

    private function parseValue(string $value, string $filePath, int $lineNumber): string
    {
        if ($value === '') {
            return '';
        }

        $quote = $value[0];
        if ($quote !== '"' && $quote !== "'") {
            return $value;
        }

        $length = strlen($value);
        if ($length < 2 || $value[$length - 1] !== $quote) {
            throw EnvLoaderException::parseError(
                $filePath,
                $lineNumber,
                sprintf('unbalanced %s quote in value', $quote),
            );
        }

        $inner = substr($value, 1, -1);

        if ($quote === "'") {
            return $inner;
        }

        return strtr($inner, [
            '\\\\' => "\\",
            '\\n' => "\n",
            '\\r' => "\r",
            '\\t' => "\t",
            '\\"' => '"',
        ]);
    }

    /** @param array<string, mixed> $effective */
    private function validateRequired(array $effective): void
    {
        if ($this->required === []) {
            return;
        }

        $missing = array_values(array_diff($this->required, array_keys($effective)));
        if ($missing !== []) {
            throw EnvLoaderException::requiredVariablesMissing($missing);
        }
    }
}
