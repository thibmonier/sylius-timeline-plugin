<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTimelinePlugin\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntry;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;
use Tmonier\SyliusTimelinePlugin\Factory\TimelineEntryFactoryInterface;
use Tmonier\SyliusTimelinePlugin\Gdpr\CustomerTimelineEraserInterface;

/**
 * Proves the GDPR right-to-erasure fix:
 *  - after erasure, the customer's email entries have no recipients,
 *  - author_name and checkout note content are cleared,
 *  - other customers' entries are untouched,
 *  - the business record (type, date, email code, order context) is preserved.
 *
 * These tests intentionally fail before the fix: the eraser is the only code that
 * clears the fields tested here, so any test that asserts an empty recipients list
 * or a null author_name would fail on unmodified entries.
 */
final class EraseCustomerTimelineCommandTest extends KernelTestCase
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

    // ── Eraser service ────────────────────────────────────────────────────────

    public function testEraserRemovesRecipientsFromEmailEntries(): void
    {
        [$customer, $order] = $this->loadCustomerAndOrder();

        $emailEntry = $this->addEmailEntry($order, $customer, ['jane@example.com', 'shop@example.com']);

        // Confirm the entry contains PII before erasure (this line would pass without the fix).
        self::assertNotEmpty($emailEntry->getContextValue('recipients'), 'Recipients must be present before erasure.');

        $eraser = $this->eraser();
        $eraser->eraseForCustomer($customer);

        $this->entityManager->clear();
        /** @var TimelineEntryInterface $refreshed */
        $refreshed = $this->entityManager->find(TimelineEntry::class, $emailEntry->getId());

        self::assertSame([], $refreshed->getContextValue('recipients'), 'Recipients must be empty after erasure.');
        self::assertSame('order_confirmation', $refreshed->getContextValue('code'), 'Non-personal context fields must be preserved.');
        self::assertSame(TimelineEntryInterface::TYPE_EMAIL, $refreshed->getType(), 'Entry type must be preserved.');
        self::assertNotNull($refreshed->getCreatedAt(), 'Entry date must be preserved.');
    }

    public function testEraserClearsAuthorNameOfCustomerAuthoredEntries(): void
    {
        [$customer, $order] = $this->loadCustomerAndOrder();

        $placedEntry = $this->addOrderPlacedEntry($order, $customer);

        self::assertNotNull($placedEntry->getAuthorName(), 'Author name must be present before erasure.');

        $this->eraser()->eraseForCustomer($customer);
        $this->entityManager->clear();

        /** @var TimelineEntryInterface $refreshed */
        $refreshed = $this->entityManager->find(TimelineEntry::class, $placedEntry->getId());
        self::assertNull($refreshed->getAuthorName(), 'Author name must be null after erasure.');
        self::assertSame(TimelineEntryInterface::TYPE_ORDER_PLACED, $refreshed->getType(), 'Entry type must be preserved.');
    }

    public function testEraserClearsCustomerNoteContent(): void
    {
        [$customer, $order] = $this->loadCustomerAndOrder();

        $noteEntry = $this->addCustomerNoteEntry($order, $customer, 'Leave it with the neighbour');

        self::assertNotNull($noteEntry->getContent(), 'Note content must be present before erasure.');

        $this->eraser()->eraseForCustomer($customer);
        $this->entityManager->clear();

        /** @var TimelineEntryInterface $refreshed */
        $refreshed = $this->entityManager->find(TimelineEntry::class, $noteEntry->getId());
        self::assertNull($refreshed->getContent(), 'Note content must be null after erasure.');
        self::assertNull($refreshed->getAuthorName(), 'Author name of the note must be null after erasure.');
    }

    public function testEraserDoesNotTouchEntriesOfOtherCustomers(): void
    {
        [$targetCustomer, $targetOrder] = $this->loadCustomerAndOrder();
        [$otherCustomer, $otherOrder] = $this->loadAnotherCustomerAndOrder($targetCustomer);

        $targetEntry = $this->addEmailEntry($targetOrder, $targetCustomer, ['target@example.com']);
        $otherEntry = $this->addEmailEntry($otherOrder, $otherCustomer, ['other@example.com']);

        $this->eraser()->eraseForCustomer($targetCustomer);
        $this->entityManager->clear();

        /** @var TimelineEntryInterface $refreshedOther */
        $refreshedOther = $this->entityManager->find(TimelineEntry::class, $otherEntry->getId());
        self::assertSame(
            ['other@example.com'],
            $refreshedOther->getContextValue('recipients'),
            'Entries of other customers must not be modified.',
        );
    }

    public function testEraserReturnsTotalCountOfModifiedEntries(): void
    {
        [$customer, $order] = $this->loadCustomerAndOrder();

        $this->addEmailEntry($order, $customer, ['a@example.com']);
        $this->addEmailEntry($order, $customer, ['b@example.com']);
        $this->addOrderPlacedEntry($order, $customer);

        $count = $this->eraser()->eraseForCustomer($customer);

        self::assertSame(3, $count);
    }

    // ── Console command ───────────────────────────────────────────────────────

    public function testCommandErasesDataByCustomerId(): void
    {
        [$customer, $order] = $this->loadCustomerAndOrder();
        $this->addEmailEntry($order, $customer, ['jane@example.com']);

        $customerId = $customer->getId();
        self::assertIsInt($customerId);
        $this->createCommandTester()->execute(['customer' => (string) $customerId]);

        $this->entityManager->clear();
        /** @var list<TimelineEntryInterface> $entries */
        $entries = $this->entityManager->getRepository(TimelineEntry::class)->findBy([
            'customer' => $customer,
            'type' => TimelineEntryInterface::TYPE_EMAIL,
        ]);
        foreach ($entries as $entry) {
            self::assertSame([], $entry->getContextValue('recipients'));
        }
    }

    public function testCommandDryRunDoesNotWriteAnything(): void
    {
        [$customer, $order] = $this->loadCustomerAndOrder();
        $emailEntry = $this->addEmailEntry($order, $customer, ['jane@example.com']);
        $originalRecipients = $emailEntry->getContextValue('recipients');

        $tester = $this->createCommandTester();
        $customerId = $customer->getId();
        self::assertIsInt($customerId);
        $tester->execute(['customer' => (string) $customerId, '--dry-run' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('dry-run', $tester->getDisplay());

        $this->entityManager->clear();
        /** @var TimelineEntryInterface $refreshed */
        $refreshed = $this->entityManager->find(TimelineEntry::class, $emailEntry->getId());
        self::assertSame($originalRecipients, $refreshed->getContextValue('recipients'), 'Dry-run must not write any change.');
    }

    public function testCommandFailsForUnknownCustomer(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['customer' => '99999999']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No customer found', $tester->getDisplay());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @return array{CustomerInterface, OrderInterface}
     */
    private function loadCustomerAndOrder(): array
    {
        /** @var OrderInterface|null $order */
        $order = $this->entityManager
            ->createQueryBuilder()
            ->select('o')
            ->from(OrderInterface::class, 'o')
            ->innerJoin('o.customer', 'c')
            ->andWhere('o.state = :state')
            ->setParameter('state', OrderInterface::STATE_NEW)
            ->orderBy('o.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        if (null === $order) {
            self::markTestSkipped('The fixtures contain no new orders.');
        }

        $customer = $order->getCustomer();
        self::assertInstanceOf(CustomerInterface::class, $customer);

        return [$customer, $order];
    }

    /**
     * Returns a second customer/order different from $exclude.
     *
     * @return array{CustomerInterface, OrderInterface}
     */
    private function loadAnotherCustomerAndOrder(CustomerInterface $exclude): array
    {
        /** @var OrderInterface|null $order */
        $order = $this->entityManager
            ->createQueryBuilder()
            ->select('o')
            ->from(OrderInterface::class, 'o')
            ->innerJoin('o.customer', 'c')
            ->andWhere('o.state = :state')
            ->andWhere('c != :exclude')
            ->setParameter('state', OrderInterface::STATE_NEW)
            ->setParameter('exclude', $exclude)
            ->orderBy('o.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        if (null === $order) {
            self::markTestSkipped('The fixtures contain no second customer to use as control group.');
        }

        $customer = $order->getCustomer();
        self::assertInstanceOf(CustomerInterface::class, $customer);

        return [$customer, $order];
    }

    /** @param list<string> $recipients */
    private function addEmailEntry(OrderInterface $order, CustomerInterface $customer, array $recipients): TimelineEntryInterface
    {
        $entry = $this->entryFactory()->createNew();
        $entry->setOrder($order);
        $entry->setCustomer($customer);
        $entry->setType(TimelineEntryInterface::TYPE_EMAIL);
        $entry->setAuthorType(TimelineEntryInterface::AUTHOR_SYSTEM);
        $entry->setContext(['code' => 'order_confirmation', 'recipients' => $recipients]);
        $entry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function addOrderPlacedEntry(OrderInterface $order, CustomerInterface $customer): TimelineEntryInterface
    {
        $entry = $this->entryFactory()->createNew();
        $entry->setOrder($order);
        $entry->setCustomer($customer);
        $entry->setType(TimelineEntryInterface::TYPE_ORDER_PLACED);
        $entry->setAuthorType(TimelineEntryInterface::AUTHOR_CUSTOMER);
        $entry->setAuthorName($customer->getFullName() ?: 'Customer');
        $entry->setContext(['number' => $order->getNumber(), 'total' => 0, 'currency' => 'EUR']);
        $entry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function addCustomerNoteEntry(OrderInterface $order, CustomerInterface $customer, string $content): TimelineEntryInterface
    {
        $entry = $this->entryFactory()->createNew();
        $entry->setOrder($order);
        $entry->setCustomer($customer);
        $entry->setType(TimelineEntryInterface::TYPE_CUSTOMER_NOTE);
        $entry->setAuthorType(TimelineEntryInterface::AUTHOR_CUSTOMER);
        $entry->setAuthorName($customer->getFullName() ?: 'Customer');
        $entry->setContent($content);
        $entry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function entryFactory(): TimelineEntryFactoryInterface
    {
        /** @var TimelineEntryFactoryInterface $factory */
        $factory = self::getContainer()->get('tmonier_timeline.factory.timeline_entry');

        return $factory;
    }

    private function eraser(): CustomerTimelineEraserInterface
    {
        /** @var CustomerTimelineEraserInterface $eraser */
        $eraser = self::getContainer()->get('tmonier_timeline.gdpr.customer_eraser');

        return $eraser;
    }

    private function createCommandTester(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        return new CommandTester((new Application($kernel))->find('tmonier:timeline:erase-customer'));
    }
}
