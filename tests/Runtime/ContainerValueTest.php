<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Runtime;

use Componenta\Config\Config;
use Componenta\Config\ConfigEntry;
use Componenta\Config\ConfigPath;
use Componenta\Config\ContainerEntry;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\Config\EnvironmentEntry;
use Componenta\Config\Exception\InvalidConfigArgumentException;
use Componenta\Config\Exception\InvalidContainerValueException;
use Componenta\Config\LazyValue;
use Componenta\Config\Tests\Support\ContainerValueTestContainer;
use DateTimeImmutable;
use DateTimeInterface;
use stdClass;

/** @param array<string, mixed> $entries */
function containerValue(array $entries = [], ?Config $config = null): ContainerValue
{
    return new ContainerValue(
        new ContainerValueTestContainer($entries),
        $config ?? new Config([], new Environment([])),
    );
}

it('returns a container entry when it matches the requested type', function (): void {
    $service = new DateTimeImmutable();
    $container = containerValue(['clock' => $service]);

    expect($container->get('clock', DateTimeImmutable::class))->toBe($service);
});

it('can be used as a PSR container directly including null entries', function (): void {
    $service = new DateTimeImmutable();
    $container = containerValue(['clock' => $service, 'nullable' => null]);

    expect($container->get('clock'))->toBe($service)
        ->and($container->has('nullable'))->toBeTrue()
        ->and($container->get('nullable'))->toBeNull();
});

it('returns the default when an optional entry is missing', function (): void {
    $default = new stdClass();

    expect(containerValue()->find('missing', $default))->toBe($default);
});

it('resolves a container entry fallback when an optional entry is missing', function (): void {
    $fallback = new DateTimeImmutable();
    $container = containerValue(['fallback.clock' => $fallback]);

    expect($container->find('clock', new ContainerEntry('fallback.clock', DateTimeInterface::class)))
        ->toBe($fallback);
});

it('uses a container entry fallback type to validate an existing entry', function (): void {
    $clock = new DateTimeImmutable();
    $container = containerValue([
        'clock' => $clock,
        'fallback.clock' => new stdClass(),
    ]);

    expect($container->find('clock', new ContainerEntry('fallback.clock', DateTimeInterface::class)))
        ->toBe($clock);
});

it('rejects an existing entry that fails the fallback type check', function (): void {
    $container = containerValue([
        'clock' => new stdClass(),
        'fallback.clock' => new DateTimeImmutable(),
    ]);

    expect(fn() => $container->find('clock', new ContainerEntry('fallback.clock', DateTimeInterface::class)))
        ->toThrow(InvalidContainerValueException::class, DateTimeInterface::class);
});

it('uses the explicit type for an existing entry when the fallback has its own type', function (): void {
    $clock = new DateTimeImmutable();
    $container = containerValue(['clock' => $clock]);

    expect($container->find('clock', new ContainerEntry('fallback.clock', stdClass::class), type: DateTimeInterface::class))
        ->toBe($clock);
});

it('rejects empty container entry identifiers and types', function (): void {
    expect(fn() => new ContainerEntry(''))
        ->toThrow(InvalidConfigArgumentException::class, 'id must not be empty')
        ->and(fn() => new ContainerEntry('service', ''))
        ->toThrow(InvalidConfigArgumentException::class, 'type must not be empty');
});

it('rejects container entry types that do not name a loadable object type', function (): void {
    expect(fn() => new ContainerEntry(
        'service',
        'Componenta\\Config\\Tests\\MissingContainerEntryType',
    ))->toThrow(
        InvalidConfigArgumentException::class,
        'must name an existing class, interface, or enum',
    );
});

it('resolves a config entry fallback against the same config snapshot', function (): void {
    $config = new Config(
        ['app' => ['name' => 'Componenta']],
        new Environment([]),
    );
    $container = containerValue(config: $config);

    expect($container->find('app.name', new ConfigEntry(new ConfigPath('app.name'))))
        ->toBe('Componenta');
});

it('resolves an environment entry fallback against the same runtime environment snapshot', function (): void {
    $config = new Config([], new Environment(['APP_NAME' => 'runtime']));
    $container = containerValue(config: $config);

    expect($container->find('app.name', new EnvironmentEntry('APP_NAME')))
        ->toBe('runtime');
});

it('executes a lazy fallback when an optional entry is missing', function (): void {
    $container = containerValue();

    expect($container->find('clock', new LazyValue(
        static fn(ContainerValue $container): string => $container->has('clock') ? 'present' : 'missing',
    )))->toBe('missing');
});

it('returns a plain callable fallback without executing it', function (): void {
    $container = containerValue();
    $callback = static fn(): string => 'computed';

    expect($container->find('clock', $callback))->toBe($callback);
});

it('throws when a container entry has an unexpected type', function (): void {
    $container = containerValue(['clock' => new stdClass()]);

    expect(fn() => $container->get('clock', DateTimeImmutable::class))
        ->toThrow(InvalidContainerValueException::class, DateTimeImmutable::class);
});

it('delegates existence checks to the wrapped container', function (): void {
    $container = containerValue(['clock' => new DateTimeImmutable()]);

    expect($container->has('clock'))->toBeTrue()
        ->and($container->has('missing'))->toBeFalse();
});

it('retains the supplied container and config by identity', function (): void {
    $config = new Config(
        ['app' => ['name' => 'Componenta']],
        new Environment([]),
    );
    $wrapped = new ContainerValueTestContainer([]);
    $container = new ContainerValue($wrapped, $config);

    expect($container->container)->toBe($wrapped)
        ->and($container->config)->toBe($config);
});
