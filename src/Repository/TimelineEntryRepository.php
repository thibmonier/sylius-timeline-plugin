<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\Repository;

use Doctrine\ORM\QueryBuilder;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\OrderTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;

class TimelineEntryRepository extends EntityRepository implements TimelineEntryRepositoryInterface
{
    public function findForOrder(OrderInterface $order): array
    {
        return $this->getEntries(
            $this->createOrderedQueryBuilder()
                ->andWhere('o.order = :order')
                ->setParameter('order', $order),
        );
    }

    public function findPinnedForOrder(OrderInterface $order): array
    {
        return $this->getEntries(
            $this->createOrderedQueryBuilder()
                ->andWhere('o.order = :order')
                ->andWhere('o.type = :note')
                ->andWhere('o.pinned = true')
                ->setParameter('order', $order)
                ->setParameter('note', TimelineEntryInterface::TYPE_NOTE),
        );
    }

    public function countNotesForOrder(OrderInterface $order): int
    {
        if (null === $order->getId()) {
            return 0;
        }

        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.order = :order')
            ->andWhere('o.type = :note')
            ->setParameter('order', $order)
            ->setParameter('note', TimelineEntryInterface::TYPE_NOTE)
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    public function findForCustomer(CustomerInterface $customer, int $limit, int $offset = 0): array
    {
        return $this->getEntries(
            $this->createCustomerQueryBuilder($customer)
                ->addSelect('ord')
                ->leftJoin('o.order', 'ord')
                ->addOrderBy('o.createdAt', 'DESC')
                ->addOrderBy('o.id', 'DESC')
                ->setFirstResult(max(0, $offset))
                ->setMaxResults(max(1, $limit)),
        );
    }

    public function createPaginatorForCustomer(CustomerInterface $customer): Pagerfanta
    {
        /** @var Pagerfanta<TimelineEntryInterface> $paginator */
        $paginator = new Pagerfanta(new QueryAdapter(
            $this->createCustomerQueryBuilder($customer)
                ->addSelect('ord')
                ->leftJoin('o.order', 'ord')
                ->addOrderBy('o.createdAt', 'DESC')
                ->addOrderBy('o.id', 'DESC'),
            false,
            false,
        ));

        return $paginator;
    }

    public function countForCustomer(CustomerInterface $customer): int
    {
        return (int) $this->createCustomerQueryBuilder($customer)
            ->select('COUNT(o.id)')
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    public function findPinnedForCustomer(CustomerInterface $customer): array
    {
        return $this->getEntries(
            $this->createOrderedQueryBuilder()
                ->andWhere('o.customer = :customer')
                ->andWhere('o.order IS NULL')
                ->andWhere('o.type = :note')
                ->andWhere('o.pinned = true')
                ->setParameter('customer', $customer)
                ->setParameter('note', TimelineEntryInterface::TYPE_NOTE),
        );
    }

    public function hasEntriesForOrder(OrderInterface $order): bool
    {
        if (null === $order->getId()) {
            return false;
        }

        return null !== $this->createQueryBuilder('o')
            ->select('o.id')
            ->andWhere('o.order = :order')
            ->setParameter('order', $order)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }

    /**
     * Customer notes, plus the order events worth showing on the customer page:
     * placed, cancelled, payments completed or refunded.
     */
    private function createCustomerQueryBuilder(CustomerInterface $customer): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('o');
        $expr = $queryBuilder->expr();

        return $queryBuilder
            ->andWhere('o.customer = :customer')
            ->andWhere($expr->orX(
                $expr->andX('o.type = :note', 'o.order IS NULL'),
                'o.type = :orderPlaced',
                $expr->andX('o.type = :orderState', 'o.transition = :cancel'),
                $expr->andX('o.type = :payment', 'o.transition IN (:paymentTransitions)'),
            ))
            ->setParameter('customer', $customer)
            ->setParameter('note', TimelineEntryInterface::TYPE_NOTE)
            ->setParameter('orderPlaced', TimelineEntryInterface::TYPE_ORDER_PLACED)
            ->setParameter('orderState', TimelineEntryInterface::TYPE_ORDER_STATE)
            ->setParameter('cancel', OrderTransitions::TRANSITION_CANCEL)
            ->setParameter('payment', TimelineEntryInterface::TYPE_PAYMENT)
            ->setParameter('paymentTransitions', [PaymentTransitions::TRANSITION_COMPLETE, PaymentTransitions::TRANSITION_REFUND])
        ;
    }

    private function createOrderedQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('o')
            ->addSelect('adminUser')
            ->leftJoin('o.adminUser', 'adminUser')
            ->addOrderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
        ;
    }

    /** @return list<TimelineEntryInterface> */
    private function getEntries(QueryBuilder $queryBuilder): array
    {
        /** @var list<TimelineEntryInterface> $entries */
        $entries = $queryBuilder->getQuery()->getResult();

        return $entries;
    }
}
