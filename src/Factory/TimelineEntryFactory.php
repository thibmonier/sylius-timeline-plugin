<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\Factory;

use Psr\Clock\ClockInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;
use Tmonier\SyliusTimelinePlugin\Model\Actor;

final readonly class TimelineEntryFactory implements TimelineEntryFactoryInterface
{
    /** @param FactoryInterface<TimelineEntryInterface> $decoratedFactory */
    public function __construct(
        private FactoryInterface $decoratedFactory,
        private ClockInterface $clock,
    ) {
    }

    public function createNew(): TimelineEntryInterface
    {
        $entry = $this->decoratedFactory->createNew();
        $entry->setCreatedAt($this->clock->now());

        return $entry;
    }

    public function createForOrder(OrderInterface $order, string $type, Actor $actor, ?\DateTimeImmutable $createdAt = null): TimelineEntryInterface
    {
        $entry = $this->createWithActor($type, $actor);
        $entry->setOrder($order);
        $customer = $order->getCustomer();
        $entry->setCustomer($customer instanceof CustomerInterface ? $customer : null);
        if ($createdAt instanceof \DateTimeImmutable) {
            $entry->setCreatedAt($createdAt);
        }

        return $entry;
    }

    public function createOrderNote(OrderInterface $order, string $content, Actor $actor): TimelineEntryInterface
    {
        $entry = $this->createForOrder($order, TimelineEntryInterface::TYPE_NOTE, $actor);
        $entry->setContent($content);

        return $entry;
    }

    public function createCustomerNote(CustomerInterface $customer, string $content, Actor $actor): TimelineEntryInterface
    {
        $entry = $this->createWithActor(TimelineEntryInterface::TYPE_NOTE, $actor);
        $entry->setCustomer($customer);
        $entry->setContent($content);

        return $entry;
    }

    private function createWithActor(string $type, Actor $actor): TimelineEntryInterface
    {
        $entry = $this->createNew();
        $entry->setType($type);
        $entry->setAuthorType($actor->type);
        $entry->setAuthorName($actor->name);
        $entry->setAdminUser($actor->adminUser);

        return $entry;
    }
}
