<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class EnvItem
{
    private const LABELS = [
        'sapi' => 'SAPI',
        'opcache_enabled' => 'OPcache',
        'os' => 'OS',
        'env' => 'Environment',
        'debug_mode' => 'Debug mode',
        'debug_level' => 'Debug level',
        'logs_dir' => 'Logs dir',
        'dumps_dir' => 'Dumps dir',
        'base' => 'Base path',
        'server_version' => 'Server',
        'client_version' => 'Client',
        'f3' => 'F3',
    ];

    /** @param list<string>|null $list */
    public function __construct(
        public readonly string $key,
        public readonly string|int|float|bool|null $value,
        public readonly ?array $list = null,
    ) {}

    public function label(): string
    {
        return self::LABELS[$this->key] ?? str_replace('_', ' ', ucfirst($this->key));
    }

    public function text(): string
    {
        return match (true) {
            $this->list !== null => implode(', ', $this->list),
            $this->value === true => 'yes',
            $this->value === false => 'no',
            $this->value === null, $this->value === '' => '-',
            default => (string)$this->value,
        };
    }

    public function raw(): mixed
    {
        return $this->list ?? $this->value;
    }
}
