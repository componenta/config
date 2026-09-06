<?php

declare(strict_types=1);

namespace Componenta\Config;

use Componenta\Config\Exception\ConfigException;
use Componenta\Config\Internal\ConfigMerger;

final class ConfigFactory
{
    /**
     * @param callable():(array<array-key, mixed>|iterable<array<array-key, mixed>>) ...$providers
     */
    public function create(
        Environment $environment,
        callable ...$providers,
    ): ConfigComposition {
        /** @var array<array-key, mixed> $combined */
        $combined = [];

        foreach ($providers as $provider) {
            $provided = $provider();

            if (is_array($provided)) {
                $combined = ConfigMerger::merge($combined, $provided);
                continue;
            }

            if (!is_iterable($provided)) {
                throw new ConfigException(sprintf(
                    'Configuration provider must return an array or iterable; got %s.',
                    get_debug_type($provided),
                ));
            }

            foreach ($provided as $contribution) {
                if (!is_array($contribution)) {
                    throw new ConfigException(sprintf(
                        'Configuration contribution must be an array; got %s.',
                        get_debug_type($contribution),
                    ));
                }

                $combined = ConfigMerger::merge($combined, $contribution);
            }
        }

        $sections = $combined[ConfigKey::DEPENDENCIES] ?? [];
        unset($combined[ConfigKey::DEPENDENCIES]);

        if (!is_array($sections)) {
            throw new ConfigException(sprintf(
                'Dependency definitions must be an array; got %s.',
                get_debug_type($sections),
            ));
        }

        /** @var array<string, mixed> $sections */
        return new ConfigComposition(
            new Config($combined, $environment),
            new DependencyDefinitions($sections),
        );
    }
}
