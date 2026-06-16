<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\User;

use App\Entity\User\User;
use App\Entity\User\Worker;
use App\Enum\Kanban\KanbanBoardMemberRole;
use App\Enum\User\WorkerStatus;
use App\Repository\Kanban\KanbanBoardRepository;
use App\Repository\Kanban\Project\KanbanProjectRepository;
use App\Service\Kanban\KanbanService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class MeController extends AbstractController
{
    public function __construct(
        private readonly KanbanProjectRepository $projectRepository,
        private readonly KanbanBoardRepository $boardRepository,
        private readonly KanbanService $kanbanService,
        private readonly RoleHierarchyInterface $roleHierarchy,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('/spa/api/me', name: 'spa_api_me', methods: ['GET'])]
    public function __invoke(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->json($this->buildMePayload($user));
    }

    #[Route('/spa/api/me', name: 'spa_api_me_update', methods: ['PATCH', 'POST'])]
    public function update(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        try {
            $this->updateProfile(
                $user,
                $request->request->all(),
                $request->files->get('avatar')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        } catch (UniqueConstraintViolationException) {
            return $this->json(['error' => 'Пользователь с таким логином уже существует.'], 409);
        }

        return $this->json($this->buildMePayload($user));
    }

    #[Route('/spa/api/me/avatar', name: 'spa_api_me_avatar', methods: ['GET'])]
    public function avatar(
        #[CurrentUser] ?User $user,
        Request $request,
        #[Autowire('%private_upload_dir_users%')] string $usersUploadDir,
    ): Response {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $avatarName = $user->getAvatarName();
        if ($avatarName === null || $avatarName === '') {
            throw $this->createNotFoundException('Фото не найдено.');
        }

        $path = $usersUploadDir . \DIRECTORY_SEPARATOR . $user->getId() . \DIRECTORY_SEPARATOR . basename($avatarName);
        if (!is_file($path)) {
            throw $this->createNotFoundException('Файл не найден.');
        }

        $mimeType = mime_content_type($path) ?: 'image/jpeg';

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $mimeType);
        $response->setContentDisposition(
            $request->query->getBoolean('inline', true)
                ? ResponseHeaderBag::DISPOSITION_INLINE
                : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            basename($avatarName)
        );

        return $response;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function updateProfile(User $user, array $payload, ?UploadedFile $avatar): void
    {
        $login = trim((string) ($payload['login'] ?? ''));
        if ($login === '') {
            throw new \InvalidArgumentException('Логин не может быть пустым.');
        }

        $phone = trim((string) ($payload['phone'] ?? ''));
        $statusValue = trim((string) ($payload['status'] ?? ''));

        $password = (string) ($payload['password'] ?? '');
        $passwordRepeat = (string) ($payload['password_repeat'] ?? '');

        $user->setLogin($login);
        $user->setPhone($phone !== '' ? $phone : null);

        if ($statusValue !== '') {
            $status = WorkerStatus::tryFrom($statusValue);
            if ($status === null) {
                throw new \InvalidArgumentException('Некорректный статус сотрудника.');
            }

            $worker = $user->getWorker();
            if ($worker === null) {
                $worker = new Worker();
                $worker->setProfession('');
                $user->setWorker($worker);
            }

            $worker->setWorkerStatus($status);
        }

        if ($password !== '' || $passwordRepeat !== '') {
            if ($password === '' || $passwordRepeat === '') {
                throw new \InvalidArgumentException('Для смены пароля заполните оба поля.');
            }
            if ($password !== $passwordRepeat) {
                throw new \InvalidArgumentException('Пароли не совпадают.');
            }
            if (mb_strlen($password) < 6) {
                throw new \InvalidArgumentException('Новый пароль должен быть не короче 6 символов.');
            }

            $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        }

        if ($avatar !== null) {
            $user->setAvatarFile($avatar);
            $user->setUpdatedAt(new \DateTimeImmutable('now'));
        }

        $this->entityManager->flush();

        if ($avatar !== null) {
            // Avoid serializing UploadedFile in authenticated user session.
            $user->setAvatarFile(null);
        }
    }

    /**
     * Список проектов — копия ProjectKanbanController::personalProjects (JSON для SPA).
     *
     * @return list<array{
     *     id: int,
     *     name: string,
     *     description: string|null,
     *     isOwner: bool,
     *     isProjectAdmin: bool,
     *     entryBoardId: int|null,
     * }>
     */
    private function buildProjectsList(User $user): array
    {
        $projects = $this->projectRepository->findByMemberWithAccessibleBoards($user);
        $projectIds = array_map(static fn ($p) => $p->getId(), $projects);

        $firstBoardsByProject = $this->boardRepository->findFirstBoardByProjectIds($projectIds);
        $boardsWithAssignedCards = $this->boardRepository->findAllByUserWithAssignedCards($user);
        $projectIdToFirstBoardWithCards = [];
        foreach ($boardsWithAssignedCards as $board) {
            $pid = $board->getProject()?->getId();
            if ($pid !== null && !isset($projectIdToFirstBoardWithCards[$pid])) {
                $projectIdToFirstBoardWithCards[$pid] = $board;
            }
        }

        $result = [];
        foreach ($projects as $project) {
            $pid = $project->getId();
            $firstBoard = $firstBoardsByProject[$pid] ?? null;
            $memberRole = $firstBoard
                ? $this->kanbanService->getMemberRole($firstBoard, $user)
                : KanbanBoardMemberRole::KANBAN_ADMIN;

            $isProjectAdmin = false;
            if ($memberRole === KanbanBoardMemberRole::KANBAN_ADMIN) {
                $isProjectAdmin = true;
                $entryBoard = $firstBoard ?? $projectIdToFirstBoardWithCards[$pid] ?? null;
            } else {
                $entryBoard = $projectIdToFirstBoardWithCards[$pid] ?? null;
            }

            $entryBoardId = $entryBoard?->getId();
            $result[] = [
                'id' => $pid,
                'name' => $project->getName() ?? 'Проект',
                'description' => $project->getDescription(),
                'isOwner' => $project->getOwner() === $user,
                'isProjectAdmin' => $isProjectAdmin,
                'entryBoardId' => $entryBoardId
            ];
        }

        return $result;
    }

    /**
     * @return array{
     *   id: int|null,
     *   login: string,
     *   roles: list<string>,
     *   lastname: string|null,
     *   firstname: string|null,
     *   patronymic: string|null,
     *   phone: string|null,
     *   status: string|null,
     *   statusLabel: string|null,
     *   avatar: string|null,
     *   avatarUrl: string|null,
     *   projects: list<array{
     *      id:int,name:string,description:string|null,isOwner:bool,isProjectAdmin:bool,entryBoardId:int|null
     *   }>
     * }
     */
    private function buildMePayload(User $user): array
    {
        $workerStatus = $user->getWorker()?->getWorkerStatus();
        $avatarUrl = null;

        if ($user->getAvatarName() !== null && $user->getAvatarName() !== '') {
            $avatarVersion = $user->getUpdatedAt()?->getTimestamp() ?? time();
            // Relative path — same-origin on SPA client (Next proxy), avoids cross-port CORS.
            $avatarUrl = $this->generateUrl(
                'spa_api_me_avatar',
                ['inline' => 1, 'v' => $avatarVersion],
                UrlGeneratorInterface::ABSOLUTE_PATH
            );
        }

        return [
            'id' => $user->getId(),
            'login' => $user->getUserIdentifier(),
            'roles' => array_values($this->roleHierarchy->getReachableRoleNames($user->getRoles())),
            'lastname' => $user->getLastname(),
            'firstname' => $user->getFirstname(),
            'patronymic' => $user->getPatronymic(),
            'phone' => $user->getPhone(),
            'status' => $workerStatus?->value,
            'statusLabel' => $workerStatus?->getLabel(),
            'avatarUrl' => $avatarUrl,
            'projects' => $this->buildProjectsList($user),
        ];
    }
}
