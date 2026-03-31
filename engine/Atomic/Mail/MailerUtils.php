<?php

declare(strict_types=1);

namespace Engine\Atomic\Mail;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Traits\Singleton;

final class MailerUtils
{
    use Singleton;

    protected App $atomic;

    private function __construct()
    {
        $this->atomic = App::instance();
    }

    public function checkSPF(string $domain): array
    {
        $result = ['exists' => false, 'record' => '', 'valid' => false];

        $records = @dns_get_record($domain, DNS_TXT);
        if (!$records) return $result;

        foreach ($records as $record) {
            if (str_starts_with($record['txt'], 'v=spf1')) {
                $result['exists'] = true;
                $result['record'] = $record['txt'];
                $result['valid'] = str_contains($record['txt'], '-all');
                break;
            }
        }

        return $result;
    }

    public function checkDKIM(string $domain, string $selector): array
    {
        $result = ['exists' => false, 'record' => '', 'valid' => false];

        $lookup = $selector . '._domainkey.' . $domain;
        $records = @dns_get_record($lookup, DNS_TXT);
        if (!$records) return $result;

        foreach ($records as $record) {
            if (str_starts_with($record['txt'], 'v=DKIM1')) {
                $result['exists'] = true;
                $result['record'] = $record['txt'];
                $result['valid'] = str_contains($record['txt'], 'p=');
                break;
            }
        }

        return $result;
    }

    public function checkDMARC(string $domain): array
    {
        $result = ['exists' => false, 'record' => '', 'valid' => false, 'policy' => ''];

        $lookup = '_dmarc.' . $domain;
        $records = @dns_get_record($lookup, DNS_TXT);
        if (!$records) return $result;

        foreach ($records as $record) {
            if (str_starts_with($record['txt'], 'v=DMARC1')) {
                $result['exists'] = true;
                $result['record'] = $record['txt'];

                if (preg_match('/p=([a-z]+)/', $record['txt'], $matches)) {
                    $result['policy'] = $matches[1];
                    $result['valid'] = in_array($result['policy'], ['none', 'quarantine', 'reject'], true);
                }
                break;
            }
        }

        return $result;
    }

    public function analyzeDeliverability(string $domain, string $selector = ''): array
    {
        $spf = $this->checkSPF($domain);
        $dmarc = $this->checkDMARC($domain);

        $result = [
            'spf' => $spf,
            'dmarc' => $dmarc,
            'score' => 0,
            'recommendations' => []
        ];

        if ($selector) {
            $result['dkim'] = $this->checkDKIM($domain, $selector);
        }

        $score = 0;

        if ($spf['exists']) {
            $score += 30;
            if ($spf['valid']) {
                $score += 10;
            } else {
                $result['recommendations'][] = 'Strengthen SPF record with "-all"';
            }
        } else {
            $result['recommendations'][] = 'Add SPF record';
        }

        if (isset($result['dkim'])) {
            if ($result['dkim']['exists']) {
                $score += 30;
                if (!$result['dkim']['valid']) {
                    $result['recommendations'][] = 'Fix invalid DKIM record';
                }
            } else {
                $result['recommendations'][] = 'Configure DKIM';
            }
        }

        if ($dmarc['exists']) {
            $score += 20;
            $policyScore = match ($dmarc['policy']) {
                'reject' => 20,
                'quarantine' => 10,
                'none' => 5,
                default => 0
            };
            $score += $policyScore;

            if ($dmarc['policy'] === 'none') {
                $result['recommendations'][] = 'Move DMARC to quarantine or reject';
            }
        } else {
            $result['recommendations'][] = 'Add DMARC record';
        }

        $result['score'] = min(100, $score);

        return $result;
    }

    private function __clone() {}
}
