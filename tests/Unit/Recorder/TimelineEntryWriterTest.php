<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTimelinePlugin\Unit\Recorder;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\AdminUser;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntry;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;
use Tmonier\SyliusTimelinePlugin\Recorder\TimelineEntryWriter;

final class TimelineEntryWriterTest extends TestCase
{
    /** @var EntityManagerInterface&MockObject */
    private EntityManagerInterface $entityManager;

    /** @var UnitOfWork&MockObject */
    private UnitOfWork $unitOfWork;

    /** @var list<object> */
    private array $managed = [];

    /** @var list<object> */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->unitOfWork = $this->createMock(UnitOfWork::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('contains')->willReturnCallback(fn (object $object): bool => in_array($object, $this->managed, true));
        $this->entityManager->method('persist')->willReturnCallback(function (object $object): void {
            $this->persisted[] = $object;
        });
        $this->entityManager->method('getUnitOfWork')->willReturn($this->unitOfWork);
    }

    public function testAnEntryOfAManagedOrderIsPersistedWithoutFlushing(): void
    {
        $entry = $this->createOrderEntry(managed: true);
        $this->entityManager->expects(self::never())->method('flush');

        self::assertTrue((new TimelineEntryWriter($this->entityManager))->persist($entry));
        self::assertSame([$entry], $this->persisted);
    }

    public function testAnEntryOfAnOrderNotPersistedYetJoinsTheFlushThatInsertsTheOrder(): void
    {
        $entry = $this->createOrderEntry(managed: false);
        $writer = new TimelineEntryWriter($this->entityManager);

        self::assertTrue($writer->persist($entry));
        self::assertSame([], $this->persisted, 'The order is never cascade-persisted.');

        $metadata = new ClassMetadata(TimelineEntry::class);
        $this->entityManager->method('getClassMetadata')->willReturn($metadata);
        $this->unitOfWork->expects(self::once())->method('computeChangeSet')->with($metadata, $entry);

        $writer->onFlush(new OnFlushEventArgs($this->entityManager));
        self::assertSame([], $this->persisted, 'Still not persisted by the application.');

        $order = $entry->getOrder();
        self::assertNotNull($order);
        $this->managed[] = $order;
        $writer->onFlush(new OnFlushEventArgs($this->entityManager));
        self::assertSame([$entry], $this->persisted);

        $writer->onFlush(new OnFlushEventArgs($this->entityManager));
        self::assertSame([$entry], $this->persisted, 'Persisted once.');
    }

    public function testPendingEntriesAreDroppedOnReset(): void
    {
        $entry = $this->createOrderEntry(managed: false);
        $writer = new TimelineEntryWriter($this->entityManager);
        $writer->persist($entry);
        $writer->reset();

        $order = $entry->getOrder();
        self::assertNotNull($order);
        $this->managed[] = $order;
        $writer->onFlush(new OnFlushEventArgs($this->entityManager));

        self::assertSame([], $this->persisted);
    }

    public function testADetachedAdministratorIsReplacedByTheNameSnapshot(): void
    {
        $entry = $this->createOrderEntry(managed: true);
        $entry->setAdminUser(new AdminUser());
        $entry->setAuthorName('John Doe');

        (new TimelineEntryWriter($this->entityManager))->persist($entry);

        self::assertNull($entry->getAdminUser());
        self::assertSame('John Doe', $entry->getAuthorName());
    }

    public function testSaveFlushesWhenTheEntryIsTheOnlyPendingChange(): void
    {
        $entry = $this->createOrderEntry(managed: true);
        $this->unitOfWork->method('getScheduledEntityInsertions')->willReturn([spl_object_id($entry) => $entry]);
        $this->unitOfWork->method('getScheduledEntityUpdates')->willReturn([]);
        $this->unitOfWork->method('getScheduledEntityDeletions')->willReturn([]);
        $this->unitOfWork->method('getScheduledCollectionUpdates')->willReturn([]);
        $this->unitOfWork->method('getScheduledCollectionDeletions')->willReturn([]);
        $this->entityManager->expects(self::once())->method('flush');

        self::assertTrue((new TimelineEntryWriter($this->entityManager))->save($entry));
    }

    public function testSaveNeverFlushesChangesOfTheApplication(): void
    {
        $entry = $this->createOrderEntry(managed: true);
        $this->unitOfWork->method('getScheduledEntityInsertions')->willReturn([spl_object_id($entry) => $entry]);
        $this->unitOfWork->method('getScheduledEntityUpdates')->willReturn([new Customer()]);
        $this->unitOfWork->method('getScheduledEntityDeletions')->willReturn([]);
        $this->unitOfWork->method('getScheduledCollectionUpdates')->willReturn([]);
        $this->unitOfWork->method('getScheduledCollectionDeletions')->willReturn([]);
        $this->entityManager->expects(self::never())->method('flush');

        self::assertTrue((new TimelineEntryWriter($this->entityManager))->save($entry));
        self::assertSame([$entry], $this->persisted, 'Left for the next flush of the application.');
    }

    public function testSaveIgnoresOrdersThatAreNotPersisted(): void
    {
        $this->entityManager->expects(self::never())->method('flush');

        self::assertFalse((new TimelineEntryWriter($this->entityManager))->save($this->createOrderEntry(managed: false)));
        self::assertSame([], $this->persisted);
    }

    private function createOrderEntry(bool $managed): TimelineEntryInterface
    {
        $order = new Order();
        $customer = new Customer();
        $order->setCustomer($customer);
        if ($managed) {
            $this->managed[] = $order;
            $this->managed[] = $customer;
        }

        $entry = new TimelineEntry();
        $entry->setType(TimelineEntryInterface::TYPE_ORDER_STATE);
        $entry->setOrder($order);
        $entry->setCustomer($customer);

        return $entry;
    }
}
