<?php

declare(strict_types=1);

namespace Componenta\Config\Internal;

use Closure;
use Componenta\Config\LazyValue;
use Componenta\VarExport\ClosureExporter;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Contract\ClosureExporterInterface;
use Componenta\VarExport\Contract\ObjectExporterInterface;
use Componenta\VarExport\ObjectExporter;
use ReflectionProperty;

/** @internal Persistent-config object exporter for config-specific wrappers. */
final readonly class ConfigCacheObjectExporter implements ObjectExporterInterface
{
    private ClosureExporterInterface $closureExporter;
    private ObjectExporterInterface $fallback;

    public function __construct(
        ExportConfig $config,
        ?ClosureExporterInterface $closureExporter = null,
        ?ObjectExporterInterface $fallback = null,
    ) {
        $this->closureExporter = $closureExporter ?? new ClosureExporter($config);
        $this->fallback = $fallback ?? new ObjectExporter($config);
    }

    public function export(object $object): string
    {
        return $this->exportWithDepth($object, 0);
    }

    public function exportWithDepth(object $object, int $depth): string
    {
        if (!$object instanceof LazyValue) {
            return $this->fallback->exportWithDepth($object, $depth);
        }

        $callback = $this->closureExporter->exportWithDepth(
            self::callback($object),
            $depth + 1,
        );
        $cache = $object->cache ? 'true' : 'false';

        return sprintf(
            'new \\%s(%s, cache: %s)',
            LazyValue::class,
            $callback,
            $cache,
        );
    }

    public function supports(object $object): bool
    {
        return $object instanceof LazyValue || $this->fallback->supports($object);
    }

    public function withConfig(ExportConfig $config): static
    {
        return new self(
            $config,
            $this->closureExporter->withConfig($config),
            $this->fallback->withConfig($config),
        );
    }

    private static function callback(LazyValue $value): Closure
    {
        $callback = (new ReflectionProperty(LazyValue::class, 'callback'))->getValue($value);

        if (!$callback instanceof Closure) {
            throw new \LogicException('LazyValue callback invariant is broken.');
        }

        return $callback;
    }
}
