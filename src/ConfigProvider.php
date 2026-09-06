<?php

declare(strict_types=1);

namespace Componenta\Config;

use Componenta\Config\Exception\InvalidConfigArgumentException;

/**
 * Base provider for the dependency schema consumed by Componenta DI.
 *
 * Config enforces only composition-safe shapes. DI remains the sole owner
 * of semantic validation and canonicalization of container definitions.
 */
class ConfigProvider
{
    /** @return array<array-key, mixed> */
    public function __invoke(): array
    {
        $application = $this->getConfig();

        if (array_key_exists(ConfigKey::DEPENDENCIES, $application)) {
            throw new InvalidConfigArgumentException(sprintf(
                'Application configuration must not define reserved root key "%s".',
                ConfigKey::DEPENDENCIES,
            ));
        }

        return array_replace(
            [ConfigKey::DEPENDENCIES => $this->getDependencies()],
            $application,
        );
    }

    /** @return array<array-key, mixed> */
    protected function getConfig(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function getFactories(): array
    {
        return [];
    }

    /** @return array<int|string, mixed> */
    protected function getInvokables(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function getAliases(): array
    {
        return [];
    }

    /**
     * Each service maps to a delegator pipeline. Callable pairs are pipeline
     * entries and therefore must be nested: `[[Decorator::class, 'decorate']]`.
     *
     * @return array<string, list<mixed>>
     */
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

    /** Null means this provider does not change the previously composed flag. */
    protected function shouldReplaceParameterResolvers(): ?bool
    {
        return null;
    }

    /** @return list<mixed> */
    protected function getAttributeDefinitions(): array
    {
        return [];
    }

    /** Null means this provider does not change the previously composed flag. */
    protected function shouldReplaceAttributeDefinitions(): ?bool
    {
        return null;
    }

    /** @return list<mixed> */
    protected function getAttributeCapabilities(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    final protected function getDependencies(): array
    {
        $dependencies = [
            ConfigKey::FACTORIES => $this->getFactories(),
            ConfigKey::INVOKABLES => $this->getInvokables(),
            ConfigKey::ALIASES => $this->getAliases(),
            ConfigKey::DELEGATORS => $this->getDelegators(),
            ConfigKey::SERVICES => $this->getServices(),
            ConfigKey::PARAMETER_RESOLVERS => $this->getParameterResolvers(),
            ConfigKey::ATTRIBUTE_DEFINITIONS => $this->getAttributeDefinitions(),
            ConfigKey::ATTRIBUTE_CAPABILITIES => $this->getAttributeCapabilities(),
        ];

        $replaceParameterResolvers = $this->shouldReplaceParameterResolvers();
        if ($replaceParameterResolvers !== null) {
            $dependencies[ConfigKey::PARAMETER_RESOLVERS_REPLACE] = $replaceParameterResolvers;
        }

        $replaceAttributeDefinitions = $this->shouldReplaceAttributeDefinitions();
        if ($replaceAttributeDefinitions !== null) {
            $dependencies[ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE] = $replaceAttributeDefinitions;
        }

        return array_filter(
            $dependencies,
            static fn(mixed $value): bool => $value !== [],
        );
    }
}
