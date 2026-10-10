<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTimelinePlugin\Unit;

use Sylius\Resource\Factory\FactoryInterface;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntry;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;

/**
 * Stands for the `tmonier_timeline.factory.timeline_entry` resource factory.
 *
 * @implements FactoryInterface<TimelineEntryInterface>
 */
final class TimelineEntryResourceFactory implements FactoryInterface
{
    public function createNew(): TimelineEntryInterface
    {
        return new TimelineEntry();
    }
}
