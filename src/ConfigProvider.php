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
    /** @var callable[] */
    private readonly array $childProviders;

    final public function __construct()
    {
        $this->childProviders = $this->getProviders();
    }

    /**
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
     * @return callable[]
     */
    protected function getProviders(): array
    {
        return [];
    }

    /**
     * Get application configuration.
     */
    protected function getConfig(): array
    {
        return [];
    }

    /**
     * Get dependency injection configuration.
     */
    protected function getDependencies(): array
    {
        [$invokables, $invokableAliases] = $this->normalizeInvokables($this->getInvokables());

        return array_filter([
            ConfigKey::FACTORIES => $this->getFactories(),
            ConfigKey::INVOKABLES => $invokables,
            ConfigKey::AUTOWIRES => $this->getAutowires(),
            ConfigKey::ALIASES => array_merge($this->getAliases(), $invokableAliases),
            ConfigKey::DELEGATORS => $this->getDelegators(),
            ConfigKey::SERVICES => $this->getServices(),
            ConfigKey::PARAMETER_RESOLVERS => $this->getParameterResolvers(),
            ConfigKey::PROPERTY_RESOLVERS => $this->getPropertyResolvers(),
        ]);
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
     * Get autowired service definitions.
     *
     * @return list<class-string>
     */
    protected function getAutowires(): array
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
     * Get custom property resolvers, keyed by priority. See
     * {@see getParameterResolvers()} for accepted value shapes.
     *
     * @return array<int, mixed>
     */
    protected function getPropertyResolvers(): array
    {
        return [];
    }
}
