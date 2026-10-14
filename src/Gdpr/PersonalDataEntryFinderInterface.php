<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\Gdpr;

use Sylius\Component\Core\Model\CustomerInterface;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;

interface PersonalDataEntryFinderInterface
{
    /**
     * Entries of the customer that carry personal data and must be cleared during a GDPR
     * erasure: customer-authored entries (order_placed, customer_note) and email entries.
     *
     * @return list<TimelineEntryInterface>
     */
    public function findForCustomer(CustomerInterface $customer): array;
}
