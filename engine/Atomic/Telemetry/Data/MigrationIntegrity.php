<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

enum MigrationIntegrity: string
{
    case Verified = 'verified';
    case NotApplied = 'not applied';
    case Modified = 'modified';
    case Legacy = 'legacy/unverified';
    case LegacyAlias = 'legacy published alias';
    case FileUnavailable = 'file unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Verified => 'Verified',
            self::NotApplied => '',
            self::Modified => 'Modified after apply',
            self::Legacy => 'Unverified (legacy)',
            self::LegacyAlias => 'Legacy alias',
            self::FileUnavailable => 'File missing',
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Modified, self::Legacy, self::LegacyAlias => Tone::Warn,
            self::FileUnavailable => Tone::Bad,
            default => Tone::Neutral,
        };
    }

    public function help(): ?string
    {
        return match ($this) {
            self::Modified => 'The file changed after it was applied. Rolling back or re-running uses the current file. '
                . 'Only a checksum of the applied version is stored, so the original contents cannot be shown; '
                . 'compare the file with version control.',
            self::Legacy => 'Applied before checksums were recorded. Run the history upgrade to adopt it.',
            self::LegacyAlias => 'Recorded under an older published name.',
            self::FileUnavailable => 'The migration file is no longer available, so it cannot be rolled back.',
            default => null,
        };
    }
}
