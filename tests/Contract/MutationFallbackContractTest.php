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

test('uncovered mutations receive the outcome of a real fallback process', function (
    int $replacementValue,
    MutationTestResult $expected,
): void {
    $mutationActive = getenv('PEST_MUTATION_TESTING') !== false;
    if ($mutationActive) {
        StreamWrapper::disable();
    }
    $source = tempnam(sys_get_temp_dir(), 'mutation-original-');
    $replacement = tempnam(sys_get_temp_dir(), 'mutation-replacement-');
    if ($source === false || $replacement === false) {
        throw new \RuntimeException('Cannot create mutation fixtures.');
    }

    try {
        file_put_contents($source, '<?php return 42;');
        file_put_contents($replacement, '<?php return ' . $replacementValue . ';');
        $test = new MutationTest(new Mutation(
            new SplFileInfo($source, '', ''),
            'fallback-contract',
            'fixture',
            1,
            1,
            '',
            $replacement,
        ));
        $test->updateResult(MutationTestResult::Uncovered);
        $script = '$original = getenv("PEST_MUTATION_TESTING");'
            . 'if ($original !== $argv[1]) { throw new RuntimeException("Wrong original file"); }'
            . 'exit((require getenv("PEST_MUTATION_FILE")) === 42 ? 0 : 1);';
        $fallback = new MutationFallback([PHP_BINARY, '-r', $script, $source], __DIR__);

        $fallback->notify(new Uncovered($test));

        expect($test->result())->toBe($expected);
    } finally {
        unlink($source);
        unlink($replacement);
        if ($mutationActive) {
            StreamWrapper::enable();
        }
    }
})->with([
    'behavior changed' => [41, MutationTestResult::Tested],
    'behavior preserved' => [42, MutationTestResult::Untested],
]);

test('a failing unmodified worker prevents mutation scores from being accepted', function (): void {
    $active = getenv('PEST_MUTATION_TESTING') !== false;
    if ($active) {
        StreamWrapper::disable();
    }
    try {
        $fallback = new MutationFallback(
            [PHP_BINARY, '-r', 'fwrite(STDERR, "CONTROL_FAILURE"); exit(1);'],
            __DIR__,
        );

        expect(fn() => $fallback->verifyControl())->toThrow(\RuntimeException::class, 'CONTROL_FAILURE');
    } finally {
        if ($active) {
            StreamWrapper::enable();
        }
    }
});
