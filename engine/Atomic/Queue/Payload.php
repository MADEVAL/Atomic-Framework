<?php

declare(strict_types=1);
namespace Engine\Atomic\Queue;

if (!defined('ATOMIC_START')) exit;

final class Payload
{
    public static function encode(array $payload): string
    {
        self::validate($payload);

        return \json_encode($payload, JSON_THROW_ON_ERROR);
    }

    public static function decode(string $payload): array
    {
        $decoded = \json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \UnexpectedValueException('Queue payload must be a JSON object.');
        }

        self::validate($decoded);
        return $decoded;
    }

    private static function validate(array $payload): void
    {
        if (!isset($payload['handler']) || !\is_string($payload['handler']) || $payload['handler'] === '') {
            throw new \UnexpectedValueException('Queue payload must contain a non-empty string handler.');
        }
        if (!isset($payload['data']) || !\is_array($payload['data'])) {
            throw new \UnexpectedValueException('Queue payload must contain array data.');
        }
        if (isset($payload['uuid_batch']) && (!\is_string($payload['uuid_batch']) || $payload['uuid_batch'] === '')) {
            throw new \UnexpectedValueException('Queue payload uuid_batch must be a non-empty string when present.');
        }
        if (isset($payload['cancel_handler'])) {
            $cancelHandler = $payload['cancel_handler'];
            $valid = \is_string($cancelHandler)
                || (\is_array($cancelHandler)
                    && isset($cancelHandler[0], $cancelHandler[1])
                    && \is_string($cancelHandler[0])
                    && \is_string($cancelHandler[1]));
            if (!$valid) {
                throw new \UnexpectedValueException('Queue payload cancel_handler must be a string or class-method pair when present.');
            }
        }
    }
}
