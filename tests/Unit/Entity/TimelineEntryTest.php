<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTimelinePlugin\Unit\Entity;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\AdminUser;
use Sylius\Component\Core\Model\AdminUserInterface;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntry;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;

final class TimelineEntryTest extends TestCase
{
    public function testItHasSafeDefaults(): void
    {
        $entry = new TimelineEntry();

        self::assertSame(TimelineEntryInterface::AUTHOR_SYSTEM, $entry->getAuthorType());
        self::assertFalse($entry->isPinned());
        self::assertSame([], $entry->getContext());
        self::assertNull($entry->getContextValue('missing'));
        self::assertFalse($entry->isNote());
    }

    public function testItKnowsItsAuthor(): void
    {
        $author = $this->createAdmin(1);
        $entry = new TimelineEntry();
        $entry->setType(TimelineEntryInterface::TYPE_NOTE);

        self::assertTrue($entry->isNote());
        self::assertFalse($entry->isAuthoredBy($author));

        $entry->setAdminUser($author);
        self::assertTrue($entry->isAuthoredBy($author));
        self::assertTrue($entry->isAuthoredBy($this->createAdmin(1)), 'Another instance of the same account (e.g. a proxy).');
        self::assertFalse($entry->isAuthoredBy($this->createAdmin(2)));
        self::assertFalse($entry->isAuthoredBy(new AdminUser()), 'An unsaved user is never the author.');
    }

    private function createAdmin(int $id): AdminUserInterface
    {
        $admin = new AdminUser();
        (new \ReflectionProperty($admin, 'id'))->setValue($admin, $id);

        return $admin;
    }
}
