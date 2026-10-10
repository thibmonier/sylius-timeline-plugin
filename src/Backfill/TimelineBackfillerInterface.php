<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\Backfill;

use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;

interface TimelineBackfillerInterface
{
    /**
     * Entries that can be rebuilt from the current state of a placed order: placed (and the
     * customer's note), completed payments, shipped shipments, cancellation.
     *
     * @return list<TimelineEntryInterface>
     */
    public function createEntries(OrderInterface $order): array;

    public function countOrdersWithoutEntries(): int;

    /**
     * Placed orders without any timeline entry, by ascending id, starting after `$afterId`.
     *
     * @return list<OrderInterface>
     */
    public function findOrdersWithoutEntries(int $afterId, int $limit): array;
}
