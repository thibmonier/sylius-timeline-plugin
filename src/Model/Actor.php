<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\Model;

use Sylius\Component\Core\Model\AdminUserInterface;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;

/** Who caused a timeline entry. */
final class Actor
{
    private function __construct(
        public readonly string $type,
        public readonly ?string $name = null,
        public readonly ?AdminUserInterface $adminUser = null,
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
