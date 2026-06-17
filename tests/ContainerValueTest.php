<?php

declare(strict_types=1);

use Componenta\Config\ContainerValue;
use Componenta\Config\ContainerEntry;
use Componenta\Config\Config;
use Componenta\Config\ConfigEntry;
use Componenta\Config\ConfigPath;
use Componenta\Config\Exception\InvalidContainerValueException;
use Componenta\Config\LazyValue;
use Psr\Container\ContainerInterface;

final class ContainerValueTestContainer implements ContainerInterface
{
    /**
     * @param array<string, mixed> $entries
     */
    public function __construct(
        private readonly array $entries,
    ) {}

    public function get(string $id): mixed
    {
        return $this->entries[$id] ?? throw new RuntimeException("Missing test entry {$id}.");
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}

it('returns a container entry when it matches the requested type', function (): void {
    $service = new DateTimeImmutable();
    $container = new ContainerValue(new ContainerValueTestContainer([
        'clock' => $service,
    ]));

    expect($container->get('clock', DateTimeImmutable::class))->toBe($service);
});

it('can be used as a PSR container directly', function (): void {
    $service = new DateTimeImmutable();
    $container = new ContainerValue(new ContainerValueTestContainer([
        'clock' => $service,
    ]));

    expect($container->get('clock'))->toBe($service);
});

it('returns the default when an optional entry is missing', function (): void {
    $default = new stdClass();
    $container = new ContainerValue(new ContainerValueTestContainer([]));

    expect($container->find('missing', $default))->toBe($default);
});

it('resolves a container entry fallback when an optional entry is missing', function (): void {
    $fallback = new DateTimeImmutable();
    $container = new ContainerValue(new ContainerValueTestContainer([
        'fallback.clock' => $fallback,
    ]));

    expect($container->find('clock', new ContainerEntry('fallback.clock', DateTimeInterface::class)))
        ->toBe($fallback);
});

it('uses a container entry fallback type to validate an existing entry', function (): void {
    $clock = new DateTimeImmutable();
    $container = new ContainerValue(new ContainerValueTestContainer([
        'clock' => $clock,
        'fallback.clock' => new stdClass(),
    ]));

    expect($container->find('clock', new ContainerEntry('fallback.clock', DateTimeInterface::class)))
        ->toBe($clock);
});

it('resolves a config entry fallback when an optional entry is missing', function (): void {
    $config = new Config([
        'app' => ['name' => 'Componenta'],
    ]);
    $container = new ContainerValue(new ContainerValueTestContainer([
        Config::class => $config,
    ]));

    expect($container->find('app.name', new ConfigEntry(new ConfigPath('app.name'))))
        ->toBe('Componenta');
});

it('executes a lazy fallback when an optional entry is missing', function (): void {
    $container = new ContainerValue(new ContainerValueTestContainer([]));

    expect($container->find('clock', new LazyValue(
        static fn (ContainerValue $container): string => $container->has('clock') ? 'present' : 'missing',
    )))->toBe('missing');
});

it('returns a plain callable fallback without executing it', function (): void {
    $container = new ContainerValue(new ContainerValueTestContainer([]));
    $callback = static fn (): string => 'computed';

    expect($container->find('clock', $callback))->toBe($callback);
});

it('throws when a container entry has an unexpected type', function (): void {
    $container = new ContainerValue(new ContainerValueTestContainer([
        'clock' => new stdClass(),
    ]));

    expect(fn () => $container->get('clock', DateTimeImmutable::class))
        ->toThrow(InvalidContainerValueException::class, DateTimeImmutable::class);
});

it('delegates existence checks to the wrapped container', function (): void {
    $container = new ContainerValue(new ContainerValueTestContainer([
        'clock' => new DateTimeImmutable(),
    ]));

    expect($container->has('clock'))->toBeTrue()
        ->and($container->has('missing'))->toBeFalse();
});

it('exposes config from the wrapped container', function (): void {
    $config = new Config(['app' => ['name' => 'Componenta']]);
    $container = new ContainerValue(new ContainerValueTestContainer([
        Config::class => $config,
    ]));

    expect($container->config)->toBe($config);
});

it('exposes an empty config when the wrapped container has none', function (): void {
    $container = new ContainerValue(new ContainerValueTestContainer([]));

    expect($container->config)->toBeInstanceOf(Config::class)
        ->and($container->config->data)->toBe([]);
});
