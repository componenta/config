<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Contract;

use Componenta\Config\ConfigKey;
use Componenta\Config\Tests\Support\PackageConfigKeyFixture;

it('allows package config key classes to inherit the shared schema and add package keys', function (): void {
    expect(PackageConfigKeyFixture::DEPENDENCIES)->toBe(ConfigKey::DEPENDENCIES)
        ->and(PackageConfigKeyFixture::FACTORIES)->toBe(ConfigKey::FACTORIES)
        ->and(PackageConfigKeyFixture::dependencyKeys())->toBe(ConfigKey::dependencyKeys())
        ->and(PackageConfigKeyFixture::FEATURE_FLAG)->toBe('package.feature_flag');
});
