<?php

namespace App\Controller;

use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\Organization;
use App\Entity\Status;
use App\Entity\User;
use App\Entity\Ticket;
use App\Form\CommentType;
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

#[Route('/{organizationSlug}')]
final class TicketController extends AbstractController
{
    #[Route('/tickets', name: 'app_ticket_index', methods: ['GET'])]
    public function index(Organization $organization, TicketRepository $ticketRepository): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Security check: user must belong to this organization
        $this->denyAccessUnlessGranted('ORGANIZATION_ACCESS', $organization);

        $tickets = $ticketRepository->findBy(
            ['organization' => $organization],
            ['createdAt' => 'DESC'],
        );

        return $this->render('ticket/index.html.twig', [
            'tickets' => $tickets,
            'organization' => $organization,
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

        $form = $this->createForm(TicketType::class, $ticket);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
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
        FileUploader $fileUploader
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

            $this->addFlash('success', 'Commentaire ajouté avec succès !');

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
        FileUploader $fileUploader
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

        $form = $this->createForm(TicketType::class, $ticket);
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
                    $attachment->setTicket($ticket);
                    $attachment->setUploadedBy($user);

                    $entityManager->persist($attachment);
                }

                $entityManager->flush();
            }

            $this->addFlash('success', 'Ticket modifié avec succès !');

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

    #[Route('/tickets/{ticketId}', name: 'app_ticket_delete', methods: ['POST'])]
    public function delete(Organization $organization, Request $request, #[MapEntity(id: 'ticketId')] Ticket $ticket, EntityManagerInterface $entityManager): Response
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
        }

        return $this->redirectToRoute('app_ticket_index', ['organizationSlug' => $organization->getSlug()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/tickets/{ticketId}/comment/{commentId}/delete', name: 'app_comment_delete', methods: ['POST'])]
    public function deleteComment(
        Organization $organization,
        Request $request,
        int $ticketId,
        #[MapEntity(id: 'commentId')] Comment $comment,
        EntityManagerInterface $entityManager
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

            $this->addFlash('success', 'Commentaire supprimé avec succès !');
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
        FileUploader $fileUploader
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

            $this->addFlash('success', 'Commentaire modifié avec succès !');

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
        FileUploader $fileUploader
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
                $this->addFlash('warning', 'Le fichier a été supprimé de la base mais pas du disque.');
            }

            $entityManager->remove($attachment);
            $entityManager->flush();

            $this->addFlash('success', 'Pièce jointe supprimée avec succès !');
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

        if ($this->isCsrfTokenValid('delete-comment-attachment-'.$attachment->getId(), $request->getPayload()->getString('_token'))) {
            try {
                $fileUploader->delete($attachment->getStoredFilename());
            } catch (\Exception $e) {
                $this->addFlash('warning', 'Le fichier a été supprimé de la base mais pas du disque.');
            }

            $entityManager->remove($attachment);
            $entityManager->flush();

            $this->addFlash('success', 'Pièce jointe supprimée avec succès !');
        }

        return $this->redirectToRoute('app_ticket_show', [
            'organizationSlug' => $organization->getSlug(),
            'ticketId' => $ticketId
        ], Response::HTTP_SEE_OTHER);
    }
}
