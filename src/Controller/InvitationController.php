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
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\FormLoginAuthenticator;

class InvitationController extends AbstractController
{
    #[Route('/invitation/{token}', name: 'app_invitation_accept')]
    public function accept(
        string $token,
        Request $request,
        UserInvitationRepository $invitationRepository,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        UserAuthenticatorInterface $userAuthenticator,
        FormLoginAuthenticator $authenticator
    ): Response {
        $invitation = $invitationRepository->findValidByToken($token);

        if (!$invitation) {
            $this->addFlash('error', 'This invitation link is invalid or has expired.');
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

            $this->addFlash('success', 'Your password has been set successfully. You are now logged in.');

            // Authenticate the user
            return $userAuthenticator->authenticateUser(
                $user,
                $authenticator,
                $request
            );
        }

        return $this->render('invitation/accept.html.twig', [
            'form' => $form,
            'user' => $user,
            'invitation' => $invitation,
        ]);
    }
}
