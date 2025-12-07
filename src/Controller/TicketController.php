<?php

namespace App\Controller;

use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\Status;
use App\Entity\User;
use App\Entity\Ticket;
use App\Form\CommentType;
use App\Form\TicketType;
use App\Repository\TicketRepository;
use App\Service\FileUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/')]
final class TicketController extends AbstractController
{
    #[Route(name: 'app_ticket_index', methods: ['GET'])]
    public function index(TicketRepository $ticketRepository): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $tickets = $ticketRepository->findBy(
            ['organization' => $user->getOrganization()],
            ['createdAt' => 'DESC'],
        );

        return $this->render('ticket/index.html.twig', [
            'tickets' => $tickets,
        ]);
    }

    #[Route('/new', name: 'app_ticket_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, FileUploader $fileUploader): Response
    {
        $ticket = new Ticket();

        /** @var User $user */
        $user = $this->getUser();
        $ticket->setOrganization($user->getOrganization());
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

            return $this->redirectToRoute('app_ticket_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('ticket/new.html.twig', [
            'ticket' => $ticket,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_ticket_show', methods: ['GET', 'POST'])]
    public function show(Request $request, Ticket $ticket, EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($ticket->getOrganization() !== $user->getOrganization()) {
            throw $this->createAccessDeniedException('You cannot access this ticket.');
        }

        $comment = new Comment();
        $form = $this->createForm(CommentType::class, $comment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $comment->setTicket($ticket);
            $comment->setAuthor($user);

            $entityManager->persist($comment);
            $entityManager->flush();

            $this->addFlash('success', 'Commentaire ajouté avec succès !');

            return $this->redirectToRoute('app_ticket_show', ['id' => $ticket->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('ticket/show.html.twig', [
            'ticket' => $ticket,
            'commentForm' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_ticket_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Ticket $ticket, EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($ticket->getOrganization() !== $user->getOrganization()) {
            throw $this->createAccessDeniedException('You cannot edit this ticket.');
        }

        $form = $this->createForm(TicketType::class, $ticket);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_ticket_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('ticket/edit.html.twig', [
            'ticket' => $ticket,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_ticket_delete', methods: ['POST'])]
    public function delete(Request $request, Ticket $ticket, EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($user->getOrganization() !== $ticket->getOrganization()) {
            throw $this->createAccessDeniedException('You cannot delete this ticket.');
        }

        if ($this->isCsrfTokenValid('delete'.$ticket->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($ticket);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_ticket_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{ticketId}/comment/{commentId}/delete', name: 'app_comment_delete', methods: ['POST'])]
    public function deleteComment(
        Request $request,
        int $ticketId,
        #[MapEntity(id: 'commentId')] Comment $comment,
        EntityManagerInterface $entityManager
    ): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Check comment -> ticket association
        if ($comment->getTicket()->getId() !== $ticketId) {
            throw $this->createNotFoundException('Comment does not belong to this ticket.');
        }

        // Check organization
        if ($comment->getTicket()->getOrganization() !== $user->getOrganization()) {
            throw $this->createAccessDeniedException('You cannot access this comment.');
        }

        // Check author
        if ($comment->getAuthor() !== $user) {
            throw $this->createAccessDeniedException('You can only delete your own comment.');
        }

        // Check CSRF token
        if ($this->isCsrfTokenValid('delete-comment-'.$comment->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($comment);
            $entityManager->flush();

            $this->addFlash('success', 'Commentaire supprimé avec succès !');
        }

        return $this->redirectToRoute('app_ticket_show', ['id' => $ticketId], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{ticketId}/comment/{commentId}/edit', name: 'app_comment_edit', methods: ['GET', 'POST'])]
    public function editComment(
        Request $request,
        int $ticketId,
        #[MapEntity(id: 'commentId')] Comment $comment,
        EntityManagerInterface $entityManager
    ): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Check comment -> ticket association
        if ($comment->getTicket()->getId() !== $ticketId) {
            throw $this->createNotFoundException('Comment does not belong to this ticket.');
        }

        // Check organization
        if ($comment->getTicket()->getOrganization() !== $user->getOrganization()) {
            throw $this->createAccessDeniedException('You cannot access this comment.');
        }

        // Check author
        if ($comment->getAuthor() !== $user) {
            throw $this->createAccessDeniedException('You can only delete your own comment.');
        }

        $form = $this->createForm(CommentType::class, $comment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Commentaire modifié avec succès !');

            return $this->redirectToRoute('app_ticket_show', ['id' => $ticketId], Response::HTTP_SEE_OTHER);
        }

        return $this->render('comment/edit.html.twig', [
            'comment' => $comment,
            'ticket' => $comment->getTicket(),
            'form' => $form,
        ]);
    }

    #[Route('/{ticketId}/attachment/{attachmentId}/download', name: 'app_attachment_download', methods: ['GET'])]
    public function downloadAttachment(
        int $ticketId,
        #[MapEntity(id: 'attachmentId')] Attachment $attachment,
        FileUploader $fileUploader
    ): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Check attachment -> ticket association
        if ($attachment->getTicket()->getId() !== $ticketId) {
            throw $this->createNotFoundException('Attachment does not belong to this ticket.');
        }

        // Check organization access
        if ($attachment->getTicket()->getOrganization() !== $user->getOrganization()) {
            throw $this->createAccessDeniedException('You cannot access this attachment.');
        }

        $filePath = $fileUploader->getTargetDirectory() . '/' . $attachment->getStoredFilename();

        if (!file_exists($filePath)) {
            throw $this->createNotFoundException('File not found.');
        }

        return $this->file($filePath, $attachment->getFilename());
    }

    #[Route('/{ticketId}/attachment/{attachmentId}/delete', name: 'app_attachment_delete', methods: ['POST'])]
    public function deleteAttachment(
        Request $request,
        int $ticketId,
        #[MapEntity(id: 'attachmentId')] Attachment $attachment,
        EntityManagerInterface $entityManager,
        FileUploader $fileUploader
    ): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($attachment->getTicket()->getId() !== $ticketId) {
            throw $this->createNotFoundException('Attachment does not belong to this ticket.');
        }

        if ($attachment->getTicket()->getOrganization() !== $user->getOrganization()) {
            throw $this->createAccessDeniedException('You cannot access this attachment.');
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

        return $this->redirectToRoute('app_ticket_show', ['id' => $ticketId], Response::HTTP_SEE_OTHER);
    }
}
