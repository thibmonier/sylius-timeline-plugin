<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\Factory;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;
use Tmonier\SyliusTimelinePlugin\Model\Actor;

/**
 * @extends FactoryInterface<TimelineEntryInterface>
 */
interface TimelineEntryFactoryInterface extends FactoryInterface
{
    public function createNew(): TimelineEntryInterface;

    /** Entry attached to the order and to its customer, so that both timelines see it. */
    public function createForOrder(OrderInterface $order, string $type, Actor $actor, ?\DateTimeImmutable $createdAt = null): TimelineEntryInterface;

    public function createOrderNote(OrderInterface $order, string $content, Actor $actor): TimelineEntryInterface;

    public function createCustomerNote(CustomerInterface $customer, string $content, Actor $actor): TimelineEntryInterface;
}
