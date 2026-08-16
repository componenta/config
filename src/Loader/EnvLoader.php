<?php

declare(strict_types=1);

namespace Componenta\Config\Loader;

use Componenta\Config\Environment;
use Componenta\Config\Exception\EnvLoaderException;
use InvalidArgumentException;

use function Componenta\Config\populate_env;

/** Loads environment variables from either an explicit file list or legacy .env* discovery. */
final class EnvLoader implements EnvLoaderInterface
{
    /** @var list<string> */ private readonly array $paths;
    /** @var list<string> */ private readonly array $required;
    /** @var list<string>|null */ private readonly ?array $filenames;

    /**
     * @param string|list<string> $paths
     * @param list<string>|null $required
     * @param list<string>|null $filenames Exact basenames, in precedence order. Null preserves legacy .env* discovery.
     */
    public function __construct(string|array $paths, ?array $required = null, ?array $filenames = null)
    {
        $this->paths = array_values((array) $paths);
        $this->required = array_values($required ?? []);
        $this->filenames = $filenames === null ? null : $this->normalizeFilenames($filenames);
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

    public function load(bool $override = false, bool $populateServer = true): ?Environment
    {
        $loaded = $this->read() ?? [];
        if ($loaded !== []) {
            populate_env($loaded, $override, $populateServer);
        }

        $effective = [...$loaded, ...self::processData()];
        $this->validateRequired($effective);

        return $effective === [] ? null : new Environment($effective);
    }

    public static function processEnvironment(): Environment
    {
        return new Environment(self::processData());
    }

    /** @return array<string, mixed> */
    private static function processData(): array
    {
        $data = [];
        $native = getenv();

        if (is_array($native)) {
            foreach ($native as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $data[$key] = $value;
                }
            }
        }

        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && (is_string($value) || is_int($value) || is_float($value) || is_bool($value))) {
                $data[$key] = $value;
            }
        }

        foreach ($_ENV as $key => $value) {
            if (is_string($key)) {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /** @return list<string> */
    private function files(string $path): array
    {
        if ($this->filenames === null) {
            $files = glob($path . DIRECTORY_SEPARATOR . '.env*');
            if ($files === false) {
                return [];
            }
            sort($files);
            return array_values(array_filter($files, 'is_file'));
        }

        $files = [];
        foreach ($this->filenames as $filename) {
            $candidate = $path . DIRECTORY_SEPARATOR . $filename;
            if (is_file($candidate)) {
                $files[] = $candidate;
            }
        }
        return $files;
    }

    /** @param list<string> $filenames @return list<string> */
    private function normalizeFilenames(array $filenames): array
    {
        $normalized = [];
        foreach ($filenames as $filename) {
            if (!is_string($filename) || $filename === '' || basename($filename) !== $filename || str_contains($filename, "\0")) {
                throw new InvalidArgumentException('Environment filenames must be non-empty basenames.');
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
        foreach (explode("\n", $content) as $lineNumber => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                throw EnvLoaderException::parseError($filePath, $lineNumber + 1, $line);
            }
            $key = trim(substr($line, 0, $pos));
            if ($key === '' || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
                throw EnvLoaderException::parseError($filePath, $lineNumber + 1, $line);
            }
            $vars[$key] = $this->parseValue(trim(substr($line, $pos + 1)), $filePath, $lineNumber + 1);
        }
        return $vars;
    }

    private function parseValue(string $value, string $filePath, int $lineNumber): string
    {
        if ($value === '') {
            return '';
        }
        $first = $value[0];
        if ($first !== '"' && $first !== "'") {
            return $value;
        }
        $length = strlen($value);
        if ($length < 2 || $value[$length - 1] !== $first) {
            throw EnvLoaderException::parseError($filePath, $lineNumber, "unbalanced $first quote in value: $value");
        }
        $inner = substr($value, 1, -1);
        return $first === '"'
            ? str_replace(['\\n', '\\r', '\\t', '\\"', '\\\\'], ["\n", "\r", "\t", '"', '\\'], $inner)
            : $inner;
    }

    /** @param array<string, mixed> $effective */
    private function validateRequired(array $effective): void
    {
        if ($this->required === []) {
            return;
        }
        $missing = array_diff($this->required, array_keys($effective));
        if ($missing !== []) {
            throw EnvLoaderException::requiredVariablesMissing($missing);
        }
    }
}
