<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth\Adapters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Log;
use Engine\Atomic\Enums\LogChannel;

class LogAdapter
{
    public function info(string $message, array $context = []): void
    {
        Log::channel(LogChannel::AUTH)->info($this->format_message($message, $context));
    }

    public function warning(string $message, array $context = []): void
    {
        Log::channel(LogChannel::AUTH)->warning($this->format_message($message, $context));
    }

    public function error(string $message, array $context = []): void
    {
        Log::channel(LogChannel::AUTH)->error($this->format_message($message, $context));
    }

    private function format_message(string $message, array $context): string
    {
        if (empty($context)) {
            return $message;
        }

        return $message . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
