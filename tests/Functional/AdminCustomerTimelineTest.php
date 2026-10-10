<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTimelinePlugin\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntry;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;
use Tmonier\SyliusTimelinePlugin\Model\Actor;

final class AdminCustomerTimelineTest extends WebTestCase
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

    public function testTheCustomerPageShowsCustomerNotesAndOrderEvents(): void
    {
        $order = $this->findPlacedOrder();
        $customer = $this->customerOf($order);
        $admin = $this->loginAdmin();
        $this->addOrderNote($order, 'Order-level note, not on the customer page', $admin);
        $customerUrl = sprintf('/admin/customers/%d', $this->idOf($customer));

        $crawler = $this->client->request('GET', $customerUrl);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-test-timeline-card]');
        self::assertContains('Order placed', $this->entryLabels($crawler));
        self::assertSelectorExists(sprintf('[data-test-timeline-entry-order][href="%s"]', $this->orderUrl($order)));
        self::assertStringNotContainsString('Order-level note', $crawler->filter('[data-test-timeline]')->text());
        self::assertStringNotContainsString('tmonier_timeline.', $crawler->filter('body')->text());

        $this->client->submitForm('Add note', ['content' => 'Prefers to be called after 6 pm']);
        self::assertResponseRedirects($customerUrl);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-timeline-entry="note"]', 'Prefers to be called after 6 pm');

        $this->client->submit($crawler->filter('[data-test-pin-note]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-pinned-notes]', 'Prefers to be called after 6 pm');

        $notes = $this->entityManager()->getRepository(TimelineEntry::class)->findBy(['customer' => $customer, 'order' => null]);
        self::assertCount(1, $notes);
        self::assertTrue($notes[0]->isPinned());
    }

    public function testLongCustomerTimelinesLinkToTheFullPaginatedPage(): void
    {
        $order = $this->findPlacedOrder();
        $customer = $this->customerOf($order);
        $admin = $this->loginAdmin();
        for ($i = 1; $i <= 55; ++$i) {
            $this->entityManager()->persist($this->entryFactory()->createCustomerNote($customer, sprintf('Note %d', $i), Actor::admin($admin)));
        }
        $this->entityManager()->flush();

        $crawler = $this->client->request('GET', sprintf('/admin/customers/%d', $this->idOf($customer)));
        self::assertCount(50, $crawler->filter('[data-test-timeline-entry]'));
        $this->client->click($crawler->filter('[data-test-timeline-show-more]')->link());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.page-header .page-title', 'Timeline');
        self::assertCount(50, $this->client->getCrawler()->filter('[data-test-timeline-entry]'));

        $total = count($this->entityManager()->getRepository(TimelineEntry::class)->findBy(['customer' => $customer]));
        $this->client->request('GET', sprintf('/admin/customers/%d/timeline?page=2', $this->idOf($customer)));
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $this->client->getCrawler()->filter('[data-test-timeline-entry]')->count());
        self::assertLessThanOrEqual($total - 50, $this->client->getCrawler()->filter('[data-test-timeline-entry]')->count());
        self::assertStringNotContainsString('tmonier_timeline.', $this->client->getCrawler()->filter('body')->text());
    }

    public function testInternalNotesAreNeverShownInTheShop(): void
    {
        $order = $this->findPlacedOrder();
        $customer = $this->customerOf($order);
        $admin = $this->loginAdmin();
        $this->addOrderNote($order, 'SECRET order note', $admin, true);
        $this->entityManager()->persist($this->entryFactory()->createCustomerNote($customer, 'SECRET customer note', Actor::admin($admin)));
        $this->entityManager()->flush();

        $shopUser = $customer->getUser();
        self::assertNotNull($shopUser);
        $this->client->loginUser($shopUser, 'shop');

        foreach ([
            sprintf('/en_US/account/orders/%s', (string) $order->getNumber()),
            '/en_US/account/orders/',
            '/en_US/account/dashboard',
        ] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            self::assertStringNotContainsString('SECRET', $crawler->filter('body')->text(), $url);
            self::assertSelectorNotExists('[data-test-timeline-card]');
        }

        self::assertSame(TimelineEntryInterface::TYPE_NOTE, $this->findEntries($order, TimelineEntryInterface::TYPE_NOTE)[0]->getType());
    }
}
