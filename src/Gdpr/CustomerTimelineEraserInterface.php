<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\Gdpr;

use Sylius\Component\Core\Model\CustomerInterface;

/**
 * Removes personal data of a customer from their timeline entries, to support the GDPR
 * right to erasure (Art. 17 GDPR) in anonymisation workflows.
 *
 * Fields cleared per entry type:
 *  - TYPE_EMAIL         → context.recipients (e-mail addresses)
 *  - TYPE_ORDER_PLACED  → author_name (customer full-name snapshot)
 *  - TYPE_CUSTOMER_NOTE → author_name + content (checkout note left by the customer)
 *
 * Business records (entry type, transition, date, order number, amounts…) are intentionally
 * preserved. Staff notes (TYPE_NOTE) are authored by administrators, not the customer, and are
 * therefore not erased — their association to the customer via customer_id suffices for the
 * anonymisation of the customer entity itself.
 */
interface CustomerTimelineEraserInterface
{
    /**
     * Erases all personal data of the given customer from their timeline entries.
     *
     * @return int number of distinct entries from which personal data was removed
     */
    public function eraseForCustomer(CustomerInterface $customer): int;
}
