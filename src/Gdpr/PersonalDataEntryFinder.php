<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\Gdpr;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;

/**
 * Kept out of TimelineEntryRepositoryInterface: adding a method to that public interface would
 * break the applications that implement it.
 */
final readonly class PersonalDataEntryFinder implements PersonalDataEntryFinderInterface
{
    /** @param class-string<TimelineEntryInterface> $entryClass */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $entryClass,
    ) {
    }

    public function findForCustomer(CustomerInterface $customer): array
    {
        /** @var list<TimelineEntryInterface> $entries */
        $entries = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from($this->entryClass, 'o')
            ->andWhere('o.customer = :customer')
            ->andWhere('o.authorType = :authorType OR o.type = :emailType')
            ->setParameter('customer', $customer)
            ->setParameter('authorType', TimelineEntryInterface::AUTHOR_CUSTOMER)
            ->setParameter('emailType', TimelineEntryInterface::TYPE_EMAIL)
            ->getQuery()
            ->getResult()
        ;

        return $entries;
    }
}
