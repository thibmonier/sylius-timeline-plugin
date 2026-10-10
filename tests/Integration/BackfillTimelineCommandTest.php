<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTimelinePlugin\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntry;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;

final class BackfillTimelineCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->entityManager = $entityManager;
        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    public function testItRebuildsTheTimelineOfOrdersWithoutEntriesAndIsIdempotent(): void
    {
        /** @var list<OrderInterface> $orders */
        $orders = $this->entityManager->getRepository(OrderInterface::class)->findBy(['paymentState' => 'paid'], ['id' => 'ASC'], 2);
        self::assertCount(2, $orders);
        $orderIds = array_map(static fn (OrderInterface $order): mixed => $order->getId(), $orders);
        $this->entityManager->createQuery(sprintf('DELETE FROM %s e WHERE e.order IN (:orders)', TimelineEntry::class))
            ->setParameter('orders', $orderIds)
            ->execute();

        $tester = $this->createCommandTester();

        $tester->execute(['--dry-run' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('2 placed order(s) without timeline entries (dry run)', $tester->getDisplay());
        self::assertStringContainsString('Would create', $tester->getDisplay());
        self::assertSame(0, $this->countEntries($orderIds));

        $tester->execute(['--batch-size' => '1']);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('for 2 order(s)', $tester->getDisplay());

        $this->entityManager->clear();
        /** @var list<TimelineEntryInterface> $entries */
        $entries = $this->entityManager->getRepository(TimelineEntry::class)->findBy(['order' => $orderIds[0]]);
        $types = array_map(static fn (TimelineEntryInterface $entry): ?string => $entry->getType(), $entries);
        self::assertContains(TimelineEntryInterface::TYPE_ORDER_PLACED, $types);
        self::assertContains(TimelineEntryInterface::TYPE_PAYMENT, $types);
        foreach ($entries as $entry) {
            if (TimelineEntryInterface::TYPE_CUSTOMER_NOTE !== $entry->getType()) {
                self::assertTrue($entry->getContextValue('backfilled'));
            }
        }

        $created = $this->countEntries($orderIds);
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Every placed order already has a timeline.', $tester->getDisplay());
        self::assertSame($created, $this->countEntries($orderIds));
    }

    /** @param list<mixed> $orderIds */
    private function countEntries(array $orderIds): int
    {
        return (int) $this->entityManager->createQuery(sprintf('SELECT COUNT(e.id) FROM %s e WHERE e.order IN (:orders)', TimelineEntry::class))
            ->setParameter('orders', $orderIds)
            ->getSingleScalarResult();
    }

    private function createCommandTester(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        return new CommandTester((new Application($kernel))->find('tmonier:timeline:backfill'));
    }
}
