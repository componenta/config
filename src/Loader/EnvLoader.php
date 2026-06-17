<?php

declare(strict_types=1);

namespace Componenta\Config\Loader;

use Componenta\Config\Environment;
use Componenta\Config\Exception\EnvLoaderException;

use function Componenta\Config\populate_env;

/**
 * Environment variable loader from .env files.
 *
 * Features:
 * - Auto-discovery of .env* files via glob
 * - Quoted value parsing with escape sequences
 * - Variable name validation (POSIX standards)
 * - Required variable validation
 * - Populates $_ENV and optionally $_SERVER
 *
 * File format:
 * - Simple: KEY=value
 * - Quoted: KEY="value with spaces" or KEY='literal value'
 * - Comments: # this is a comment
 * - Escape sequences in double quotes: \n, \r, \t, \\, \"
 */
final class EnvLoader implements EnvLoaderInterface
{
    /** @var string[] */
    private readonly array $paths;

    /** @var string[] */
    private readonly array $required;

    /**
     * @param string|string[] $paths Paths to search for .env* files
     * @param string[]|null $required Variables that must be present
     */
    public function __construct(
        string|array $paths,
        ?array $required = null,
    ) {
        $this->paths = (array) $paths;
        $this->required = $required ?? [];
    }

    public function load(bool $override = false, bool $populateServer = true): ?Environment
    {
        $loaded = [];
        $found = false;

        foreach ($this->paths as $path) {
            $files = glob($path . DIRECTORY_SEPARATOR . '.env*');

            if ($files === false || $files === []) {
                continue;
            }

            sort($files);

            foreach ($files as $filePath) {
                $content = file_get_contents($filePath);

                if ($content === false) {
                    throw EnvLoaderException::fileNotReadable($filePath);
                }

                $found = true;
                $loaded = [...$loaded, ...$this->parseContent($content, $filePath)];
            }
        }

        if (!$found) {
            if ($_ENV !== []) return Environment::fromGlobals();
            return null;
        }

        $this->validateRequired($loaded);

        populate_env($loaded, $override, $populateServer);

        return new Environment($loaded);
    }

    /**
     * @return array<string, string>
     */
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

            $vars[$key] = $this->parseValue(
                trim(substr($line, $pos + 1)),
                $filePath,
                $lineNumber + 1,
            );
        }

        return $vars;
    }

    /**
     * Parses a `.env` value, honouring quoted forms.
     *
     * If a value starts with a `"` or `'`, it MUST end with the same
     * quote character - an unbalanced quoted value is reported via
     * {@see EnvLoaderException::parseError()} rather than silently leaving
     * the opening quote in the result. Unquoted values are returned as-is.
     */
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
            throw EnvLoaderException::parseError(
                $filePath,
                $lineNumber,
                "unbalanced $first quote in value: $value",
            );
        }

        $inner = substr($value, 1, -1);

        return $first === '"'
            ? str_replace(
                ['\\n', '\\r', '\\t', '\\"', '\\\\'],
                ["\n", "\r", "\t", '"', '\\'],
                $inner,
            )
            : $inner;
    }

    /**
     * @param array<string, string> $loaded
     */
    private function validateRequired(array $loaded): void
    {
        if ($this->required === []) {
            return;
        }

        $missing = array_diff($this->required, array_keys($loaded));

        if ($missing !== []) {
            throw EnvLoaderException::requiredVariablesMissing($missing);
        }
    }
}