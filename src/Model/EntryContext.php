<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\Model;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\ShipmentInterface;

/**
 * Snapshots stored in `TimelineEntry::$context`: what the entry shows must not change when
 * the order, payment method or shipping method is edited later.
 */
final class EntryContext
{
    /** @return array<string, mixed> */
    public static function forOrder(OrderInterface $order): array
    {
        return [
            'number' => $order->getNumber(),
            'total' => $order->getTotal(),
            'currency' => $order->getCurrencyCode(),
        ];
    }

    /** @return array<string, mixed> */
    public static function forPayment(PaymentInterface $payment): array
    {
        $method = $payment->getMethod();

        return [
            'payment_id' => $payment->getId(),
            'method' => $method?->getName() ?? $method?->getCode(),
            'amount' => $payment->getAmount(),
            'currency' => $payment->getCurrencyCode(),
        ];
    }

    /** @return array<string, mixed> */
    public static function forShipment(ShipmentInterface $shipment): array
    {
        $method = $shipment->getMethod();

        return array_filter([
            'shipment_id' => $shipment->getId(),
            'method' => $method?->getName() ?? $method?->getCode(),
            'tracking' => $shipment->getTracking(),
        ], static fn (mixed $value): bool => null !== $value && '' !== $value);
    }
}
