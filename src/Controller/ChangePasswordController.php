<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class ChangePasswordController extends AbstractController
{
    #[Route('/change-password', name: 'app_change_password', methods: ['GET', 'POST'])]
    public function changePassword(Request $request, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $currentPassword = (string) $form->get('currentPassword')->getData();

            if (!$passwordHasher->isPasswordValid($user, $currentPassword)) {
                $form->get('currentPassword')->addError(new FormError('Le mot de passe actuel est incorrect.'));
            } else {
                /** @var string $newPassword */
                $newPassword = (string) $form->get('newPassword')->getData();
                $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
                $entityManager->flush();

                $this->addFlash('success', 'Votre mot de passe a été modifié avec succès.');

                return $this->redirectToRoute('app_home');
            }
        }

        return $this->render('security/change_password.html.twig', [
            'changePasswordForm' => $form,
        ]);
    }
}
