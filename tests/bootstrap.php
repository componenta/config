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
}

require_once $packageDirectory . '/src/functions.php';

if (defined('COMPONENTA_CONFIG_MUTATION_ARGUMENTS')) {
    $pestContainer = \Pest\Support\Container::getInstance();
    /** @var \Pest\Mutate\Tester\MutationTestRunner $mutationRunner */
    $mutationRunner = $pestContainer->get(\Pest\Mutate\Contracts\MutationTestRunner::class);
    if ($mutationRunner->isEnabled()) {
        /** @var \Pest\Mutate\Repositories\ConfigurationRepository $mutationConfiguration */
        $mutationConfiguration = $pestContainer->get(\Pest\Mutate\Repositories\ConfigurationRepository::class);
        /** @var list<string> $arguments */
        $arguments = COMPONENTA_CONFIG_MUTATION_ARGUMENTS;
        $arguments = $mutationConfiguration->cliConfiguration->fromArguments($arguments);
        array_shift($arguments);
        $php = \Componenta\Config\Tests\Support\MutationRuntime::command();
        $mutationRunner->setOriginalArguments([...$php, __DIR__ . '/mutate.php', ...$arguments]);
        $fallback = new \Componenta\Config\Tests\Support\MutationFallback([
                ...$php,
                '-d', 'xdebug.mode=off',
                __DIR__ . '/mutate.php',
                __DIR__,
                '--configuration=' . $packageDirectory . '/phpunit.xml',
                '--compact',
                '--colors=never',
                '--bail',
                '--do-not-cache-result',
            ], $packageDirectory, reportDirectory: $packageDirectory . '/.phpunit.cache/mutation-workers');
        \Pest\Mutate\Event\Facade::instance()->registerSubscriber($fallback);
        $coverage = new \Componenta\Config\Tests\Support\MutationCoverage($fallback);
        \Pest\Mutate\Event\Facade::instance()->registerSubscriber($coverage);
        $configuration = $mutationConfiguration->mergedConfiguration();
        \Pest\Mutate\Event\Facade::instance()->registerSubscriber(
            new \Componenta\Config\Tests\Support\MutationFallbackPreparation(
                $fallback,
                $coverage,
                $configuration->parallel ? $configuration->processes : 1,
            ),
        );
    }
}
