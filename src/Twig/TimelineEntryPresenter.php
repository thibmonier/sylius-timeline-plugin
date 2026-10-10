<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\Twig;

use Sylius\Component\Order\OrderTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;

/** Label, icon and colour of a timeline entry. */
final class TimelineEntryPresenter
{
    private const ICONS_BY_TYPE = [
        TimelineEntryInterface::TYPE_ORDER_PLACED => 'tabler:shopping-bag',
        TimelineEntryInterface::TYPE_CUSTOMER_NOTE => 'tabler:user',
        TimelineEntryInterface::TYPE_NOTE => 'tabler:pencil',
        TimelineEntryInterface::TYPE_EMAIL => 'tabler:mail',
        TimelineEntryInterface::TYPE_ORDER_STATE => 'tabler:circle-check',
        TimelineEntryInterface::TYPE_PAYMENT_STATE => 'tabler:coins',
        TimelineEntryInterface::TYPE_PAYMENT => 'tabler:credit-card',
        TimelineEntryInterface::TYPE_SHIPPING_STATE => 'tabler:truck',
        TimelineEntryInterface::TYPE_SHIPMENT => 'tabler:truck',
        TimelineEntryInterface::TYPE_TRANSITION => 'tabler:arrows-split',
    ];

    private const ICONS_BY_TRANSITION = [
        'cancel' => 'tabler:x',
        'fail' => 'tabler:exclamation-circle',
        'refund' => 'tabler:arrow-back-up',
        'partially_refund' => 'tabler:arrow-back-up',
    ];

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function getLabel(TimelineEntryInterface $entry): string
    {
        $type = (string) $entry->getType();

        return match ($type) {
            TimelineEntryInterface::TYPE_ORDER_PLACED,
            TimelineEntryInterface::TYPE_CUSTOMER_NOTE,
            TimelineEntryInterface::TYPE_NOTE => $this->translator->trans('tmonier_timeline.event.' . $type),
            TimelineEntryInterface::TYPE_EMAIL => $this->translator->trans('tmonier_timeline.event.email', [
                '%email%' => $this->getEmailName($entry->getContextValue('code')),
            ]),
            default => $this->getTransitionLabel($entry),
        };
    }

    public function getIcon(TimelineEntryInterface $entry): string
    {
        if (!in_array($entry->getType(), [TimelineEntryInterface::TYPE_ORDER_PLACED, TimelineEntryInterface::TYPE_NOTE, TimelineEntryInterface::TYPE_CUSTOMER_NOTE, TimelineEntryInterface::TYPE_EMAIL], true)) {
            $icon = self::ICONS_BY_TRANSITION[(string) $entry->getTransition()] ?? null;
            if (null !== $icon) {
                return $icon;
            }
        }

        return self::ICONS_BY_TYPE[(string) $entry->getType()] ?? 'tabler:point';
    }

    /** Tabler colour name used for the badge of the entry. */
    public function getColor(TimelineEntryInterface $entry): string
    {
        return match (true) {
            $entry->isNote() => 'yellow',
            TimelineEntryInterface::TYPE_CUSTOMER_NOTE === $entry->getType() => 'cyan',
            TimelineEntryInterface::TYPE_ORDER_PLACED === $entry->getType() => 'primary',
            TimelineEntryInterface::TYPE_EMAIL === $entry->getType() => 'azure',
            in_array($entry->getTransition(), [OrderTransitions::TRANSITION_CANCEL, PaymentTransitions::TRANSITION_FAIL], true) => 'red',
            in_array($entry->getTransition(), [PaymentTransitions::TRANSITION_REFUND, 'partially_refund'], true) => 'orange',
            default => 'green',
        };
    }

    public function getAuthor(TimelineEntryInterface $entry): string
    {
        return match ($entry->getAuthorType()) {
            TimelineEntryInterface::AUTHOR_ADMIN => $entry->getAuthorName() ?? $this->translator->trans('tmonier_timeline.author.admin'),
            TimelineEntryInterface::AUTHOR_CUSTOMER => null === $entry->getAuthorName()
                ? $this->translator->trans('tmonier_timeline.author.customer')
                : sprintf('%s (%s)', $entry->getAuthorName(), $this->translator->trans('tmonier_timeline.author.customer')),
            default => $this->translator->trans('tmonier_timeline.author.system'),
        };
    }

    private function getTransitionLabel(TimelineEntryInterface $entry): string
    {
        $key = sprintf('tmonier_timeline.event.%s.%s', (string) $entry->getWorkflow(), (string) $entry->getTransition());
        if ($this->hasTranslation($key)) {
            return $this->translator->trans($key);
        }

        return $this->translator->trans('tmonier_timeline.event.transition', [
            '%workflow%' => (string) $entry->getWorkflow(),
            '%transition%' => (string) $entry->getTransition(),
        ]);
    }

    private function getEmailName(mixed $code): string
    {
        if (!is_string($code)) {
            return '';
        }

        $key = 'tmonier_timeline.email.' . $code;

        return $this->hasTranslation($key) ? $this->translator->trans($key) : $code;
    }

    private function hasTranslation(string $key): bool
    {
        // MessageCatalogue::has() also looks into the fallback locales.
        return $this->translator instanceof TranslatorBagInterface && $this->translator->getCatalogue()->has($key);
    }
}
