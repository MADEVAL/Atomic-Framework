<?php
declare(strict_types=1);

namespace Engine\Atomic\Exceptions;

if (!defined('ATOMIC_START')) exit;

/**
 * A Telegram Bot API call failed: missing token or chat id, transport error,
 * invalid response, or an `ok: false` answer from Telegram.
 *
 * The message never contains the bot token.
 */
class TelegramException extends AtomicException
{
    public function __construct(
        string $message,
        private readonly string $api_method = '',
        private readonly ?int $error_code = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $error_code ?? 0, $previous);
    }

    /** Bot API method that failed, e.g. `setWebhook`; empty when no call was made. */
    public function get_api_method(): string
    {
        return $this->api_method;
    }

    /** Telegram `error_code` (400, 401, 403, 429, ...) or the HTTP status; null when the request never got an answer. */
    public function get_error_code(): ?int
    {
        return $this->error_code;
    }
}
