<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserInvitation;
use App\Form\UserInvitationType;
use App\Repository\UserRepository;
use App\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/users')]
#[IsGranted('ROLE_ADMIN')]
class AdminUserController extends AbstractController
{
    #[Route('', name: 'app_admin_user_index', methods: ['GET'])]
    public function index(UserRepository $userRepository): Response
    {
        $users = $userRepository->findBy([], ['createdAt' => 'DESC']);

        return $this->render('admin/user/index.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route('/new', name: 'app_admin_user_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        EmailService $emailService,
        TranslatorInterface $translator
    ): Response {
        $user = new User();
        $user->setIsVerified(true);

        $form = $this->createForm(UserInvitationType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Set a temporary password (will be overwritten when user accepts invitation)
            $temporaryPassword = bin2hex(random_bytes(16));
            $user->setPassword($passwordHasher->hashPassword($user, $temporaryPassword));

            // Create invitation
            $invitation = new UserInvitation();
            $invitation->setUser($user);

            $entityManager->persist($user);
            $entityManager->persist($invitation);
            $entityManager->flush();

            // Generate invitation URL
            $invitationUrl = $this->generateUrl('app_invitation_accept', [
                'token' => $invitation->getToken(),
            ], UrlGeneratorInterface::ABSOLUTE_URL);

            // Send invitation email
            $emailService->sendUserInvitation($invitation, $invitationUrl);

            $this->addFlash('success', sprintf($translator->trans('User "%s" has been created and invitation email sent.'), $user->getEmail()));

            return $this->redirectToRoute('app_admin_user_index');
        }

        return $this->render('admin/user/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_user_show', methods: ['GET'])]
    public function show(User $user): Response
    {
        return $this->render('admin/user/show.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/{id}/resend-invitation', name: 'app_admin_user_resend_invitation', methods: ['POST'])]
    public function resendInvitation(
        User $user,
        Request $request,
        EntityManagerInterface $entityManager,
        EmailService $emailService,
        TranslatorInterface $translator
    ): Response {
        if (!$this->isCsrfTokenValid('resend-invitation-' . $user->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $invitation = $user->getInvitation();

        if (!$invitation) {
            $this->addFlash('error', $translator->trans('This user has no pending invitation.'));
            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
        }

        if ($invitation->isAccepted()) {
            $this->addFlash('error', $translator->trans('This invitation has already been accepted.'));
            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
        }

        // Create a new invitation (invalidates the old one)
        $entityManager->remove($invitation);
        $entityManager->flush();

        $newInvitation = new UserInvitation();
        $newInvitation->setUser($user);
        $entityManager->persist($newInvitation);
        $entityManager->flush();

        // Generate invitation URL
        $invitationUrl = $this->generateUrl('app_invitation_accept', [
            'token' => $newInvitation->getToken(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        // Send invitation email
        $emailService->sendUserInvitation($newInvitation, $invitationUrl);

        $this->addFlash('success', sprintf($translator->trans('Invitation email has been resent to %s.'), $user->getEmail()));

        return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
    }

    #[Route('/{id}/delete', name: 'app_admin_user_delete', methods: ['POST'])]
    public function delete(Request $request, User $user, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        if ($this->isCsrfTokenValid('delete-user-' . $user->getId(), $request->request->get('_token'))) {
            $email = $user->getEmail();
            $entityManager->remove($user);
            $entityManager->flush();

            $this->addFlash('success', sprintf($translator->trans('User "%s" has been deleted successfully.'), $email));
        }

        return $this->redirectToRoute('app_admin_user_index');
    }
}
