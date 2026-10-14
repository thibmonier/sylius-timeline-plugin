<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\Recorder;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\OrderPaymentTransitions;
use Sylius\Component\Core\OrderShippingTransitions;
use Sylius\Component\Order\OrderTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Shipping\ShipmentTransitions;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;
use Tmonier\SyliusTimelinePlugin\Factory\TimelineEntryFactoryInterface;
use Tmonier\SyliusTimelinePlugin\Model\Actor;
use Tmonier\SyliusTimelinePlugin\Model\EntryContext;

final readonly class TimelineRecorder implements TimelineRecorderInterface
{
    public const TYPES_BY_WORKFLOW = [
        OrderTransitions::GRAPH => TimelineEntryInterface::TYPE_ORDER_STATE,
        OrderPaymentTransitions::GRAPH => TimelineEntryInterface::TYPE_PAYMENT_STATE,
        OrderShippingTransitions::GRAPH => TimelineEntryInterface::TYPE_SHIPPING_STATE,
        PaymentTransitions::GRAPH => TimelineEntryInterface::TYPE_PAYMENT,
        ShipmentTransitions::GRAPH => TimelineEntryInterface::TYPE_SHIPMENT,
    ];

    /**
     * Bookkeeping transitions applied automatically while an order is placed: they would only
     * repeat "order placed".
     */
    public const IGNORED_TRANSITIONS = [
        OrderPaymentTransitions::GRAPH => [OrderPaymentTransitions::TRANSITION_REQUEST_PAYMENT],
        OrderShippingTransitions::GRAPH => [OrderShippingTransitions::TRANSITION_REQUEST_SHIPPING],
        PaymentTransitions::GRAPH => [PaymentTransitions::TRANSITION_CREATE, PaymentTransitions::TRANSITION_PROCESS],
        ShipmentTransitions::GRAPH => [ShipmentTransitions::TRANSITION_CREATE],
    ];

    /** @param list<string> $enabledWorkflows */
    public function __construct(
        private TimelineEntryFactoryInterface $entryFactory,
        private TimelineEntryWriterInterface $writer,
        private ActorResolverInterface $actorResolver,
        private array $enabledWorkflows,
        private bool $recordEmails = true,
    ) {
    }

    public function recordTransition(object $subject, string $workflow, string $transition, ?string $fromState, ?string $toState): array
    {
        if (!in_array($workflow, $this->enabledWorkflows, true) || in_array($transition, self::IGNORED_TRANSITIONS[$workflow] ?? [], true)) {
            return [];
        }

        $order = $this->resolveOrder($subject);
        if (!$order instanceof \Sylius\Component\Core\Model\OrderInterface || OrderInterface::STATE_CART === $order->getState()) {
            return [];
        }

        if (OrderTransitions::GRAPH === $workflow && OrderTransitions::TRANSITION_CREATE === $transition) {
            return $this->recordOrderPlaced($order);
        }

        $entry = $this->entryFactory->createForOrder(
            $order,
            self::TYPES_BY_WORKFLOW[$workflow] ?? TimelineEntryInterface::TYPE_TRANSITION,
            $this->actorResolver->resolve(),
        );
        $entry->setWorkflow($workflow);
        $entry->setTransition($transition);
        $entry->setFromState($fromState);
        $entry->setToState($toState);

        if ($subject instanceof PaymentInterface) {
            $entry->setContext(EntryContext::forPayment($subject));
        } elseif ($subject instanceof ShipmentInterface) {
            $entry->setContext(EntryContext::forShipment($subject));
        }

        return $this->writer->persist($entry) ? [$entry] : [];
    }

    public function recordEmail(string $code, array $recipients, array $data): ?TimelineEntryInterface
    {
        if (!$this->recordEmails) {
            return null;
        }

        $order = $this->resolveOrder($data['order'] ?? $data['shipment'] ?? $data['payment'] ?? null);
        if (!$order instanceof \Sylius\Component\Core\Model\OrderInterface || null === $order->getId()) {
            return null;
        }

        $entry = $this->entryFactory->createForOrder($order, TimelineEntryInterface::TYPE_EMAIL, $this->actorResolver->resolve());
        $entry->setContext([
            'code' => $code,
            'recipients' => $this->normalizeRecipients($recipients),
        ]);

        return $this->writer->save($entry) ? $entry : null;
    }

    /** @return list<TimelineEntryInterface> */
    private function recordOrderPlaced(OrderInterface $order): array
    {
        $customer = $order->getCustomer();
        $actor = $this->actorResolver->resolve(Actor::customer($customer?->getFullName()));

        $placed = $this->entryFactory->createForOrder($order, TimelineEntryInterface::TYPE_ORDER_PLACED, $actor, $order->getCheckoutCompletedAt() instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($order->getCheckoutCompletedAt()) : null);
        $placed->setWorkflow(OrderTransitions::GRAPH);
        $placed->setTransition(OrderTransitions::TRANSITION_CREATE);
        $placed->setContext(EntryContext::forOrder($order));
        if (!$this->writer->persist($placed)) {
            return [];
        }

        $notes = trim((string) $order->getNotes());
        if ('' === $notes) {
            return [$placed];
        }

        $customerNote = $this->entryFactory->createForOrder($order, TimelineEntryInterface::TYPE_CUSTOMER_NOTE, Actor::customer($customer?->getFullName()), $placed->getCreatedAt());
        $customerNote->setContent($notes);

        return $this->writer->persist($customerNote) ? [$placed, $customerNote] : [$placed];
    }

    private function resolveOrder(mixed $subject): ?OrderInterface
    {
        if ($subject instanceof OrderInterface) {
            return $subject;
        }

        if ($subject instanceof PaymentInterface || $subject instanceof ShipmentInterface) {
            $order = $subject->getOrder();

            return $order instanceof OrderInterface ? $order : null;
        }

        return null;
    }

    /**
     * Sylius accepts both `['a@example.com']` and `['a@example.com' => 'Name']`.
     *
     * @param array<array-key, mixed> $recipients
     *
     * @return list<string>
     */
    private function normalizeRecipients(array $recipients): array
    {
        $addresses = [];
        foreach ($recipients as $key => $value) {
            $address = is_string($key) ? $key : $value;
            if (is_string($address) && '' !== $address) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }
}
