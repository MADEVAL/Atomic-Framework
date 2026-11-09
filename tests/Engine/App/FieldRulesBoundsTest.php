<?php
declare(strict_types=1);
namespace Tests\Engine\App;
if (!defined('ATOMIC_START')) exit;
use Engine\Atomic\App\Schema\Domain\FieldRules;
use PHPUnit\Framework\TestCase;
final class FieldRulesBoundsTest extends TestCase
{
    public static function conflicting_pairs(): array
    {
        $cases = [];
        foreach (['min', 'max'] as $bound) {
            foreach ([['num', 'str'], ['num', 'mb'], ['str', 'mb']] as [$a, $b]) {
                $cases[] = [$a.'_'.$bound, $b.'_'.$bound];
            }
        }
        return $cases;
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('conflicting_pairs')]
    public function test_conflicting_variants_throw(string $a, string $b): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Field "title" has conflicting '.substr($a, -3).' rules: '.$a.'(2), '.$b.'(5). Keep only one variant.');
        (new FieldRules())->$a(2)->$b(5)->to_policy('title');
    }
    public function test_single_variants_and_repeated_variant_use_last_value(): void
    {
        foreach (['num', 'str', 'mb'] as $variant) {
            foreach (['min', 'max'] as $bound) {
                $method = $variant.'_'.$bound;
                $policy = (new FieldRules())->$method(2)->$method(5)->to_policy();
                $this->assertSame(5, $policy[$bound]);
                $this->assertSame([$method], array_map(fn($r) => $r->value, $policy['rules']));
            }
        }
    }
}
