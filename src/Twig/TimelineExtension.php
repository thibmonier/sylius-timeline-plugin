<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\Twig;

use Sylius\Component\Core\Model\CustomerInterface;
use Tmonier\SyliusTimelinePlugin\Controller\Admin\TimelineNoteController;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;
use Tmonier\SyliusTimelinePlugin\Repository\TimelineEntryRepositoryInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class TimelineExtension extends AbstractExtension
{
    public const CUSTOMER_SHOW_LIMIT = 50;

    public function __construct(
        private readonly TimelineEntryRepositoryInterface $entryRepository,
        private readonly TimelineEntryPresenter $presenter,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('tmonier_timeline_order_entries', $this->entryRepository->findForOrder(...)),
            new TwigFunction('tmonier_timeline_order_pinned_notes', $this->entryRepository->findPinnedForOrder(...)),
            new TwigFunction('tmonier_timeline_order_note_count', $this->entryRepository->countNotesForOrder(...)),
            new TwigFunction('tmonier_timeline_customer_entries', $this->findCustomerEntries(...)),
            new TwigFunction('tmonier_timeline_customer_entry_count', $this->entryRepository->countForCustomer(...)),
            new TwigFunction('tmonier_timeline_customer_pinned_notes', $this->entryRepository->findPinnedForCustomer(...)),
            new TwigFunction('tmonier_timeline_label', $this->presenter->getLabel(...)),
            new TwigFunction('tmonier_timeline_icon', $this->presenter->getIcon(...)),
            new TwigFunction('tmonier_timeline_color', $this->presenter->getColor(...)),
            new TwigFunction('tmonier_timeline_author', $this->presenter->getAuthor(...)),
            new TwigFunction('tmonier_timeline_note_csrf_token_id', $this->getNoteCsrfTokenId(...)),
            new TwigFunction('tmonier_timeline_add_note_csrf_token_id', static fn (): string => TimelineNoteController::ADD_CSRF_TOKEN_ID),
        ];
    }

    /** @return list<TimelineEntryInterface> */
    public function findCustomerEntries(CustomerInterface $customer, int $limit = self::CUSTOMER_SHOW_LIMIT): array
    {
        return $this->entryRepository->findForCustomer($customer, $limit);
    }

    public function getNoteCsrfTokenId(TimelineEntryInterface $entry): string
    {
        return TimelineNoteController::noteCsrfTokenId($entry);
    }
}
