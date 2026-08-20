<?php

declare(strict_types=1);

namespace Componenta\Config;

use InvalidArgumentException;
use ReflectionMethod;

/**
 * Base provider for application and dependency configuration.
 *
 * Override only the sections your package owns. Child providers are evaluated
 * when the provider is invoked and merged in declaration order, so provider
 * subclasses remain free to use ordinary constructors for their own state.
 */
class ConfigProvider
{
    /** @return array<array-key, mixed> */
    public function __invoke(): array
    {
        $config = [
            ConfigKey::DEPENDENCIES => $this->getDependencies(),
            ...$this->getConfig(),
        ];

        foreach ($this->getProviders() as $provider) {
            if (!is_callable($provider)) {
                throw new InvalidArgumentException(sprintf(
                    'Configuration provider must be callable; got %s.',
                    get_debug_type($provider),
                ));
            }

            $child = $provider();

            if (!is_array($child)) {
                if (!is_iterable($child)) {
                    throw new InvalidArgumentException(sprintf(
                        'Child configuration provider must return an array or iterable; got %s.',
                        get_debug_type($child),
                    ));
                }

                $child = iterator_to_array($child);
            }

            if ($child !== []) {
                $config = config_merge($config, $child);
            }
        }

        return $config;
    }

    /** @return iterable<callable(): iterable<array-key, mixed>> */
    protected function getProviders(): iterable
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function getConfig(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function getFactories(): array
    {
        return [];
    }

    /** @return array<int|string, class-string> */
    protected function getInvokables(): array
    {
        return [];
    }

    /** @return array<string, non-empty-string> */
    protected function getAliases(): array
    {
        return [];
    }

    /** @return array<string, list<mixed>> */
    protected function getDelegators(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function getServices(): array
    {
        return [];
    }

    /** @return array<int, mixed> */
    protected function getParameterResolvers(): array
    {
        return [];
    }

    protected function shouldReplaceParameterResolvers(): bool
    {
        return false;
    }

    /** @return list<mixed> */
    protected function getAttributeDefinitions(): array
    {
        return [];
    }

    protected function shouldReplaceAttributeDefinitions(): bool
    {
        return false;
    }

    /** @return list<mixed> */
    protected function getAttributeCapabilities(): array
    {
        return [];
    }

    /**
     * Extra dependency sections understood by the downstream container.
     *
     * Keys must be non-empty strings and must not replace one of the standard
     * sections exposed by this provider. The downstream consumer validates
     * extension-specific keys and values.
     *
     * @return array<string, mixed>
     */
    protected function getDependencyExtensions(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    final protected function getDependencies(): array
    {
        [$invokables, $invokableAliases] = $this->normalizeInvokables($this->getInvokables());
        $aliases = $this->mergeAliases($this->getAliases(), $invokableAliases);

        $replaceParameterResolvers = $this->shouldReplaceParameterResolvers();
        $replaceAttributeDefinitions = $this->shouldReplaceAttributeDefinitions();

        $dependencies = [
            ConfigKey::FACTORIES => $this->getFactories(),
            ConfigKey::INVOKABLES => $invokables,
            ConfigKey::ALIASES => $aliases,
            ConfigKey::DELEGATORS => $this->getDelegators(),
            ConfigKey::SERVICES => $this->getServices(),
            ConfigKey::PARAMETER_RESOLVERS => $this->getParameterResolvers(),
            ConfigKey::PARAMETER_RESOLVERS_REPLACE => $replaceParameterResolvers,
            ConfigKey::ATTRIBUTE_DEFINITIONS => $this->getAttributeDefinitions(),
            ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE => $replaceAttributeDefinitions,
            ConfigKey::ATTRIBUTE_CAPABILITIES => $this->getAttributeCapabilities(),
        ];

        foreach ($this->getDependencyExtensions() as $key => $value) {
            if (!is_string($key) || $key === '') {
                throw new InvalidArgumentException(
                    'Dependency extension keys must be non-empty strings.',
                );
            }

            if (array_key_exists($key, $dependencies)) {
                throw new InvalidArgumentException(sprintf(
                    'Dependency extension cannot replace standard key "%s".',
                    $key,
                ));
            }

            $dependencies[$key] = $value;
        }

        $hasParameterResolverReplaceFlag = $replaceParameterResolvers
            || $this->isHookOverridden('shouldReplaceParameterResolvers');
        $hasAttributeDefinitionReplaceFlag = $replaceAttributeDefinitions
            || $this->isHookOverridden('shouldReplaceAttributeDefinitions');

        return array_filter(
            $dependencies,
            static function (mixed $value, int|string $key) use (
                $hasParameterResolverReplaceFlag,
                $hasAttributeDefinitionReplaceFlag,
            ): bool {
                if ($value === [] || $value === null) {
                    return false;
                }

                if ($value !== false) {
                    return true;
                }

                return ($key === ConfigKey::PARAMETER_RESOLVERS_REPLACE
                        && $hasParameterResolverReplaceFlag)
                    || ($key === ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE
                        && $hasAttributeDefinitionReplaceFlag);
            },
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @param array<int|string, class-string> $invokables
     * @return array{0: list<class-string>, 1: array<string, class-string>}
     */
    private function normalizeInvokables(array $invokables): array
    {
        $normalized = [];
        $aliases = [];

        foreach ($invokables as $key => $class) {
            if (!is_string($class) || $class === '') {
                throw new InvalidArgumentException(
                    'Invokable entries must be non-empty class strings.',
                );
            }

            $normalized[] = $class;

            if (is_string($key)) {
                if ($key === '') {
                    throw new InvalidArgumentException(
                        'Invokable alias ids must be non-empty strings.',
                    );
                }

                $aliases[$key] = $class;
            }
        }

        return [$normalized, $aliases];
    }

    /**
     * @param array<string, non-empty-string> $explicit
     * @param array<string, class-string> $fromInvokables
     * @return array<string, non-empty-string>
     */
    private function mergeAliases(array $explicit, array $fromInvokables): array
    {
        foreach ($explicit as $alias => $target) {
            if (!is_string($alias) || $alias === '' || !is_string($target) || $target === '') {
                throw new InvalidArgumentException(
                    'Aliases must map non-empty string ids to non-empty string targets.',
                );
            }
        }

        foreach ($fromInvokables as $alias => $target) {
            if (isset($explicit[$alias]) && $explicit[$alias] !== $target) {
                throw new InvalidArgumentException(sprintf(
                    'Invokable alias "%s" conflicts with explicit alias target "%s".',
                    $alias,
                    $explicit[$alias],
                ));
            }

            $explicit[$alias] ??= $target;
        }

        return $explicit;
    }

    private function isHookOverridden(string $method): bool
    {
        static $cache = [];

        $class = static::class;

        return $cache[$class][$method] ??= (new ReflectionMethod($class, $method))
            ->getDeclaringClass()
            ->getName() !== self::class;
    }
}
