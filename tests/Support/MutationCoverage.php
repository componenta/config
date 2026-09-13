<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Support;

use Pest\Mutate\Event\Events\TestSuite\StartMutationGeneration;
use Pest\Mutate\Event\Events\TestSuite\StartMutationGenerationSubscriber;
use Pest\Mutate\Mutation;
use Pest\Support\Coverage;
use RuntimeException;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Report\PHP;

final class MutationCoverage implements StartMutationGenerationSubscriber
{
    /** @var array<string,array<int,list<string>|null>> */
    private array $coveredLines = [];
    public function __construct(private readonly MutationFallback $fallback) {}

    public function notify(StartMutationGeneration $event): void
    {
        $this->fallback->verifyControl();
        $path = Coverage::getPath();
        if ($path === '') {
            throw new RuntimeException('Pest did not provide a mutation coverage path.');
        }
        $coverage = require $path;
        if (!$coverage instanceof CodeCoverage) {
            throw new RuntimeException('Pest did not produce a valid mutation coverage report.');
        }

        $data = $coverage->getData();
        $lines = $data->lineCoverage();
        foreach ($lines as &$file) {
            foreach ($file as &$tests) {
                if ($tests === null) {
                    continue;
                }
                // Whole classes retain every covering test without exceeding Windows process limits.
                $tests = array_values(array_unique(array_map(static function (string $test): string {
                    $separator = strpos($test, '::');
                    return $separator === false ? $test : substr($test, 0, $separator + 2);
                }, $tests)));
            }
            unset($tests);
        }
        unset($file);
        $this->coveredLines = $lines;
        $data->setLineCoverage($lines);
        (new PHP())->process($coverage, $path);

        // Coverage is complete; subsequent PHP processes only need to execute assertions.
        putenv('XDEBUG_MODE=off');
        $_SERVER['XDEBUG_MODE'] = 'off';
        $_ENV['XDEBUG_MODE'] = 'off';
    }

    public function covers(Mutation $mutation): bool
    {
        foreach (range($mutation->startLine, $mutation->endLine) as $line) {
            if (($this->coveredLines[$mutation->file->getRealPath()][$line] ?? []) !== []) {
                return true;
            }
        }
        return false;
    }
}
