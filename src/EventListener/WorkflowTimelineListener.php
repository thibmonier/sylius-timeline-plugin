<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Tmonier\SyliusTimelinePlugin\Recorder\TimelineRecorderInterface;

/**
 * Listens to `workflow.completed` (every workflow) and lets the recorder filter on the
 * configured workflows. Recording must never break a checkout or a back-office action.
 */
final readonly class WorkflowTimelineListener
{
    public function __construct(
        private TimelineRecorderInterface $recorder,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $transition = $event->getTransition();
        if (!$transition instanceof \Symfony\Component\Workflow\Transition) {
            return;
        }

        // Sylius workflows are state machines: one "from" and one "to" place per transition.
        $from = $transition->getFroms()[0] ?? null;
        $to = $transition->getTos()[0] ?? null;

        try {
            $this->recorder->recordTransition(
                $event->getSubject(),
                $event->getWorkflowName(),
                $transition->getName(),
                is_string($from) ? $from : null,
                is_string($to) ? $to : null,
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Could not record the "{workflow}.{transition}" transition in the timeline: {message}', [
                'workflow' => $event->getWorkflowName(),
                'transition' => $transition->getName(),
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }
    }
}
