<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\Gdpr;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;

/**
 * Erases personal data of a customer from their timeline entries.
 *
 * Strategy (one SELECT + one flush, no N+1):
 *  1. Load in a single query every entry that carries PII of the customer:
 *     entries whose author_type is 'customer' (order_placed, customer_note) and email entries.
 *  2. For each loaded entry, clear the relevant fields in-memory.
 *  3. A single flush persists all changes.
 */
final class CustomerTimelineEraser implements CustomerTimelineEraserInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PersonalDataEntryFinderInterface $finder,
    ) {
    }

    public function eraseForCustomer(CustomerInterface $customer): int
    {
        $entries = $this->finder->findForCustomer($customer);

        $count = 0;

        foreach ($entries as $entry) {
            $modified = false;

            // Clear the customer's full-name snapshot (TYPE_ORDER_PLACED, TYPE_CUSTOMER_NOTE).
            if (null !== $entry->getAuthorName()) {
                $entry->setAuthorName(null);
                $modified = true;
            }

            // Clear the checkout note written by the customer (TYPE_CUSTOMER_NOTE).
            if (TimelineEntryInterface::TYPE_CUSTOMER_NOTE === $entry->getType() && null !== $entry->getContent()) {
                $entry->setContent(null);
                $modified = true;
            }

            // Clear e-mail addresses from the recipients list (TYPE_EMAIL).
            if (TimelineEntryInterface::TYPE_EMAIL === $entry->getType()) {
                $context = $entry->getContext();
                if (!empty($context['recipients'])) {
                    $context['recipients'] = [];
                    $entry->setContext($context);
                    $modified = true;
                }
            }

            if ($modified) {
                ++$count;
            }
        }

        // Single flush — persists all in-memory changes at once.
        $this->entityManager->flush();

        return $count;
    }
}
