<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTimelinePlugin\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\Order;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;
use Tmonier\SyliusTimelinePlugin\EventListener\WorkflowTimelineListener;
use Tmonier\SyliusTimelinePlugin\Recorder\TimelineRecorderInterface;

final class WorkflowTimelineListenerTest extends TestCase
{
    public function testItPassesTheTransitionToTheRecorder(): void
    {
        $order = new Order();
        $recorder = $this->createMock(TimelineRecorderInterface::class);
        $recorder->expects(self::once())->method('recordTransition')->with($order, 'sylius_order', 'cancel', 'new', 'cancelled')->willReturn([]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        (new WorkflowTimelineListener($recorder, $logger))($this->createEvent($order));
    }

    public function testAFailureIsLoggedAndNeverBreaksTheTransition(): void
    {
        $recorder = $this->createMock(TimelineRecorderInterface::class);
        $recorder->method('recordTransition')->willThrowException(new \RuntimeException('Database is gone'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            self::stringContains('Could not record'),
            self::callback(static fn (array $context): bool => 'sylius_order' === $context['workflow'] && 'Database is gone' === $context['message']),
        );

        (new WorkflowTimelineListener($recorder, $logger))($this->createEvent(new Order()));
    }

    private function createEvent(object $subject): CompletedEvent
    {
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->method('getName')->willReturn('sylius_order');

        return new CompletedEvent($subject, new Marking(['cancelled' => 1]), new Transition('cancel', 'new', 'cancelled'), $workflow);
    }
}
