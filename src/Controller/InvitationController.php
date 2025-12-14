<?php

namespace App\Controller;

use App\Form\SetPasswordType;
use App\Repository\UserInvitationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class InvitationController extends AbstractController
{
    #[Route('/invitation/{token}', name: 'app_invitation_accept')]
    public function accept(
        string $token,
        Request $request,
        UserInvitationRepository $invitationRepository,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        TranslatorInterface $translator
    ): Response {
        $invitation = $invitationRepository->findValidByToken($token);

        if (!$invitation) {
            $this->addFlash('error', $translator->trans('This invitation link is invalid or has expired.'));
            return $this->redirectToRoute('app_login');
        }

        $user = $invitation->getUser();

        $form = $this->createForm(SetPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $password = $form->get('password')->getData();

            // Set the user's password
            $user->setPassword($passwordHasher->hashPassword($user, $password));

            // Mark invitation as accepted
            $invitation->setAcceptedAt(new \DateTimeImmutable());

            $entityManager->flush();

            $this->addFlash('success', $translator->trans('Your password has been set successfully. You can now log in with your credentials.'));

            return $this->redirectToRoute('app_login');
        }

        return $this->render('invitation/accept.html.twig', [
            'form' => $form,
            'user' => $user,
            'invitation' => $invitation,
        ]);
    }
}
