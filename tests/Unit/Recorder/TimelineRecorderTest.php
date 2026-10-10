<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTimelinePlugin\Unit\Recorder;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\AdminUser;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShippingMethod;
use Symfony\Component\Clock\MockClock;
use Tests\Tmonier\SyliusTimelinePlugin\Unit\TimelineEntryResourceFactory;
use Tmonier\SyliusTimelinePlugin\DependencyInjection\Configuration;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;
use Tmonier\SyliusTimelinePlugin\Factory\TimelineEntryFactory;
use Tmonier\SyliusTimelinePlugin\Model\Actor;
use Tmonier\SyliusTimelinePlugin\Recorder\ActorResolverInterface;
use Tmonier\SyliusTimelinePlugin\Recorder\TimelineEntryWriterInterface;
use Tmonier\SyliusTimelinePlugin\Recorder\TimelineRecorder;

final class TimelineRecorderTest extends TestCase
{
    private InMemoryWriter $writer;

    private ?Actor $currentActor = null;

    protected function setUp(): void
    {
        $this->writer = new InMemoryWriter();
        $this->currentActor = null;
    }

    public function testACancellationIsRecordedWithTheAuthenticatedAdministrator(): void
    {
        $admin = new AdminUser();
        $admin->setFirstName('John');
        $admin->setLastName('Doe');
        $this->currentActor = Actor::admin($admin);
        $order = $this->createPlacedOrder();

        $entries = $this->recorder()->recordTransition($order, 'sylius_order', 'cancel', 'new', 'cancelled');

        self::assertCount(1, $entries);
        self::assertSame($entries, $this->writer->persisted);
        $entry = $entries[0];
        self::assertSame(TimelineEntryInterface::TYPE_ORDER_STATE, $entry->getType());
        self::assertSame('sylius_order', $entry->getWorkflow());
        self::assertSame('cancel', $entry->getTransition());
        self::assertSame('new', $entry->getFromState());
        self::assertSame('cancelled', $entry->getToState());
        self::assertSame($order, $entry->getOrder());
        self::assertSame($order->getCustomer(), $entry->getCustomer());
        self::assertSame('John Doe', $entry->getAuthorName());
        self::assertSame($admin, $entry->getAdminUser());
    }

    public function testWithoutAuthenticatedUserTheSystemIsTheAuthor(): void
    {
        $entries = $this->recorder()->recordTransition($this->createPlacedOrder(), 'sylius_order_payment', 'pay', 'awaiting_payment', 'paid');

        self::assertSame(TimelineEntryInterface::TYPE_PAYMENT_STATE, $entries[0]->getType());
        self::assertSame(TimelineEntryInterface::AUTHOR_SYSTEM, $entries[0]->getAuthorType());
    }

    public function testPaymentTransitionsKeepAPaymentSnapshot(): void
    {
        $order = $this->createPlacedOrder();
        $method = new PaymentMethod();
        $method->setCurrentLocale('en_US');
        $method->setFallbackLocale('en_US');
        $method->setCode('bank_transfer');
        $method->setName('Bank transfer');
        $payment = new Payment();
        $payment->setMethod($method);
        $payment->setAmount(1999);
        $payment->setCurrencyCode('EUR');
        $order->addPayment($payment);

        $entries = $this->recorder()->recordTransition($payment, 'sylius_payment', 'complete', 'new', 'completed');

        self::assertSame(TimelineEntryInterface::TYPE_PAYMENT, $entries[0]->getType());
        self::assertSame($order, $entries[0]->getOrder());
        self::assertSame('Bank transfer', $entries[0]->getContextValue('method'));
        self::assertSame(1999, $entries[0]->getContextValue('amount'));
        self::assertSame('EUR', $entries[0]->getContextValue('currency'));
    }

    public function testShipmentTransitionsKeepTheMethodAndTheTrackingCode(): void
    {
        $order = $this->createPlacedOrder();
        $method = new ShippingMethod();
        $method->setCurrentLocale('en_US');
        $method->setFallbackLocale('en_US');
        $method->setCode('dhl');
        $method->setName('DHL Express');
        $shipment = new Shipment();
        $shipment->setMethod($method);
        $shipment->setTracking('TRACK-1');
        $order->addShipment($shipment);

        $entries = $this->recorder()->recordTransition($shipment, 'sylius_shipment', 'ship', 'ready', 'shipped');

        self::assertSame(TimelineEntryInterface::TYPE_SHIPMENT, $entries[0]->getType());
        self::assertSame(['method' => 'DHL Express', 'tracking' => 'TRACK-1'], $entries[0]->getContext());
    }

    public function testPlacingAnOrderRecordsTheCustomerAndTheirCheckoutNote(): void
    {
        $order = $this->createPlacedOrder();
        $order->setNumber('000042');
        $order->setCurrencyCode('EUR');
        $order->setNotes("  Leave it with the neighbour\nThanks  ");
        $order->setCheckoutCompletedAt(new \DateTime('2026-10-01 10:00:00'));

        $entries = $this->recorder()->recordTransition($order, 'sylius_order', 'create', 'cart', 'new');

        self::assertCount(2, $entries);
        [$placed, $customerNote] = $entries;
        self::assertSame(TimelineEntryInterface::TYPE_ORDER_PLACED, $placed->getType());
        self::assertSame(TimelineEntryInterface::AUTHOR_CUSTOMER, $placed->getAuthorType());
        self::assertSame('Jane Roe', $placed->getAuthorName());
        self::assertSame(['number' => '000042', 'total' => 0, 'currency' => 'EUR'], $placed->getContext());
        self::assertEquals(new \DateTimeImmutable('2026-10-01 10:00:00'), $placed->getCreatedAt());

        self::assertSame(TimelineEntryInterface::TYPE_CUSTOMER_NOTE, $customerNote->getType());
        self::assertSame("Leave it with the neighbour\nThanks", $customerNote->getContent());
        self::assertSame(TimelineEntryInterface::AUTHOR_CUSTOMER, $customerNote->getAuthorType());
    }

    public function testAnOrderWithoutNoteOnlyRecordsThePlacement(): void
    {
        $entries = $this->recorder()->recordTransition($this->createPlacedOrder(), 'sylius_order', 'create', 'cart', 'new');

        self::assertCount(1, $entries);
    }

    public function testBookkeepingTransitionsDisabledWorkflowsCartsAndOtherSubjectsAreIgnored(): void
    {
        $order = $this->createPlacedOrder();
        $cart = new Order();

        self::assertSame([], $this->recorder()->recordTransition($order, 'sylius_order_payment', 'request_payment', 'cart', 'awaiting_payment'));
        self::assertSame([], $this->recorder()->recordTransition($order, 'sylius_order_checkout', 'complete', 'completed', 'completed'));
        self::assertSame([], $this->recorder(['sylius_order'])->recordTransition($order, 'sylius_order_payment', 'pay', 'awaiting_payment', 'paid'));
        self::assertSame([], $this->recorder()->recordTransition($cart, 'sylius_order', 'cancel', 'cart', 'cancelled'));
        self::assertSame([], $this->recorder()->recordTransition(new \stdClass(), 'sylius_order', 'cancel', 'new', 'cancelled'));
        self::assertSame([], $this->writer->persisted);
    }

    public function testOtherConfiguredWorkflowsAreRecordedAsGenericTransitions(): void
    {
        $entries = $this->recorder(['app_order_review'])->recordTransition($this->createPlacedOrder(), 'app_order_review', 'approve', 'pending', 'approved');

        self::assertSame(TimelineEntryInterface::TYPE_TRANSITION, $entries[0]->getType());
        self::assertSame('app_order_review', $entries[0]->getWorkflow());
    }

    public function testAnEmailAboutAnOrderIsSavedWithItsRecipients(): void
    {
        $order = $this->createPlacedOrder();

        $entry = $this->recorder()->recordEmail('order_confirmation', ['jane@example.com' => 'Jane Roe', 'shop@example.com'], ['order' => $order, 'channel' => null]);

        self::assertNotNull($entry);
        self::assertSame([$entry], $this->writer->saved);
        self::assertSame(TimelineEntryInterface::TYPE_EMAIL, $entry->getType());
        self::assertSame(['code' => 'order_confirmation', 'recipients' => ['jane@example.com', 'shop@example.com']], $entry->getContext());
    }

    public function testAShipmentEmailIsAttachedToTheOrderOfTheShipment(): void
    {
        $order = $this->createPlacedOrder();
        $shipment = new Shipment();
        $order->addShipment($shipment);

        $entry = $this->recorder()->recordEmail('shipment_confirmation', ['jane@example.com'], ['shipment' => $shipment]);

        self::assertSame($order, $entry?->getOrder());
    }

    public function testEmailsWithoutOrderOrWhenDisabledAreIgnored(): void
    {
        self::assertNull($this->recorder()->recordEmail('user_registration', ['jane@example.com'], ['user' => new \stdClass()]));
        self::assertNull($this->recorder()->recordEmail('order_confirmation', ['jane@example.com'], ['order' => new Order()]), 'Unsaved order.');
        self::assertNull($this->recorder(recordEmails: false)->recordEmail('order_confirmation', ['jane@example.com'], ['order' => $this->createPlacedOrder()]));
        self::assertSame([], $this->writer->saved);
    }

    /** @param list<string> $workflows */
    private function recorder(array $workflows = Configuration::DEFAULT_WORKFLOWS, bool $recordEmails = true): TimelineRecorder
    {
        $actorResolver = new class($this) implements ActorResolverInterface {
            public function __construct(private readonly TimelineRecorderTest $test)
            {
            }

            public function resolve(?Actor $fallback = null): Actor
            {
                return $this->test->currentActor() ?? $fallback ?? Actor::system();
            }
        };

        return new TimelineRecorder(
            new TimelineEntryFactory(new TimelineEntryResourceFactory(), new MockClock()),
            $this->writer,
            $actorResolver,
            $workflows,
            $recordEmails,
        );
    }

    public function currentActor(): ?Actor
    {
        return $this->currentActor;
    }

    private function createPlacedOrder(): OrderInterface
    {
        $customer = new Customer();
        $customer->setFirstName('Jane');
        $customer->setLastName('Roe');

        $order = new Order();
        $order->setCustomer($customer);
        $order->setState(OrderInterface::STATE_NEW);
        (new \ReflectionProperty($order, 'id'))->setValue($order, 42);

        return $order;
    }
}

final class InMemoryWriter implements TimelineEntryWriterInterface
{
    /** @var list<TimelineEntryInterface> */
    public array $persisted = [];

    /** @var list<TimelineEntryInterface> */
    public array $saved = [];

    public function persist(TimelineEntryInterface $entry): bool
    {
        $this->persisted[] = $entry;

        return true;
    }

    public function save(TimelineEntryInterface $entry): bool
    {
        $this->saved[] = $entry;

        return true;
    }
}
