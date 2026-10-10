<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTimelinePlugin\Functional;

use Sylius\Component\Core\Model\AdminUserInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntry;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;

final class AdminOrderTimelineTest extends WebTestCase
{
    use TimelineTestTrait;

    protected function setUp(): void
    {
        $this->setUpClient();
    }

    protected function tearDown(): void
    {
        $this->tearDownClient();
        parent::tearDown();
    }

    public function testTheOrderPageShowsTheTimelineRecordedWhenTheFixturesWerePlaced(): void
    {
        $order = $this->findPlacedOrder();
        $this->loginAdmin();

        $crawler = $this->client->request('GET', $this->orderUrl($order));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-test-timeline-card]');
        self::assertSelectorExists('[data-test-note-form]');
        $labels = $this->entryLabels($crawler);
        self::assertContains('Order placed', $labels);
        self::assertContains('Payment completed', $labels);
        self::assertSame('Order placed', end($labels), 'Entries are listed newest first.');
        self::assertStringNotContainsString('tmonier_timeline.', $crawler->filter('body')->text());
    }

    public function testAnAdministratorAddsAnInternalNote(): void
    {
        $order = $this->findPlacedOrder();
        $this->loginAdmin();

        $this->client->request('GET', $this->orderUrl($order));
        $this->client->submitForm('Add note', [
            'content' => "Call the customer <b>before</b> shipping.\nThe parcel is fragile.",
        ]);

        self::assertResponseRedirects($this->orderUrl($order));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-sylius-flash-message]', 'The note has been added.');

        $content = $crawler->filter('[data-test-timeline-entry="note"] [data-test-timeline-entry-content]');
        self::assertCount(1, $content);
        self::assertStringContainsString('Call the customer <b>before</b> shipping.', $content->text(), 'HTML is escaped.');
        self::assertCount(0, $content->filter('b'), 'HTML is escaped.');
        self::assertCount(1, $content->filter('br'), 'Line breaks are kept.');
        self::assertSame('John Doe', trim($crawler->filter('[data-test-timeline-entry="note"] [data-test-timeline-entry-author]')->text()));

        $notes = $this->findEntries($order, TimelineEntryInterface::TYPE_NOTE);
        self::assertCount(1, $notes);
        self::assertSame("Call the customer <b>before</b> shipping.\nThe parcel is fragile.", $notes[0]->getContent());
        self::assertSame(TimelineEntryInterface::AUTHOR_ADMIN, $notes[0]->getAuthorType());
        self::assertSame($this->customerOf($order)->getId(), $notes[0]->getCustomer()?->getId());
    }

    public function testEmptyAndTooLongNotesAreRejected(): void
    {
        $order = $this->findPlacedOrder();
        $this->loginAdmin();

        $this->client->request('GET', $this->orderUrl($order));
        $this->client->submitForm('Add note', ['content' => "  \n "]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-sylius-flash-message-type="error"]', 'The note cannot be empty.');

        $this->client->submitForm('Add note', ['content' => str_repeat('a', TimelineEntryInterface::NOTE_MAX_LENGTH + 1)]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-sylius-flash-message-type="error"]', 'The note cannot be longer than 5000 characters.');

        self::assertSame([], $this->findEntries($order, TimelineEntryInterface::TYPE_NOTE));
    }

    public function testAnInvalidCsrfTokenIsRejected(): void
    {
        $order = $this->findPlacedOrder();
        $admin = $this->loginAdmin();
        $note = $this->addOrderNote($order, 'Existing note', $admin);

        $this->client->request('POST', sprintf('/admin/orders/%d/timeline/notes', $this->idOf($order)), [
            'content' => 'Forged note',
            '_csrf_token' => 'invalid',
        ]);
        self::assertResponseStatusCodeSame(400);

        $this->client->request('POST', sprintf('/admin/timeline/notes/%d/delete', $this->idOf($note)), ['_csrf_token' => 'invalid']);
        self::assertResponseStatusCodeSame(400);

        $this->client->request('POST', sprintf('/admin/timeline/notes/%d/pin', $this->idOf($note)), ['_csrf_token' => 'invalid']);
        self::assertResponseStatusCodeSame(400);

        self::assertCount(1, $this->findEntries($order, TimelineEntryInterface::TYPE_NOTE));
        self::assertFalse($note->isPinned());
    }

    public function testNotesAreOnlyAvailableToAdministrators(): void
    {
        $order = $this->findPlacedOrder();

        $this->client->request('POST', sprintf('/admin/orders/%d/timeline/notes', $this->idOf($order)), ['content' => 'Anonymous']);

        self::assertResponseRedirects();
        self::assertStringContainsString('/admin/login', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame([], $this->findEntries($order, TimelineEntryInterface::TYPE_NOTE));
    }

    public function testAnAdministratorPinsAndUnpinsANote(): void
    {
        $order = $this->findPlacedOrder();
        $admin = $this->loginAdmin();
        $this->addOrderNote($order, 'VIP customer, gift wrap everything', $admin);

        $crawler = $this->client->request('GET', $this->orderUrl($order));
        self::assertSelectorNotExists('[data-test-pinned-notes]');

        $this->client->submit($crawler->filter('[data-test-pin-note]')->form());
        self::assertResponseRedirects($this->orderUrl($order));
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextContains('[data-test-pinned-notes]', 'VIP customer, gift wrap everything');
        self::assertSelectorExists('[data-test-unpin-note]');

        $this->client->submit($crawler->filter('[data-test-unpin-note]')->form());
        $this->client->followRedirect();
        self::assertSelectorNotExists('[data-test-pinned-notes]');
    }

    public function testTheAuthorDeletesTheirNote(): void
    {
        $order = $this->findPlacedOrder();
        $admin = $this->loginAdmin();
        $this->addOrderNote($order, 'Wrong order, ignore', $admin);

        $crawler = $this->client->request('GET', $this->orderUrl($order));
        $this->client->submit($crawler->filter('[data-test-delete-note]')->form());

        self::assertResponseRedirects($this->orderUrl($order));
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-sylius-flash-message]', 'The note has been deleted.');
        self::assertSame([], $this->findEntries($order, TimelineEntryInterface::TYPE_NOTE));
    }

    public function testAnotherAdministratorCannotDeleteTheNoteButCanPinIt(): void
    {
        $order = $this->findPlacedOrder();
        $otherAdmin = $this->entityManager()->getRepository(AdminUserInterface::class)->findOneBy(['username' => 'api']);
        self::assertInstanceOf(AdminUserInterface::class, $otherAdmin);
        $note = $this->addOrderNote($order, 'Written by a colleague', $otherAdmin);
        $this->loginAdmin();

        $crawler = $this->client->request('GET', $this->orderUrl($order));
        self::assertSelectorNotExists('[data-test-delete-note]');
        self::assertSelectorExists('[data-test-pin-note]');

        $token = $crawler->filter('[data-test-note-actions] input[name="_csrf_token"]')->first()->attr('value');
        self::assertNotNull($token);
        $this->client->request('POST', sprintf('/admin/timeline/notes/%d/delete', $this->idOf($note)), ['_csrf_token' => $token]);
        self::assertResponseStatusCodeSame(403);

        self::assertNotNull($this->entityManager()->getRepository(TimelineEntry::class)->find($this->idOf($note)));
    }

    public function testCancellingTheOrderIsRecordedWithTheAdministrator(): void
    {
        $order = $this->findPlacedOrder('awaiting_payment');
        $this->loginAdmin();

        $crawler = $this->client->request('GET', $this->orderUrl($order));
        $this->client->submit($crawler->filter('[data-test-cancel-order]')->form());
        self::assertResponseRedirects();

        $cancellations = $this->findEntries($order, TimelineEntryInterface::TYPE_ORDER_STATE);
        self::assertCount(1, $cancellations);
        self::assertSame('cancel', $cancellations[0]->getTransition());
        self::assertSame('new', $cancellations[0]->getFromState());
        self::assertSame('cancelled', $cancellations[0]->getToState());
        self::assertSame(TimelineEntryInterface::AUTHOR_ADMIN, $cancellations[0]->getAuthorType());
        self::assertSame('John Doe', $cancellations[0]->getAuthorName());

        $paymentStates = $this->findEntries($order, TimelineEntryInterface::TYPE_PAYMENT_STATE);
        self::assertSame(['cancel'], array_map(static fn (TimelineEntryInterface $entry): ?string => $entry->getTransition(), $paymentStates));

        $crawler = $this->client->followRedirect();
        $labels = $this->entryLabels($crawler);
        self::assertContains('Order cancelled', $labels);
        self::assertContains('Order payment cancelled', $labels);
    }

    public function testShippingIsRecordedWithTheTrackingCodeAndTheConfirmationEmail(): void
    {
        $order = $this->findPlacedOrder();
        $this->loginAdmin();

        $crawler = $this->client->request('GET', $this->orderUrl($order));
        $form = $crawler->filter('form[action*="/ship"]')->form();
        $trackingField = array_values(array_filter(array_keys($form->all()), static fn (string $name): bool => str_contains($name, '[tracking]')));
        self::assertCount(1, $trackingField);
        $form[$trackingField[0]] = 'TRACK-42';
        $this->client->submit($form);
        self::assertResponseRedirects();

        $shipments = $this->findEntries($order, TimelineEntryInterface::TYPE_SHIPMENT);
        self::assertCount(1, $shipments);
        self::assertSame('ship', $shipments[0]->getTransition());
        self::assertSame('TRACK-42', $shipments[0]->getContextValue('tracking'));
        self::assertNotNull($shipments[0]->getContextValue('method'));

        $emails = $this->findEntries($order, TimelineEntryInterface::TYPE_EMAIL);
        self::assertCount(1, $emails);
        self::assertSame('shipment_confirmation', $emails[0]->getContextValue('code'));
        self::assertSame([$this->customerOf($order)->getEmail()], $emails[0]->getContextValue('recipients'));
        self::assertNotNull($emails[0]->getId(), 'The e-mail entry is flushed even though it is recorded after the application flush.');

        $crawler = $this->client->followRedirect();
        $labels = $this->entryLabels($crawler);
        self::assertContains('Shipment shipped', $labels);
        self::assertContains('Order shipped', $labels);
        self::assertContains('E-mail sent: shipment confirmation', $labels);
        self::assertStringContainsString('TRACK-42', $crawler->filter('[data-test-timeline]')->text());
    }

    public function testResendingTheOrderConfirmationIsRecorded(): void
    {
        $order = $this->findPlacedOrder();
        $this->loginAdmin();

        $crawler = $this->client->request('GET', $this->orderUrl($order));
        $this->client->click($crawler->filter('[data-test-resend-order-confirmation-email]')->link());
        self::assertResponseRedirects($this->orderUrl($order));

        $emails = $this->findEntries($order, TimelineEntryInterface::TYPE_EMAIL);
        self::assertCount(1, $emails);
        self::assertSame('order_confirmation_resent', $emails[0]->getContextValue('code'));
        self::assertSame(TimelineEntryInterface::AUTHOR_ADMIN, $emails[0]->getAuthorType());

        $this->client->followRedirect();
        self::assertContains('E-mail sent: order confirmation (resent)', $this->entryLabels($this->client->getCrawler()));
    }

    public function testTheOrdersGridShowsTheNumberOfInternalNotes(): void
    {
        $order = $this->findPlacedOrder();
        $admin = $this->loginAdmin();
        $this->addOrderNote($order, 'First', $admin);
        $this->addOrderNote($order, 'Second', $admin);

        $crawler = $this->client->request('GET', sprintf('/admin/orders/?criteria[number][value]=%s', (string) $order->getNumber()));

        self::assertResponseIsSuccessful();
        self::assertSame('2', trim($crawler->filter('[data-test-order-notes-count]')->text()));
    }
}
