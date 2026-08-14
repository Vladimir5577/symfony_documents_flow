<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Inventory;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Inventory\Upd;
use App\Entity\Inventory\UpdFile;
use App\Entity\Organization\AbstractOrganization;
use App\Entity\User\User;
use App\Repository\Inventory\NomenclatureItemRepository;
use App\Repository\Inventory\UpdFileRepository;
use App\Repository\Inventory\UpdRepository;
use App\Repository\Organization\OrganizationRepository;
use App\Service\Inventory\InventoryAccessResolver;
use App\Service\Inventory\UpdFileService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Универсальные передаточные документы и их файлы.
 *
 * Документ заводится раньше позиций и живёт по видимости своей организации-получателя:
 * его целиком видит админ этой организации и её поддерева, главный администратор — все,
 * ответственный за категорию — никакие. Номер и дату последний всё равно увидит
 * на карточке своей позиции, там отдаётся компактная ссылка без файлов.
 *
 * Раз видимость и право править совпадают по множеству людей, проверка одна —
 * isOrganizationAdmin, и она же в resolveUpd.
 */
#[Route('/spa/api/inventory/upd')]
#[IsGranted('ROLE_INVENTORY_MANAGER')]
final class UpdController extends AbstractController
{
    public function __construct(
        private readonly UpdRepository $updRepository,
        private readonly UpdFileRepository $fileRepository,
        private readonly NomenclatureItemRepository $itemRepository,
        private readonly OrganizationRepository $organizationRepository,
        private readonly InventoryAccessResolver $accessResolver,
        private readonly UpdFileService $fileService,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'spa_api_inventory_upd_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $scope = $this->accessResolver->resolveCurrent();

        $page = max(1, $request->query->getInt('page', 1));
        $pageSize = max(1, min(100, $request->query->getInt('page_size', 20)));

        $pagination = $this->updRepository->findPaginated(
            $scope,
            $this->filtersFromRequest($request),
            $page,
            $pageSize,
        );

        return $this->json([
            'upd' => array_map(fn (Upd $upd): array => $this->format($upd), $pagination['items']),
            'pagination' => [
                'current_page' => $pagination['page'],
                'total_pages' => $pagination['totalPages'],
                'total_items' => $pagination['total'],
                'items_per_page' => $pagination['limit'],
            ],
            // Ответственный за категорию проходит сюда по роли, но список у него пуст,
            // а создание вернёт 403. Отличить его от админа организации по одной роли
            // фронт не может — отдаём готовый ответ, чтобы он не рисовал кнопку,
            // обречённую на отказ. Так же сделано в списке доступов.
            'canManage' => $scope->full || $scope->adminOrgIds !== [],
        ]);
    }

    #[Route('/{id}', name: 'spa_api_inventory_upd_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        [$upd, $error] = $this->resolveUpd($id);
        if ($error !== null) {
            return $error;
        }

        return $this->json([
            'upd' => $this->format($upd, $this->itemRepository->countByUpd($upd)),
            'files' => array_map(
                fn (UpdFile $file): array => $this->formatFile($file),
                $this->fileRepository->findByUpd($upd),
            ),
        ]);
    }

    #[Route('', name: 'spa_api_inventory_upd_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?User $currentUser): JsonResponse
    {
        if (!$currentUser instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $organizationId = (int) ($payload['organizationId'] ?? 0);
        if (!$this->accessResolver->resolveCurrent()->isOrganizationAdmin($organizationId)) {
            return $this->json(
                ['error' => SpaApiError::INVENTORY_ORGANIZATION_NOT_ALLOWED],
                Response::HTTP_FORBIDDEN,
            );
        }

        $organization = $this->organizationRepository->find($organizationId);
        if (!$organization instanceof AbstractOrganization) {
            return $this->json(['error' => SpaApiError::ORGANIZATION_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $upd = new Upd();
        $upd->setOrganization($organization);
        $upd->setCreatedBy($currentUser);

        $fieldError = $this->applyFields($upd, $payload, true);
        if ($fieldError !== null) {
            return $this->json(['error' => $fieldError], Response::HTTP_BAD_REQUEST);
        }

        $this->em->persist($upd);
        $this->em->flush();

        return $this->json(['upd' => $this->format($upd, 0)], Response::HTTP_CREATED);
    }

    /**
     * Организация не меняется: позиции уже привязаны и проверялись на вхождение
     * в её поддерево, а смена получателя задним числом сделала бы часть из них
     * невалидными молча. Ошиблись организацией — документ заводится заново.
     */
    #[Route('/{id}', name: 'spa_api_inventory_upd_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        [$upd, $error] = $this->resolveUpd($id);
        if ($error !== null) {
            return $error;
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $fieldError = $this->applyFields($upd, $payload, false);
        if ($fieldError !== null) {
            return $this->json(['error' => $fieldError], Response::HTTP_BAD_REQUEST);
        }

        $this->em->flush();

        return $this->json(['upd' => $this->format($upd, $this->itemRepository->countByUpd($upd))]);
    }

    #[Route('/{id}', name: 'spa_api_inventory_upd_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        [$upd, $error] = $this->resolveUpd($id);
        if ($error !== null) {
            return $error;
        }

        $itemsCount = $this->itemRepository->countByUpd($upd);
        if ($itemsCount > 0) {
            return $this->json([
                'error' => SpaApiError::INVENTORY_UPD_HAS_ITEMS,
                'itemsCount' => $itemsCount,
            ], Response::HTTP_CONFLICT);
        }

        // Файлы сносим до документа: внешний ключ стоит CASCADE, строки уехали бы
        // сами, а объекты остались бы висеть в бакете — ключи хранятся только в них.
        $this->fileService->deleteAllForUpd($upd);

        $this->em->remove($upd);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/{id}/files', name: 'spa_api_inventory_upd_file_upload', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function uploadFile(int $id, Request $request, #[CurrentUser] ?User $currentUser): JsonResponse
    {
        [$upd, $error] = $this->resolveUpd($id);
        if ($error !== null) {
            return $error;
        }

        $file = $request->files->get('file');
        if ($file === null) {
            // Сюда же приезжает запрос крупнее post_max_size: PHP выбрасывает тело
            // целиком, и файла в нём просто нет.
            return $this->json(
                ['error' => SpaApiError::INVENTORY_FILE_UPLOAD_FAILED],
                Response::HTTP_BAD_REQUEST,
            );
        }

        [$updFile, $uploadError] = $this->fileService->upload(
            $file,
            $upd,
            $currentUser instanceof User ? $currentUser : null,
        );
        if ($uploadError !== null) {
            return $this->json(['error' => $uploadError], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        return $this->json(['file' => $this->formatFile($updFile)], Response::HTTP_CREATED);
    }

    #[Route(
        '/{id}/files/{fileId}',
        name: 'spa_api_inventory_upd_file_delete',
        requirements: ['id' => '\d+', 'fileId' => '\d+'],
        methods: ['DELETE'],
    )]
    public function deleteFile(int $id, int $fileId): JsonResponse
    {
        [$upd, $error] = $this->resolveUpd($id);
        if ($error !== null) {
            return $error;
        }

        $file = $this->resolveFile($upd, $fileId);
        if ($file === null) {
            return $this->json(['error' => SpaApiError::INVENTORY_FILE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $this->fileService->delete($file);

        return $this->json(['success' => true]);
    }

    #[Route(
        '/{id}/files/{fileId}',
        name: 'spa_api_inventory_upd_file_download',
        requirements: ['id' => '\d+', 'fileId' => '\d+'],
        methods: ['GET'],
    )]
    public function downloadFile(int $id, int $fileId): Response
    {
        [$upd, $error] = $this->resolveUpd($id);
        if ($error !== null) {
            return $error;
        }

        $file = $this->resolveFile($upd, $fileId);
        if ($file === null) {
            return $this->json(['error' => SpaApiError::INVENTORY_FILE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if (!$this->fileService->exists($file)) {
            return $this->json(['error' => SpaApiError::INVENTORY_FILE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        return $this->streamDownload($file);
    }

    /**
     * Имя намеренно не `stream()`: в AbstractController уже есть protected-метод
     * с таким именем, и приватный одноимённый роняет загрузку класса целиком —
     * вместе со всем контейнером. В ExportController та же оговорка.
     */
    private function streamDownload(UpdFile $file): StreamedResponse
    {
        $stream = $this->fileService->getObjectStream($file);

        $response = new StreamedResponse(static function () use ($stream): void {
            while (!$stream->eof()) {
                echo $stream->read(8192);
            }
        });

        $response->headers->set('Content-Type', $file->getContentType());
        $response->headers->set('Content-Length', (string) $file->getSizeBytes());
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $file->getFilename()),
        );
        $response->headers->set('Cache-Control', 'max-age=0, private');

        return $response;
    }

    /**
     * @return array{0: Upd|null, 1: JsonResponse|null}
     */
    private function resolveUpd(int $id): array
    {
        $upd = $this->updRepository->find($id);
        if (!$upd instanceof Upd) {
            return [null, $this->json(['error' => SpaApiError::INVENTORY_UPD_NOT_FOUND], Response::HTTP_NOT_FOUND)];
        }

        if (!$this->accessResolver->resolveCurrent()->isOrganizationAdmin((int) $upd->getOrganization()->getId())) {
            return [null, $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN)];
        }

        return [$upd, null];
    }

    /**
     * Файл ищем в пределах документа, а не по одному id: иначе, зная номер строки,
     * можно было бы скачать вложение чужого УПД через свой.
     */
    private function resolveFile(Upd $upd, int $fileId): ?UpdFile
    {
        $file = $this->fileRepository->find($fileId);
        if (!$file instanceof UpdFile || $file->getUpd()->getId() !== $upd->getId()) {
            return null;
        }

        return $file;
    }

    /**
     * @return string|null код ошибки из SpaApiError, если значение не разобрано
     */
    private function applyFields(Upd $upd, array $payload, bool $isCreate): ?string
    {
        if ($isCreate || array_key_exists('number', $payload)) {
            $number = trim((string) ($payload['number'] ?? ''));
            if ($number === '') {
                return SpaApiError::INVENTORY_UPD_NUMBER_REQUIRED;
            }
            $upd->setNumber(mb_substr($number, 0, 64));
        }

        if ($isCreate || array_key_exists('date', $payload)) {
            // Та же сверка обратным форматированием, что у даты приобретения товара:
            // createFromFormat возвращает false только на структурном мусоре,
            // а «2026-02-31» молча переполнил бы в 3 марта.
            $raw = trim((string) ($payload['date'] ?? ''));
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
            if ($date === false || $date->format('Y-m-d') !== $raw) {
                return SpaApiError::INVENTORY_INVALID_DATE;
            }
            $upd->setDate($date);
        }

        if (array_key_exists('supplier', $payload)) {
            $supplier = trim((string) $payload['supplier']);
            $upd->setSupplier($supplier === '' ? null : mb_substr($supplier, 0, 255));
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function filtersFromRequest(Request $request): array
    {
        $organizationId = $request->query->getInt('organization_id');
        $organizationIds = null;
        if ($organizationId > 0) {
            $organizationIds = $request->query->getBoolean('only_own_organization')
                ? [$organizationId]
                : $this->organizationRepository->findOrganizationWithChildrenIds($organizationId);
        }

        return [
            'organizationIds' => $organizationIds,
            'dateFrom' => $this->parseDate((string) $request->query->get('date_from', '')),
            'dateTo' => $this->parseDate((string) $request->query->get('date_to', '')),
            'search' => (string) $request->query->get('search', ''),
        ];
    }

    /**
     * Границы периода в фильтре — мусор просто игнорируем: это не ввод данных,
     * а сужение выборки, и ронять список из-за кривого query-параметра незачем.
     */
    private function parseDate(string $raw): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        return $date !== false && $date->format('Y-m-d') === $raw ? $date : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function format(Upd $upd, ?int $itemsCount = null): array
    {
        $organization = $upd->getOrganization();
        $createdBy = $upd->getCreatedBy();

        $formatted = [
            'id' => $upd->getId(),
            'number' => $upd->getNumber(),
            'date' => $upd->getDate()->format('Y-m-d'),
            'supplier' => $upd->getSupplier(),
            'label' => $upd->getLabel(),
            'organization' => [
                'id' => $organization->getId(),
                'name' => $organization->getName(),
                'fullName' => $organization->getFullName(),
                'path' => $organization->getPath(),
            ],
            'createdBy' => $createdBy !== null
                ? ['id' => $createdBy->getId(), 'login' => $createdBy->getLogin()]
                : null,
            'createdAt' => $upd->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];

        if ($itemsCount !== null) {
            $formatted['itemsCount'] = $itemsCount;
        }

        return $formatted;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatFile(UpdFile $file): array
    {
        $uploadedBy = $file->getUploadedBy();

        return [
            'id' => $file->getId(),
            'filename' => $file->getFilename(),
            'contentType' => $file->getContentType(),
            'sizeBytes' => $file->getSizeBytes(),
            'uploadedBy' => $uploadedBy !== null
                ? ['id' => $uploadedBy->getId(), 'login' => $uploadedBy->getLogin()]
                : null,
            'createdAt' => $file->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
