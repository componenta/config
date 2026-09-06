<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Provider;

use Componenta\Config\ConfigKey;
use Componenta\Config\ConfigProvider;
use Componenta\Config\Exception\InvalidConfigArgumentException;

it('returns one contribution containing application sections and dependency definitions', function (): void {
    $provider = new class () extends ConfigProvider {
        protected function getConfig(): array
        {
            return [
                'app' => ['name' => 'Componenta'],
                404 => 'not-found',
            ];
        }

        protected function getFactories(): array
        {
            return ['service' => 'ServiceFactory'];
        }

        protected function getServices(): array
        {
            return ['ready' => 'service'];
        }
    };

    expect($provider())->toBe([
        ConfigKey::DEPENDENCIES => [
            ConfigKey::FACTORIES => ['service' => 'ServiceFactory'],
            ConfigKey::SERVICES => ['ready' => 'service'],
        ],
        'app' => ['name' => 'Componenta'],
        404 => 'not-found',
    ]);
});

it('exposes every dependency contribution hook without DI normalization', function (): void {
    $service = new \stdClass();
    $provider = new class ($service) extends ConfigProvider {
        public function __construct(private readonly object $service) {}

        protected function getFactories(): array
        {
            return ['service' => 'Factory'];
        }

        protected function getInvokables(): array
        {
            return ['Invokable', 'alias' => 'AliasedInvokable'];
        }

        protected function getAliases(): array
        {
            return ['alias' => 'Target'];
        }

        protected function getDelegators(): array
        {
            return ['service' => [['Decorator', 'decorate']]];
        }

        protected function getServices(): array
        {
            return ['ready' => $this->service];
        }

        protected function getParameterResolvers(): array
        {
            return [900 => 'Resolver'];
        }

        protected function shouldReplaceParameterResolvers(): ?bool
        {
            return true;
        }

        protected function getAttributeDefinitions(): array
        {
            return ['Definition'];
        }

        protected function shouldReplaceAttributeDefinitions(): ?bool
        {
            return false;
        }

        protected function getAttributeCapabilities(): array
        {
            return ['Capability'];
        }
    };

    expect($provider()[ConfigKey::DEPENDENCIES])->toBe([
        ConfigKey::FACTORIES => ['service' => 'Factory'],
        ConfigKey::INVOKABLES => ['Invokable', 'alias' => 'AliasedInvokable'],
        ConfigKey::ALIASES => ['alias' => 'Target'],
        ConfigKey::DELEGATORS => ['service' => [['Decorator', 'decorate']]],
        ConfigKey::SERVICES => ['ready' => $service],
        ConfigKey::PARAMETER_RESOLVERS => [900 => 'Resolver'],
        ConfigKey::ATTRIBUTE_DEFINITIONS => ['Definition'],
        ConfigKey::ATTRIBUTE_CAPABILITIES => ['Capability'],
        ConfigKey::PARAMETER_RESOLVERS_REPLACE => true,
        ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE => false,
    ]);
});

it('rejects application config that shadows the reserved dependency root', function (): void {
    $provider = new class () extends ConfigProvider {
        protected function getConfig(): array
        {
            return [
                ConfigKey::DEPENDENCIES => ['unsafe' => true],
            ];
        }
    };

    expect($provider(...))->toThrow(
        InvalidConfigArgumentException::class,
        'reserved root key',
    );
});
