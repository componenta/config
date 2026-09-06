<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

$packageDirectory = dirname(__DIR__);
$packagesDirectory = dirname($packageDirectory);
$workspaceDirectory = dirname($packagesDirectory);
$localAutoload = $packageDirectory . '/vendor/autoload.php';
$toolchainAutoload = $packagesDirectory . '/var-export/vendor/autoload.php';
$usingLocalAutoload = is_file($localAutoload);

$autoload = $usingLocalAutoload ? $localAutoload : $toolchainAutoload;

if (!is_file($autoload)) {
    throw new RuntimeException('Install Config development dependencies before running its test suite.');
}

/** @var ClassLoader $loader */
$loader = require $autoload;

$loader->setPsr4('Componenta\\Config\\', $packageDirectory . '/src');
$loader->setPsr4('Componenta\\Config\\Tests\\', __DIR__);

if (!$usingLocalAutoload) {
    $loader->setPsr4(
        'Componenta\\Arrayable\\',
        $workspaceDirectory . '/vendor/componenta/arrayable/src',
    );
    $loader->setPsr4(
        'Psr\\Container\\',
        $workspaceDirectory . '/vendor/psr/container/src',
    );

    require $packageDirectory . '/src/functions.php';
}
