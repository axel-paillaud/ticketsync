<?php

namespace App\Controller;

use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\Organization;
use App\Entity\Status;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Entity\Ticket;
use App\Form\CommentType;
use App\Form\TimeEntryType;
use App\Form\TicketType;
use App\Repository\TicketRepository;
use App\Service\EmailService;
use App\Service\FileUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
// Note : we can use this instead of $user = $this->getUser() everywhere
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/{organizationSlug}')]
final class TicketController extends AbstractController
{
    #[Route('/tickets', name: 'app_ticket_index', methods: ['GET'])]
    public function index(Organization $organization, TicketRepository $ticketRepository, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Security check: user must belong to this organization
        $this->denyAccessUnlessGranted('ORGANIZATION_ACCESS', $organization);

        // Check if we should include resolved tickets (default: false)
        $includeResolved = $request->query->getBoolean('resolved', false);

        $tickets = $ticketRepository->findByOrganizationOrderedByPriority($organization, $includeResolved);

        return $this->render('ticket/index.html.twig', [
            'tickets' => $tickets,
            'organization' => $organization,
            'includeResolved' => $includeResolved,
        ]);
    }

    #[Route('/tickets/new', name: 'app_ticket_new', methods: ['GET', 'POST'])]
    public function new(Organization $organization, Request $request, EntityManagerInterface $entityManager, FileUploader $fileUploader): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Security check: user must belong to this organization
        $this->denyAccessUnlessGranted('ORGANIZATION_ACCESS', $organization);

        $ticket = new Ticket();
        $ticket->setOrganization($organization);
        $ticket->setCreatedBy($user);

        $defaultStatus = $entityManager->getRepository(Status::class)->findOneBy(['slug' => 'open']);
        if ($defaultStatus) {
            $ticket->setStatus($defaultStatus);
        }

        $form = $this->createForm(TicketType::class, $ticket, [
            'is_admin' => $this->isGranted('ROLE_ADMIN'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Security: prevent non-admins from changing organization (even if they modify HTML)
            if (!$this->isGranted('ROLE_ADMIN')) {
                $ticket->setOrganization($organization);
            }

            $entityManager->persist($ticket);
            $entityManager->flush();

            // Handle file upload
            /** @var UploadedFile[] $attachmentFiles */
            $attachmentFiles = $form->get('attachments')->getData();

            if ($attachmentFiles) {
                foreach ($attachmentFiles as $file) {
                    $fileData = $fileUploader->upload($file);

                    $attachment = new Attachment();
                    $attachment->setFilename($fileData['filename']);
                    $attachment->setStoredFilename($fileData['storedFilename']);
                    $attachment->setMimeType($fileData['mimeType']);
                    $attachment->setSize($fileData['size']);
                    $attachment->setTicket($ticket);
                    $attachment->setUploadedBy($user);

                    $entityManager->persist($attachment);
                }

                $entityManager->flush();
            }

            return $this->redirectToRoute('app_ticket_index', ['organizationSlug' => $organization->getSlug()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('ticket/new.html.twig', [
            'ticket' => $ticket,
            'form' => $form,
            'organization' => $organization,
        ]);
    }

    #[Route('/tickets/{ticketId}', name: 'app_ticket_show', methods: ['GET', 'POST'])]
    public function show(
        Organization $organization,
        Request $request,
        #[MapEntity(id: 'ticketId')] Ticket $ticket,
        EntityManagerInterface $entityManager,
        FileUploader $fileUploader,
        TranslatorInterface $translator
    ): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Security check: user must belong to this organization
        $this->denyAccessUnlessGranted('ORGANIZATION_ACCESS', $organization);

        // Security check: ticket must belong to this organization (only for non-admins)
        if (!$this->isGranted('ROLE_ADMIN') && $ticket->getOrganization() !== $organization) {
            throw $this->createNotFoundException('Ticket not found in this organization.');
        }

        $comment = new Comment();
        $form = $this->createForm(CommentType::class, $comment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $comment->setTicket($ticket);
            $comment->setAuthor($user);

            $entityManager->persist($comment);
            $entityManager->flush();

            // Handle file uploads
            /** @var UploadedFile[] $attachmentFiles */
            $attachmentFiles = $form->get('attachments')->getData();

            if ($attachmentFiles) {
                foreach ($attachmentFiles as $file) {
                    $fileData = $fileUploader->upload($file);

                    $attachment = new Attachment();
                    $attachment->setFilename($fileData['filename']);
                    $attachment->setStoredFilename($fileData['storedFilename']);
                    $attachment->setMimeType($fileData['mimeType']);
                    $attachment->setSize($fileData['size']);
                    $attachment->setComment($comment);
                    $attachment->setUploadedBy($user);

                    $entityManager->persist($attachment);
                }

                $entityManager->flush();
            }

            $this->addFlash('success', $translator->trans('Comment added successfully!'));

            return $this->redirectToRoute('app_ticket_show', [
                'organizationSlug' => $organization->getSlug(),
                'ticketId' => $ticket->getId()
            ], Response::HTTP_SEE_OTHER);
        }

        return $this->render('ticket/show.html.twig', [
            'ticket' => $ticket,
            'commentForm' => $form,
            'organization' => $organization,
        ]);
    }

    #[Route('/tickets/{ticketId}/edit', name: 'app_ticket_edit', methods: ['GET', 'POST'])]
    public function edit(
        Organization $organization,
        Request $request,
        #[MapEntity(id: 'ticketId')] Ticket $ticket,
        EntityManagerInterface $entityManager,
        FileUploader $fileUploader,
        TranslatorInterface $translator,
    ): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Security check: user must belong to this organization
        $this->denyAccessUnlessGranted('ORGANIZATION_ACCESS', $organization);

        // Security check: ticket must belong to this organization (only for non-admins)
        if (!$this->isGranted('ROLE_ADMIN') && $ticket->getOrganization() !== $organization) {
            throw $this->createNotFoundException('Ticket not found in this organization.');
        }

        // Security check: user must have permission to edit this ticket
        $this->denyAccessUnlessGranted('TICKET_EDIT', $ticket);

        // Store original values to prevent unauthorized changes
        $originalStatus = $ticket->getStatus();
        $originalOrganization = $ticket->getOrganization();

        $form = $this->createForm(TicketType::class, $ticket, [
            'is_admin' => $this->isGranted('ROLE_ADMIN'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Security: prevent non-admins from changing status
            if (!$this->isGranted('ROLE_ADMIN') && $ticket->getStatus() !== $originalStatus) {
                $ticket->setStatus($originalStatus);
            }

            // Security: prevent non-admins from changing organization (even if they modify HTML)
            if (!$this->isGranted('ROLE_ADMIN')) {
                $ticket->setOrganization($originalOrganization);
            }

            $entityManager->flush();

            // Handle new file uploads
            /** @var UploadedFile[] $attachmentFiles */
            $attachmentFiles = $form->get('attachments')->getData();

            if ($attachmentFiles) {
                foreach ($attachmentFiles as $file) {
                    $fileData = $fileUploader->upload($file);

                    $attachment = new Attachment();
                    $attachment->setFilename($fileData['filename']);
                    $attachment->setStoredFilename($fileData['storedFilename']);
                    $attachment->setMimeType($fileData['mimeType']);
                    $attachment->setSize($fileData['size']);
                    $attachment->setTicket($ticket);
                    $attachment->setUploadedBy($user);

                    $entityManager->persist($attachment);
                }

                $entityManager->flush();
            }

            $this->addFlash('success', $translator->trans('Ticket successfully modified!'));

            return $this->redirectToRoute('app_ticket_show', [
                'organizationSlug' => $organization->getSlug(),
                'ticketId' => $ticket->getId()
            ], Response::HTTP_SEE_OTHER);
        }

        return $this->render('ticket/edit.html.twig', [
            'ticket' => $ticket,
            'form' => $form,
            'organization' => $organization,
        ]);
    }

    #[Route('/tickets/{ticketId}/delete', name: 'app_ticket_delete', methods: ['POST'])]
    public function delete(Organization $organization, Request $request, #[MapEntity(id: 'ticketId')] Ticket $ticket, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        // Security check: user must belong to this organization
        $this->denyAccessUnlessGranted('ORGANIZATION_ACCESS', $organization);

        // Security check: ticket must belong to this organization (only for non-admins)
        if (!$this->isGranted('ROLE_ADMIN') && $ticket->getOrganization() !== $organization) {
            throw $this->createNotFoundException('Ticket not found in this organization.');
        }

        // Security check: user must have permission to delete this ticket
        $this->denyAccessUnlessGranted('TICKET_DELETE', $ticket);

        if ($this->isCsrfTokenValid('delete'.$ticket->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($ticket);
            $entityManager->flush();

            $this->addFlash('success', $translator->trans('Ticket successfully deleted!'));
        }

        return $this->redirectToRoute(
            'app_ticket_index',
            ['organizationSlug' => $organization->getSlug()],
            Response::HTTP_SEE_OTHER
        );
    }

    #[Route('/tickets/{ticketId}/comment/{commentId}/delete', name: 'app_comment_delete', methods: ['POST'])]
    public function deleteComment(
        Organization $organization,
        Request $request,
        int $ticketId,
        #[MapEntity(id: 'commentId')] Comment $comment,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
    ): Response
    {
        // Security check: user must belong to this organization
        $this->denyAccessUnlessGranted('ORGANIZATION_ACCESS', $organization);

        // Check comment -> ticket association
        if ($comment->getTicket()->getId() !== $ticketId) {
            throw $this->createNotFoundException('Comment does not belong to this ticket.');
        }

        // Check organization (only for non-admins, admins can access all organizations)
        if (!$this->isGranted('ROLE_ADMIN') && $comment->getTicket()->getOrganization() !== $organization) {
            throw $this->createNotFoundException('Comment not found in this organization.');
        }

        // Security check: user must have permission to delete this comment
        $this->denyAccessUnlessGranted('COMMENT_DELETE', $comment);

        // Check CSRF token
        if ($this->isCsrfTokenValid('delete-comment-'.$comment->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($comment);
            $entityManager->flush();

            $this->addFlash('success', $translator->trans('Comment successfully deleted!'));
        }

        return $this->redirectToRoute('app_ticket_show', [
            'organizationSlug' => $organization->getSlug(),
            'ticketId' => $ticketId
        ], Response::HTTP_SEE_OTHER);
    }

    #[Route('/tickets/{ticketId}/comment/{commentId}/edit', name: 'app_comment_edit', methods: ['GET', 'POST'])]
    public function editComment(
        Organization $organization,
        Request $request,
        int $ticketId,
        #[MapEntity(id: 'commentId')] Comment $comment,
        EntityManagerInterface $entityManager,
        FileUploader $fileUploader,
        TranslatorInterface $translator
    ): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Security check: user must belong to this organization
        $this->denyAccessUnlessGranted('ORGANIZATION_ACCESS', $organization);

        // Check comment -> ticket association
        if ($comment->getTicket()->getId() !== $ticketId) {
            throw $this->createNotFoundException('Comment does not belong to this ticket.');
        }

        // Check organization (only for non-admins, admins can access all organizations)
        if (!$this->isGranted('ROLE_ADMIN') && $comment->getTicket()->getOrganization() !== $organization) {
            throw $this->createNotFoundException('Comment not found in this organization.');
        }

        // Security check: user must have permission to edit this comment
        $this->denyAccessUnlessGranted('COMMENT_EDIT', $comment);

        $form = $this->createForm(CommentType::class, $comment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            // Handle new file uploads
            /** @var UploadedFile[] $attachmentFiles */
            $attachmentFiles = $form->get('attachments')->getData();

            if ($attachmentFiles) {
                foreach ($attachmentFiles as $file) {
                    $fileData = $fileUploader->upload($file);

                    $attachment = new Attachment();
                    $attachment->setFilename($fileData['filename']);
                    $attachment->setStoredFilename($fileData['storedFilename']);
                    $attachment->setMimeType($fileData['mimeType']);
                    $attachment->setSize($fileData['size']);
                    $attachment->setComment($comment);
                    $attachment->setUploadedBy($user);

                    $entityManager->persist($attachment);
                }

                $entityManager->flush();
            }

            $this->addFlash('success', $translator->trans('Comment successfully modified!'));

            return $this->redirectToRoute('app_ticket_show', [
                'organizationSlug' => $organization->getSlug(),
                'ticketId' => $ticketId
            ], Response::HTTP_SEE_OTHER);
        }

        return $this->render('comment/edit.html.twig', [
            'comment' => $comment,
            'ticket' => $comment->getTicket(),
            'form' => $form,
            'organization' => $organization,
        ]);
    }

    #[Route('/tickets/{ticketId}/attachment/{attachmentId}/download', name: 'app_attachment_download', methods: ['GET'])]
    public function downloadAttachment(
        Organization $organization,
        int $ticketId,
        #[MapEntity(id: 'attachmentId')] Attachment $attachment,
        FileUploader $fileUploader
    ): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Security check: user must belong to this organization, except admin
        $this->denyAccessUnlessGranted('ORGANIZATION_ACCESS', $organization);

        // Check attachment -> ticket association
        if ($attachment->getTicket()->getId() !== $ticketId) {
            throw $this->createNotFoundException('Attachment does not belong to this ticket.');
        }

        // Check organization access
        if ($attachment->getTicket()->getOrganization() !== $organization) {
            throw $this->createNotFoundException('Attachment not found in this organization.');
        }

        $filePath = $fileUploader->getTargetDirectory() . '/' . $attachment->getStoredFilename();

        if (!file_exists($filePath)) {
            throw $this->createNotFoundException('File not found.');
        }

        return $this->file($filePath, $attachment->getFilename());
    }

    #[Route('/tickets/{ticketId}/attachment/{attachmentId}/delete', name: 'app_attachment_delete', methods: ['POST'])]
    public function deleteAttachment(
        Organization $organization,
        Request $request,
        int $ticketId,
        #[MapEntity(id: 'attachmentId')] Attachment $attachment,
        EntityManagerInterface $entityManager,
        FileUploader $fileUploader,
        TranslatorInterface $translator
    ): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Security check: user must belong to this organization, except admin
        $this->denyAccessUnlessGranted('ORGANIZATION_ACCESS', $organization);

        if ($attachment->getTicket()->getId() !== $ticketId) {
            throw $this->createNotFoundException('Attachment does not belong to this ticket.');
        }

        if ($attachment->getTicket()->getOrganization() !== $organization) {
            throw $this->createNotFoundException('Attachment not found in this organization.');
        }

        if ($attachment->getUploadedBy() !== $user) {
            throw $this->createAccessDeniedException('You can only delete your own attachments.');
        }

        // Check CSRF
        if ($this->isCsrfTokenValid('delete-attachment-'.$attachment->getId(), $request->getPayload()->getString('_token'))) {
            try {
                $fileUploader->delete($attachment->getStoredFilename());
            } catch (\Exception $e) {
                $this->addFlash('warning', $translator->trans('The file has been removed from database but not from disk.'));
            }

            $entityManager->remove($attachment);
            $entityManager->flush();

            $this->addFlash('success', $translator->trans('Attachment successfully deleted!'));
        }

        return $this->redirectToRoute('app_ticket_show', [
            'organizationSlug' => $organization->getSlug(),
            'ticketId' => $ticketId
        ], Response::HTTP_SEE_OTHER);
    }

    #[Route('/tickets/{ticketId}/comment/{commentId}/attachment/{attachmentId}/download', name: 'app_comment_attachment_download', methods: ['GET'])]
    public function downloadCommentAttachment(
        Organization $organization,
        int $ticketId,
        int $commentId,
        #[MapEntity(id: 'attachmentId')] Attachment $attachment,
        FileUploader $fileUploader
    ): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Security check: user must belong to this organization, except admin
        $this->denyAccessUnlessGranted('ORGANIZATION_ACCESS', $organization);

        if (!$attachment->getComment() || $attachment->getComment()->getId() !== $commentId) {
            throw $this->createNotFoundException('Attachment does not belong to this comment.');
        }

        if ($attachment->getComment()->getTicket()->getId() !== $ticketId) {
            throw $this->createNotFoundException('Comment does not belong to this ticket.');
        }

        if ($attachment->getComment()->getTicket()->getOrganization() !== $organization) {
            throw $this->createNotFoundException('Attachment not found in this organization.');
        }

        $filePath = $fileUploader->getTargetDirectory() . '/' . $attachment->getStoredFilename();

        if (!file_exists($filePath)) {
            throw $this->createNotFoundException('File not found.');
        }

        return $this->file($filePath, $attachment->getFilename());
    }

    #[Route('/tickets/{ticketId}/comment/{commentId}/attachment/{attachmentId}/delete', name: 'app_comment_attachment_delete', methods: ['POST'])]
    public function deleteCommentAttachment(
        Organization $organization,
        Request $request,
        int $ticketId,
        int $commentId,
        #[MapEntity(id: 'attachmentId')] Attachment $attachment,
        EntityManagerInterface $entityManager,
        FileUploader $fileUploader,
        TranslatorInterface $translator
    ): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Security check: user must belong to this organization, except admin
        $this->denyAccessUnlessGranted('ORGANIZATION_ACCESS', $organization);

        if (!$attachment->getComment() || $attachment->getComment()->getId() !== $commentId) {
            throw $this->createNotFoundException('Attachment does not belong to this comment.');
        }

        if ($attachment->getComment()->getTicket()->getId() !== $ticketId) {
            throw $this->createNotFoundException('Comment does not belong to this ticket.');
        }

        if ($attachment->getComment()->getTicket()->getOrganization() !== $organization) {
            throw $this->createNotFoundException('Attachment not found in this organization.');
        }

        if ($this->isCsrfTokenValid('delete-comment-attachment-'.$attachment->getId(), $request->getPayload()->getString('_token'))) {
            try {
                $fileUploader->delete($attachment->getStoredFilename());
            } catch (\Exception $e) {
                $this->addFlash('warning', $translator->trans('The file has been removed from database but not from disk.'));
            }

            $entityManager->remove($attachment);
            $entityManager->flush();

            $this->addFlash('success', $translator->trans('Attachment successfully deleted!'));
        }

        return $this->redirectToRoute('app_ticket_show', [
            'organizationSlug' => $organization->getSlug(),
            'ticketId' => $ticketId
        ], Response::HTTP_SEE_OTHER);
    }

    // ============================================
    // TIME ENTRY METHODS
    // ============================================

    #[Route('/tickets/{ticketId}/time-entry/new', name: 'app_time_entry_new', methods: ['GET', 'POST'])]
    public function newTimeEntry(
        Organization $organization,
        Request $request,
        #[MapEntity(id: 'ticketId')] Ticket $ticket,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator
    ): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Security check: admin-only for MVP
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        // Security check: user must have organization access
        $this->denyAccessUnlessGranted('ORGANIZATION_ACCESS', $organization);

        // Security check: ticket must belong to this organization (non-admins only)
        if (!$this->isGranted('ROLE_ADMIN') && $ticket->getOrganization() !== $organization) {
            throw $this->createNotFoundException('Ticket not found in this organization.');
        }

        $timeEntry = new TimeEntry();
        $timeEntry->setTicket($ticket);
        $timeEntry->setCreatedBy($user);
        $timeEntry->setOrganization($organization);

        // Set default work date to today
        $timeEntry->setWorkDate(new \DateTimeImmutable());

        $form = $this->createForm(TimeEntryType::class, $timeEntry);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Snapshot hourly rate from organization
            $timeEntry->setHourlyRateSnapshot($organization->getHourlyRate());

            // Calculate billed hours and amount
            $timeEntry->setBilledHours($timeEntry->calculateBilledHours());
            $timeEntry->setBilledAmount($timeEntry->calculateBilledAmount());

            $entityManager->persist($timeEntry);
            $entityManager->flush();

            $this->addFlash('success', $translator->trans('Time entry added successfully!'));

            return $this->redirectToRoute('app_ticket_show', [
                'organizationSlug' => $organization->getSlug(),
                'ticketId' => $ticket->getId()
            ], Response::HTTP_SEE_OTHER);
        }

        return $this->render('time_entry/new.html.twig', [
            'timeEntry' => $timeEntry,
            'ticket' => $ticket,
            'form' => $form,
            'organization' => $organization,
        ]);
    }

    #[Route('/tickets/{ticketId}/time-entry/{timeEntryId}/edit', name: 'app_time_entry_edit', methods: ['GET', 'POST'])]
    public function editTimeEntry(
        Organization $organization,
        Request $request,
        int $ticketId,
        #[MapEntity(id: 'timeEntryId')] TimeEntry $timeEntry,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator
    ): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Security check: admin-only for MVP
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        // Security check: user must have organization access
        $this->denyAccessUnlessGranted('ORGANIZATION_ACCESS', $organization);

        // Check time entry -> ticket association
        if ($timeEntry->getTicket()->getId() !== $ticketId) {
            throw $this->createNotFoundException('Time entry does not belong to this ticket.');
        }

        // Check organization (only for non-admins)
        if (!$this->isGranted('ROLE_ADMIN') && $timeEntry->getTicket()->getOrganization() !== $organization) {
            throw $this->createNotFoundException('Time entry not found in this organization.');
        }

        // Security check: user must have permission to edit
        $this->denyAccessUnlessGranted('TIMEENTRY_EDIT', $timeEntry);

        $form = $this->createForm(TimeEntryType::class, $timeEntry);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Recalculate billed hours and amount (in case hours changed)
            $timeEntry->setBilledHours($timeEntry->calculateBilledHours());
            $timeEntry->setBilledAmount($timeEntry->calculateBilledAmount());

            // Note: hourlyRateSnapshot is NOT updated on edit (preserves original rate)

            $entityManager->flush();

            $this->addFlash('success', $translator->trans('Time entry updated successfully!'));

            return $this->redirectToRoute('app_ticket_show', [
                'organizationSlug' => $organization->getSlug(),
                'ticketId' => $ticketId
            ], Response::HTTP_SEE_OTHER);
        }

        return $this->render('time_entry/edit.html.twig', [
            'timeEntry' => $timeEntry,
            'ticket' => $timeEntry->getTicket(),
            'form' => $form,
            'organization' => $organization,
        ]);
    }

    #[Route('/tickets/{ticketId}/time-entry/{timeEntryId}/delete', name: 'app_time_entry_delete', methods: ['POST'])]
    public function deleteTimeEntry(
        Organization $organization,
        Request $request,
        int $ticketId,
        #[MapEntity(id: 'timeEntryId')] TimeEntry $timeEntry,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator
    ): Response
    {
        // Security check: admin-only for MVP
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        // Security check: user must have organization access
        $this->denyAccessUnlessGranted('ORGANIZATION_ACCESS', $organization);

        // Check time entry -> ticket association
        if ($timeEntry->getTicket()->getId() !== $ticketId) {
            throw $this->createNotFoundException('Time entry does not belong to this ticket.');
        }

        // Check organization (only for non-admins)
        if (!$this->isGranted('ROLE_ADMIN') && $timeEntry->getTicket()->getOrganization() !== $organization) {
            throw $this->createNotFoundException('Time entry not found in this organization.');
        }

        // Security check: user must have permission to delete
        $this->denyAccessUnlessGranted('TIMEENTRY_DELETE', $timeEntry);

        // Check CSRF token
        if ($this->isCsrfTokenValid('delete-time-entry-'.$timeEntry->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($timeEntry);
            $entityManager->flush();

            $this->addFlash('success', $translator->trans('Time entry deleted successfully!'));
        }

        return $this->redirectToRoute('app_ticket_show', [
            'organizationSlug' => $organization->getSlug(),
            'ticketId' => $ticketId
        ], Response::HTTP_SEE_OTHER);
    }
}
