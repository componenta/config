<?php

declare(strict_types=1);

namespace Componenta\Config\Tests;

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

    public function testInvokeReturnsAutowires(): void
    {
        $provider = new class extends ConfigProvider {
            protected function getAutowires(): array
            {
                return ['ServiceA', 'ServiceB'];
            }
        };

        $config = $provider();

        self::assertSame(['ServiceA', 'ServiceB'], $config['dependencies']['autowires']);
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

        self::assertArrayHasKey('factories', $config['dependencies']);
        self::assertArrayNotHasKey('invokables', $config['dependencies']);
        self::assertArrayNotHasKey('autowires', $config['dependencies']);
        self::assertArrayNotHasKey('aliases', $config['dependencies']);
        self::assertArrayNotHasKey('delegators', $config['dependencies']);
        self::assertArrayNotHasKey('services', $config['dependencies']);
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
