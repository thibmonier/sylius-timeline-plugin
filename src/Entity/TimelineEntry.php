<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\Entity;

use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;

class TimelineEntry implements TimelineEntryInterface
{
    protected ?int $id = null;

    protected ?OrderInterface $order = null;

    protected ?CustomerInterface $customer = null;

    protected ?string $type = null;

    protected ?string $workflow = null;

    protected ?string $transition = null;

    protected ?string $fromState = null;

    protected ?string $toState = null;

    /** @var array<string, mixed> */
    protected array $context = [];

    protected ?string $content = null;

    protected bool $pinned = false;

    protected string $authorType = self::AUTHOR_SYSTEM;

    protected ?AdminUserInterface $adminUser = null;

    protected ?string $authorName = null;

    protected ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): ?OrderInterface
    {
        return $this->order;
    }

    public function setOrder(?OrderInterface $order): void
    {
        $this->order = $order;
    }

    public function getCustomer(): ?CustomerInterface
    {
        return $this->customer;
    }

    public function setCustomer(?CustomerInterface $customer): void
    {
        $this->customer = $customer;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): void
    {
        $this->type = $type;
    }

    public function isNote(): bool
    {
        return self::TYPE_NOTE === $this->type;
    }

    public function getWorkflow(): ?string
    {
        return $this->workflow;
    }

    public function setWorkflow(?string $workflow): void
    {
        $this->workflow = $workflow;
    }

    public function getTransition(): ?string
    {
        return $this->transition;
    }

    public function setTransition(?string $transition): void
    {
        $this->transition = $transition;
    }

    public function getFromState(): ?string
    {
        return $this->fromState;
    }

    public function setFromState(?string $fromState): void
    {
        $this->fromState = $fromState;
    }

    public function getToState(): ?string
    {
        return $this->toState;
    }

    public function setToState(?string $toState): void
    {
        $this->toState = $toState;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function setContext(array $context): void
    {
        $this->context = $context;
    }

    public function getContextValue(string $key): mixed
    {
        return $this->context[$key] ?? null;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): void
    {
        $this->content = $content;
    }

    public function isPinned(): bool
    {
        return $this->pinned;
    }

    public function setPinned(bool $pinned): void
    {
        $this->pinned = $pinned;
    }

    public function getAuthorType(): string
    {
        return $this->authorType;
    }

    public function setAuthorType(string $authorType): void
    {
        $this->authorType = $authorType;
    }

    public function getAdminUser(): ?AdminUserInterface
    {
        return $this->adminUser;
    }

    public function setAdminUser(?AdminUserInterface $adminUser): void
    {
        $this->adminUser = $adminUser;
    }

    public function getAuthorName(): ?string
    {
        return $this->authorName;
    }

    public function setAuthorName(?string $authorName): void
    {
        $this->authorName = $authorName;
    }

    public function isAuthoredBy(AdminUserInterface $adminUser): bool
    {
        if (null === $this->adminUser) {
            return false;
        }

        if ($this->adminUser === $adminUser) {
            return true;
        }

        return null !== $adminUser->getId() && $this->adminUser->getId() === $adminUser->getId();
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }
}
