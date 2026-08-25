<?php

declare(strict_types=1);

namespace Componenta\Config\Internal;

use Closure;
use Componenta\Config\ConfigEntry;
use Componenta\Config\ConfigPath;
use Componenta\Config\ContainerEntry;
use Componenta\Config\EnvironmentEntry;
use Componenta\Config\LazyValue;
use Componenta\VarExport\ClosureExporter;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Contract\ClosureExporterInterface;
use Componenta\VarExport\Contract\ContextualClosureExporterInterface;
use Componenta\VarExport\Contract\ContextualObjectExporterInterface;
use Componenta\VarExport\Contract\ContextualValueExporterInterface;
use Componenta\VarExport\Contract\ObjectExporterInterface;
use Componenta\VarExport\ExportContext;
use Componenta\VarExport\ObjectExporter;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/** @internal Config-owned object strategy for persistent cache values. */
final readonly class ConfigCacheObjectExporter implements ContextualObjectExporterInterface
{
    private ClosureExporterInterface $closureExporter;
    private ObjectExporterInterface $fallback;

    public function __construct(
        private ExportConfig $config,
        ?ClosureExporterInterface $closureExporter = null,
        ?ObjectExporterInterface $fallback = null,
        private ?ContextualValueExporterInterface $valueExporter = null,
    ) {
        $this->closureExporter = $closureExporter ?? new ClosureExporter($config);
        $this->fallback = $fallback ?? new ObjectExporter($config);
    }

    public function export(object $object): string
    {
        return $this->exportWithContext($object, ExportContext::root());
    }

    public function exportWithDepth(object $object, int $depth): string
    {
        return $this->exportWithContext(
            $object,
            new ExportContext($depth, baseIndent: str_repeat($this->config->indent, $depth)),
        );
    }

    public function exportWithContext(object $object, ExportContext $context): string
    {
        return match (true) {
            $object instanceof LazyValue => $this->exportLazyValue($object, $context),
            $object instanceof ConfigEntry => $this->exportConstructed(ConfigEntry::class, ['key' => $object->key, 'default' => $object->default], $context),
            $object instanceof EnvironmentEntry => $this->exportConstructed(EnvironmentEntry::class, ['key' => $object->key, 'default' => $object->default], $context),
            $object instanceof ContainerEntry => $this->exportConstructed(ContainerEntry::class, ['id' => $object->id, 'type' => $object->type], $context),
            $object instanceof ConfigPath => $this->exportConstructed(ConfigPath::class, ['value' => $object->value], $context),
            $this->fallback instanceof ContextualObjectExporterInterface => $this->fallback->exportWithContext($object, $context),
            default => $this->fallback->exportWithDepth($object, $context->depth),
        };
    }

    public function supports(object $object): bool
    {
        try {
            $this->export($object);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function withConfig(ExportConfig $config): static
    {
        return new self(
            $config,
            $this->closureExporter->withConfig($config),
            $this->fallback->withConfig($config),
        );
    }

    public function withValueExporter(ContextualValueExporterInterface $valueExporter): static
    {
        $fallback = $this->fallback;
        if ($fallback instanceof ContextualObjectExporterInterface) {
            $fallback = $fallback->withValueExporter($valueExporter);
        }

        return new self($this->config, $this->closureExporter, $fallback, $valueExporter);
    }

    private function exportLazyValue(LazyValue $value, ExportContext $context): string
    {
        try {
            $callback = $this->exportCallback(
                $value->callback(),
                $context->child('callback', $context->baseIndent . $this->config->indent),
            );
        } catch (Throwable $e) {
            throw new RuntimeException(
                sprintf('Cannot export LazyValue at %s: %s', $context->location(), $e->getMessage()),
                previous: $e,
            );
        }

        return sprintf(
            'new \\%s(%s, cache: %s)',
            LazyValue::class,
            $callback,
            $value->cache ? 'true' : 'false',
        );
    }

    /** @param class-string $class @param array<string, mixed> $arguments */
    private function exportConstructed(string $class, array $arguments, ExportContext $context): string
    {
        if ($this->valueExporter === null) {
            throw new RuntimeException(sprintf(
                '%s requires a contextual value dispatcher to export nested values.',
                self::class,
            ));
        }

        $parts = [];
        $childIndent = $context->baseIndent . $this->config->indent;
        foreach ($arguments as $name => $value) {
            $child = $context->child($name, $childIndent);
            try {
                $parts[] = $this->valueExporter->exportValue($value, $child);
            } catch (Throwable $e) {
                throw new RuntimeException(
                    sprintf('Cannot export config cache value at %s: %s', $child->location(), $e->getMessage()),
                    previous: $e,
                );
            }
        }

        return sprintf('new \\%s(%s)', ltrim($class, '\\'), implode(', ', $parts));
    }

    private function exportCallableTarget(object $target, ExportContext $context): string
    {
        $reflection = new ReflectionClass($target);
        if (
            $reflection->isReadOnly()
            && !$reflection->isAnonymous()
            && $reflection->getConstructor() === null
            && array_filter(
                $reflection->getProperties(),
                static fn(\ReflectionProperty $property): bool => !$property->isStatic(),
            ) === []
        ) {
            return 'new \\' . $reflection->getName() . '()';
        }

        if ($this->valueExporter === null) {
            throw new RuntimeException(
                'Cannot persist bound LazyValue callback without a contextual value dispatcher.',
            );
        }

        return $this->valueExporter->exportValue($target, $context);
    }

    private function exportCallback(Closure $callback, ExportContext $context): string
    {
        $reflection = new ReflectionFunction($callback);
        if ($reflection->getName() === '{closure}' || str_starts_with($reflection->getName(), '{closure:')) {
            return $this->closureExporter instanceof ContextualClosureExporterInterface
                ? $this->closureExporter->exportWithContext($callback, $context)
                : $this->closureExporter->exportWithDepth($callback, $context->depth);
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
            $target = $this->exportCallableTarget(
                $boundObject,
                $context->child('target', $context->baseIndent . $this->config->indent),
            );

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
                'Cannot persist user-defined named function LazyValue callback "%s": use an anonymous closure or an autoloadable public method.',
                $reflection->getName(),
            ));
        }

        return sprintf(
            'static fn($context) => \\%s($context)',
            ltrim($reflection->getName(), '\\'),
        );
    }
}
