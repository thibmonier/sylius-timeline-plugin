<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\Recorder;

use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;

interface TimelineEntryWriterInterface
{
    /**
     * Schedules the entry for the next flush of the application, so that it is saved in the
     * same transaction as the change it describes (and not at all if that change is not).
     * An entry whose order is not persisted yet waits for the flush that inserts the order.
     * Returns false when the entry cannot be scheduled.
     */
    public function persist(TimelineEntryInterface $entry): bool;

    /**
     * Saves the entry now when the unit of work holds no other pending change, otherwise
     * leaves it scheduled for the application's next flush. Used for events that happen
     * after the application has flushed, such as e-mails.
     */
    public function save(TimelineEntryInterface $entry): bool;
}
