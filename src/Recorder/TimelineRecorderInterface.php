<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\Recorder;

use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;

interface TimelineRecorderInterface
{
    /**
     * Records a completed workflow transition of an order, a payment or a shipment.
     * Returns the entries scheduled for the next flush (none when the transition is not tracked).
     *
     * @return list<TimelineEntryInterface>
     */
    public function recordTransition(object $subject, string $workflow, string $transition, ?string $fromState, ?string $toState): array;

    /**
     * Records an e-mail sent by the Sylius mailer when its data references an order.
     *
     * @param array<array-key, mixed> $recipients
     * @param array<array-key, mixed> $data
     */
    public function recordEmail(string $code, array $recipients, array $data): ?TimelineEntryInterface;
}
