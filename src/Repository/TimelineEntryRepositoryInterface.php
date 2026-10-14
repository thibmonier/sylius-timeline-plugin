<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\Repository;

use Pagerfanta\Pagerfanta;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;

/**
 * @extends RepositoryInterface<TimelineEntryInterface>
 */
interface TimelineEntryRepositoryInterface extends RepositoryInterface
{
    /**
     * Every entry of the order, newest first.
     *
     * @return list<TimelineEntryInterface>
     */
    public function findForOrder(OrderInterface $order): array;

    /** @return list<TimelineEntryInterface> */
    public function findPinnedForOrder(OrderInterface $order): array;

    public function countNotesForOrder(OrderInterface $order): int;

    /**
     * Notes on the customer and key events of their orders, newest first.
     *
     * @return list<TimelineEntryInterface>
     */
    public function findForCustomer(CustomerInterface $customer, int $limit, int $offset = 0): array;

    /** @return Pagerfanta<TimelineEntryInterface> */
    public function createPaginatorForCustomer(CustomerInterface $customer): Pagerfanta;

    public function countForCustomer(CustomerInterface $customer): int;

    /** @return list<TimelineEntryInterface> */
    public function findPinnedForCustomer(CustomerInterface $customer): array;

    public function hasEntriesForOrder(OrderInterface $order): bool;

    /**
     * Entries of the customer that carry personal data and must be cleared during a GDPR
     * erasure: customer-authored entries (order_placed, customer_note) and email entries.
     *
     * @return list<TimelineEntryInterface>
     */
    public function findPersonalDataEntriesForCustomer(CustomerInterface $customer): array;
}
