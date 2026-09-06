<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Contract;

use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigKey;
use Componenta\Config\ConfigProvider;
use Componenta\Config\Environment;
use Componenta\Config\Exception\ConfigMergeException;

it('composes raw dependency definitions without triggering autoload', function (): void {
    $autoloaded = [];
    $autoload = static function (string $class) use (&$autoloaded): void {
        $autoloaded[] = $class;
    };
    spl_autoload_register($autoload);

    try {
        $composition = (new ConfigFactory())->create(
            new Environment([]),
            static fn(): array => [
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::DELEGATORS => [
                        'service' => ['Unloaded\Decorator', 'decorate'],
                    ],
                ],
            ],
        );

        expect($composition->dependencies->sections)->toBe([
            ConfigKey::DELEGATORS => [
                'service' => ['Unloaded\Decorator', 'decorate'],
            ],
        ])->and($autoloaded)->toBe([]);
    } finally {
        spl_autoload_unregister($autoload);
    }
});

it('merges application data independently of dependency definitions', function (): void {
    $provider = new class () extends ConfigProvider {
        protected function getConfig(): array
        {
            return ['first'];
        }

        protected function getAliases(): array
        {
            return ['alias' => 'Target'];
        }
    };

    $dependenciesFirst = (new ConfigFactory())->create(
        new Environment([]),
        $provider,
        static fn(): array => ['second'],
    );
    $dependenciesLast = (new ConfigFactory())->create(
        new Environment([]),
        static fn(): array => ['zero'],
        $provider,
    );

    expect($dependenciesFirst->config->toArray())->toBe(['first', 'second'])
        ->and($dependenciesFirst->dependencies->sections)->toBe([
            ConfigKey::ALIASES => ['alias' => 'Target'],
        ])
        ->and($dependenciesLast->config->toArray())->toBe(['zero', 'first'])
        ->and($dependenciesLast->dependencies->sections)->toBe([
            ConfigKey::ALIASES => ['alias' => 'Target'],
        ]);
});

it('preserves dependency definitions when later application data replaces the root shape', function (): void {
    $composition = (new ConfigFactory())->create(
        new Environment([]),
        static fn(): array => [
            'legacy' => true,
            ConfigKey::DEPENDENCIES => [
                ConfigKey::ALIASES => ['alias' => 'Target'],
            ],
        ],
        static fn(): array => ['replacement'],
    );

    expect($composition->config->toArray())->toBe(['replacement'])
        ->and($composition->dependencies->sections)->toBe([
            ConfigKey::ALIASES => ['alias' => 'Target'],
        ]);
});

it('merges keyed dependency sections atomically with later definitions winning', function (): void {
    $sections = (new ConfigFactory())->create(
        new Environment([]),
        static fn(): array => [
            ConfigKey::DEPENDENCIES => [
                ConfigKey::FACTORIES => [
                    'first-only' => ['factory.one', 'create'],
                    'shared' => ['factory.one', 'create'],
                ],
                ConfigKey::ALIASES => [
                    'first-only' => 'FirstOnly',
                    'shared' => 'First',
                ],
                ConfigKey::SERVICES => [
                    'first-only' => ['origin' => 'first'],
                    'shared' => ['first' => true],
                ],
                ConfigKey::PARAMETER_RESOLVERS => [
                    100 => 'FirstOnlyResolver',
                    900 => 'FirstResolver',
                ],
            ],
        ],
        static fn(): array => [
            ConfigKey::DEPENDENCIES => [
                ConfigKey::FACTORIES => [
                    'shared' => ['factory.two', 'build'],
                    'second-only' => ['factory.two', 'create'],
                ],
                ConfigKey::ALIASES => [
                    'shared' => 'Second',
                    'second-only' => 'SecondOnly',
                ],
                ConfigKey::SERVICES => [
                    'shared' => ['second' => true],
                    'second-only' => ['origin' => 'second'],
                ],
                ConfigKey::PARAMETER_RESOLVERS => [
                    900 => 'SecondResolver',
                    1000 => 'SecondOnlyResolver',
                ],
            ],
        ],
    )->dependencies->sections;

    expect($sections)->toBe([
        ConfigKey::FACTORIES => [
            'first-only' => ['factory.one', 'create'],
            'shared' => ['factory.two', 'build'],
            'second-only' => ['factory.two', 'create'],
        ],
        ConfigKey::ALIASES => [
            'first-only' => 'FirstOnly',
            'shared' => 'Second',
            'second-only' => 'SecondOnly',
        ],
        ConfigKey::SERVICES => [
            'first-only' => ['origin' => 'first'],
            'shared' => ['second' => true],
            'second-only' => ['origin' => 'second'],
        ],
        ConfigKey::PARAMETER_RESOLVERS => [
            100 => 'FirstOnlyResolver',
            900 => 'SecondResolver',
            1000 => 'SecondOnlyResolver',
        ],
    ]);
});

it('appends ordered dependency pipelines while replacing keyed invokables', function (): void {
    $sections = (new ConfigFactory())->create(
        new Environment([]),
        static fn(): array => [
            ConfigKey::DEPENDENCIES => [
                ConfigKey::INVOKABLES => [
                    'service' => 'First',
                    'first-only' => 'FirstOnly',
                    'NumericFirst',
                ],
                ConfigKey::DELEGATORS => [
                    'service' => ['FirstDecorator'],
                    'first-only' => ['FirstOnlyDecorator'],
                ],
                ConfigKey::ATTRIBUTE_DEFINITIONS => ['FirstDefinition'],
                ConfigKey::ATTRIBUTE_CAPABILITIES => ['FirstCapability'],
            ],
        ],
        static fn(): array => [
            ConfigKey::DEPENDENCIES => [
                ConfigKey::INVOKABLES => [
                    'service' => 'Second',
                    'second-only' => 'SecondOnly',
                    'NumericSecond',
                ],
                ConfigKey::DELEGATORS => [
                    'service' => ['SecondDecorator'],
                    'second-only' => ['SecondOnlyDecorator'],
                ],
                ConfigKey::ATTRIBUTE_DEFINITIONS => ['SecondDefinition'],
                ConfigKey::ATTRIBUTE_CAPABILITIES => ['SecondCapability'],
            ],
        ],
    )->dependencies->sections;

    expect($sections)->toBe([
        ConfigKey::INVOKABLES => [
            'service' => 'Second',
            'first-only' => 'FirstOnly',
            0 => 'NumericFirst',
            'second-only' => 'SecondOnly',
            1 => 'NumericSecond',
        ],
        ConfigKey::DELEGATORS => [
            'service' => ['FirstDecorator', 'SecondDecorator'],
            'first-only' => ['FirstOnlyDecorator'],
            'second-only' => ['SecondOnlyDecorator'],
        ],
        ConfigKey::ATTRIBUTE_DEFINITIONS => ['FirstDefinition', 'SecondDefinition'],
        ConfigKey::ATTRIBUTE_CAPABILITIES => ['FirstCapability', 'SecondCapability'],
    ]);
});

it('keeps later dependency definitions after an explicitly empty dependency contribution', function (): void {
    $sections = (new ConfigFactory())->create(
        new Environment([]),
        static fn(): array => [
            ConfigKey::DEPENDENCIES => [],
        ],
        static fn(): array => [
            ConfigKey::DEPENDENCIES => [
                ConfigKey::ALIASES => ['alias' => 'Target'],
            ],
        ],
    )->dependencies->sections;

    expect($sections)->toBe([
        ConfigKey::ALIASES => ['alias' => 'Target'],
    ]);
});

it('uses the last explicitly supplied dependency replacement flags', function (): void {
    $sections = (new ConfigFactory())->create(
        new Environment([]),
        static fn(): array => [
            ConfigKey::DEPENDENCIES => [
                ConfigKey::PARAMETER_RESOLVERS_REPLACE => true,
                ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE => true,
            ],
        ],
        static fn(): array => [
            ConfigKey::DEPENDENCIES => [],
        ],
        static fn(): array => [
            ConfigKey::DEPENDENCIES => [
                ConfigKey::PARAMETER_RESOLVERS_REPLACE => false,
                ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE => false,
            ],
        ],
    )->dependencies->sections;

    expect($sections)->toBe([
        ConfigKey::PARAMETER_RESOLVERS_REPLACE => false,
        ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE => false,
    ]);
});

it('keeps replacement flags when a later ConfigProvider omits them', function (): void {
    $explicit = new class () extends ConfigProvider {
        protected function shouldReplaceParameterResolvers(): ?bool
        {
            return true;
        }

        protected function shouldReplaceAttributeDefinitions(): ?bool
        {
            return true;
        }
    };
    $omitting = new class () extends ConfigProvider {};

    $sections = (new ConfigFactory())->create(
        new Environment([]),
        $explicit,
        $omitting,
    )->dependencies->sections;

    expect($sections)->toBe([
        ConfigKey::PARAMETER_RESOLVERS_REPLACE => true,
        ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE => true,
    ]);
});

it('rejects a non-array dependency root with a typed merge exception', function (): void {
    expect(fn() => (new ConfigFactory())->create(
        new Environment([]),
        static fn(): array => [
            ConfigKey::DEPENDENCIES => 'invalid',
        ],
    ))->toThrow(
        ConfigMergeException::class,
        'Dependency root "dependencies" must be an array',
    );
});

it('rejects composition-unsafe dependency section shapes', function (
    array $dependencies,
    string $message,
): void {
    expect(fn() => (new ConfigFactory())->create(
        new Environment([]),
        static fn(): array => [
            ConfigKey::DEPENDENCIES => $dependencies,
        ],
    ))->toThrow(ConfigMergeException::class, $message);
})->with([
    'unsupported section' => [
        ['unsupported' => []],
        'Unsupported dependency section "unsupported"',
    ],
    'factories must be an array' => [
        [ConfigKey::FACTORIES => 'invalid'],
        'Dependency section "factories" must be an array',
    ],
    'invokables must be an array' => [
        [ConfigKey::INVOKABLES => 'invalid'],
        'Dependency section "invokables" must be an array',
    ],
    'aliases must be an array' => [
        [ConfigKey::ALIASES => 'invalid'],
        'Dependency section "aliases" must be an array',
    ],
    'delegators must be an array' => [
        [ConfigKey::DELEGATORS => 'invalid'],
        'Dependency section "delegators" must be an array',
    ],
    'services must be an array' => [
        [ConfigKey::SERVICES => 'invalid'],
        'Dependency section "services" must be an array',
    ],
    'parameter resolvers must be an array' => [
        [ConfigKey::PARAMETER_RESOLVERS => 'invalid'],
        'Dependency section "parameter_resolvers" must be an array',
    ],
    'attribute definitions must be an array' => [
        [ConfigKey::ATTRIBUTE_DEFINITIONS => 'invalid'],
        'Dependency section "attribute_definitions" must be an array',
    ],
    'attribute capabilities must be an array' => [
        [ConfigKey::ATTRIBUTE_CAPABILITIES => 'invalid'],
        'Dependency section "attribute_capabilities" must be an array',
    ],
    'parameter resolver replacement flag must be bool' => [
        [ConfigKey::PARAMETER_RESOLVERS_REPLACE => 1],
        'Dependency section "parameter_resolvers_replace" must be bool',
    ],
    'attribute definition replacement flag must be bool' => [
        [ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE => 1],
        'Dependency section "attribute_definitions_replace" must be bool',
    ],
    'attribute definitions must be a list' => [
        [ConfigKey::ATTRIBUTE_DEFINITIONS => ['named' => 'Definition']],
        'Dependency section "attribute_definitions" must be a list',
    ],
    'attribute capabilities must be a list' => [
        [ConfigKey::ATTRIBUTE_CAPABILITIES => ['named' => 'Capability']],
        'Dependency section "attribute_capabilities" must be a list',
    ],
    'delegator id must not be empty' => [
        [ConfigKey::DELEGATORS => ['' => []]],
        'Delegator service ids must be non-empty strings',
    ],
    'delegator id must be a string' => [
        [ConfigKey::DELEGATORS => [0 => []]],
        'Delegator service ids must be non-empty strings',
    ],
    'delegator pipeline must be an array' => [
        [ConfigKey::DELEGATORS => ['service' => 'invalid']],
        'Delegators for "service" must be configured as a pipeline list',
    ],
    'delegator pipeline must be a list' => [
        [ConfigKey::DELEGATORS => ['service' => ['named' => 'Decorator']]],
        'Delegators for "service" must be configured as a pipeline list',
    ],
]);

it('publishes the dependency section names consumed by DI', function (): void {
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
