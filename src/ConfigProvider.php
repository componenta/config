<?php

declare(strict_types=1);

namespace Componenta\Config;

/**
 * Base configuration provider for dependency injection containers.
 *
 * Provides structured way to define container configuration.
 * Can be extended to create modular configuration providers.
 *
 * @example
 * ```php
 * class AppConfigProvider extends ConfigProvider
 * {
 *     protected function getFactories(): array
 *     {
 *         return [
 *             LoggerInterface::class => LoggerFactory::class,
 *         ];
 *     }
 *
 *     protected function getInvokables(): array
 *     {
 *         return [
 *             // Simple invokable
 *             SimpleService::class,
 *             // With alias
 *             ServiceInterface::class => ConcreteService::class,
 *         ];
 *     }
 *
 *     protected function getConfig(): array
 *     {
 *         return [
 *             'app' => ['name' => 'MyApp'],
 *         ];
 *     }
 * }
 * ```
 */
class ConfigProvider
{
    /** @var list<callable(): array<string, mixed>> */
    private readonly array $childProviders;

    final public function __construct()
    {
        $this->childProviders = $this->getProviders();
    }

    /**
     * @return array<array-key, mixed>
     * Get complete configuration array.
     */
    public function __invoke(): array
    {
        $config = [
            ConfigKey::DEPENDENCIES => $this->getDependencies(),
            ...$this->getConfig(),
        ];

        foreach ($this->childProviders as $provider) {
            $childConfig = $provider();

            if ($childConfig === []) {
                continue;
            }

            $config = config_merge($config, $childConfig);
        }

        return $config;
    }

    /**
     * Get child configuration providers.
     *
     * @return list<callable(): array<string, mixed>>
     */
    protected function getProviders(): array
    {
        return [];
    }

    /**
     * Get application configuration.
     * @return array<string, mixed>
     */
    protected function getConfig(): array
    {
        return [];
    }

    /**
     * Get dependency injection configuration.
     * @return array<string, mixed>
     */
    final protected function getDependencies(): array
    {
        [$invokables, $invokableAliases] = $this->normalizeInvokables($this->getInvokables());

        $dependencies = [
            ConfigKey::FACTORIES => $this->getFactories(),
            ConfigKey::INVOKABLES => $invokables,
            ConfigKey::ALIASES => array_merge($this->getAliases(), $invokableAliases),
            ConfigKey::DELEGATORS => $this->getDelegators(),
            ConfigKey::SERVICES => $this->getServices(),
            ConfigKey::PARAMETER_RESOLVERS => $this->getParameterResolvers(),
            ConfigKey::PARAMETER_RESOLVERS_REPLACE => $this->shouldReplaceParameterResolvers(),
            ConfigKey::ATTRIBUTE_HANDLERS => $this->getAttributeHandlers(),
            ConfigKey::ATTRIBUTE_HANDLERS_REPLACE => $this->shouldReplaceAttributeHandlers(),
        ];
        $replaceParameterResolvers = $dependencies[ConfigKey::PARAMETER_RESOLVERS_REPLACE];
        $replaceAttributeHandlers = $dependencies[ConfigKey::ATTRIBUTE_HANDLERS_REPLACE];

        foreach ($this->getDependencyExtensions() as $key => $value) {
            if (!is_string($key) || !in_array($key, ConfigKey::dependencyKeys(), true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Unsupported dependency configuration key "%s".',
                    (string) $key,
                ));
            }

            if (array_key_exists($key, $dependencies)) {
                throw new \InvalidArgumentException(sprintf(
                    'Dependency extension cannot replace base key "%s".',
                    $key,
                ));
            }

            $dependencies[$key] = $value;
        }

        $hasParameterResolverReplaceFlag = $replaceParameterResolvers
            || $this->isHookOverridden('shouldReplaceParameterResolvers');
        $hasAttributeHandlerReplaceFlag = $replaceAttributeHandlers
            || $this->isHookOverridden('shouldReplaceAttributeHandlers');

        return array_filter(
            $dependencies,
            static function (mixed $value, int|string $key) use (
                $hasParameterResolverReplaceFlag,
                $hasAttributeHandlerReplaceFlag,
            ): bool {
                if ($value === [] || $value === null) {
                    return false;
                }

                if ($value !== false) {
                    return true;
                }

                return ($key === ConfigKey::PARAMETER_RESOLVERS_REPLACE
                        && $hasParameterResolverReplaceFlag)
                    || ($key === ConfigKey::ATTRIBUTE_HANDLERS_REPLACE
                        && $hasAttributeHandlerReplaceFlag);
            },
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * A false replacement flag is meaningful only when a provider explicitly
     * overrides the corresponding hook. The inherited base false remains the
     * omitted default and therefore cannot accidentally cancel an earlier true.
     */
    private function isHookOverridden(string $method): bool
    {
        static $cache = [];

        $class = static::class;

        return $cache[$class][$method] ??= (new \ReflectionMethod($class, $method))
            ->getDeclaringClass()
            ->getName() !== self::class;
    }

    /**
     * Normalizes invokables array, extracting aliases from keyed entries.
     *
     * @param array<int|string, class-string> $invokables
     * @return array{0: list<class-string>, 1: array<string, class-string>}
     */
    private function normalizeInvokables(array $invokables): array
    {
        $normalized = [];
        $aliases = [];

        foreach ($invokables as $key => $value) {
            $normalized[] = $value;

            if (is_string($key)) {
                $aliases[$key] = $value;
            }
        }

        return [$normalized, $aliases];
    }

    /**
     * Get factory definitions.
     *
     * @return array<string, string|callable(\Componenta\Config\ContainerValue, array<string|int, mixed>):mixed>
     */
    protected function getFactories(): array
    {
        return [];
    }

    /**
     * Get invokable service definitions.
     *
     * Classes instantiated directly without constructor arguments.
     * Keyed entries create aliases automatically.
     *
     * @return array<int|string, class-string>
     */
    protected function getInvokables(): array
    {
        return [];
    }

    /**
     * Get service aliases.
     *
     * @return array<string, string>
     */
    protected function getAliases(): array
    {
        return [];
    }

    /**
     * Get delegator factories.
     *
     * @return array<string, list<callable|class-string>>
     */
    protected function getDelegators(): array
    {
        return [];
    }

    /**
     * Get pre-instantiated services.
     *
     * @return array<string, mixed>
     */
    protected function getServices(): array
    {
        return [];
    }

    /**
     * Get custom parameter resolvers, keyed by priority. Each value can be a
     * class-string (autowired through the container), a callable receiving the
     * container, or a pre-built `ParameterResolverInterface` instance.
     *
     * @return array<int, mixed>
     */
    protected function getParameterResolvers(): array
    {
        return [];
    }

    /**
     * Whether custom parameter resolvers replace the default resolver chain.
     *
     * An inherited false is omitted. A provider that overrides this hook and
     * returns false explicitly can cancel an earlier true during composition.
     */
    protected function shouldReplaceParameterResolvers(): bool
    {
        return false;
    }

    /**
     * Get runtime attribute handlers in registration order.
     *
     * @return list<mixed>
     */
    protected function getAttributeHandlers(): array
    {
        return [];
    }

    /**
     * Whether custom attribute handlers replace all built-in handlers.
     *
     * An inherited false is omitted. A provider that overrides this hook and
     * returns false explicitly can cancel an earlier true during composition.
     */
    protected function shouldReplaceAttributeHandlers(): bool
    {
        return false;
    }

    /**
     * Get supported DI metadata not represented by the base hooks above.
     *
     * Extensions cannot replace base sections and unknown keys are rejected.
     *
     * @return array<array-key, mixed>
     */
    protected function getDependencyExtensions(): array
    {
        return [];
    }
}
