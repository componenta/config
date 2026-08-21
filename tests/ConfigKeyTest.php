<?php

declare(strict_types=1);

use Componenta\Config\ConfigKey;

final class PackageConfigKeyFixture extends ConfigKey
{
    public const string FEATURE_FLAG = 'package.feature_flag';
}

it('allows package config key classes to inherit the shared schema and add package keys', function (): void {
    expect(PackageConfigKeyFixture::DEPENDENCIES)->toBe(ConfigKey::DEPENDENCIES)
        ->and(PackageConfigKeyFixture::FACTORIES)->toBe(ConfigKey::FACTORIES)
        ->and(PackageConfigKeyFixture::dependencyKeys())->toBe(ConfigKey::dependencyKeys())
        ->and(PackageConfigKeyFixture::FEATURE_FLAG)->toBe('package.feature_flag');
});
