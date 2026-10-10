<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTimelinePlugin\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Core\OrderShippingStates;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntry;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;
use Tmonier\SyliusTimelinePlugin\Factory\TimelineEntryFactoryInterface;
use Tmonier\SyliusTimelinePlugin\Model\Actor;

/**
 * Tests run against the test application loaded with the Sylius fixtures. The kernel is not
 * rebooted between requests and every test runs in a transaction rolled back at the end, so
 * that cancelled orders or shipped shipments do not leak into the next test.
 */
trait TimelineTestTrait
{
    private KernelBrowser $client;

    private function setUpClient(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->connection()->beginTransaction();
    }

    private function tearDownClient(): void
    {
        if ($this->connection()->isTransactionActive()) {
            $this->connection()->rollBack();
        }
    }

    private function loginAdmin(string $username = 'sylius'): AdminUserInterface
    {
        $admin = $this->entityManager()->getRepository(AdminUserInterface::class)->findOneBy(['username' => $username]);
        self::assertInstanceOf(AdminUserInterface::class, $admin);
        $this->client->loginUser($admin, 'admin');

        return $admin;
    }

    /**
     * A placed order of a customer with a shop account, ready to ship, in the given payment
     * state. The fixtures are random: when no order is already paid, the payment of an
     * awaiting one is completed through the state machine (the test transaction rolls it back).
     */
    private function findPlacedOrder(string $paymentState = 'paid'): OrderInterface
    {
        $order = $this->findReadyOrder($paymentState);
        if (null === $order && OrderPaymentStates::STATE_PAID === $paymentState) {
            $order = $this->findReadyOrder(OrderPaymentStates::STATE_AWAITING_PAYMENT);
            if (null !== $order) {
                $this->completePayment($order);
            }
        }

        if (null === $order) {
            self::markTestSkipped(sprintf('The random fixtures contain no placed order awaiting shipping with the "%s" payment state.', $paymentState));
        }

        return $order;
    }

    private function findReadyOrder(string $paymentState): ?OrderInterface
    {
        /** @var OrderInterface|null $order */
        $order = $this->entityManager()
            ->createQueryBuilder()
            ->select('o')
            ->from(OrderInterface::class, 'o')
            ->innerJoin('o.customer', 'c')
            ->innerJoin('c.user', 'u')
            ->andWhere('o.state = :state')
            ->andWhere('o.paymentState = :paymentState')
            ->andWhere('o.shippingState = :shippingState')
            ->setParameter('state', OrderInterface::STATE_NEW)
            ->setParameter('paymentState', $paymentState)
            ->setParameter('shippingState', OrderShippingStates::STATE_READY)
            ->orderBy('o.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        return $order;
    }

    private function completePayment(OrderInterface $order): void
    {
        /** @var StateMachineInterface $stateMachine */
        $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');

        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment, 'An order awaiting payment has a new payment.');
        $stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);
        $this->entityManager()->flush();

        self::assertSame(OrderPaymentStates::STATE_PAID, $order->getPaymentState());
    }

    private function customerOf(OrderInterface $order): CustomerInterface
    {
        $customer = $order->getCustomer();
        self::assertInstanceOf(CustomerInterface::class, $customer);

        return $customer;
    }

    private function addOrderNote(OrderInterface $order, string $content, AdminUserInterface $author, bool $pinned = false): TimelineEntryInterface
    {
        $note = $this->entryFactory()->createOrderNote($order, $content, Actor::admin($author));
        $note->setPinned($pinned);
        $this->entityManager()->persist($note);
        $this->entityManager()->flush();

        return $note;
    }

    /** @return list<TimelineEntryInterface> */
    private function findEntries(OrderInterface $order, ?string $type = null): array
    {
        $criteria = ['order' => $order];
        if (null !== $type) {
            $criteria['type'] = $type;
        }

        /** @var list<TimelineEntryInterface> $entries */
        $entries = $this->entityManager()->getRepository(TimelineEntry::class)->findBy($criteria, ['id' => 'ASC']);

        return $entries;
    }

    private function orderUrl(OrderInterface $order): string
    {
        return sprintf('/admin/orders/%d', $this->idOf($order));
    }

    private function idOf(OrderInterface|CustomerInterface|TimelineEntryInterface $resource): int
    {
        $id = $resource->getId();
        self::assertIsInt($id);

        return $id;
    }

    /** @return list<string> */
    private function entryLabels(Crawler $crawler): array
    {
        /** @var list<string> $labels */
        $labels = $crawler->filter('[data-test-timeline-entry-label]')->each(static fn (Crawler $node): string => trim($node->text()));

        return $labels;
    }

    private function entryFactory(): TimelineEntryFactoryInterface
    {
        /** @var TimelineEntryFactoryInterface $factory */
        $factory = self::getContainer()->get('tmonier_timeline.factory.timeline_entry');

        return $factory;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }
}
