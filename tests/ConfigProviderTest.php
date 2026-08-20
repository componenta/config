<?php

declare(strict_types=1);

use Componenta\Config\ConfigKey;
use Componenta\Config\ConfigProvider;

it('returns an empty dependency section for the base provider', function (): void {
    expect((new ConfigProvider())())->toBe([ConfigKey::DEPENDENCIES => []]);
});

it('builds standard dependency sections', function (): void {
    $provider = new class extends ConfigProvider {
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
            return ['service' => ['Decorate']];
        }

        protected function getServices(): array
        {
            return ['ready' => new stdClass()];
        }

        protected function getParameterResolvers(): array
        {
            return [900 => 'Resolver'];
        }

        protected function shouldReplaceParameterResolvers(): bool
        {
            return true;
        }

        protected function getAttributeDefinitions(): array
        {
            return ['Definition'];
        }

        protected function shouldReplaceAttributeDefinitions(): bool
        {
            return true;
        }

        protected function getAttributeCapabilities(): array
        {
            return ['Capability'];
        }
    };

    $dependencies = $provider()[ConfigKey::DEPENDENCIES];

    expect($dependencies[ConfigKey::FACTORIES])->toBe(['service' => 'Factory'])
        ->and($dependencies[ConfigKey::INVOKABLES])->toBe(['Service', 'AliasedService'])
        ->and($dependencies[ConfigKey::ALIASES])->toBe([
            'explicit' => 'ExplicitService',
            'alias' => 'AliasedService',
        ])
        ->and($dependencies[ConfigKey::DELEGATORS])->toBe(['service' => ['Decorate']])
        ->and($dependencies[ConfigKey::PARAMETER_RESOLVERS])->toBe([900 => 'Resolver'])
        ->and($dependencies[ConfigKey::PARAMETER_RESOLVERS_REPLACE])->toBeTrue()
        ->and($dependencies[ConfigKey::ATTRIBUTE_DEFINITIONS])->toBe(['Definition'])
        ->and($dependencies[ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE])->toBeTrue()
        ->and($dependencies[ConfigKey::ATTRIBUTE_CAPABILITIES])->toBe(['Capability'])
        ->and($dependencies[ConfigKey::SERVICES]['ready'])->toBeInstanceOf(stdClass::class);
});

it('merges child providers after the parent', function (): void {
    $provider = new class extends ConfigProvider {
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
            return [
                new class extends ConfigProvider {
                    protected function getFactories(): array
                    {
                        return ['service' => 'ChildFactory', 'other' => 'OtherFactory'];
                    }

                    protected function getConfig(): array
                    {
                        return ['app' => ['debug' => true]];
                    }
                },
            ];
        }
    };

    $config = $provider();

    expect($config[ConfigKey::DEPENDENCIES][ConfigKey::FACTORIES])->toBe([
        'service' => 'ChildFactory',
        'other' => 'OtherFactory',
    ])->and($config['app'])->toBe(['name' => 'base', 'debug' => true]);
});

it('allows downstream-specific dependency extension keys', function (): void {
    $provider = new class extends ConfigProvider {
        protected function getDependencyExtensions(): array
        {
            return ['container_specific' => ['enabled' => true]];
        }
    };

    expect($provider()[ConfigKey::DEPENDENCIES]['container_specific'])
        ->toBe(['enabled' => true]);
});

it('rejects dependency extensions that replace standard sections', function (): void {
    $provider = new class extends ConfigProvider {
        protected function getDependencyExtensions(): array
        {
            return [ConfigKey::FACTORIES => ['service' => 'Factory']];
        }
    };

    expect($provider(...))
        ->toThrow(InvalidArgumentException::class, 'cannot replace standard key');
});

it('rejects conflicting invokable and explicit aliases', function (): void {
    $provider = new class extends ConfigProvider {
        protected function getInvokables(): array
        {
            return ['service' => 'InvokableService'];
        }

        protected function getAliases(): array
        {
            return ['service' => 'DifferentService'];
        }
    };

    expect($provider(...))
        ->toThrow(InvalidArgumentException::class, 'conflicts with explicit alias');
});

it('keeps an explicitly overridden false replacement flag', function (): void {
    $provider = new class extends ConfigProvider {
        protected function shouldReplaceAttributeDefinitions(): bool
        {
            return true;
        }

        protected function getProviders(): iterable
        {
            return [
                new class extends ConfigProvider {
                    protected function shouldReplaceAttributeDefinitions(): bool
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

it('omits inherited false replacement flags', function (): void {
    $dependencies = (new ConfigProvider())()[ConfigKey::DEPENDENCIES];

    expect($dependencies)
        ->not->toHaveKey(ConfigKey::PARAMETER_RESOLVERS_REPLACE)
        ->not->toHaveKey(ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE);
});

it('rejects non-callable child providers when invoked', function (): void {
    $provider = new class extends ConfigProvider {
        protected function getProviders(): iterable
        {
            return ['not-callable'];
        }
    };

    expect($provider(...))->toThrow(InvalidArgumentException::class, 'must be callable');
});

it('allows provider subclasses to use constructor state', function (): void {
    $provider = new class ('configured') extends ConfigProvider {
        public function __construct(private readonly string $value) {}

        protected function getConfig(): array
        {
            return ['value' => $this->value];
        }
    };

    expect($provider()['value'])->toBe('configured');
});

it('accepts iterable results from child providers', function (): void {
    $provider = new class extends ConfigProvider {
        protected function getProviders(): iterable
        {
            return [
                static function (): Traversable {
                    yield 'feature' => ['enabled' => true];
                },
            ];
        }
    };

    expect($provider()['feature'])->toBe(['enabled' => true]);
});

it('exposes only the current dependency schema', function (): void {
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
    ])->and(defined(ConfigKey::class . '::ATTRIBUTE_HANDLERS'))->toBeFalse()
        ->and(defined(ConfigKey::class . '::GENERATED_ENTRY_RESOLVER_FILE'))->toBeFalse()
        ->and(method_exists(ConfigProvider::class, 'getAttributeHandlers'))->toBeFalse();
});
