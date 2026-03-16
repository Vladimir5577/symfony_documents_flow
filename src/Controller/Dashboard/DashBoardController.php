<?php

namespace App\Controller\Dashboard;

use App\Entity\User\User;
use App\Repository\Organization\OrganizationRepository;
use Grpc\ChannelCredentials;
use Grpc\TestService\GreetServiceClient;
use Grpc\TestService\NoParam;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashBoardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dash_board')]
    public function dashBoard(
        #[Autowire('%env(MERCURE_PUBLIC_URL)%')] string $mercurePublicUrl,
        OrganizationRepository $organizationRepository,
    ): Response
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

        $organizations = [];
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $userOrganization = $user->getOrganization();
        $rootOrganization = $userOrganization && !$isAdmin ? $userOrganization->getRootOrganization() : null;
        $organizationTree = $organizationRepository->getOrganizationTree($isAdmin ? null : $rootOrganization);
        foreach ($organizationTree as $org) {
            $loadedOrg = $organizationRepository->findWithChildren($org->getId());
            if ($loadedOrg) {
                $organizations[] = $loadedOrg;
            }
        }
        if ($organizations === [] && $userOrganization) {
            $loadedOrg = $organizationRepository->findWithChildren($userOrganization->getId());
            if ($loadedOrg) {
                $organizations[] = $loadedOrg;
            }
        }

        return $this->render('dash_board/index.html.twig', [
            'active_tab' => 'dashboard',
            'controller_name' => 'DashBoardController',
            'user' => $user,
            'user_display_name' => $userDisplayName,
            'organization' => $organization,
            'organizations' => $organizations,
            'mercure_public_url' => $mercurePublicUrl,
            'current_user_id' => $user->getId(),
        ]);
    }


    #[Route('/call_grpc', name: 'app_call_grpc')]
    public function callGRPC(): Response
    {
        $client = new GreetServiceClient('host.docker.internal:8070', [
            'credentials' => ChannelCredentials::createInsecure(),
        ]);

        $request = new NoParam();
        [$reply, $status] = $client->SayHello($request)->wait();

        if ($status->code !== \Grpc\STATUS_OK) {
            return $this->json([
                'error' => $status->details ?? 'gRPC call failed',
                'code' => $status->code,
            ], 502);
        }

        return $this->json(['message' => $reply->getMessage()]);
    }
}
