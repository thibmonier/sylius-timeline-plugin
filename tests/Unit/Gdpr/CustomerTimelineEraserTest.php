<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTimelinePlugin\Unit\Gdpr;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\CustomerInterface;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntry;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;
use Tmonier\SyliusTimelinePlugin\Gdpr\CustomerTimelineEraser;
use Tmonier\SyliusTimelinePlugin\Repository\TimelineEntryRepositoryInterface;

final class CustomerTimelineEraserTest extends TestCase
{
    /** @var EntityManagerInterface&MockObject */
    private EntityManagerInterface $entityManager;

    /** @var TimelineEntryRepositoryInterface&MockObject */
    private TimelineEntryRepositoryInterface $repository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(TimelineEntryRepositoryInterface::class);
    }

    public function testItErasesRecipientsFromEmailEntries(): void
    {
        $customer = new Customer();
        $emailEntry = $this->makeEmailEntry($customer, ['jane@example.com', 'shop@example.com']);
        $otherEntry = $this->makeOrderPlacedEntry($customer, 'Jane Doe');

        $this->repository->method('findPersonalDataEntriesForCustomer')->willReturn([$emailEntry, $otherEntry]);
        $this->entityManager->expects(self::once())->method('flush');

        $eraser = new CustomerTimelineEraser($this->entityManager, $this->repository);
        $count = $eraser->eraseForCustomer($customer);

        self::assertSame([], $emailEntry->getContextValue('recipients'));
        self::assertSame(2, $count);
    }

    public function testItErasesAuthorNameFromCustomerAuthoredEntries(): void
    {
        $customer = new Customer();
        $placedEntry = $this->makeOrderPlacedEntry($customer, 'Jane Doe');

        $this->repository->method('findPersonalDataEntriesForCustomer')->willReturn([$placedEntry]);
        $this->entityManager->expects(self::once())->method('flush');

        $eraser = new CustomerTimelineEraser($this->entityManager, $this->repository);
        $eraser->eraseForCustomer($customer);

        self::assertNull($placedEntry->getAuthorName());
    }

    public function testItErasesContentOfCustomerCheckoutNotes(): void
    {
        $customer = new Customer();
        $noteEntry = $this->makeCustomerNoteEntry($customer, 'Jane Doe', 'Leave it with the neighbour');

        $this->repository->method('findPersonalDataEntriesForCustomer')->willReturn([$noteEntry]);
        $this->entityManager->expects(self::once())->method('flush');

        $eraser = new CustomerTimelineEraser($this->entityManager, $this->repository);
        $eraser->eraseForCustomer($customer);

        self::assertNull($noteEntry->getContent());
        self::assertNull($noteEntry->getAuthorName());
    }

    public function testItDoesNotCountEntriesWithNoDataToErase(): void
    {
        $customer = new Customer();
        // Email entry with no recipients yet (already erased or never set).
        $emailEntry = $this->makeEmailEntry($customer, []);

        $this->repository->method('findPersonalDataEntriesForCustomer')->willReturn([$emailEntry]);
        $this->entityManager->expects(self::once())->method('flush');

        $eraser = new CustomerTimelineEraser($this->entityManager, $this->repository);
        $count = $eraser->eraseForCustomer($customer);

        self::assertSame(0, $count, 'An entry that has nothing to erase should not be counted.');
    }

    public function testItPreservesNonPersonalContextFields(): void
    {
        $customer = new Customer();
        $emailEntry = $this->makeEmailEntry($customer, ['jane@example.com']);
        $emailEntry->setContext([
            'code' => 'order_confirmation',
            'recipients' => ['jane@example.com'],
        ]);

        $this->repository->method('findPersonalDataEntriesForCustomer')->willReturn([$emailEntry]);
        $this->entityManager->method('flush');

        $eraser = new CustomerTimelineEraser($this->entityManager, $this->repository);
        $eraser->eraseForCustomer($customer);

        self::assertSame('order_confirmation', $emailEntry->getContextValue('code'), 'Non-personal context fields must be preserved.');
        self::assertSame([], $emailEntry->getContextValue('recipients'));
    }

    public function testItCallsFlushExactlyOnce(): void
    {
        $customer = new Customer();
        $entries = [
            $this->makeEmailEntry($customer, ['a@example.com']),
            $this->makeOrderPlacedEntry($customer, 'Jane'),
            $this->makeCustomerNoteEntry($customer, 'Jane', 'deliver asap'),
        ];

        $this->repository->method('findPersonalDataEntriesForCustomer')->willReturn($entries);
        $this->entityManager->expects(self::once())->method('flush');

        $eraser = new CustomerTimelineEraser($this->entityManager, $this->repository);
        $count = $eraser->eraseForCustomer($customer);

        self::assertSame(3, $count);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @param list<string> $recipients */
    private function makeEmailEntry(CustomerInterface $customer, array $recipients): TimelineEntry
    {
        $entry = new TimelineEntry();
        $entry->setCustomer($customer);
        $entry->setType(TimelineEntryInterface::TYPE_EMAIL);
        $entry->setAuthorType(TimelineEntryInterface::AUTHOR_SYSTEM);
        $entry->setContext(['code' => 'order_confirmation', 'recipients' => $recipients]);
        $entry->setCreatedAt(new \DateTimeImmutable());

        return $entry;
    }

    private function makeOrderPlacedEntry(CustomerInterface $customer, string $authorName): TimelineEntry
    {
        $entry = new TimelineEntry();
        $entry->setCustomer($customer);
        $entry->setType(TimelineEntryInterface::TYPE_ORDER_PLACED);
        $entry->setAuthorType(TimelineEntryInterface::AUTHOR_CUSTOMER);
        $entry->setAuthorName($authorName);
        $entry->setContext(['number' => '000042', 'total' => 9900, 'currency' => 'EUR']);
        $entry->setCreatedAt(new \DateTimeImmutable());

        return $entry;
    }

    private function makeCustomerNoteEntry(CustomerInterface $customer, string $authorName, string $content): TimelineEntry
    {
        $entry = new TimelineEntry();
        $entry->setCustomer($customer);
        $entry->setType(TimelineEntryInterface::TYPE_CUSTOMER_NOTE);
        $entry->setAuthorType(TimelineEntryInterface::AUTHOR_CUSTOMER);
        $entry->setAuthorName($authorName);
        $entry->setContent($content);
        $entry->setCreatedAt(new \DateTimeImmutable());

        return $entry;
    }
}
