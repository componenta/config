<?php

declare(strict_types=1);

namespace Componenta\Config\Tests;

use Componenta\Config\ConfigKey;
use Componenta\Config\ConfigLoader;
use Componenta\Config\ConfigProvider;
use PHPUnit\Framework\TestCase;

use function Componenta\Config\config_merge;

final class ConfigCompositionRegressionTest extends TestCase
{
    public function testConfigLoaderPreservesParameterResolverPriorities(): void
    {
        $config = ConfigLoader::load(
            null,
            static fn (): array => [
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::PARAMETER_RESOLVERS => [1200 => 'ResolverA'],
                ],
            ],
            static fn (): array => [
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::PARAMETER_RESOLVERS => [900 => 'ResolverB'],
                ],
            ],
        );

        self::assertSame(
            [1200 => 'ResolverA', 900 => 'ResolverB'],
            $config->get(ConfigKey::DEPENDENCIES)[ConfigKey::PARAMETER_RESOLVERS],
        );
    }

    public function testLaterResolverAtSamePriorityReplacesEarlierResolver(): void
    {
        $merged = config_merge(
            [
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::PARAMETER_RESOLVERS => [
                        1200 => 'ResolverA',
                        900 => 'ResolverOld',
                    ],
                ],
            ],
            [
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::PARAMETER_RESOLVERS => [
                        900 => 'ResolverB',
                        700 => 'ResolverC',
                    ],
                ],
            ],
        );

        self::assertSame(
            [
                1200 => 'ResolverA',
                900 => 'ResolverB',
                700 => 'ResolverC',
            ],
            $merged[ConfigKey::DEPENDENCIES][ConfigKey::PARAMETER_RESOLVERS],
        );
    }

    public function testArrayValuedResolverSpecificationIsReplacedAtomically(): void
    {
        $merged = config_merge(
            [
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::PARAMETER_RESOLVERS => [
                        900 => ['resolver.a', 'create'],
                    ],
                ],
            ],
            [
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::PARAMETER_RESOLVERS => [
                        900 => ['resolver.b', 'build'],
                    ],
                ],
            ],
        );

        self::assertSame(
            [900 => ['resolver.b', 'build']],
            $merged[ConfigKey::DEPENDENCIES][ConfigKey::PARAMETER_RESOLVERS],
        );
    }

    public function testFactoryAliasAndServiceEntriesAreAtomicById(): void
    {
        $merged = config_merge(
            [
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::FACTORIES => [
                        'service' => ['factory.a', 'create'],
                    ],
                    ConfigKey::ALIASES => [
                        'alias' => 'TargetA',
                    ],
                    ConfigKey::SERVICES => [
                        'settings' => [
                            'enabled' => true,
                            'stale' => true,
                        ],
                    ],
                ],
            ],
            [
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::FACTORIES => [
                        'service' => ['factory.b', 'build'],
                    ],
                    ConfigKey::ALIASES => [
                        'alias' => 'TargetB',
                    ],
                    ConfigKey::SERVICES => [
                        'settings' => [
                            'enabled' => false,
                        ],
                    ],
                ],
            ],
        );

        $dependencies = $merged[ConfigKey::DEPENDENCIES];

        self::assertSame(['factory.b', 'build'], $dependencies[ConfigKey::FACTORIES]['service']);
        self::assertSame('TargetB', $dependencies[ConfigKey::ALIASES]['alias']);
        self::assertSame(['enabled' => false], $dependencies[ConfigKey::SERVICES]['settings']);
    }

    public function testListLikeDependencySectionsKeepHistoricalMergeSemantics(): void
    {
        $merged = config_merge(
            [
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::INVOKABLES => [
                        'service.alias' => 'ServiceA',
                        'ServiceB',
                    ],
                    ConfigKey::DELEGATORS => [
                        'service' => ['DelegatorA'],
                    ],
                    ConfigKey::ATTRIBUTE_HANDLERS => ['HandlerA'],
                ],
            ],
            [
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::INVOKABLES => [
                        'service.alias' => 'ServiceC',
                        'ServiceD',
                    ],
                    ConfigKey::DELEGATORS => [
                        'service' => ['DelegatorB'],
                    ],
                    ConfigKey::ATTRIBUTE_HANDLERS => ['HandlerB'],
                ],
            ],
        );

        $dependencies = $merged[ConfigKey::DEPENDENCIES];

        self::assertSame(
            [
                'service.alias' => 'ServiceC',
                0 => 'ServiceB',
                1 => 'ServiceD',
            ],
            $dependencies[ConfigKey::INVOKABLES],
        );
        self::assertSame(
            ['DelegatorA', 'DelegatorB'],
            $dependencies[ConfigKey::DELEGATORS]['service'],
        );
        self::assertSame(
            ['HandlerA', 'HandlerB'],
            $dependencies[ConfigKey::ATTRIBUTE_HANDLERS],
        );
    }

    public function testNestedApplicationDependenciesKeyKeepsGenericMergeSemantics(): void
    {
        $merged = config_merge(
            ['feature' => ['dependencies' => [10 => 'A']]],
            ['feature' => ['dependencies' => [5 => 'B']]],
        );

        self::assertSame(
            [10 => 'A', 11 => 'B'],
            $merged['feature']['dependencies'],
        );
    }

    public function testOverrideIndexesKeepsHistoricalRecursiveReplacementSemantics(): void
    {
        $merged = config_merge(
            [
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::SERVICES => [
                        'settings' => [
                            'old' => true,
                        ],
                    ],
                ],
            ],
            [
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::OVERRIDE_INDEXES => true,
                    ConfigKey::SERVICES => [
                        'settings' => [
                            'new' => true,
                        ],
                    ],
                ],
            ],
        );

        self::assertSame(
            ['old' => true, 'new' => true],
            $merged[ConfigKey::DEPENDENCIES][ConfigKey::SERVICES]['settings'],
        );
    }

    public function testExplicitFalseReplaceFlagsCanCancelEarlierTrue(): void
    {
        $provider = new class extends ConfigProvider {
            protected function shouldReplaceParameterResolvers(): bool
            {
                return true;
            }

            protected function shouldReplaceAttributeHandlers(): bool
            {
                return true;
            }

            protected function getProviders(): array
            {
                return [
                    new class extends ConfigProvider {
                        protected function shouldReplaceParameterResolvers(): bool
                        {
                            return false;
                        }

                        protected function shouldReplaceAttributeHandlers(): bool
                        {
                            return false;
                        }
                    },
                ];
            }
        };

        $dependencies = $provider()[ConfigKey::DEPENDENCIES];

        self::assertFalse($dependencies[ConfigKey::PARAMETER_RESOLVERS_REPLACE]);
        self::assertFalse($dependencies[ConfigKey::ATTRIBUTE_HANDLERS_REPLACE]);
    }

    public function testInheritedBaseFalseDoesNotCancelEarlierTrue(): void
    {
        $provider = new class extends ConfigProvider {
            protected function shouldReplaceParameterResolvers(): bool
            {
                return true;
            }

            protected function getProviders(): array
            {
                return [new ConfigProvider()];
            }
        };

        self::assertTrue(
            $provider()[ConfigKey::DEPENDENCIES][ConfigKey::PARAMETER_RESOLVERS_REPLACE],
        );
    }

    public function testInheritedDefaultFalseReplaceFlagsRemainOmitted(): void
    {
        $dependencies = (new ConfigProvider())()[ConfigKey::DEPENDENCIES];

        self::assertArrayNotHasKey(ConfigKey::PARAMETER_RESOLVERS_REPLACE, $dependencies);
        self::assertArrayNotHasKey(ConfigKey::ATTRIBUTE_HANDLERS_REPLACE, $dependencies);
    }

    public function testDependencyHookEvaluationOrderIsUnchanged(): void
    {
        ConfigCompositionHookOrderProvider::$calls = [];

        (new ConfigCompositionHookOrderProvider())();

        self::assertSame(
            [
                'getInvokables',
                'getFactories',
                'getAliases',
                'getDelegators',
                'getServices',
                'getParameterResolvers',
                'shouldReplaceParameterResolvers',
                'getAttributeHandlers',
                'shouldReplaceAttributeHandlers',
                'getDependencyExtensions',
            ],
            ConfigCompositionHookOrderProvider::$calls,
        );
    }
}

final class ConfigCompositionHookOrderProvider extends ConfigProvider
{
    /** @var list<string> */
    public static array $calls = [];

    protected function getInvokables(): array
    {
        self::$calls[] = __FUNCTION__;
        return [];
    }

    protected function getFactories(): array
    {
        self::$calls[] = __FUNCTION__;
        return [];
    }

    protected function getAliases(): array
    {
        self::$calls[] = __FUNCTION__;
        return [];
    }

    protected function getDelegators(): array
    {
        self::$calls[] = __FUNCTION__;
        return [];
    }

    protected function getServices(): array
    {
        self::$calls[] = __FUNCTION__;
        return [];
    }

    protected function getParameterResolvers(): array
    {
        self::$calls[] = __FUNCTION__;
        return [];
    }

    protected function shouldReplaceParameterResolvers(): bool
    {
        self::$calls[] = __FUNCTION__;
        return false;
    }

    protected function getAttributeHandlers(): array
    {
        self::$calls[] = __FUNCTION__;
        return [];
    }

    protected function shouldReplaceAttributeHandlers(): bool
    {
        self::$calls[] = __FUNCTION__;
        return false;
    }

    protected function getDependencyExtensions(): array
    {
        self::$calls[] = __FUNCTION__;
        return [];
    }
}
