<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Support;

use Pest\Mutate\Event\Events\TestSuite\StartMutationSuite;
use Pest\Mutate\Event\Events\TestSuite\StartMutationSuiteSubscriber;

/** Runs unlinked mutations with the full suite before Pest consumes their outcomes. */
final readonly class MutationFallbackPreparation implements StartMutationSuiteSubscriber
{
    public function __construct(
        private MutationFallback $fallback,
        private MutationCoverage $coverage,
        private int $processes,
    ) {}

    public function notify(StartMutationSuite $event): void
    {
        $uncovered = [];
        foreach ($event->mutationSuite->repository->all() as $collection) {
            foreach ($collection->tests() as $test) {
                if (!$this->coverage->covers($test->mutation)) {
                    $uncovered[] = $test;
                }
            }
        }
        $this->fallback->prime($uncovered, $this->processes);
    }
}
