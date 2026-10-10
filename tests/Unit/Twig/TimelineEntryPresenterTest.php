<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTimelinePlugin\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntry;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;
use Tmonier\SyliusTimelinePlugin\Twig\TimelineEntryPresenter;

final class TimelineEntryPresenterTest extends TestCase
{
    private TimelineEntryPresenter $presenter;

    protected function setUp(): void
    {
        $translator = new Translator('fr');
        $translator->setFallbackLocales(['en']);
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', __DIR__ . '/../../../translations/messages.en.yaml', 'en');
        $translator->addResource('yaml', __DIR__ . '/../../../translations/messages.fr.yaml', 'fr');

        $this->presenter = new TimelineEntryPresenter($translator);
    }

    public function testWorkflowTransitionsHaveTheirOwnLabelIconAndColour(): void
    {
        $entry = $this->createEntry(TimelineEntryInterface::TYPE_ORDER_STATE, 'sylius_order', 'cancel');

        self::assertSame('Commande annulée', $this->presenter->getLabel($entry));
        self::assertSame('tabler:x', $this->presenter->getIcon($entry));
        self::assertSame('red', $this->presenter->getColor($entry));
    }

    public function testUnknownTransitionsFallBackToAGenericLabel(): void
    {
        $entry = $this->createEntry(TimelineEntryInterface::TYPE_TRANSITION, 'app_review', 'approve');

        self::assertSame('app_review : approve', $this->presenter->getLabel($entry));
        self::assertSame('tabler:arrows-split', $this->presenter->getIcon($entry));
    }

    public function testEmailsShowTheTranslatedEmailNameOrItsCode(): void
    {
        $entry = $this->createEntry(TimelineEntryInterface::TYPE_EMAIL);
        $entry->setContext(['code' => 'order_confirmation']);
        self::assertSame('E-mail envoyé : confirmation de commande', $this->presenter->getLabel($entry));

        $entry->setContext(['code' => 'app_invoice']);
        self::assertSame('E-mail envoyé : app_invoice', $this->presenter->getLabel($entry));
        self::assertSame('tabler:mail', $this->presenter->getIcon($entry));
    }

    public function testNotesAndAuthors(): void
    {
        $note = $this->createEntry(TimelineEntryInterface::TYPE_NOTE);
        $note->setAuthorType(TimelineEntryInterface::AUTHOR_ADMIN);
        $note->setAuthorName('John Doe');
        self::assertSame('Note interne', $this->presenter->getLabel($note));
        self::assertSame('yellow', $this->presenter->getColor($note));
        self::assertSame('John Doe', $this->presenter->getAuthor($note));

        $placed = $this->createEntry(TimelineEntryInterface::TYPE_ORDER_PLACED, 'sylius_order', 'create');
        $placed->setAuthorType(TimelineEntryInterface::AUTHOR_CUSTOMER);
        self::assertSame('Commande passée', $this->presenter->getLabel($placed));
        self::assertSame('Client', $this->presenter->getAuthor($placed));
        $placed->setAuthorName('Jane Roe');
        self::assertSame('Jane Roe (Client)', $this->presenter->getAuthor($placed));

        self::assertSame('Système', $this->presenter->getAuthor($this->createEntry(TimelineEntryInterface::TYPE_PAYMENT)));
    }

    private function createEntry(string $type, ?string $workflow = null, ?string $transition = null): TimelineEntryInterface
    {
        $entry = new TimelineEntry();
        $entry->setType($type);
        $entry->setWorkflow($workflow);
        $entry->setTransition($transition);

        return $entry;
    }
}
