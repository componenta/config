<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Contract;

use Componenta\Config\Tests\Support\MutationFallback;
use Pest\Mutate\Event\Events\Test\Outcome\Uncovered;
use Pest\Mutate\Mutation;
use Pest\Mutate\MutationTest;
use Pest\Mutate\Support\MutationTestResult;
use Pest\Mutate\Support\StreamWrapper;
use Symfony\Component\Finder\SplFileInfo;

test('fallback batches execute real workers concurrently and reuse each recorded outcome once', function (): void {
    $active = getenv('PEST_MUTATION_TESTING') !== false;
    if ($active) {
        StreamWrapper::disable();
    }
    $directory = sys_get_temp_dir() . '/config-mutation-batch-' . bin2hex(random_bytes(8));
    mkdir($directory);
    try {
        $original = $directory . '/original.php';
        file_put_contents($original, '<?php return 42;');
        $tests = [];
        foreach ([42, 41] as $value) {
            $replacement = $directory . '/replacement-' . $value . '.php';
            file_put_contents($replacement, '<?php return ' . $value . ';');
            $tests[] = new MutationTest(new Mutation(new SplFileInfo($original, '', ''), 'batch-' . $value, 'fixture', 1, 1, '', $replacement));
        }
        $script = '$directory=$argv[1]; $file=getenv("PEST_MUTATION_FILE");'
            . '$mark=$directory."/".basename($file).".started";file_put_contents($mark,"x",FILE_APPEND);'
            . '$deadline=microtime(true)+5;while(count(glob($directory."/*.started"))<2){'
            . 'if(microtime(true)>$deadline){fwrite(STDERR,"Workers did not overlap");exit(79);}usleep(10000);}'
            . 'exit((require $file)===42?0:1);';
        $fallback = new MutationFallback([PHP_BINARY, '-r', $script, $directory], __DIR__);
        $fallback->prime($tests, 2);
        foreach ($tests as $test) {
            $fallback->notify(new Uncovered($test));
        }

        expect($tests[0]->result())->toBe(MutationTestResult::Untested)
            ->and($tests[1]->result())->toBe(MutationTestResult::Tested)
            ->and(file_get_contents($directory . '/replacement-42.php.started'))->toBe('x')
            ->and(file_get_contents($directory . '/replacement-41.php.started'))->toBe('x');
    } finally {
        foreach (glob($directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
        if ($active) {
            StreamWrapper::enable();
        }
    }
});
