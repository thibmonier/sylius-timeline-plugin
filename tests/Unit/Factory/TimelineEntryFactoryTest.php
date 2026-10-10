<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTimelinePlugin\Unit\Factory;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\AdminUser;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Symfony\Component\Clock\MockClock;
use Tests\Tmonier\SyliusTimelinePlugin\Unit\TimelineEntryResourceFactory;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;
use Tmonier\SyliusTimelinePlugin\Factory\TimelineEntryFactory;
use Tmonier\SyliusTimelinePlugin\Model\Actor;

final class TimelineEntryFactoryTest extends TestCase
{
    private TimelineEntryFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new TimelineEntryFactory(new TimelineEntryResourceFactory(), new MockClock('2026-10-10 12:00:00'));
    }

    public function testOrderEntriesAreAttachedToTheOrderAndItsCustomer(): void
    {
        $customer = new Customer();
        $order = new Order();
        $order->setCustomer($customer);
        $admin = new AdminUser();
        $admin->setFirstName('Ada');
        $admin->setLastName('Lovelace');

        $entry = $this->factory->createForOrder($order, TimelineEntryInterface::TYPE_ORDER_STATE, Actor::admin($admin));

        self::assertSame($order, $entry->getOrder());
        self::assertSame($customer, $entry->getCustomer());
        self::assertSame(TimelineEntryInterface::TYPE_ORDER_STATE, $entry->getType());
        self::assertSame(TimelineEntryInterface::AUTHOR_ADMIN, $entry->getAuthorType());
        self::assertSame('Ada Lovelace', $entry->getAuthorName());
        self::assertSame($admin, $entry->getAdminUser());
        self::assertEquals(new \DateTimeImmutable('2026-10-10 12:00:00'), $entry->getCreatedAt());
    }

    public function testTheCreationDateCanBeForced(): void
    {
        $entry = $this->factory->createForOrder(new Order(), TimelineEntryInterface::TYPE_ORDER_PLACED, Actor::customer('Jane'), new \DateTimeImmutable('2025-01-01'));

        self::assertEquals(new \DateTimeImmutable('2025-01-01'), $entry->getCreatedAt());
        self::assertSame(TimelineEntryInterface::AUTHOR_CUSTOMER, $entry->getAuthorType());
        self::assertSame('Jane', $entry->getAuthorName());
        self::assertNull($entry->getAdminUser());
    }

    public function testItCreatesNotes(): void
    {
        $customer = new Customer();
        $admin = new AdminUser();
        $admin->setEmail('staff@example.com');

        $note = $this->factory->createCustomerNote($customer, 'Prefers phone calls', Actor::admin($admin));

        self::assertTrue($note->isNote());
        self::assertNull($note->getOrder());
        self::assertSame($customer, $note->getCustomer());
        self::assertSame('Prefers phone calls', $note->getContent());
        self::assertSame('staff@example.com', $note->getAuthorName(), 'Falls back to the e-mail without a name.');

        $orderNote = $this->factory->createOrderNote(new Order(), 'Fragile', Actor::admin($admin));
        self::assertTrue($orderNote->isNote());
        self::assertNotNull($orderNote->getOrder());
        self::assertSame('Fragile', $orderNote->getContent());
    }
}
