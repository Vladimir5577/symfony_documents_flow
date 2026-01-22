<?php

namespace App\Controller\Dashboard;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class DashBoardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dash_board')]
    public function dashBoard(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $organization = $user->getOrganization();

        $userDisplayName = trim(implode(' ', array_filter([
            $user->getLastname(),
            $user->getFirstname(),
            $user->getPatronymic(),
        ]))) ?: $user->getLogin();

        return $this->render('dash_board/index.html.twig', [
            'active_tab' => 'dashboard',
            'controller_name' => 'DashBoardController',
            'user' => $user,
            'user_display_name' => $userDisplayName,
            'organization' => $organization,
        ]);
    }
}
