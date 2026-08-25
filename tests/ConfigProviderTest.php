<?php

declare(strict_types=1);

use Componenta\Config\ConfigKey;
use Componenta\Config\ConfigProvider;

final class ConfigProviderCallablePairFixture
{
    public static function decorate(string $value): string
    {
        return $value;
    }
}

it('returns an empty dependency section for the base provider', function (): void {
    expect((new ConfigProvider())())->toBe([ConfigKey::DEPENDENCIES => []]);
});

it('transports the DI v5 dependency schema without reimplementing DI normalization', function (): void {
    $provider = new class () extends ConfigProvider {
        protected function getFactories(): array
        {
            return ['service' => 'Factory'];
        }
        protected function getInvokables(): array
        {
            return ['Service', 'alias' => 'AliasedService'];
        }
        protected function getAliases(): array
        {
            return ['explicit' => 'ExplicitService'];
        }
        protected function getDelegators(): array
        {
            return ['service' => [[ConfigProviderCallablePairFixture::class, 'decorate']]];
        }
        protected function getServices(): array
        {
            return ['ready' => new stdClass()];
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

    $dependencies = $provider()[ConfigKey::DEPENDENCIES];

    expect($dependencies[ConfigKey::FACTORIES])->toBe(['service' => 'Factory'])
        ->and($dependencies[ConfigKey::INVOKABLES])->toBe([
            0 => 'Service',
            'alias' => 'AliasedService',
        ])->and($dependencies[ConfigKey::ALIASES])->toBe(['explicit' => 'ExplicitService'])
        ->and($dependencies[ConfigKey::DELEGATORS])->toBe([
            'service' => [[ConfigProviderCallablePairFixture::class, 'decorate']],
        ])->and($dependencies[ConfigKey::PARAMETER_RESOLVERS])->toBe([900 => 'Resolver'])
        ->and($dependencies[ConfigKey::PARAMETER_RESOLVERS_REPLACE])->toBeTrue()
        ->and($dependencies[ConfigKey::ATTRIBUTE_DEFINITIONS])->toBe(['Definition'])
        ->and($dependencies[ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE])->toBeFalse()
        ->and($dependencies[ConfigKey::ATTRIBUTE_CAPABILITIES])->toBe(['Capability'])
        ->and($dependencies[ConfigKey::SERVICES]['ready'])->toBeInstanceOf(stdClass::class);
});

it('preserves numeric application config keys produced by the provider', function (): void {
    $provider = new class () extends ConfigProvider {
        protected function getConfig(): array
        {
            return [404 => 'not-found', 500 => 'server-error'];
        }
    };

    $config = $provider();

    expect($config[404])->toBe('not-found')
        ->and($config[500])->toBe('server-error')
        ->and($config)->not->toHaveKey(0)
        ->and($config)->not->toHaveKey(1);
});

it('rejects direct callable-pair delegators even without child providers', function (): void {
    $provider = new class () extends ConfigProvider {
        protected function getDelegators(): array
        {
            return [
                'service' => [ConfigProviderCallablePairFixture::class, 'decorate'],
            ];
        }
    };

    expect($provider(...))
        ->toThrow(InvalidArgumentException::class, 'callable pairs must be nested');
});

it('leaves alias compatibility to DI v5 canonicalization', function (): void {
    $provider = new class () extends ConfigProvider {
        protected function getInvokables(): array
        {
            return ['service' => 'ConcreteService'];
        }

        protected function getAliases(): array
        {
            return [
                'service' => 'AliasTarget',
                'AliasTarget' => 'ConcreteService',
            ];
        }
    };

    expect($provider()[ConfigKey::DEPENDENCIES])->toBe([
        ConfigKey::INVOKABLES => ['service' => 'ConcreteService'],
        ConfigKey::ALIASES => [
            'service' => 'AliasTarget',
            'AliasTarget' => 'ConcreteService',
        ],
    ]);
});

it('merges child providers after the parent and accepts iterable output', function (): void {
    $provider = new class () extends ConfigProvider {
        protected function getFactories(): array
        {
            return ['service' => 'BaseFactory'];
        }
        protected function getConfig(): array
        {
            return ['app' => ['name' => 'base', 'debug' => false]];
        }
        protected function getProviders(): iterable
        {
            yield static function (): Traversable {
                yield ConfigKey::DEPENDENCIES => [
                    ConfigKey::FACTORIES => [
                        'service' => 'ChildFactory',
                        'other' => 'OtherFactory',
                    ],
                ];
                yield 'app' => ['debug' => true];
            };
        }
    };

    $config = $provider();

    expect($config[ConfigKey::DEPENDENCIES][ConfigKey::FACTORIES])->toBe([
        'service' => 'ChildFactory',
        'other' => 'OtherFactory',
    ])->and($config['app'])->toBe(['name' => 'base', 'debug' => true]);
});

it('uses tri-state replacement hooks so inherited providers do not cancel explicit flags', function (): void {
    $provider = new class () extends ConfigProvider {
        protected function shouldReplaceAttributeDefinitions(): ?bool
        {
            return true;
        }
        protected function getProviders(): iterable
        {
            return [new class () extends ConfigProvider {}];
        }
    };

    expect($provider()[ConfigKey::DEPENDENCIES][ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE])
        ->toBeTrue();
});

it('allows a later provider to explicitly cancel a replacement flag', function (): void {
    $provider = new class () extends ConfigProvider {
        protected function shouldReplaceAttributeDefinitions(): ?bool
        {
            return true;
        }
        protected function getProviders(): iterable
        {
            return [
                new class () extends ConfigProvider {
                    protected function shouldReplaceAttributeDefinitions(): ?bool
                    {
                        return false;
                    }
                },
            ];
        }
    };

    expect($provider()[ConfigKey::DEPENDENCIES][ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE])
        ->toBeFalse();
});

it('omits unspecified replacement flags', function (): void {
    $dependencies = (new ConfigProvider())()[ConfigKey::DEPENDENCIES];

    expect($dependencies)
        ->not->toHaveKey(ConfigKey::PARAMETER_RESOLVERS_REPLACE)
        ->not->toHaveKey(ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE);
});

it('rejects application configuration that shadows the dependency root', function (): void {
    $provider = new class () extends ConfigProvider {
        protected function getConfig(): array
        {
            return [ConfigKey::DEPENDENCIES => ['factories' => ['unsafe' => 'override']]];
        }
    };

    expect($provider(...))
        ->toThrow(InvalidArgumentException::class, 'reserved root key');
});

it('rejects non-callable child providers and invalid child output', function (): void {
    $invalidProvider = new class () extends ConfigProvider {
        protected function getProviders(): iterable
        {
            return ['not-callable'];
        }
    };

    expect($invalidProvider(...))
        ->toThrow(InvalidArgumentException::class, 'must be callable');

    $invalidOutput = new class () extends ConfigProvider {
        protected function getProviders(): iterable
        {
            return [static fn(): string => 'invalid'];
        }
    };

    expect($invalidOutput(...))
        ->toThrow(InvalidArgumentException::class, 'array or iterable');
});

it('exposes the exact DI v5 dependency schema', function (): void {
    expect(ConfigKey::dependencyKeys())->toBe([
        ConfigKey::FACTORIES,
        ConfigKey::INVOKABLES,
        ConfigKey::ALIASES,
        ConfigKey::DELEGATORS,
        ConfigKey::SERVICES,
        ConfigKey::PARAMETER_RESOLVERS,
        ConfigKey::PARAMETER_RESOLVERS_REPLACE,
        ConfigKey::ATTRIBUTE_DEFINITIONS,
        ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE,
        ConfigKey::ATTRIBUTE_CAPABILITIES,
    ]);
});
