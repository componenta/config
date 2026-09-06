<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Contract;

use Componenta\Config\ConfigComposition;
use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigKey;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\Config\Exception\ConfigException;
use RuntimeException;
use stdClass;

function invokeRuntimeConfigProvider(callable $provider): void
{
    (new ConfigFactory())->create(
        new Environment([]),
        $provider,
    );
}

it('separates dependency definitions from the runtime application config', function (): void {
    $environment = new Environment(['APP_ENV' => 'test']);
    $service = new stdClass();

    $composition = (new ConfigFactory())->create(
        environment: $environment,
        providers: static fn(): array => [
            'app' => ['name' => 'Componenta'],
            ConfigKey::DEPENDENCIES => [
                ConfigKey::SERVICES => ['runtime' => $service],
            ],
        ],
    );

    expect($composition)
        ->toBeInstanceOf(ConfigComposition::class)
        ->and($composition->config->toArray())->toBe([
            'app' => ['name' => 'Componenta'],
        ])
        ->and($composition->config->environment)->toBe($environment)
        ->and($composition->config->has(ConfigKey::DEPENDENCIES))->toBeFalse()
        ->and($composition->dependencies)->toBeInstanceOf(DependencyDefinitions::class)
        ->and($composition->dependencies->sections)->toBe([
            ConfigKey::SERVICES => ['runtime' => $service],
        ]);
});

it('invokes every provider occurrence once in argument order', function (): void {
    $events = [];
    $sharedCalls = 0;
    $middleCalls = 0;
    $repeated = static function () use (&$events, &$sharedCalls): array {
        ++$sharedCalls;
        $event = 'shared-' . $sharedCalls;
        $events[] = $event;

        return ['sequence' => [$event]];
    };
    $middle = static function () use (&$events, &$middleCalls): array {
        ++$middleCalls;
        $events[] = 'middle';

        return ['sequence' => ['middle']];
    };

    $composition = (new ConfigFactory())->create(
        new Environment([]),
        $repeated,
        $middle,
        $repeated,
    );

    expect($sharedCalls)->toBe(2)
        ->and($middleCalls)->toBe(1)
        ->and($events)->toBe(['shared-1', 'middle', 'shared-2'])
        ->and($composition->config->toArray())->toBe([
            'sequence' => ['shared-1', 'middle', 'shared-2'],
        ]);
});

it('folds iterable contributions in yielded order and ignores empty contributions', function (): void {
    $calls = 0;
    $iterable = static function () use (&$calls): iterable {
        ++$calls;

        yield ['sequence' => ['first']];
        yield [];
        yield ['sequence' => ['second']];
    };

    $composition = (new ConfigFactory())->create(
        new Environment([]),
        $iterable,
        static fn(): array => ['sequence' => ['third']],
    );

    expect($calls)->toBe(1)
        ->and($composition->config->toArray())->toBe([
            'sequence' => ['first', 'second', 'third'],
        ]);
});

it('propagates the original provider exception unchanged', function (): void {
    $expected = new RuntimeException('provider failed');

    try {
        (new ConfigFactory())->create(
            new Environment([]),
            static fn(): never => throw $expected,
        );
    } catch (RuntimeException $actual) {
        expect($actual)->toBe($expected);

        return;
    }

    throw new \LogicException('The provider exception was not propagated.');
});

it('propagates an exception thrown while consuming provider contributions unchanged', function (): void {
    $expected = new RuntimeException('provider iteration failed');
    $provider = static function () use ($expected): iterable {
        yield ['loaded' => true];

        throw $expected;
    };

    try {
        (new ConfigFactory())->create(new Environment([]), $provider);
    } catch (RuntimeException $actual) {
        expect($actual)->toBe($expected);

        return;
    }

    throw new \LogicException('The provider iteration exception was not propagated.');
});

it('preserves arbitrary runtime values in development and production compositions', function (): void {
    $service = new stdClass();
    $resource = fopen('php://memory', 'r+');
    if ($resource === false) {
        throw new \RuntimeException('Unable to create the runtime resource fixture.');
    }

    $callback = static fn(): object => $service;
    $provider = static fn(): array => [
        'runtime' => [
            'service' => $service,
            'callback' => $callback,
            'resource' => $resource,
        ],
        ConfigKey::DEPENDENCIES => [
            ConfigKey::SERVICES => [
                'service' => $service,
                'callback' => $callback,
                'resource' => $resource,
            ],
        ],
    ];

    try {
        foreach (['development', 'production'] as $mode) {
            $composition = (new ConfigFactory())->create(
                new Environment(['APP_ENV' => $mode]),
                $provider,
            );
            $runtime = $composition->config->array('runtime');
            $services = $composition->dependencies->sections[ConfigKey::SERVICES];
            if (!is_array($services)) {
                throw new \LogicException('The services dependency fixture must remain an array.');
            }

            expect($runtime['service'])->toBe($service)
                ->and($runtime['callback'])->toBe($callback)
                ->and($runtime['resource'])->toBe($resource)
                ->and($services['service'])->toBe($service)
                ->and($services['callback'])->toBe($callback)
                ->and($services['resource'])->toBe($resource);
        }
    } finally {
        fclose($resource);
    }
});

it('rejects invalid provider and iterable contribution outputs', function (): void {
    expect(fn() => invokeRuntimeConfigProvider(
        static fn(): int => 42,
    ))->toThrow(
        ConfigException::class,
        'provider must return an array or iterable; got int',
    )->and(fn() => invokeRuntimeConfigProvider(
        static function (): iterable {
            yield ['valid' => true];
            yield 'invalid';
        },
    ))->toThrow(
        ConfigException::class,
        'contribution must be an array; got string',
    );
});
