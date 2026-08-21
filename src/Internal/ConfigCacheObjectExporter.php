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
use ReflectionFunction;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

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

        $callback = $this->exportCallback(self::callback($object), $depth + 1);
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

    private function exportCallback(Closure $callback, int $depth): string
    {
        $reflection = new ReflectionFunction($callback);

        if ($reflection->getName() === '{closure}') {
            return $this->closureExporter->exportWithDepth($callback, $depth);
        }

        $boundObject = $reflection->getClosureThis();
        if ($boundObject !== null) {
            $method = new ReflectionMethod($boundObject, $reflection->getName());
            if (!$method->isPublic()) {
                throw new RuntimeException(sprintf(
                    'Cannot persist LazyValue callback %s::%s(): method is not public.',
                    $boundObject::class,
                    $method->getName(),
                ));
            }
            if (!$this->fallback->supports($boundObject)) {
                throw new RuntimeException(sprintf(
                    'Cannot persist LazyValue callback target of type %s.',
                    $boundObject::class,
                ));
            }

            $target = $this->fallback->exportWithDepth($boundObject, $depth + 1);

            return sprintf(
                '\\Closure::fromCallable([%s, %s])',
                $target,
                var_export($method->getName(), true),
            );
        }

        $scope = $reflection->getClosureScopeClass();
        if ($scope !== null) {
            $calledClass = $reflection->getClosureCalledClass() ?? $scope;
            $method = new ReflectionMethod($calledClass->getName(), $reflection->getName());
            if (!$method->isPublic() || !$method->isStatic()) {
                throw new RuntimeException(sprintf(
                    'Cannot persist LazyValue callback %s::%s(): method must be public static.',
                    $calledClass->getName(),
                    $method->getName(),
                ));
            }

            return sprintf(
                'static fn($context) => \\%s::%s($context)',
                ltrim($calledClass->getName(), '\\'),
                $method->getName(),
            );
        }

        if (!$reflection->isInternal()) {
            throw new RuntimeException(sprintf(
                'Cannot persist user-defined named function LazyValue callback "%s": use self-contained anonymous closure logic or an autoloadable public method.',
                $reflection->getName(),
            ));
        }

        return sprintf(
            'static fn($context) => \\%s($context)',
            ltrim($reflection->getName(), '\\'),
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
