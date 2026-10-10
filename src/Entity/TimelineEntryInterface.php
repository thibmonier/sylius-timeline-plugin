<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\Entity;

use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Model\ResourceInterface;

interface TimelineEntryInterface extends ResourceInterface
{
    /** Checkout completed (`sylius_order` → `create`). */
    public const TYPE_ORDER_PLACED = 'order_placed';

    /** Note written by the customer during checkout (`Order::$notes`). */
    public const TYPE_CUSTOMER_NOTE = 'customer_note';

    /** `sylius_order` transitions (cancel, fulfill). */
    public const TYPE_ORDER_STATE = 'order_state';

    /** `sylius_order_payment` transitions. */
    public const TYPE_PAYMENT_STATE = 'payment_state';

    /** `sylius_order_shipping` transitions. */
    public const TYPE_SHIPPING_STATE = 'shipping_state';

    /** `sylius_payment` transitions of one payment. */
    public const TYPE_PAYMENT = 'payment';

    /** `sylius_shipment` transitions of one shipment. */
    public const TYPE_SHIPMENT = 'shipment';

    /** Transition of another configured workflow whose subject is an order, a payment or a shipment. */
    public const TYPE_TRANSITION = 'transition';

    /** E-mail sent by the Sylius mailer about an order. */
    public const TYPE_EMAIL = 'email';

    /** Internal staff note, on an order or on a customer. */
    public const TYPE_NOTE = 'note';

    public const AUTHOR_ADMIN = 'admin';

    public const AUTHOR_CUSTOMER = 'customer';

    public const AUTHOR_SYSTEM = 'system';

    public const NOTE_MAX_LENGTH = 5000;

    public function getId(): ?int;

    public function getOrder(): ?OrderInterface;

    public function setOrder(?OrderInterface $order): void;

    public function getCustomer(): ?CustomerInterface;

    public function setCustomer(?CustomerInterface $customer): void;

    public function getType(): ?string;

    public function setType(?string $type): void;

    public function isNote(): bool;

    public function getWorkflow(): ?string;

    public function setWorkflow(?string $workflow): void;

    public function getTransition(): ?string;

    public function setTransition(?string $transition): void;

    public function getFromState(): ?string;

    public function setFromState(?string $fromState): void;

    public function getToState(): ?string;

    public function setToState(?string $toState): void;

    /** @return array<string, mixed> */
    public function getContext(): array;

    /** @param array<string, mixed> $context */
    public function setContext(array $context): void;

    public function getContextValue(string $key): mixed;

    public function getContent(): ?string;

    public function setContent(?string $content): void;

    public function isPinned(): bool;

    public function setPinned(bool $pinned): void;

    public function getAuthorType(): string;

    public function setAuthorType(string $authorType): void;

    public function getAdminUser(): ?AdminUserInterface;

    public function setAdminUser(?AdminUserInterface $adminUser): void;

    /** Snapshot of the author's name, kept when the admin account is deleted. */
    public function getAuthorName(): ?string;

    public function setAuthorName(?string $authorName): void;

    public function isAuthoredBy(AdminUserInterface $adminUser): bool;

    public function getCreatedAt(): ?\DateTimeImmutable;

    public function setCreatedAt(\DateTimeImmutable $createdAt): void;
}
