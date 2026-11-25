<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Runtime;

use Componenta\Config\Config;
use Componenta\Config\ConfigPath;
use Componenta\Config\Environment;
use Componenta\Config\Exception\ConfigResolutionException;
use Componenta\Config\LazyValue;
use PHPUnit\Framework\Assert;
use stdClass;

it('keeps referenced settings consistent with a cached derived value', function (): void {
    $mode = 'prod';
    $settings = ['mode' => &$mode];
    $config = new Config([
        'mode' => &$mode,
        'settings' => &$settings,
        'label' => new LazyValue(static fn(Config $current): string => 'app-' . $current->string('mode')),
    ], new Environment([]));

    $label = $config->string('label');
    $mode = 'dev';
    $settings = ['mode' => 'test'];

    expect($config->string('mode'))->toBe('prod')
        ->and($config->get(new ConfigPath('settings.mode')))->toBe('prod')
        ->and($config->string('label'))->toBe($label);
});

it('isolates changes to application arrays returned to a caller', function (string $accessor): void {
    $mode = 'prod';
    $config = new Config(['settings' => ['mode' => &$mode]], new Environment([]));

    $copy = match ($accessor) {
        'property' => $config->data,
        'properties' => get_object_vars($config)['data'],
        'iterator' => iterator_to_array($config->getIterator()),
        'selection' => $config->only('settings')->toArray(),
        default => $config->toArray(),
    };
    Assert::assertIsArray($copy);
    $settings = &$copy['settings'];
    Assert::assertIsArray($settings);
    $settings['mode'] = 'dev';

    expect($config->get(new ConfigPath('settings.mode')))->toBe('prod')
        ->and($mode)->toBe('prod');
})->with(['property', 'properties', 'iterator', 'selection', 'toArray']);

it('retains the cycle diagnostics after changes to its input and exported arrays', function (): void {
    $recursive = ['mode' => 'prod'];
    $recursive['self'] = &$recursive;
    $config = new Config(['settings' => $recursive], new Environment([]));

    $copy = $config->data;
    $settings = &$copy['settings'];
    Assert::assertIsArray($settings);
    $self = &$settings['self'];
    Assert::assertIsArray($self);
    $self['mode'] = 'dev';
    $recursive = ['mode' => 'test'];

    expect($config->get(new ConfigPath('settings.self.mode')))->toBe('prod')
        ->and(fn() => $config->get('settings'))->toThrow(
            ConfigResolutionException::class,
            'settings.self.self',
        );
});

it('retains the resolution path of a cycle between several arrays', function (string $view): void {
    $nodes = [[], []];
    $nodes[0] = ['mode' => 'dev', 'next' => &$nodes[1]];
    $nodes[1] = ['mode' => 'prod', 'next' => &$nodes[0]];
    $config = new Config(['settings' => &$nodes[0], 'unused' => true], new Environment([]));
    $config = match ($view) {
        'only' => $config->only('settings'),
        'except' => $config->except('unused'),
        default => $config,
    };

    $exported = $config->toArray()['settings'];
    Assert::assertIsArray($exported);
    $next = &$exported['next'];
    Assert::assertIsArray($next);
    $next['mode'] = 'local';
    $nodes[1]['mode'] = 'outside';

    expect($config->get(new ConfigPath('settings.next.mode')))->toBe('prod');

    try {
        $config->get('settings');
        Assert::fail('A cyclic configuration must fail.');
    } catch (ConfigResolutionException $exception) {
        expect($exception->path)->toBe(['settings', 'next', 'next', 'next']);
    }
})->with(['original', 'only', 'except']);

it('captures a cycle whose references have no surviving external aliases', function (): void {
    $settings = (static function (): array {
        $first = [];
        $second = ['mode' => 'prod', 'next' => &$first];
        $first['next'] = &$second;

        return $first;
    })();
    $config = new Config(['settings' => $settings], new Environment([]));

    // Padding copies the reference cell and lets the caller write to it.
    $input = array_pad($settings, 2, null);
    $next = &$input['next'];
    Assert::assertIsArray($next);
    $next['mode'] = 'outside';

    expect($config->get(new ConfigPath('settings.next.mode')))->toBe('prod')
        ->and(fn() => $config->get('settings'))->toThrow(
            ConfigResolutionException::class,
            'Configuration resolution exceeds maximum depth of 64',
        );
});

it('shares the identity of an object even when its array slot was a reference', function (): void {
    $object = new stdClass();
    $service = $object;
    $config = new Config(['service' => &$service], new Environment([]));

    $service = new stdClass();

    expect($config->get('service'))->toBe($object)
        ->and($config->toArray()['service'])->toBe($object);
});

it('restores application values and the shared environment from a native snapshot', function (): void {
    $mode = 'prod';
    $environment = new Environment(['MODE' => &$mode]);
    $config = new Config(['settings' => ['mode' => &$mode]], $environment);

    $restored = unserialize(serialize([$config, $environment]), [
        'allowed_classes' => [Config::class, Environment::class, stdClass::class],
    ]);

    Assert::assertIsArray($restored);
    [$snapshot, $runtime] = $restored;
    Assert::assertInstanceOf(Config::class, $snapshot);
    Assert::assertInstanceOf(Environment::class, $runtime);
    expect($snapshot->get(new ConfigPath('settings.mode')))->toBe('prod')
        ->and($runtime->string('MODE'))->toBe('prod')
        ->and($snapshot->environment)->toBe($runtime);
});
