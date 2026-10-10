<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\Recorder;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Symfony\Contracts\Service\ResetInterface;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;

final class TimelineEntryWriter implements TimelineEntryWriterInterface, ResetInterface
{
    /**
     * Entries whose order is not persisted yet (e.g. created and completed in memory, then
     * persisted, like the Sylius fixtures do). They join the flush that inserts the order.
     *
     * @var list<TimelineEntryInterface>
     */
    private array $pending = [];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function persist(TimelineEntryInterface $entry): bool
    {
        $order = $entry->getOrder();
        if (null !== $order && !$this->entityManager->contains($order)) {
            // Never cascade-persist an order: if the application does not persist it, the
            // flush would fail and take the application's own change down with it.
            $this->pending[] = $entry;

            return true;
        }

        if (null === $order && null !== $entry->getCustomer() && !$this->entityManager->contains($entry->getCustomer())) {
            return false;
        }

        $this->doPersist($entry);

        return true;
    }

    public function save(TimelineEntryInterface $entry): bool
    {
        $order = $entry->getOrder();
        if (null !== $order && !$this->entityManager->contains($order)) {
            return false;
        }

        if (!$this->persist($entry)) {
            return false;
        }

        $unitOfWork = $this->entityManager->getUnitOfWork();
        $unitOfWork->computeChangeSets();

        $isAlone = [$entry] === array_values($unitOfWork->getScheduledEntityInsertions()) &&
            [] === $unitOfWork->getScheduledEntityUpdates() &&
            [] === $unitOfWork->getScheduledEntityDeletions() &&
            [] === $unitOfWork->getScheduledCollectionUpdates() &&
            [] === $unitOfWork->getScheduledCollectionDeletions();

        if ($isAlone) {
            $this->entityManager->flush();
        }

        return true;
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ([] === $this->pending) {
            return;
        }

        $entityManager = $args->getObjectManager();
        if ($entityManager !== $this->entityManager) {
            return;
        }

        $unitOfWork = $entityManager->getUnitOfWork();
        $stillPending = [];
        foreach ($this->pending as $entry) {
            $order = $entry->getOrder();
            if (null === $order || !$entityManager->contains($order)) {
                $stillPending[] = $entry;

                continue;
            }

            $this->doPersist($entry);
            $unitOfWork->computeChangeSet($entityManager->getClassMetadata($entry::class), $entry);
        }

        $this->pending = $stillPending;
    }

    public function reset(): void
    {
        $this->pending = [];
    }

    private function doPersist(TimelineEntryInterface $entry): void
    {
        $customer = $entry->getCustomer();
        if (null !== $customer && null !== $entry->getOrder() && !$this->entityManager->contains($customer)) {
            $entry->setCustomer(null);
        }

        $adminUser = $entry->getAdminUser();
        if (null !== $adminUser && !$this->entityManager->contains($adminUser)) {
            // Detached user (custom user provider): keep the name snapshot only.
            $entry->setAdminUser(null);
        }

        $this->entityManager->persist($entry);
    }
}
