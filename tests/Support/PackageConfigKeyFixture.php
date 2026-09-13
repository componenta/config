<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Support;

use Componenta\Config\ConfigKey;

final class PackageConfigKeyFixture extends ConfigKey
{
    public const string FEATURE_FLAG = 'package.feature_flag';
}
