<?php

declare(strict_types=1);

use Pest\Kernel;
use Pest\Mutate\Support\StreamWrapper;
use Pest\Panic;
use Pest\TestSuite;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

if (filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOL)) {
    throw new RuntimeException('Mutation testing requires opcache.enable_cli=0; use composer test-mutation.');
}

$packageDirectory = dirname(__DIR__);
$vendorDirectory = is_file($packageDirectory . '/vendor/autoload.php')
    ? $packageDirectory . '/vendor'
    : dirname($packageDirectory) . '/var-export/vendor';

if (!is_file($vendorDirectory . '/autoload.php')) {
    throw new RuntimeException('Install Config development dependencies before running mutation tests.');
}

// Composer must leave this file to PHPUnit bootstrap, after Pest enables mutation substitution.
$functions = realpath($packageDirectory . '/src/functions.php');
/** @var array<string, string> $autoloadFiles */
$autoloadFiles = require $vendorDirectory . '/composer/autoload_files.php';
$loadedFiles = $GLOBALS['__composer_autoload_files'] ?? [];
if (!is_array($loadedFiles)) {
    throw new RuntimeException('Unexpected Composer autoload state.');
}
foreach ($autoloadFiles as $identifier => $file) {
    if (realpath($file) === $functions) {
        $loadedFiles[$identifier] = true;
    }
}

$GLOBALS['__composer_autoload_files'] = $loadedFiles;
$_SERVER['COLLISION_PRINTER'] = 'DefaultPrinter';
require $vendorDirectory . '/autoload.php';
$GLOBALS['_composer_bin_dir'] = $vendorDirectory . '/bin';

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'];
foreach ($arguments as $index => $argument) {
    if ($argument === '--processes' && isset($arguments[$index + 1]) && !str_starts_with($arguments[$index + 1], '-')) {
        $arguments[$index] .= '=' . $arguments[$index + 1];
        unset($arguments[$index + 1]);
    }
}
$_SERVER['argv'] = array_values($arguments);

// Mutation children receive a literal quoted filter and may inherit --processes from Pest 4.
if (getenv('PEST_MUTATION_TESTING') !== false) {
    /** @var list<string> $arguments */
    $arguments = $_SERVER['argv'];
    $arguments = array_values(array_filter(
        $arguments,
        static fn(string $argument): bool => $argument !== '--processes' && !str_starts_with($argument, '--processes='),
    ));
    foreach ($arguments as &$argument) {
        if (str_starts_with($argument, '--filter="') && str_ends_with($argument, '"')) {
            $argument = '--filter=' . substr($argument, 10, -1);
        }
    }
    unset($argument);
    $_SERVER['argv'] = $arguments;

    // Restore normal file locking before Pest saves its result cache on Windows.
    register_shutdown_function(static function (): void {
        if (class_exists(StreamWrapper::class, false)) {
            StreamWrapper::disable();
        }
    });
}

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'];
define('COMPONENTA_CONFIG_MUTATION_ARGUMENTS', $arguments);

// Only mutation processes run in parallel; the initial coverage suite uses this bootstrap.
$_SERVER['argv'] = array_values(array_filter(
    $arguments,
    static fn(string $argument): bool => $argument !== '--parallel' && $argument !== '--processes' && !str_starts_with($argument, '--processes='),
));
if (in_array('--compact', $arguments, true)) {
    $_SERVER['COLLISION_PRINTER_COMPACT'] = 'true';
    $arguments = array_values(array_filter($arguments, static fn(string $argument): bool => $argument !== '--compact'));
}

$input = new ArgvInput($arguments);
$output = new ConsoleOutput(ConsoleOutput::VERBOSITY_NORMAL, $input->getParameterOption('--colors', 'always') !== 'never');

try {
    $kernel = Kernel::boot(TestSuite::getInstance($packageDirectory, 'tests'), $input, $output);
    require_once __DIR__ . '/Support/MutationCoverage.php';
    if (in_array('--mutate', $arguments, true)) {
        /** @var \Pest\Mutate\Repositories\ConfigurationRepository $configuration */
        $configuration = \Pest\Support\Container::getInstance()->get(\Pest\Mutate\Repositories\ConfigurationRepository::class);
        $configuration->cliConfiguration->fromArguments($arguments);
        $arguments = array_values(array_filter(
            $arguments,
            static fn(string $argument): bool => $argument !== '--processes' && !str_starts_with($argument, '--processes='),
        ));
    }
    exit($kernel->handle($arguments, $arguments));
} catch (Throwable $error) {
    Panic::with($error);
}
