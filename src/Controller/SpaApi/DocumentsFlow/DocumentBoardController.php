<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\DocumentsFlow;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\Document;
use App\Entity\Document\DocumentBoard;
use App\Entity\User\User;
use App\Repository\Document\DocumentBoardRepository;
use App\Repository\Document\DocumentRepository;
use App\Repository\User\UserRepository;
use App\Service\SpaApi\Documents\DocumentAccessService;
use App\Service\SpaApi\Documents\DocumentApiPresenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/documents-flow/boards')]
final class DocumentBoardController extends AbstractController
{
    private const SCENE_MAX_BYTES = 8_000_000;

    public function __construct(
        private readonly DocumentBoardRepository $boardRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly UserRepository $userRepository,
        private readonly DocumentAccessService $accessService,
        private readonly DocumentApiPresenter $presenter,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'spa_api_documents_flow_boards_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $documentId = $request->query->get('documentId');
        $documentId = $documentId === null || $documentId === '' ? null : (int) $documentId;

        $boards = $this->boardRepository->findVisibleTo($user, $this->accessService->isAdmin(), $documentId);

        return $this->json([
            'items' => array_map(
                fn (DocumentBoard $board) => $this->presentSummary($board, $user),
                $boards,
            ),
        ]);
    }

    #[Route('', name: 'spa_api_documents_flow_boards_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $payload = $this->decodeJson($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $title = trim((string) ($payload['title'] ?? 'Схема'));
        if ($title === '') {
            return $this->json(['error' => SpaApiError::BOARD_TITLE_REQUIRED], Response::HTTP_BAD_REQUEST);
        }
        if (mb_strlen($title) > 255) {
            return $this->json(['error' => SpaApiError::BOARD_TITLE_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        $document = null;
        if (isset($payload['documentId']) && $payload['documentId'] !== null && $payload['documentId'] !== '') {
            $document = $this->documentRepository->find((int) $payload['documentId']);
            if (!$document instanceof Document) {
                return $this->json(['error' => SpaApiError::DOCUMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
            }
            if (!$this->accessService->canEditOutgoingDocument($document, $user)) {
                return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
            }
        }

        $board = new DocumentBoard();
        $board->setTitle($title);
        $board->setCreatedBy($user);
        $board->setDocument($document);
        $this->replaceParticipants($board, $payload['userIds'] ?? []);

        $this->entityManager->persist($board);
        $this->entityManager->flush();

        return $this->json(['board' => $this->presentSummary($board, $user)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'spa_api_documents_flow_boards_view', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function view(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $board = $this->visibleBoard($id, $user);
        if ($board instanceof JsonResponse) {
            return $board;
        }

        if ($request->query->getBoolean('access')) {
            return $this->json([
                'board' => [
                    'me' => $this->presenter->presentUserBrief($user),
                    'canManage' => $user instanceof User && $this->canManage($board, $user),
                ],
            ]);
        }

        return $this->json(['board' => $this->presentDetail($board, $user)]);
    }

    #[Route('/{id}', name: 'spa_api_documents_flow_boards_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $board = $this->visibleBoard($id, $user);
        if ($board instanceof JsonResponse) {
            return $board;
        }
        if (!$user instanceof User || !$this->canManage($board, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $payload = $this->decodeJson($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        if (array_key_exists('title', $payload)) {
            $title = trim((string) $payload['title']);
            if ($title === '' || mb_strlen($title) > 255) {
                return $this->json(['error' => SpaApiError::BOARD_TITLE_REQUIRED], Response::HTTP_BAD_REQUEST);
            }
            $board->setTitle($title);
            $board->touch();
        }

        if (array_key_exists('userIds', $payload)) {
            if (!is_array($payload['userIds'])) {
                return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
            }
            $this->replaceParticipants($board, $payload['userIds']);
            $board->touch();
        }

        $this->entityManager->flush();

        return $this->json(['board' => $this->presentDetail($board, $user)]);
    }

    #[Route('/{id}/scene', name: 'spa_api_documents_flow_boards_scene', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function saveScene(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $board = $this->visibleBoard($id, $user);
        if ($board instanceof JsonResponse) {
            return $board;
        }

        if (strlen($request->getContent()) > self::SCENE_MAX_BYTES) {
            return $this->json(['error' => SpaApiError::BOARD_SCENE_TOO_LARGE], Response::HTTP_BAD_REQUEST);
        }

        $payload = $this->decodeJson($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $scene = $this->encodeScene($payload);
        if ($scene === null) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $board->setScene($scene);
        $this->entityManager->flush();

        return $this->json(['ok' => true]);
    }

    #[Route('/{id}', name: 'spa_api_documents_flow_boards_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $board = $this->visibleBoard($id, $user);
        if ($board instanceof JsonResponse) {
            return $board;
        }
        if (!$user instanceof User || !$this->canManage($board, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $this->entityManager->remove($board);
        $this->entityManager->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function visibleBoard(int $id, ?User $user): DocumentBoard|JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $board = $this->boardRepository->find($id);
        if (!$board instanceof DocumentBoard) {
            return $this->json(['error' => SpaApiError::BOARD_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }
        if (!$this->canView($board, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        return $board;
    }

    private function canView(DocumentBoard $board, User $user): bool
    {
        if ($this->accessService->isAdmin()) {
            return true;
        }
        if ($board->getCreatedBy()?->getId() === $user->getId()) {
            return true;
        }
        foreach ($board->getParticipants() as $participant) {
            if ($participant->getId() === $user->getId()) {
                return true;
            }
        }

        $document = $board->getDocument();

        return $document instanceof Document && $this->accessService->canViewDocument($document, $user);
    }

    private function canManage(DocumentBoard $board, User $user): bool
    {
        if ($this->accessService->isAdmin()) {
            return true;
        }
        if ($board->getCreatedBy()?->getId() === $user->getId()) {
            return true;
        }

        $document = $board->getDocument();

        return $document instanceof Document && $this->accessService->canEditOutgoingDocument($document, $user);
    }

    /** @param mixed $userIds */
    private function replaceParticipants(DocumentBoard $board, mixed $userIds): void
    {
        $board->getParticipants()->clear();
        if (!is_array($userIds)) {
            return;
        }

        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id) => (int) $id, $userIds),
            static fn (int $id) => $id > 0,
        )));
        if ($ids === []) {
            return;
        }

        foreach ($this->userRepository->findBy(['id' => $ids]) as $participant) {
            if ($participant->getId() === $board->getCreatedBy()?->getId()) {
                continue;
            }
            $board->getParticipants()->add($participant);
        }
    }

    /** @param array<string, mixed> $payload */
    private function encodeScene(array $payload): ?string
    {
        $elements = $payload['elements'] ?? null;
        if (!is_array($elements) || count($elements) > 20000) {
            return null;
        }

        $files = $payload['files'] ?? [];
        if (!is_array($files) || (array_is_list($files) && $files !== [])) {
            return null;
        }

        try {
            $json = json_encode(
                [
                    'elements' => array_values($elements),
                    'files' => $files === [] ? new \stdClass() : $files,
                ],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException) {
            return null;
        }

        if (strlen($json) > self::SCENE_MAX_BYTES) {
            return null;
        }

        return $json;
    }

    /** @return array<string, mixed> */
    private function presentSummary(DocumentBoard $board, User $user): array
    {
        $document = $board->getDocument();

        return [
            'id' => $board->getId(),
            'title' => $board->getTitle(),
            'documentId' => $document?->getId(),
            'documentName' => $document?->getName(),
            'documentDirection' => $this->documentDirection($board, $user),
            'updatedAt' => $board->getUpdatedAt()->format(DATE_ATOM),
            'canManage' => $this->canManage($board, $user),
        ];
    }

    /** @return array<string, mixed> */
    private function presentDetail(DocumentBoard $board, User $user): array
    {
        $participants = [];
        foreach ($board->getParticipants() as $participant) {
            $brief = $this->presenter->presentUserBrief($participant);
            if ($brief !== null) {
                $participants[] = $brief;
            }
        }

        return [
            ...$this->presentSummary($board, $user),
            'scene' => $board->scenePayload(),
            'participants' => $participants,
            'me' => $this->presenter->presentUserBrief($user),
        ];
    }

    private function documentDirection(DocumentBoard $board, User $user): ?string
    {
        $document = $board->getDocument();
        if (!$document instanceof Document) {
            return null;
        }
        if ($document->getCreatedBy()?->getId() === $user->getId() || $this->accessService->isAdmin()) {
            return 'outgoing';
        }
        if ($this->accessService->findUserRecipient($document, $user) !== null) {
            return 'incoming';
        }

        return null;
    }

    /** @return array<string, mixed>|JsonResponse */
    private function decodeJson(Request $request): array|JsonResponse
    {
        if ($request->getContent() === '') {
            return [];
        }

        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        return $payload;
    }
}
