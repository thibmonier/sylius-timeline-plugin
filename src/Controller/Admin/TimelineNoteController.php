<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\CustomerRepositoryInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;
use Tmonier\SyliusTimelinePlugin\Factory\TimelineEntryFactoryInterface;
use Tmonier\SyliusTimelinePlugin\Model\Actor;
use Tmonier\SyliusTimelinePlugin\Repository\TimelineEntryRepositoryInterface;

/**
 * Internal staff notes on orders and customers. Every action is a CSRF-protected POST
 * that redirects back to the order or customer page.
 */
final class TimelineNoteController extends AbstractController
{
    public const ADD_CSRF_TOKEN_ID = 'tmonier_timeline_note';

    public const CUSTOMER_TIMELINE_PER_PAGE = 50;

    /**
     * @param OrderRepositoryInterface<OrderInterface> $orderRepository
     * @param CustomerRepositoryInterface<CustomerInterface> $customerRepository
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly TimelineEntryRepositoryInterface $entryRepository,
        private readonly TimelineEntryFactoryInterface $entryFactory,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function noteCsrfTokenId(TimelineEntryInterface $entry): string
    {
        return sprintf('tmonier_timeline_note_%d', (int) $entry->getId());
    }

    public function addOrderNoteAction(Request $request, int $id): Response
    {
        $order = $this->orderRepository->find($id);
        if (!$order instanceof OrderInterface) {
            throw new NotFoundHttpException('Order not found.');
        }

        $redirect = $this->redirectToRoute('sylius_admin_order_show', ['id' => $id]);
        $content = $this->getValidContent($request, self::ADD_CSRF_TOKEN_ID);
        if (null === $content) {
            return $redirect;
        }

        $this->entityManager->persist($this->entryFactory->createOrderNote($order, $content, Actor::admin($this->getAdminUser())));
        $this->entityManager->flush();
        $this->addFlash('success', 'tmonier_timeline.note_added');

        return $redirect;
    }

    public function addCustomerNoteAction(Request $request, int $id): Response
    {
        $customer = $this->customerRepository->find($id);
        if (!$customer instanceof CustomerInterface) {
            throw new NotFoundHttpException('Customer not found.');
        }

        $redirect = $this->redirectToRoute('sylius_admin_customer_show', ['id' => $id]);
        $content = $this->getValidContent($request, self::ADD_CSRF_TOKEN_ID);
        if (null === $content) {
            return $redirect;
        }

        $this->entityManager->persist($this->entryFactory->createCustomerNote($customer, $content, Actor::admin($this->getAdminUser())));
        $this->entityManager->flush();
        $this->addFlash('success', 'tmonier_timeline.note_added');

        return $redirect;
    }

    /** Any administrator can pin or unpin a note. */
    public function togglePinAction(Request $request, int $id): Response
    {
        $note = $this->getNote($request, $id);
        $this->getAdminUser();

        $note->setPinned(!$note->isPinned());
        $this->entityManager->flush();
        $this->addFlash('success', $note->isPinned() ? 'tmonier_timeline.note_pinned' : 'tmonier_timeline.note_unpinned');

        return $this->redirectToTarget($note);
    }

    /** Only the author can delete a note, or any administrator once the author's account is gone. */
    public function deleteAction(Request $request, int $id): Response
    {
        $note = $this->getNote($request, $id);
        $adminUser = $this->getAdminUser();

        if (null !== $note->getAdminUser() && !$note->isAuthoredBy($adminUser)) {
            throw new AccessDeniedHttpException('Only the author can delete this note.');
        }

        $redirect = $this->redirectToTarget($note);
        $this->entityManager->remove($note);
        $this->entityManager->flush();
        $this->addFlash('success', 'tmonier_timeline.note_deleted');

        return $redirect;
    }

    public function customerTimelineAction(Request $request, int $id): Response
    {
        $customer = $this->customerRepository->find($id);
        if (!$customer instanceof CustomerInterface) {
            throw new NotFoundHttpException('Customer not found.');
        }

        $paginator = $this->entryRepository->createPaginatorForCustomer($customer);
        $paginator->setMaxPerPage(self::CUSTOMER_TIMELINE_PER_PAGE);
        $paginator->setCurrentPage(max(1, min($request->query->getInt('page', 1), $paginator->getNbPages())));

        return $this->render('@TmonierSyliusTimelinePlugin/admin/customer/timeline.html.twig', [
            'resource' => $customer,
            'customer' => $customer,
            'paginator' => $paginator,
        ]);
    }

    private function getValidContent(Request $request, string $csrfTokenId): ?string
    {
        $this->assertCsrfToken($request, $csrfTokenId);
        $this->getAdminUser();

        // Keep the author's line breaks, normalised; escaping happens at render time.
        $content = trim(str_replace(["\r\n", "\r"], "\n", $request->request->getString('content')));
        if ('' === $content) {
            $this->addFlash('error', 'tmonier_timeline.note_empty');

            return null;
        }

        if (mb_strlen($content) > TimelineEntryInterface::NOTE_MAX_LENGTH) {
            $this->addFlash('error', [
                'message' => 'tmonier_timeline.note_too_long',
                'parameters' => ['%limit%' => TimelineEntryInterface::NOTE_MAX_LENGTH],
            ]);

            return null;
        }

        return $content;
    }

    private function getNote(Request $request, int $id): TimelineEntryInterface
    {
        $note = $this->entryRepository->find($id);
        if (!$note instanceof TimelineEntryInterface || !$note->isNote()) {
            throw new NotFoundHttpException('Note not found.');
        }

        $this->assertCsrfToken($request, self::noteCsrfTokenId($note));

        return $note;
    }

    private function assertCsrfToken(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_csrf_token'))) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }

    private function getAdminUser(): AdminUserInterface
    {
        $user = $this->getUser();
        if (!$user instanceof AdminUserInterface) {
            throw new AccessDeniedHttpException('Only administrators can manage internal notes.');
        }

        return $user;
    }

    private function redirectToTarget(TimelineEntryInterface $note): RedirectResponse
    {
        $order = $note->getOrder();
        if (null !== $order) {
            return $this->redirectToRoute('sylius_admin_order_show', ['id' => $order->getId()]);
        }

        return $this->redirectToRoute('sylius_admin_customer_show', ['id' => $note->getCustomer()?->getId()]);
    }
}
