<?php

namespace App\Controller;

use App\Form\SettingsType;
use App\Service\SettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/parametres', name: 'app_admin_settings', methods: ['GET', 'POST'])]
#[IsGranted('ROLE_ADMIN')]
class AdminSettingsController extends AbstractController
{
    public function __invoke(Request $request, SettingsService $settings): Response
    {
        $form = $this->createForm(SettingsType::class, [
            SettingsService::KEY_EMAIL_DOMAIN_STUDENT => $settings->getStudentEmailDomain(),
            SettingsService::KEY_EMAIL_DOMAIN_TEACHER => $settings->getTeacherEmailDomain(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $settings->set(SettingsService::KEY_EMAIL_DOMAIN_STUDENT, trim((string) $data[SettingsService::KEY_EMAIL_DOMAIN_STUDENT]));
            $settings->set(SettingsService::KEY_EMAIL_DOMAIN_TEACHER, trim((string) $data[SettingsService::KEY_EMAIL_DOMAIN_TEACHER]));

            $this->addFlash('success', 'Les formats d\'e-mail ont été mis à jour.');

            return $this->redirectToRoute('app_admin_settings');
        }

        return $this->render('admin/settings.html.twig', [
            'settingsForm' => $form,
        ]);
    }
}
