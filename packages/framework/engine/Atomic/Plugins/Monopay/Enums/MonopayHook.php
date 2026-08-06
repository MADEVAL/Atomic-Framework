<?php
declare(strict_types=1);

namespace Engine\Atomic\Plugins\Monopay\Enums;

if (!defined('ATOMIC_START')) exit;

enum MonopayHook: string
{
    case PAYMENT_INVOICE_DATA          = 'monopay.payment.invoice_data';
    case PAYMENT_CREATED               = 'monopay.payment.created';
    case PAYMENT_CREATE_FAILED         = 'monopay.payment.create_failed';
    case PAYMENT_UPDATED               = 'monopay.payment.updated';
    case PAYMENT_STATUS                = 'monopay.payment.status';
    case PAYMENT_SHOULD_MARK_FULFILLED = 'monopay.payment.should_mark_fulfilled';
    case PAYMENT_VERIFICATION_FAILED   = 'monopay.payment.verification_failed';
    case PAYMENT_VERIFIED              = 'monopay.payment.verified';
    case PAYMENT_SUCCESS               = 'monopay.payment.success';
    case PAYMENT_FAILED                = 'monopay.payment.failed';
    case PAYMENT_PENDING               = 'monopay.payment.pending';
    case PAYMENT_REVERSED              = 'monopay.payment.reversed';
    case PAYMENT_HOLD                  = 'monopay.payment.hold';

    public static function status(PaymentStatus|string $status): string
    {
        $status_value = $status instanceof PaymentStatus ? $status->value : $status;

        return self::PAYMENT_STATUS->value . '.' . $status_value;
    }
}