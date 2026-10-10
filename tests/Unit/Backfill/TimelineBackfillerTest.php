<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTimelinePlugin\Unit\Backfill;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\Shipment;
use Symfony\Component\Clock\MockClock;
use Tests\Tmonier\SyliusTimelinePlugin\Unit\TimelineEntryResourceFactory;
use Tmonier\SyliusTimelinePlugin\Backfill\TimelineBackfiller;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntry;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;
use Tmonier\SyliusTimelinePlugin\Factory\TimelineEntryFactory;

final class TimelineBackfillerTest extends TestCase
{
    private TimelineBackfiller $backfiller;

    protected function setUp(): void
    {
        $this->backfiller = new TimelineBackfiller(
            new TimelineEntryFactory(new TimelineEntryResourceFactory(), new MockClock('2026-10-10')),
            $this->createMock(EntityManagerInterface::class),
            Order::class,
            TimelineEntry::class,
        );
    }

    public function testItRebuildsWhatTheOrderStillTells(): void
    {
        $order = $this->createPlacedOrder();
        $order->setNotes('Ring twice');

        $paid = new Payment();
        $paid->setState(PaymentInterface::STATE_COMPLETED);
        $paid->setAmount(1500);
        $paid->setUpdatedAt(new \DateTime('2026-09-02 09:00:00'));
        $order->addPayment($paid);
        $failed = new Payment();
        $failed->setState(PaymentInterface::STATE_FAILED);
        $order->addPayment($failed);

        $shipped = new Shipment();
        $shipped->setState('shipped');
        $shipped->setTracking('TRACK-9');
        $shipped->setShippedAt(new \DateTime('2026-09-03 15:00:00'));
        $order->addShipment($shipped);
        $order->addShipment(new Shipment());

        $entries = $this->backfiller->createEntries($order);

        self::assertSame(
            [TimelineEntryInterface::TYPE_ORDER_PLACED, TimelineEntryInterface::TYPE_CUSTOMER_NOTE, TimelineEntryInterface::TYPE_PAYMENT, TimelineEntryInterface::TYPE_SHIPMENT],
            array_map(static fn (TimelineEntryInterface $entry): ?string => $entry->getType(), $entries),
        );
        [$placed, $note, $payment, $shipment] = $entries;

        self::assertEquals(new \DateTimeImmutable('2026-09-01 10:00:00'), $placed->getCreatedAt());
        self::assertSame(TimelineEntryInterface::AUTHOR_CUSTOMER, $placed->getAuthorType());
        self::assertSame('Jane Roe', $placed->getAuthorName());
        self::assertTrue($placed->getContextValue('backfilled'));
        self::assertSame('000007', $placed->getContextValue('number'));

        self::assertSame('Ring twice', $note->getContent());

        self::assertSame('complete', $payment->getTransition());
        self::assertSame(1500, $payment->getContextValue('amount'));
        self::assertEquals(new \DateTimeImmutable('2026-09-02 09:00:00'), $payment->getCreatedAt());
        self::assertSame(TimelineEntryInterface::AUTHOR_SYSTEM, $payment->getAuthorType());

        self::assertSame('ship', $shipment->getTransition());
        self::assertSame('TRACK-9', $shipment->getContextValue('tracking'));
        self::assertEquals(new \DateTimeImmutable('2026-09-03 15:00:00'), $shipment->getCreatedAt());

        foreach ($entries as $entry) {
            self::assertSame($order, $entry->getOrder());
            self::assertSame($order->getCustomer(), $entry->getCustomer());
        }
    }

    public function testACancelledOrderGetsACancellationNeverDatedBeforeThePlacement(): void
    {
        $order = $this->createPlacedOrder();
        $order->setState(OrderInterface::STATE_CANCELLED);
        $order->setUpdatedAt(new \DateTime('2020-01-01'));

        $entries = $this->backfiller->createEntries($order);

        self::assertCount(2, $entries);
        self::assertSame('cancel', $entries[1]->getTransition());
        self::assertEquals(new \DateTimeImmutable('2026-09-01 10:00:00'), $entries[1]->getCreatedAt());
    }

    public function testCartsAreIgnored(): void
    {
        self::assertSame([], $this->backfiller->createEntries(new Order()));
    }

    private function createPlacedOrder(): OrderInterface
    {
        $customer = new Customer();
        $customer->setFirstName('Jane');
        $customer->setLastName('Roe');

        $order = new Order();
        $order->setCustomer($customer);
        $order->setNumber('000007');
        $order->setState(OrderInterface::STATE_NEW);
        $order->setCheckoutCompletedAt(new \DateTime('2026-09-01 10:00:00'));

        return $order;
    }
}
