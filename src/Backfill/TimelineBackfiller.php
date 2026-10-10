<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\Backfill;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Order\OrderTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Shipping\ShipmentTransitions;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;
use Tmonier\SyliusTimelinePlugin\Factory\TimelineEntryFactoryInterface;
use Tmonier\SyliusTimelinePlugin\Model\Actor;
use Tmonier\SyliusTimelinePlugin\Model\EntryContext;

final class TimelineBackfiller implements TimelineBackfillerInterface
{
    /**
     * @param class-string $orderClass
     * @param class-string $entryClass
     */
    public function __construct(
        private readonly TimelineEntryFactoryInterface $entryFactory,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $orderClass,
        private readonly string $entryClass,
    ) {
    }

    public function createEntries(OrderInterface $order): array
    {
        $placedAt = $order->getCheckoutCompletedAt();
        if (null === $placedAt) {
            return [];
        }
        $placedAt = \DateTimeImmutable::createFromInterface($placedAt);
        $customerActor = Actor::customer($order->getCustomer()?->getFullName());

        $placed = $this->createEntry($order, TimelineEntryInterface::TYPE_ORDER_PLACED, $customerActor, $placedAt, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CREATE);
        $placed->setContext(EntryContext::forOrder($order) + ['backfilled' => true]);
        $entries = [$placed];

        $notes = trim((string) $order->getNotes());
        if ('' !== $notes) {
            $customerNote = $this->createEntry($order, TimelineEntryInterface::TYPE_CUSTOMER_NOTE, $customerActor, $placedAt);
            $customerNote->setContent($notes);
            $entries[] = $customerNote;
        }

        foreach ($order->getPayments() as $payment) {
            if (!$payment instanceof PaymentInterface || PaymentInterface::STATE_COMPLETED !== $payment->getState()) {
                continue;
            }

            $entry = $this->createEntry($order, TimelineEntryInterface::TYPE_PAYMENT, Actor::system(), $this->latest($placedAt, $payment->getUpdatedAt()), PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);
            $entry->setToState(PaymentInterface::STATE_COMPLETED);
            $entry->setContext(EntryContext::forPayment($payment) + ['backfilled' => true]);
            $entries[] = $entry;
        }

        foreach ($order->getShipments() as $shipment) {
            if (null === $shipment->getShippedAt()) {
                continue;
            }

            $entry = $this->createEntry($order, TimelineEntryInterface::TYPE_SHIPMENT, Actor::system(), $this->latest($placedAt, $shipment->getShippedAt()), ShipmentTransitions::GRAPH, ShipmentTransitions::TRANSITION_SHIP);
            $entry->setToState($shipment->getState());
            $entry->setContext(EntryContext::forShipment($shipment) + ['backfilled' => true]);
            $entries[] = $entry;
        }

        if (OrderInterface::STATE_CANCELLED === $order->getState()) {
            $entry = $this->createEntry($order, TimelineEntryInterface::TYPE_ORDER_STATE, Actor::system(), $this->latest($placedAt, $order->getUpdatedAt()), OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL);
            $entry->setToState(OrderInterface::STATE_CANCELLED);
            $entry->setContext(['backfilled' => true]);
            $entries[] = $entry;
        }

        return $entries;
    }

    public function countOrdersWithoutEntries(): int
    {
        return (int) $this->createOrdersWithoutEntriesQueryBuilder()
            ->select('COUNT(o.id)')
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    public function findOrdersWithoutEntries(int $afterId, int $limit): array
    {
        /** @var list<OrderInterface> $orders */
        $orders = $this->createOrdersWithoutEntriesQueryBuilder()
            ->andWhere('o.id > :afterId')
            ->setParameter('afterId', $afterId)
            ->orderBy('o.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult()
        ;

        return $orders;
    }

    private function createOrdersWithoutEntriesQueryBuilder(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from($this->orderClass, 'o')
            ->andWhere('o.checkoutCompletedAt IS NOT NULL')
            ->andWhere(sprintf('NOT EXISTS (SELECT e.id FROM %s e WHERE IDENTITY(e.order) = o.id)', $this->entryClass))
        ;
    }

    private function createEntry(
        OrderInterface $order,
        string $type,
        Actor $actor,
        \DateTimeImmutable $createdAt,
        ?string $workflow = null,
        ?string $transition = null,
    ): TimelineEntryInterface {
        $entry = $this->entryFactory->createForOrder($order, $type, $actor, $createdAt);
        $entry->setWorkflow($workflow);
        $entry->setTransition($transition);

        return $entry;
    }

    private function latest(\DateTimeImmutable $placedAt, ?\DateTimeInterface $date): \DateTimeImmutable
    {
        if (null === $date) {
            return $placedAt;
        }

        $date = \DateTimeImmutable::createFromInterface($date);

        return $date > $placedAt ? $date : $placedAt;
    }
}
