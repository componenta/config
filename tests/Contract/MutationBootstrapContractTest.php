<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Contract;

use Pest\Mutate\Support\StreamWrapper;
use RuntimeException;
use Symfony\Component\Process\Process;

test('mutation bootstrap applies function substitutions before Composer can load them', function (): void {
    $mutationActive = getenv('PEST_MUTATION_TESTING') !== false;
    if ($mutationActive) {
        // Native file locks are needed while Symfony Process manages its child pipes on Windows.
        StreamWrapper::disable();
    }
    try {
        $packageDirectory = dirname(__DIR__, 2);
        $functions = realpath($packageDirectory . '/src/functions.php');
        if ($functions === false) {
            throw new RuntimeException('Cannot locate Config functions.');
        }
        $replacement = tempnam(sys_get_temp_dir(), 'config-mutation-');
        if ($replacement === false) {
            throw new RuntimeException('Cannot create mutation bootstrap fixture.');
        }

        try {
            file_put_contents($replacement, "<?php throw new RuntimeException('CONFIG_MUTATION_BOOTSTRAP_SENTINEL');\n");
            $command = [
                PHP_BINARY,
                '-d',
                'opcache.enable_cli=0',
                '-d',
                'xdebug.mode=off',
                $packageDirectory . '/tests/mutate.php',
                'tests/Contract/FunctionsTest.php',
                '--filter=creates helper value objects including runtime environment references',
                '--colors=never',
                '--compact',
                '--do-not-cache-result',
            ];
            $original = new Process($command, $packageDirectory, [
                'PEST_MUTATION_TESTING' => $functions,
                'PEST_MUTATION_FILE' => $functions,
            ]);
            $original->run();
            expect($original->getExitCode())->toBe(0)
                ->and($original->getOutput())->toContain('1 passed');

            $mutated = new Process($command, $packageDirectory, [
                'PEST_MUTATION_TESTING' => $functions,
                'PEST_MUTATION_FILE' => $replacement,
            ]);
            $mutated->run();
            expect($mutated->getExitCode())->toBeGreaterThan(0, $mutated->getOutput() . $mutated->getErrorOutput())
                ->and($mutated->getOutput() . $mutated->getErrorOutput())->toContain('CONFIG_MUTATION_BOOTSTRAP_SENTINEL');
        } finally {
            unlink($replacement);
        }
    } finally {
        if ($mutationActive) {
            StreamWrapper::enable();
        }
    }
});
