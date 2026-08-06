<?php
declare(strict_types=1);

namespace {
    use Engine\Atomic\Plugins\Monopay\Monopay;
    use Engine\Atomic\Plugins\Monopay\Order;

    if (!function_exists('monopay')) {
        function monopay(): ?Monopay
        {
            return get_plugin('Monopay');
        }
    }

    if (!function_exists('monopay_get_order')) {
        function monopay_get_order(): ?Order
        {
            $plugin = monopay();
            return $plugin ? $plugin->get_order() : null;
        }
    }

    if (!function_exists('monopay_create_payment')) {
        function monopay_create_payment(
            float $amount,
            string $destination,
            array $options = []
        ): array {
            $plugin = monopay();
            if (!$plugin) {
                return ['ok' => false, 'error' => 'Monopay plugin not loaded'];
            }
            return $plugin->create_payment($amount, $destination, $options);
        }
    }

    if (!function_exists('monopay_get_status')) {
        function monopay_get_status(string $invoice_id): array
        {
            $plugin = monopay();
            if (!$plugin || !$plugin->get_order()) {
                return ['ok' => false, 'error' => 'Monopay plugin not configured'];
            }
            return $plugin->get_order()->get_status($invoice_id);
        }
    }

    if (!function_exists('monopay_is_paid')) {
        function monopay_is_paid(string $invoice_id): bool
        {
            $plugin = monopay();
            if (!$plugin || !$plugin->get_order()) {
                return false;
            }
            return $plugin->get_order()->is_paid($invoice_id);
        }
    }

    if (!function_exists('monopay_cancel')) {
        function monopay_cancel(string $invoice_id, ?float $amount = null, array $options = []): array
        {
            $plugin = monopay();
            if (!$plugin || !$plugin->get_order()) {
                return ['ok' => false, 'error' => 'Monopay plugin not configured'];
            }
            return $plugin->get_order()->cancel($invoice_id, $amount, $options);
        }
    }
}

