<?php
declare(strict_types=1);

namespace Engine\Atomic\CLI\Console;

if (!defined('ATOMIC_START')) exit;

final class CommandSuggester
{
    public function __construct(private readonly int $limit = 3)
    {
    }

    /**
     * @param iterable<string> $candidates
     * @return list<string>
     */
    public function suggest(string $input, iterable $candidates): array
    {
        $input = $this->normalize($input);
        if ($input === '' || $this->limit < 1) {
            return [];
        }

        /** @var array<string, array{score: float, same_group: bool, kind: string}> $ranked */
        $ranked = [];
        foreach ($candidates as $candidate) {
            $candidate = $this->normalize($candidate);
            if ($candidate === '' || $candidate === $input || isset($ranked[$candidate])) {
                continue;
            }

            $match = $this->score($input, $candidate);
            if ($match !== null) {
                $ranked[$candidate] = $match;
            }
        }

        $has_strong_same_group_match = false;
        foreach ($ranked as $match) {
            if ($match['same_group'] && $match['score'] >= 80) {
                $has_strong_same_group_match = true;
                break;
            }
        }
        if ($has_strong_same_group_match) {
            $ranked = array_filter(
                $ranked,
                static fn(array $match): bool => !in_array(
                    $match['kind'],
                    ['cross_group_fuzzy', 'full_fuzzy'],
                    true
                )
            );
        }

        uksort($ranked, static function (string $left, string $right) use ($ranked): int {
            $score_order = $ranked[$right]['score'] <=> $ranked[$left]['score'];
            return $score_order !== 0 ? $score_order : strcmp($left, $right);
        });

        return array_slice(array_keys($ranked), 0, $this->limit);
    }

    /** @return array{score: float, same_group: bool, kind: string}|null */
    private function score(string $input, string $candidate): ?array
    {
        $input_parts = explode('/', $input);
        $candidate_parts = explode('/', $candidate);
        $input_name = end($input_parts);
        $candidate_name = end($candidate_parts);
        $input_group = implode('/', array_slice($input_parts, 0, -1));
        $candidate_group = implode('/', array_slice($candidate_parts, 0, -1));
        $same_group = $input_group !== ''
            && $candidate_group !== ''
            && $input_group === $candidate_group;

        if (strlen($input) >= 3 && str_starts_with($candidate, $input)) {
            return [
                'score' => 100 - $this->length_penalty($input, $candidate),
                'same_group' => $same_group,
                'kind' => 'full_prefix',
            ];
        }

        if (strlen($input_name) >= 2 && str_starts_with($candidate_name, $input_name)) {
            $base = $same_group ? 95 : 75;
            return [
                'score' => $base - $this->length_penalty($input_name, $candidate_name),
                'same_group' => $same_group,
                'kind' => 'name_prefix',
            ];
        }

        $name_similarity = $this->similarity($input_name, $candidate_name);
        if ($same_group && $name_similarity >= 0.6) {
            return [
                'score' => 80 + $name_similarity,
                'same_group' => true,
                'kind' => 'same_group_fuzzy',
            ];
        }

        if (strlen($input_name) >= 3 && $name_similarity >= 0.72) {
            return [
                'score' => 65 + $name_similarity,
                'same_group' => $same_group,
                'kind' => 'cross_group_fuzzy',
            ];
        }

        $similarity = $this->similarity($input, $candidate);
        if ($similarity >= 0.68) {
            return [
                'score' => 50 + $similarity,
                'same_group' => $same_group,
                'kind' => 'full_fuzzy',
            ];
        }

        return null;
    }

    private function similarity(string $left, string $right): float
    {
        $length = max(strlen($left), strlen($right));
        return $length === 0 ? 1.0 : 1 - (levenshtein($left, $right) / $length);
    }

    private function length_penalty(string $input, string $candidate): float
    {
        return (strlen($candidate) - strlen($input)) / max(1, strlen($candidate));
    }

    private function normalize(string $command): string
    {
        return strtolower(trim($command, " \t\n\r\0\x0B/"));
    }
}
