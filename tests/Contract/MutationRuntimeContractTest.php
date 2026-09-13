<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Contract;

use Pest\Mutate\Support\StreamWrapper;
use Symfony\Component\Process\Process;

test('mutation workers inherit the selected PHP configuration', function (): void {
    $active = getenv('PEST_MUTATION_TESTING') !== false;
    if ($active) {
        StreamWrapper::disable();
    }
    $ini = tempnam(sys_get_temp_dir(), 'mutation-ini-');
    if ($ini === false) {
        throw new \RuntimeException('Cannot create PHP configuration fixture.');
    }
    try {
        file_put_contents($ini, "precision=9\nopcache.enable_cli=0\n");
        $root = dirname(__DIR__, 2);
        $vendor = is_file($root . '/vendor/autoload.php') ? $root . '/vendor' : dirname($root) . '/var-export/vendor';
        $script = 'require ' . var_export($vendor . '/autoload.php', true) . ';'
            . 'require ' . var_export($root . '/tests/Support/MutationRuntime.php', true) . ';'
            . '$command = Componenta\\Config\\Tests\\Support\\MutationRuntime::command();'
            . '$process = new Symfony\\Component\\Process\\Process([...$command, "-r", \'echo ini_get("precision");\']);'
            . '$process->run(); echo $process->getOutput(); exit($process->getExitCode());';
        $process = new Process([PHP_BINARY, '-c', $ini, '-r', $script], $root, [
            'PEST_MUTATION_TESTING' => false,
            'PEST_MUTATION_FILE' => false,
            'XDEBUG_MODE' => 'off',
        ]);
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(trim($process->getOutput()))->toBe('9');
    } finally {
        unlink($ini);
        if ($active) {
            StreamWrapper::enable();
        }
    }
});
