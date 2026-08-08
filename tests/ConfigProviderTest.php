<?php

declare(strict_types=1);

namespace Componenta\Config\Tests;

use Componenta\Config\ConfigKey;
use Componenta\Config\ConfigProvider;
use PHPUnit\Framework\TestCase;

final class ConfigProviderTest extends TestCase
{
    public function testInvokeReturnsEmptyDependenciesForBaseProvider(): void
    {
        $provider = new ConfigProvider();

        $config = $provider();

        self::assertSame(['dependencies' => []], $config);
    }

    public function testInvokeReturnsFactories(): void
    {
        $provider = new class extends ConfigProvider {
            protected function getFactories(): array
            {
                return ['ServiceA' => 'FactoryA'];
            }
        };

        $config = $provider();

        self::assertSame(['ServiceA' => 'FactoryA'], $config['dependencies']['factories']);
    }

    public function testInvokeReturnsInvokablesAsList(): void
    {
        $provider = new class extends ConfigProvider {
            protected function getInvokables(): array
            {
                return ['ServiceA', 'ServiceB'];
            }
        };

        $config = $provider();

        self::assertSame(['ServiceA', 'ServiceB'], $config['dependencies']['invokables']);
    }

    public function testInvokeExtractsAliasesFromKeyedInvokables(): void
    {
        $provider = new class extends ConfigProvider {
            protected function getInvokables(): array
            {
                return [
                    'SimpleService',
                    'AliasInterface' => 'ConcreteService',
                ];
            }
        };

        $config = $provider();

        self::assertSame(['SimpleService', 'ConcreteService'], $config['dependencies']['invokables']);
        self::assertSame(['AliasInterface' => 'ConcreteService'], $config['dependencies']['aliases']);
    }

    public function testInvokeMergesInvokableAliasesWithExplicitAliases(): void
    {
        $provider = new class extends ConfigProvider {
            protected function getInvokables(): array
            {
                return [
                    'Interface1' => 'Concrete1',
                ];
            }

            protected function getAliases(): array
            {
                return [
                    'Interface2' => 'Concrete2',
                ];
            }
        };

        $config = $provider();

        self::assertSame([
            'Interface2' => 'Concrete2',
            'Interface1' => 'Concrete1',
        ], $config['dependencies']['aliases']);
    }

    public function testInvokeReturnsVersionTwoExtensionConfiguration(): void
    {
        $provider = new class extends ConfigProvider {
            protected function getServices(): array
            {
                return ['feature.enabled' => false];
            }

            protected function getParameterResolvers(): array
            {
                return [100 => 'ResolverA'];
            }

            protected function shouldReplaceParameterResolvers(): bool
            {
                return true;
            }

            protected function getAttributeHandlers(): array
            {
                return ['HandlerA'];
            }

            protected function shouldReplaceAttributeHandlers(): bool
            {
                return true;
            }

            protected function getDependencyExtensions(): array
            {
                return [
                    ConfigKey::GENERATED_ENTRY_RESOLVER_FILE => 'runtime/container.resolver.php',
                    ConfigKey::GENERATED_ENTRY_RESOLVER_RELEASE_FINGERPRINT => 'release-42',
                ];
            }
        };

        $config = $provider();

        self::assertSame([
            ConfigKey::SERVICES => ['feature.enabled' => false],
            ConfigKey::PARAMETER_RESOLVERS => [100 => 'ResolverA'],
            ConfigKey::PARAMETER_RESOLVERS_REPLACE => true,
            ConfigKey::ATTRIBUTE_HANDLERS => ['HandlerA'],
            ConfigKey::ATTRIBUTE_HANDLERS_REPLACE => true,
            ConfigKey::GENERATED_ENTRY_RESOLVER_FILE => 'runtime/container.resolver.php',
            ConfigKey::GENERATED_ENTRY_RESOLVER_RELEASE_FINGERPRINT => 'release-42',
        ], $config[ConfigKey::DEPENDENCIES]);
    }

    public function testInvokeReturnsDelegators(): void
    {
        $provider = new class extends ConfigProvider {
            protected function getDelegators(): array
            {
                return [
                    'ServiceA' => ['Delegator1', 'Delegator2'],
                ];
            }
        };

        $config = $provider();

        self::assertSame(
            ['ServiceA' => ['Delegator1', 'Delegator2']],
            $config['dependencies']['delegators'],
        );
    }

    public function testInvokeReturnsServices(): void
    {
        $provider = new class extends ConfigProvider {
            protected function getServices(): array
            {
                return ['config' => ['key' => 'value']];
            }
        };

        $config = $provider();

        self::assertSame(['config' => ['key' => 'value']], $config['dependencies']['services']);
    }

    public function testInvokeReturnsApplicationConfig(): void
    {
        $provider = new class extends ConfigProvider {
            protected function getConfig(): array
            {
                return [
                    'app' => ['name' => 'MyApp'],
                    'debug' => true,
                ];
            }
        };

        $config = $provider();

        self::assertSame('MyApp', $config['app']['name']);
        self::assertTrue($config['debug']);
    }

    public function testInvokeMergesChildProviders(): void
    {
        $provider = new ConfigProviderTestParentFixture();

        $config = $provider();

        self::assertArrayHasKey('ParentService', $config['dependencies']['factories']);
        self::assertArrayHasKey('ChildService', $config['dependencies']['factories']);
        self::assertSame('config', $config['parent']);
        self::assertSame('config', $config['child']);
    }

    public function testInvokeFiltersEmptyArrays(): void
    {
        $provider = new class extends ConfigProvider {
            protected function getFactories(): array
            {
                return ['Service' => 'Factory'];
            }
        };

        $config = $provider();

        self::assertSame([
            ConfigKey::FACTORIES => ['Service' => 'Factory'],
        ], $config[ConfigKey::DEPENDENCIES]);
    }

    public function testDependencyExtensionsRejectUnknownKeys(): void
    {
        $provider = new class extends ConfigProvider {
            protected function getDependencyExtensions(): array
            {
                return ['unknown' => true];
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported dependency configuration key "unknown".');

        $provider();
    }

    public function testDependencyExtensionsCannotReplaceBaseKeys(): void
    {
        $provider = new class extends ConfigProvider {
            protected function getDependencyExtensions(): array
            {
                return [ConfigKey::FACTORIES => ['Service' => 'Factory']];
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Dependency extension cannot replace base key "factories".');

        $provider();
    }

    public function testVersionTwoSchemaDoesNotExposeLegacyKeys(): void
    {
        self::assertSame([
            ConfigKey::FACTORIES,
            ConfigKey::INVOKABLES,
            ConfigKey::ALIASES,
            ConfigKey::DELEGATORS,
            ConfigKey::SERVICES,
            ConfigKey::PARAMETER_RESOLVERS,
            ConfigKey::PARAMETER_RESOLVERS_REPLACE,
            ConfigKey::ATTRIBUTE_HANDLERS,
            ConfigKey::ATTRIBUTE_HANDLERS_REPLACE,
            ConfigKey::GENERATED_ENTRY_RESOLVER_FILE,
            ConfigKey::GENERATED_ENTRY_RESOLVER_RELEASE_FINGERPRINT,
        ], ConfigKey::dependencyKeys());

        self::assertFalse(defined(ConfigKey::class . '::AUTOWIRES'));
        self::assertFalse(defined(ConfigKey::class . '::PROPERTY_RESOLVERS'));
        self::assertFalse(defined(ConfigKey::class . '::PROPERTY_RESOLVERS_REPLACE'));
        self::assertFalse(method_exists(ConfigProvider::class, 'getAutowires'));
        self::assertFalse(method_exists(ConfigProvider::class, 'getPropertyResolvers'));
        self::assertTrue(method_exists(ConfigProvider::class, 'getDependencyExtensions'));
        self::assertTrue((new \ReflectionMethod(ConfigProvider::class, 'getDependencies'))->isFinal());
    }

}

// =========================================================================
// FIXTURES
// =========================================================================

/**
 * @internal
 */
final class ConfigProviderTestChildFixture extends ConfigProvider
{
    protected function getFactories(): array
    {
        return ['ChildService' => 'ChildFactory'];
    }

    protected function getConfig(): array
    {
        return ['child' => 'config'];
    }
}

/**
 * @internal
 */
final class ConfigProviderTestParentFixture extends ConfigProvider
{
    protected function getProviders(): array
    {
        return [new ConfigProviderTestChildFixture()];
    }

    protected function getFactories(): array
    {
        return ['ParentService' => 'ParentFactory'];
    }

    protected function getConfig(): array
    {
        return ['parent' => 'config'];
    }
}
