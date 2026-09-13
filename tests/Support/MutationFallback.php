<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Support;

use Pest\Mutate\Event\Events\Test\Outcome\Uncovered;
use Pest\Mutate\Event\Events\Test\Outcome\UncoveredSubscriber;
use Pest\Mutate\MutationTest;
use Pest\Mutate\Support\MutationTestResult;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class MutationFallback implements UncoveredSubscriber
{
    /** @var array<string,MutationTestResult> */
    private array $primed = [];
    /** @param list<string> $command */
    public function __construct(
        private readonly array $command,
        private readonly string $directory,
        private readonly float $timeout = 120.0,
        private readonly ?string $reportDirectory = null,
    ) {}

    public function verifyControl(): void
    {
        $process = new Process($this->command, $this->directory, [
            'PEST_MUTATION_TESTING' => false,
            'PEST_MUTATION_FILE' => false,
            'XDEBUG_MODE' => 'off',
        ], timeout: $this->timeout);
        $process->run();
        $this->report('control', $process);
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Unmodified mutation worker failed: ' . $process->getOutput() . $process->getErrorOutput());
        }
    }

    /** @param list<MutationTest> $tests */
    public function prime(array $tests, int $processes): void
    {
        foreach (array_chunk($tests, max(1, $processes)) as $batch) {
            $running = [];
            try {
                foreach ($batch as $test) {
                    $process = $this->process($test);
                    $process->start();
                    $running[] = [$test, $process];
                }
                foreach ($running as [$test, $process]) {
                    $this->primed[$test->getId()] = $this->finish($test, $process);
                }
            } finally {
                foreach ($running as [$_test, $process]) {
                    if ($process->isRunning()) {
                        $process->stop();
                    }
                }
            }
        }
    }

    public function notify(Uncovered $event): void
    {
        $cached = $this->primed[$event->test->getId()] ?? null;
        if ($cached !== null) {
            $event->test->updateResult($cached);
            return;
        }
        $process = $this->process($event->test);
        $process->start();
        $event->test->updateResult($this->finish($event->test, $process));
    }

    private function process(MutationTest $test): Process
    {
        return new Process($this->command, $this->directory, [
            'PEST_MUTATION_TESTING' => $test->mutation->file->getRealPath(),
            'PEST_MUTATION_FILE' => $test->mutation->modifiedSourcePath,
            'XDEBUG_MODE' => 'off',
        ], timeout: $this->timeout);
    }

    private function finish(MutationTest $test, Process $process): MutationTestResult
    {
        try {
            $process->wait();
            $result = $process->isSuccessful() ? MutationTestResult::Untested : MutationTestResult::Tested;
        } catch (ProcessTimedOutException) {
            $result = MutationTestResult::Timeout;
        }
        $this->report($test->getId(), $process);
        return $result;
    }

    private function report(string $name, Process $process): void
    {
        if ($this->reportDirectory === null) {
            return;
        }
        if (!is_dir($this->reportDirectory) && !mkdir($this->reportDirectory, recursive: true) && !is_dir($this->reportDirectory)) {
            throw new \RuntimeException('Cannot create mutation worker report directory.');
        }
        $output = 'exit=' . $process->getExitCode() . PHP_EOL . $process->getOutput() . $process->getErrorOutput();
        if (file_put_contents($this->reportDirectory . '/' . $name . '.log', $output) === false) {
            throw new \RuntimeException('Cannot save mutation worker report.');
        }
    }
}
