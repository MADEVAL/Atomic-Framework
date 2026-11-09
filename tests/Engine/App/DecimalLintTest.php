<?php
declare(strict_types=1);
namespace Tests\Engine\App;
if (!defined('ATOMIC_START')) exit;
use Engine\Atomic\App\Schema\Definition\FieldConfLinter;
use Engine\Atomic\Enums\Rule;
use PHPUnit\Framework\TestCase;
final class DecimalLintTest extends TestCase
{
    public function test_decimal_is_known_and_accepts_numeric_rules(): void
    {
        $this->assertSame([], FieldConfLinter::lint([
            'price' => ['type' => 'DECIMAL'],
            'bounded' => ['type' => 'DECIMAL', 'rules' => [Rule::NUM_MIN, Rule::NUM_MAX], 'min' => 0, 'max' => 100],
        ]));
    }
}
