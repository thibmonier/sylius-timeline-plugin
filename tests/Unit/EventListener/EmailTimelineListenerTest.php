<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTimelinePlugin\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Mailer\Event\EmailSendEvent;
use Sylius\Component\Mailer\Model\Email;
use Tmonier\SyliusTimelinePlugin\EventListener\EmailTimelineListener;
use Tmonier\SyliusTimelinePlugin\Recorder\TimelineRecorderInterface;

final class EmailTimelineListenerTest extends TestCase
{
    public function testItPassesTheEmailToTheRecorder(): void
    {
        $order = new Order();
        $recorder = $this->createMock(TimelineRecorderInterface::class);
        $recorder->expects(self::once())->method('recordEmail')->with('order_confirmation', ['jane@example.com'], ['order' => $order]);

        (new EmailTimelineListener($recorder, $this->createMock(LoggerInterface::class)))($this->createEvent(['order' => $order]));
    }

    public function testAFailureIsLoggedAndNeverBreaksTheMailer(): void
    {
        $recorder = $this->createMock(TimelineRecorderInterface::class);
        $recorder->method('recordEmail')->willThrowException(new \RuntimeException('boom'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        (new EmailTimelineListener($recorder, $logger))($this->createEvent([]));
    }

    /** @param array<string, mixed> $data */
    private function createEvent(array $data): EmailSendEvent
    {
        $email = new Email();
        $email->setCode('order_confirmation');

        return new EmailSendEvent(null, $email, $data, ['jane@example.com']);
    }
}
