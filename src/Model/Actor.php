<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\Model;

use Sylius\Component\Core\Model\AdminUserInterface;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;

/** Who caused a timeline entry. */
final readonly class Actor
{
    private function __construct(
        public string $type,
        public ?string $name = null,
        public ?AdminUserInterface $adminUser = null,
    ) {
    }

    public static function admin(AdminUserInterface $adminUser): self
    {
        $name = trim(sprintf('%s %s', $adminUser->getFirstName() ?? '', $adminUser->getLastName() ?? ''));
        if ('' === $name) {
            $name = $adminUser->getEmail() ?? $adminUser->getUsername();
        }

        return new self(TimelineEntryInterface::AUTHOR_ADMIN, $name, $adminUser);
    }

    public static function customer(?string $name = null): self
    {
        return new self(TimelineEntryInterface::AUTHOR_CUSTOMER, null === $name || '' === trim($name) ? null : trim($name));
    }

    public static function system(): self
    {
        return new self(TimelineEntryInterface::AUTHOR_SYSTEM);
    }

    public function isSystem(): bool
    {
        return TimelineEntryInterface::AUTHOR_SYSTEM === $this->type;
    }
}
