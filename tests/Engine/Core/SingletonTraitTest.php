<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\Traits\Singleton;
use PHPUnit\Framework\TestCase;

class SingletonTraitTest extends TestCase
{
    protected function tearDown(): void
    {
        SingletonStub::reset();
        SingletonArgsStub::reset();
        SingletonChildStub::reset();
        SingletonParentStub::reset();
    }

    public function test_instance_returns_same_object(): void
    {
        $a = SingletonStub::instance();
        $b = SingletonStub::instance();

        $this->assertSame($a, $b);
    }

    public function test_instance_returns_new_object_after_reset(): void
    {
        $a = SingletonStub::instance();
        SingletonStub::reset();
        $b = SingletonStub::instance();

        $this->assertNotSame($a, $b);
    }

    public function test_instance_passes_constructor_arguments(): void
    {
        $obj = SingletonArgsStub::instance('hello', 42);

        $this->assertSame('hello', $obj->arg1);
        $this->assertSame(42, $obj->arg2);
    }

    public function test_instance_returns_first_instance_ignoring_later_args(): void
    {
        $a = SingletonArgsStub::instance('first', 1);
        $b = SingletonArgsStub::instance('second', 2);

        $this->assertSame($a, $b);
        $this->assertSame('first', $b->arg1);
    }

    public function test_different_classes_have_independent_instances(): void
    {
        $a = SingletonStub::instance();
        $b = SingletonParentStub::instance();

        $this->assertNotSame($a, $b);
    }

    public function test_child_and_parent_have_separate_instances(): void
    {
        SingletonParentStub::reset();
        SingletonChildStub::reset();

        $parent = SingletonParentStub::instance();
        $child = SingletonChildStub::instance();

        $this->assertNotSame($parent, $child);
    }

    public function test_clone_is_disabled(): void
    {
        $this->expectException(\Error::class);

        $obj = SingletonStub::instance();
        $cloned = clone $obj;
        unset($cloned);
    }

    public function test_reset_called_multiple_times_is_safe(): void
    {
        SingletonStub::reset();
        SingletonStub::reset();
        $obj = SingletonStub::instance();

        $this->assertNotNull($obj);
    }
}

final class SingletonStub
{
    use Singleton;

    private function __construct() {}
}

final class SingletonArgsStub
{
    use Singleton;

    public function __construct(
        public readonly string $arg1,
        public readonly int $arg2,
    ) {}
}

class SingletonParentStub
{
    use Singleton;

    protected function __construct() {}
}

final class SingletonChildStub extends SingletonParentStub
{
    use Singleton;
}
