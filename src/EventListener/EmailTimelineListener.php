<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\EventListener;

use Psr\Log\LoggerInterface;
use Sylius\Component\Mailer\Event\EmailSendEvent;
use Tmonier\SyliusTimelinePlugin\Recorder\TimelineRecorderInterface;

/** Listens to `sylius.email_send.post_send` (`SyliusMailerEvents::EMAIL_POST_SEND`). */
final readonly class EmailTimelineListener
{
    public function __construct(
        private TimelineRecorderInterface $recorder,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(EmailSendEvent $event): void
    {
        $code = (string) $event->getEmail()->getCode();

        try {
            $this->recorder->recordEmail($code, $event->getRecipients(), $event->getData());
        } catch (\Throwable $exception) {
            $this->logger->error('Could not record the "{code}" e-mail in the timeline: {message}', [
                'code' => $code,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }
    }
}
