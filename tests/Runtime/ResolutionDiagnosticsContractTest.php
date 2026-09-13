<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Runtime;

use Componenta\Config\Config;
use Componenta\Config\ConfigEntry;
use Componenta\Config\ConfigPath;
use Componenta\Config\Environment;
use Componenta\Config\EnvironmentEntry;
use Componenta\Config\Exception\ConfigResolutionException;
use Componenta\Config\Exception\InvalidConfigValueException;
use Componenta\Config\LazyValue;

test('cycle diagnostics contain only the repeating portion of a nested lookup', function (): void {
    $config = new Config([
        'entry' => new ConfigEntry('first'),
        'first' => new ConfigEntry('second'),
        'second' => new ConfigEntry('first'),
    ], new Environment([]));

    try {
        $config->get('entry');
        \PHPUnit\Framework\Assert::fail('A cyclic configuration must fail.');
    } catch (ConfigResolutionException $exception) {
        expect($exception->path)->toBe(['first', 'second', 'first'])
            ->and($exception->getMessage())->toBe('Circular configuration resolution detected: first -> second -> first.');
    }
});

test('descriptor diagnostics identify a repeated environment entry after a lazy prefix', function (): void {
    $entry = new EnvironmentEntry('LOOP');
    $config = new Config([
        'value' => new LazyValue(static fn(): EnvironmentEntry => $entry),
    ], new Environment(['LOOP' => $entry]));

    try {
        $config->get('value');
        \PHPUnit\Framework\Assert::fail('A repeated descriptor must fail.');
    } catch (ConfigResolutionException $exception) {
        expect($exception->path)->toBe(['EnvironmentEntry', 'EnvironmentEntry'])
            ->and($exception->getMessage())->toBe('Circular descriptor result detected: EnvironmentEntry -> EnvironmentEntry.');
    }
});

test('recursive defaults report a string path including numeric array keys', function (): void {
    $recursive = [];
    $recursive['again'] = &$recursive;
    $config = new Config([], new Environment([]));

    try {
        $config->get('missing', [8 => $recursive]);
        \PHPUnit\Framework\Assert::fail('A recursive default must fail.');
    } catch (ConfigResolutionException $exception) {
        expect($exception->path)->toBe(['default', '8', 'again', 'again'])
            ->and($exception->getMessage())->toBe('Circular configuration array detected at default.8.again.again.');
    }
});

test('typed accessors preserve numeric and nested keys in conversion errors', function (
    string $accessor,
    int|ConfigPath $key,
): void {
    $config = new Config([7 => [], 'nested' => ['value' => []]], new Environment([]));

    try {
        $config->$accessor($key);
        \PHPUnit\Framework\Assert::fail('An array cannot be converted to a scalar.');
    } catch (InvalidConfigValueException $exception) {
        expect($exception->key)->toBe($key instanceof ConfigPath ? 'nested.value' : '7')
            ->and($exception->expectedType)->toBe($accessor)
            ->and($exception->actualType)->toBe('array');
    }
})->with(['string', 'int', 'float', 'bool'])->with([7, new ConfigPath('nested.value')]);
