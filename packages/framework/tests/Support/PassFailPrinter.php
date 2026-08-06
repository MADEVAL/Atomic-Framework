<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber;
use PHPUnit\Event\Test\MarkedIncomplete;
use PHPUnit\Event\Test\MarkedIncompleteSubscriber;
use PHPUnit\Event\Test\Passed;
use PHPUnit\Event\Test\PassedSubscriber;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionStarted;
use PHPUnit\Event\TestRunner\ExecutionStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

final class PassFailPrinter implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $state = new PassFailState();

        $facade->replaceOutput();
        $facade->replaceProgressOutput();
        $facade->replaceResultOutput();

        $facade->registerSubscribers(
            new class ($state) implements ExecutionStartedSubscriber {
                public function __construct(private PassFailState $s) {}
                public function notify(ExecutionStarted $event): void { $this->s->executionStarted($event); }
            },
            new class ($state) implements PassedSubscriber {
                public function __construct(private PassFailState $s) {}
                public function notify(Passed $event): void { $this->s->testPassed($event); }
            },
            new class ($state) implements FailedSubscriber {
                public function __construct(private PassFailState $s) {}
                public function notify(Failed $event): void { $this->s->testFailed($event); }
            },
            new class ($state) implements ErroredSubscriber {
                public function __construct(private PassFailState $s) {}
                public function notify(Errored $event): void { $this->s->testErrored($event); }
            },
            new class ($state) implements SkippedSubscriber {
                public function __construct(private PassFailState $s) {}
                public function notify(Skipped $event): void { $this->s->testSkipped($event); }
            },
            new class ($state) implements MarkedIncompleteSubscriber {
                public function __construct(private PassFailState $s) {}
                public function notify(MarkedIncomplete $event): void { $this->s->testIncomplete($event); }
            },
            new class ($state) implements ExecutionFinishedSubscriber {
                public function __construct(private PassFailState $s) {}
                public function notify(ExecutionFinished $event): void { $this->s->executionFinished(); }
            },
        );
    }
}

final class PassFailState
{
    private string $currentClass = '';
    private int $passed = 0;
    private int $failed = 0;
    private int $errors = 0;
    private int $skipped = 0;
    private int $incomplete = 0;
    /** @var list<array{test: string, message: string}> */
    private array $failureDetails = [];
    /** @var list<array{test: string, message: string}> */
    private array $errorDetails = [];

    // ANSI color codes
    private const GREEN  = "\033[32m";
    private const RED    = "\033[31m";
    private const YELLOW = "\033[33m";
    private const CYAN   = "\033[36m";
    private const WHITE  = "\033[37m";
    private const BOLD   = "\033[1m";
    private const RESET  = "\033[0m";

    public function executionStarted(ExecutionStarted $event): void
    {
        echo PHP_EOL;
        echo self::BOLD . self::WHITE . "  Atomic Framework -- Test Suite" . self::RESET . PHP_EOL;
        echo "  " . str_repeat('-', 56) . PHP_EOL . PHP_EOL;
    }

    public function testPassed(Passed $event): void
    {
        $this->passed++;
        $this->printSuiteHeader($event->test());
        $name = $this->testName($event->test());
        echo "  " . self::GREEN . "[PASS]" . self::RESET . " " . $name . PHP_EOL;
    }

    public function testFailed(Failed $event): void
    {
        $this->failed++;
        $this->printSuiteHeader($event->test());
        $name = $this->testName($event->test());
        $msg = $event->throwable()->message();
        echo "  " . self::RED . "[FAIL]" . self::RESET . " " . $name . PHP_EOL;
        echo "         " . self::RED . $this->firstLine($msg) . self::RESET . PHP_EOL;
        $this->failureDetails[] = ['test' => $this->fullTestId($event->test()), 'message' => $msg];
    }

    public function testErrored(Errored $event): void
    {
        $this->errors++;
        $this->printSuiteHeader($event->test());
        $name = $this->testName($event->test());
        $msg = $event->throwable()->message();
        echo "  " . self::RED . "[ERROR]" . self::RESET . " " . $name . PHP_EOL;
        echo "          " . self::RED . $this->firstLine($msg) . self::RESET . PHP_EOL;
        $this->errorDetails[] = ['test' => $this->fullTestId($event->test()), 'message' => $msg];
    }

    public function testSkipped(Skipped $event): void
    {
        $this->skipped++;
        $this->printSuiteHeader($event->test());
        $name = $this->testName($event->test());
        echo "  " . self::YELLOW . "[SKIP]" . self::RESET . " " . $name . PHP_EOL;
    }

    public function testIncomplete(MarkedIncomplete $event): void
    {
        $this->incomplete++;
        $this->printSuiteHeader($event->test());
        $name = $this->testName($event->test());
        echo "  " . self::CYAN . "[TODO]" . self::RESET . " " . $name . PHP_EOL;
    }

    public function executionFinished(): void
    {
        $total = $this->passed + $this->failed + $this->errors + $this->skipped + $this->incomplete;

        echo PHP_EOL . "  " . str_repeat('=', 56) . PHP_EOL;

        if (!empty($this->failureDetails)) {
            echo PHP_EOL . self::BOLD . self::RED . "  FAILURES:" . self::RESET . PHP_EOL;
            foreach ($this->failureDetails as $i => $d) {
                echo "  " . ($i + 1) . ") " . $d['test'] . PHP_EOL;
                echo "     " . self::RED . $this->firstLine($d['message']) . self::RESET . PHP_EOL;
            }
        }

        if (!empty($this->errorDetails)) {
            echo PHP_EOL . self::BOLD . self::RED . "  ERRORS:" . self::RESET . PHP_EOL;
            foreach ($this->errorDetails as $i => $d) {
                echo "  " . ($i + 1) . ") " . $d['test'] . PHP_EOL;
                echo "     " . self::RED . $this->firstLine($d['message']) . self::RESET . PHP_EOL;
            }
        }

        echo PHP_EOL;
        echo "  " . self::GREEN . "PASS: {$this->passed}" . self::RESET;
        echo "  " . self::RED . "FAIL: {$this->failed}" . self::RESET;
        echo "  " . self::RED . "ERROR: {$this->errors}" . self::RESET;
        echo "  " . self::YELLOW . "SKIP: {$this->skipped}" . self::RESET;
        if ($this->incomplete > 0) {
            echo "  " . self::CYAN . "TODO: {$this->incomplete}" . self::RESET;
        }
        echo "  TOTAL: {$total}" . PHP_EOL;

        if ($this->failed === 0 && $this->errors === 0) {
            echo PHP_EOL . self::BOLD . self::GREEN . "  ALL TESTS PASSED!" . self::RESET . PHP_EOL;
        }

        echo PHP_EOL;
    }

    private function printSuiteHeader(mixed $test): void
    {
        if (!$test instanceof TestMethod) {
            return;
        }
        $className = $test->testDox()->prettifiedClassName();
        if ($className !== $this->currentClass) {
            $this->currentClass = $className;
            echo PHP_EOL . self::BOLD . self::WHITE . "  " . $className . self::RESET . PHP_EOL;
        }
    }

    private function testName(mixed $test): string
    {
        if ($test instanceof TestMethod) {
            return $test->testDox()->prettifiedMethodName();
        }
        return $test->id();
    }

    private function fullTestId(mixed $test): string
    {
        if ($test instanceof TestMethod) {
            return $test->className() . '::' . $test->methodName();
        }
        return $test->id();
    }

    private function firstLine(string $text): string
    {
        $pos = strpos($text, "\n");
        return $pos !== false ? substr($text, 0, $pos) : $text;
    }
}
