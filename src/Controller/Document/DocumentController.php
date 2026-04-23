<?php


namespace App\Controller\Document;

use App\Entity\Document\Document;
use App\Entity\Document\DocumentHistory;
use App\Entity\Document\DocumentUserRecipient;
use App\Entity\Document\File;
use App\Entity\Organization\AbstractOrganization;
use App\Entity\Organization\Department;
use App\Entity\User\User;
use App\Enum\DocumentRecipientRole;
use App\Enum\DocumentStatus;
use App\Repository\Document\DocumentHistoryRepository;
use App\Repository\Document\DocumentRepository;
use App\Repository\Document\DocumentTypeRepository;
use App\Repository\Document\DocumentUserRecipientRepository;
use App\Repository\Organization\OrganizationRepository;
use App\Service\Notification\NotificationService;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class DocumentController extends AbstractController
{
    #[Route('/new_document', name: 'app_new_document')]
    public function index(DocumentTypeRepository $documentTypeRepository): Response
    {
        $documentTypes = $documentTypeRepository->findBy([], ['name' => 'ASC']);

        return $this->render('document/new_document.html.twig', [
            'active_tab' => 'new_document',
            'document_types' => $documentTypes,
        ]);
    }

    #[Route('/create_document/{type_id}', name: 'app_create_document', methods: ['GET', 'POST'])]
    public function createDocument(
        int                    $type_id,
        Request                $request,
        DocumentTypeRepository $documentTypeRepository,
        OrganizationRepository $organizationRepository,
        UserRepository         $userRepository,
        EntityManagerInterface $entityManager,
        ValidatorInterface     $validator,
        NotificationService    $notificationService,
    ): Response
    {
        $currentUser = $this->getUser();
//        if (!$currentUser instanceof User) {
//            $this->addFlash('error', 'Необходимо войти в систему для создания документа.');
//            return $this->redirectToRoute('app_login');
//        }

        $documentType = $documentTypeRepository->find($type_id);
        if (!$documentType) {
            throw $this->createNotFoundException('Тип документа не найден');
        }

        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $userOrganization = $currentUser->getOrganization();

        // Получаем дерево организаций для пользователя
        $organizationTree = $organizationRepository->getOrganizationTree(null);

        // Загружаем организации с дочерними для отображения дерева
        $organizationsWithChildren = [];
        if (!empty($organizationTree)) {
            foreach ($organizationTree as $org) {
                $loadedOrg = $organizationRepository->findWithChildren($org->getId());
                if ($loadedOrg) {
                    $organizationsWithChildren[] = $loadedOrg;
                }
            }
        }

        // Если всё ещё пусто, но у пользователя есть организация, загружаем её
        if (empty($organizationsWithChildren) && $userOrganization) {
            $loadedOrg = $organizationRepository->findWithChildren($userOrganization->getId());
            if ($loadedOrg) {
                $organizationsWithChildren[] = $loadedOrg;
            }
        }

        $formData = [];
        $initialFormData = [
            'status' => DocumentStatus::DRAFT->value,
            'is_published' => false,
        ];
        // Устанавливаем организацию пользователя по умолчанию
        if ($userOrganization) {
            $initialFormData['organization_id'] = $userOrganization->getId();
        }

        // Пользователей для мультиселекта не грузим — выбор только через модалку по организациям.
        // При повторном отображении формы (ошибка валидации) подгружаем только выбранных по id.
        $renderForm = function (array $data = [], array $executorUsers = [], array $recipientUsers = []) use ($documentType, $organizationsWithChildren, $initialFormData, $userRepository, $isAdmin, $userOrganization): Response {
            $formData = $data !== [] ? $data : $initialFormData;
            if ($executorUsers === [] && !empty($formData['executor_user_ids'])) {
                $executorUsers = $userRepository->findByIds((array) $formData['executor_user_ids']);
            } elseif ($executorUsers === [] && !empty($formData['user_ids'])) {
                $executorUsers = $userRepository->findByIds((array) $formData['user_ids']);
            }
            if ($recipientUsers === [] && !empty($formData['recipient_user_ids'])) {
                $recipientUsers = $userRepository->findByIds((array) $formData['recipient_user_ids']);
            }
            return $this->render('document/create_document.html.twig', [
                'active_tab' => 'new_document',
                'document_type' => $documentType,
                'organizations' => $organizationsWithChildren,
                'form_data' => $formData,
                'document_statuses' => DocumentStatus::getCreationChoices(),
                'executor_users' => $executorUsers,
                'recipient_users' => $recipientUsers,
                'is_admin' => $isAdmin,
                'user_organization' => $userOrganization,
            ]);
        };

        if (!$request->isMethod('POST')) {
            return $renderForm([], []);
        }

        $formData = $request->request->all();

        // Валидация CSRF токена
        if (!$this->isCsrfTokenValid('create_document', $formData['_csrf_token'] ?? '')) {
            $this->addFlash('error', 'Неверный CSRF токен.');
            return $this->redirectToRoute('app_create_document', ['type_id' => $type_id]);
        }

        // Получаем название документа
        $name = trim((string)($formData['name'] ?? ''));
        if ($name === '') {
            $this->addFlash('error', 'Название документа обязательно для заполнения.');
            return $renderForm($formData);
        }

        // Получаем организацию: для не-админа всегда берём организацию пользователя
        if ($isAdmin) {
            $organizationId = (int)($formData['organization_id'] ?? 0);
            if ($organizationId <= 0) {
                $this->addFlash('error', 'Необходимо выбрать организацию.');
                return $renderForm($formData);
            }
        } else {
            if (!$userOrganization) {
                $this->addFlash('error', 'Не удалось определить организацию. Обратитесь к администратору.');
                return $this->redirectToRoute('app_new_document');
            }
            $organizationId = $userOrganization->getId();
        }

        $organization = $organizationRepository->find($organizationId);
        if (!$organization) {
            $this->addFlash('error', 'Организация не найдена.');
            return $renderForm($formData);
        }

        // Создаем документ
        $document = new Document();
        $document->setName($name);
        $document->setDescription(trim((string)($formData['description'] ?? '')) ?: null);
        $document->setOrganizationCreator($organization);
        $document->setDocumentType($documentType);
        // Обрабатываем статус (по умолчанию — черновик)
        $statusStr = trim((string)($formData['status'] ?? ''));
        if ($statusStr !== '') {
            try {
                $status = DocumentStatus::from($statusStr);
                $document->setStatus($status);
            } catch (\ValueError $e) {
                $this->addFlash('error', 'Неверный статус документа.');
                return $renderForm($formData);
            }
        } else {
            $document->setStatus(DocumentStatus::DRAFT);
        }

        $document->setCreatedBy($currentUser);

        // Обрабатываем deadline
        $deadlineStr = trim((string)($formData['deadline'] ?? ''));
        if ($deadlineStr !== '') {
            try {
                $deadline = new \DateTime($deadlineStr);
                $document->setDeadline($deadline);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Неверный формат даты срока выполнения.');
                return $renderForm($formData);
            }
        }

        $wantsPublish = isset($formData['is_published']) && (bool) $formData['is_published'];
        if ($wantsPublish) {
            if ($document->getStatus() === DocumentStatus::DRAFT) {
                $this->addFlash('error', 'Документ нельзя опубликовать в статусе черновик.');
                return $renderForm($formData);
            }
            $executorUserIds = $formData['executor_user_ids'] ?? [];
            $recipientUserIds = $formData['recipient_user_ids'] ?? [];
            $legacyUserIds = $formData['user_ids'] ?? [];

            if (!is_array($executorUserIds)) {
                $executorUserIds = [];
            }
            if (!is_array($recipientUserIds)) {
                $recipientUserIds = [];
            }
            if (!is_array($legacyUserIds)) {
                $legacyUserIds = [];
            }

            $allRecipientIds = array_merge($executorUserIds, $recipientUserIds, $legacyUserIds);
            if (empty(array_filter(array_map('intval', $allRecipientIds)))) {
                $this->addFlash('error', 'Документ нельзя опубликовать без получателей.');
                return $renderForm($formData);
            }
        }

        $document->setIsPublished($wantsPublish);

        // Валидация документа
        $errors = $validator->validate($document);
        if (count($errors) > 0) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error->getMessage());
            }
            return $renderForm($formData);
        }

        // Валидация файлов (до создания документа)
        $uploadedFiles = $request->files->get('files');
        if (!\is_array($uploadedFiles)) {
            $uploadedFiles = $uploadedFiles ? [$uploadedFiles] : [];
        }
        $maxFileSizeBytes = 5 * 1024 * 1024; // 5 МБ, как на странице загрузки файлов
        foreach ($uploadedFiles as $uploadedFile) {
            if (!$uploadedFile instanceof UploadedFile) {
                continue;
            }
            if ($uploadedFile->getSize() > $maxFileSizeBytes) {
                $this->addFlash('error', sprintf(
                    'Файл «%s» превышает допустимый размер (макс. 5 МБ). При ошибке валидации файлы нужно выбрать заново.',
                    $uploadedFile->getClientOriginalName()
                ));
                return $renderForm($formData);
            }
            if ($uploadedFile->getError() !== \UPLOAD_ERR_OK) {
                $this->addFlash('error', sprintf(
                    'Ошибка загрузки файла «%s». При ошибке валидации файлы нужно выбрать заново.',
                    $uploadedFile->getClientOriginalName()
                ));
                return $renderForm($formData);
            }
        }

        // Сохраняем документ
        $entityManager->persist($document);

        // Присваиваем документ выбранным пользователям
        $executorUserIds = $formData['executor_user_ids'] ?? [];
        $recipientUserIds = $formData['recipient_user_ids'] ?? [];
        $legacyUserIds = $formData['user_ids'] ?? [];
        if (!is_array($executorUserIds)) {
            $executorUserIds = [];
        }
        if (!is_array($recipientUserIds)) {
            $recipientUserIds = [];
        }
        if (!is_array($legacyUserIds)) {
            $legacyUserIds = [];
        }

        $executorUserIds = array_values(array_unique(array_filter(array_map('intval', array_merge($executorUserIds, $legacyUserIds)), fn($id) => $id > 0)));
        $recipientUserIds = array_values(array_unique(array_filter(array_map('intval', $recipientUserIds), fn($id) => $id > 0)));

        if (!empty($executorUserIds) || !empty($recipientUserIds)) {
            $now = new \DateTimeImmutable();
            foreach ($executorUserIds as $userId) {
                $userId = (int)$userId;
                if ($userId <= 0) {
                    continue;
                }
                $recipientUser = $userRepository->findActive($userId);
                if (!$recipientUser) {
                    continue;
                }

                $recipient = new DocumentUserRecipient();
                $recipient->setDocument($document);
                $recipient->setUser($recipientUser);
                $recipient->setRole(DocumentRecipientRole::EXECUTOR);
                $recipient->setStatus(DocumentStatus::NEW);
                $recipient->setCreatedAt($now);
                $recipient->setUpdatedAt($now);

                $document->addUserRecipient($recipient);
                $entityManager->persist($recipient);
            }

            foreach ($recipientUserIds as $userId) {
                $userId = (int)$userId;
                if ($userId <= 0) {
                    continue;
                }
                $recipientUser = $userRepository->findActive($userId);
                if (!$recipientUser) {
                    continue;
                }

                $recipient = new DocumentUserRecipient();
                $recipient->setDocument($document);
                $recipient->setUser($recipientUser);
                $recipient->setRole(DocumentRecipientRole::RECIPIENT);
                $recipient->setStatus(DocumentStatus::NEW);
                $recipient->setCreatedAt($now);
                $recipient->setUpdatedAt($now);

                $document->addUserRecipient($recipient);
                $entityManager->persist($recipient);
            }
        }

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        try {
            // Сначала сохраняем документ и получателей, чтобы у документа появился id (нужен Vich для пути загрузки)
            $entityManager->flush();

            // Прикрепляем файлы к документу (после flush document уже имеет id)
            foreach ($uploadedFiles as $uploadedFile) {
                if (!$uploadedFile instanceof UploadedFile) {
                    continue;
                }
                $clientName = $uploadedFile->getClientOriginalName();
                $fileEntity = new File();
                $fileEntity->setDocument($document);
                $fileEntity->setFile($uploadedFile);
                $fileEntity->setTitle(pathinfo($clientName, PATHINFO_FILENAME));
                $document->addFile($fileEntity);
                $entityManager->persist($fileEntity);
            }
            $entityManager->flush();

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        if ($wantsPublish) {
            $recipientsById = [];
            foreach ($document->getUserRecipients() as $recipient) {
                $user = $recipient->getUser();
                if ($user !== null) {
                    $recipientsById[$user->getId()] = $user;
                }
            }
            $recipients = array_values($recipientsById);
            if ($recipients !== []) {
                $link = $this->generateUrl('app_view_incoming_document', ['id' => $document->getId()]);
                $notificationService->notifyNewIncomingDocumentToRecipients($recipients, $document->getName(), $link);
            }
        }

        $this->addFlash('success', 'Документ успешно создан.');

        return $this->redirectToRoute('app_view_outgoing_document', ['id' => $document->getId()]);
    }

    #[Route('/document/organization-users/{id}', name: 'app_document_org_users', methods: ['GET'])]
    public function getOrganizationUsers(
        int                    $id,
        Request                $request,
        EntityManagerInterface $entityManager,
        UserRepository         $userRepository
    ): JsonResponse
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $organization = $entityManager->find(AbstractOrganization::class, $id);
        if (!$organization) {
            return new JsonResponse(['error' => 'Organization not found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $users = $userRepository->findByOrganization($organization);

        $data = [];
        foreach ($users as $user) {
            $fullName = trim(sprintf(
                '%s %s %s',
                (string) $user->getLastname(),
                (string) $user->getFirstname(),
                (string) ($user->getPatronymic() ?? '')
            ));

            $profession = $user->getWorker()?->getProfession();

            $data[] = [
                'id' => $user->getId(),
                'name' => $fullName,
                'profession' => $profession,
            ];
        }

        return new JsonResponse($data);
    }

    #[Route('/document/users/search', name: 'app_document_users_search', methods: ['GET'])]
    public function searchUsersForDocument(
        Request        $request,
        UserRepository $userRepository
    ): JsonResponse
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $query = trim((string) $request->query->get('query', ''));
        if (mb_strlen($query) < 2) {
            return new JsonResponse([]);
        }

        $result = $userRepository->findPaginated(1, 20, $query);
        $data = [];
        foreach ($result['users'] as $user) {
            $fullName = trim(sprintf(
                '%s %s %s',
                (string) $user->getLastname(),
                (string) $user->getFirstname(),
                (string) ($user->getPatronymic() ?? '')
            ));

            $data[] = [
                'id' => $user->getId(),
                'name' => $fullName,
                'profession' => $user->getWorker()?->getProfession(),
            ];
        }

        return new JsonResponse($data);
    }

    #[Route('/incoming_documents', name: 'app_incoming_documents')]
    public function getIncomingDocuments(
        Request $request,
        DocumentUserRecipientRepository $recipientRepository,
        DocumentTypeRepository $documentTypeRepository,
    ): Response {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            $this->addFlash('error', 'Необходимо войти в систему.');
            return $this->redirectToRoute('app_login');
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 10;

        $statusParam = trim((string) $request->query->get('status', ''));
        $status = $statusParam !== '' ? (DocumentStatus::tryFrom($statusParam)) : null;

        $typeIdParam = trim((string) $request->query->get('type_id', ''));
        $typeId = $typeIdParam !== '' ? (int) $typeIdParam : null;

        $creator = trim((string) $request->query->get('creator', '')) ?: null;
        $name = trim((string) $request->query->get('name', '')) ?: null;

        $createdFromRaw = trim((string) $request->query->get('created_from', ''));
        $createdToRaw = trim((string) $request->query->get('created_to', ''));
        $createdFrom = $createdFromRaw !== '' ? \DateTimeImmutable::createFromFormat('!Y-m-d', $createdFromRaw) : null;
        $createdTo = $createdToRaw !== '' ? \DateTimeImmutable::createFromFormat('!Y-m-d', $createdToRaw) : null;
        if ($createdFrom === false) { $createdFrom = null; }
        if ($createdTo === false) { $createdTo = null; } elseif ($createdTo !== null) {
            $createdTo = $createdTo->setTime(23, 59, 59);
        }

        $filters = [
            'status' => $status,
            'typeId' => $typeId,
            'creator' => $creator,
            'createdFrom' => $createdFrom,
            'createdTo' => $createdTo,
            'name' => $name,
        ];

        $pagination = $recipientRepository->findPaginatedByUser($currentUser, $page, $limit, $filters);
        $organizationPaths = [];
        foreach ($pagination['recipients'] as $recipient) {
            $document = $recipient->getDocument();
            if (!$document || !$document->getId()) {
                continue;
            }
            $organizationPaths[$document->getId()] = $this->buildOrganizationPath($document->getOrganizationCreator());
        }

        $activeFilters = [
            'status' => $statusParam !== '' ? $statusParam : '',
            'type_id' => $typeId !== null ? (string) $typeId : '',
            'creator' => $creator ?? '',
            'created_from' => $createdFromRaw,
            'created_to' => $createdToRaw,
            'name' => $name ?? '',
        ];

        return $this->render('document/incoming_documents.html.twig', [
            'active_tab' => 'incoming_documents',
            'recipients' => $pagination['recipients'],
            'organization_paths' => $organizationPaths,
            'pagination' => [
                'current_page' => $pagination['page'],
                'total_pages' => $pagination['totalPages'],
                'total_items' => $pagination['total'],
                'items_per_page' => $pagination['limit'],
            ],
            'document_types' => $documentTypeRepository->findBy([], ['name' => 'ASC']),
            'document_statuses' => DocumentStatus::cases(),
            'filters' => $activeFilters,
        ]);
    }

    #[Route('/outgoing_documents', name: 'app_outgoing_documents')]
    public function getOutgoingDocuments(
        Request $request,
        DocumentRepository $documentRepository,
        DocumentTypeRepository $documentTypeRepository,
    ): Response {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            $this->addFlash('error', 'Необходимо войти в систему.');
            return $this->redirectToRoute('app_login');
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 10;

        $typeIdParam = trim((string) $request->query->get('type_id', ''));
        $typeId = $typeIdParam !== '' ? (int) $typeIdParam : null;
        $name = trim((string) $request->query->get('name', '')) ?: null;

        $filters = [
            'typeId' => $typeId,
            'name' => $name,
        ];

        $pagination = $documentRepository->findPaginatedByCreatedBy($currentUser, $page, $limit, $filters);

        $activeFilters = [
            'type_id' => $typeId !== null ? (string) $typeId : '',
            'name' => $name ?? '',
        ];

        return $this->render('document/outgoing_documents.html.twig', [
            'active_tab' => 'outgoing_documents',
            'documents' => $pagination['documents'],
            'pagination' => [
                'current_page' => $pagination['page'],
                'total_pages' => $pagination['totalPages'],
                'total_items' => $pagination['total'],
                'items_per_page' => $pagination['limit'],
            ],
            'document_types' => $documentTypeRepository->findBy([], ['name' => 'ASC']),
            'filters' => $activeFilters,
        ]);
    }

    #[Route('/view_incoming_document/{id}', name: 'app_view_incoming_document', requirements: ['id' => '\d+'])]
    public function viewIncomingDocument(
        int $id,
        EntityManagerInterface $entityManager,
        DocumentRepository $documentRepository,
    ): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            $this->addFlash('error', 'Необходимо войти в систему.');
            return $this->redirectToRoute('app_login');
        }

        $document = $documentRepository->findOneWithRelations($id);
        if (!$document) {
            throw $this->createNotFoundException('Документ не найден.');
        }

        // Доступ: создатель, получатель или админ
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $isCreator = $document->getCreatedBy() && $document->getCreatedBy()->getId() === $currentUser->getId();
        $isRecipient = false;
        $userRecipient = null;
        foreach ($document->getUserRecipients() as $recipient) {
            if ($recipient->getUser() && $recipient->getUser()->getId() === $currentUser->getId()) {
                $isRecipient = true;
                $userRecipient = $recipient;

                // when user open document set status to viewed and add to history
                if ($recipient->getStatus() === DocumentStatus::NEW) {
                    $recipient->setStatus(DocumentStatus::VIEWED);
                    $entityManager->persist($recipient);

                    $history = new DocumentHistory();
                    $history->setDocument($document);
                    $history->setUser($currentUser);
                    $history->setAction('Пользователь просмотрел документ');
                    $history->setOldStatus(DocumentStatus::NEW);
                    $history->setNewStatus(DocumentStatus::VIEWED);
                    $history->setCreatedAt(new \DateTimeImmutable());

                    $entityManager->persist($history);
                    $entityManager->flush();
                }
                break;
            }
        }

        if (!$isAdmin && !$isCreator && !$isRecipient) {
            $this->addFlash('error', 'Нет доступа к этому документу.');
            return $this->redirectToRoute('app_incoming_documents');
        }

        [$executorRecipients, $recipientRecipients] = $this->splitRecipientsByRole($document->getUserRecipients()->toArray());
        $organizationPath = $this->buildOrganizationPath($document->getOrganizationCreator());

        return $this->render('document/view_incoming_document.html.twig', [
            'active_tab' => 'incoming_documents',
            'document' => $document,
            'isRecipient' => $isRecipient,
            'userRecipient' => $userRecipient,
            'executorRecipients' => $executorRecipients,
            'recipientRecipients' => $recipientRecipients,
            'organizationPath' => $organizationPath,
        ]);
    }

    #[Route('/view_outgoing_document/{id}', name: 'app_view_outgoing_document', requirements: ['id' => '\d+'])]
    public function viewOutgoingDocument(int $id, DocumentRepository $documentRepository): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            $this->addFlash('error', 'Необходимо войти в систему.');
            return $this->redirectToRoute('app_login');
        }

        $document = $documentRepository->findOneWithRelations($id);
        if (!$document) {
            throw $this->createNotFoundException('Документ не найден.');
        }

        // Доступ: создатель, получатель или админ
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $isCreator = $document->getCreatedBy() && $document->getCreatedBy()->getId() === $currentUser->getId();
        $isRecipient = false;
        foreach ($document->getUserRecipients() as $recipient) {
            if ($recipient->getUser() && $recipient->getUser()->getId() === $currentUser->getId()) {
                $isRecipient = true;
                break;
            }
        }

        if (!$isAdmin && !$isCreator && !$isRecipient) {
            $this->addFlash('error', 'Нет доступа к этому документу.');
            return $this->redirectToRoute('app_incoming_documents');
        }

        [$executorRecipients, $recipientRecipients] = $this->splitRecipientsByRole($document->getUserRecipients()->toArray());
        $organizationPath = $this->buildOrganizationPath($document->getOrganizationCreator());

        return $this->render('document/view_outgoing_document.html.twig', [
            'active_tab' => 'outgoing_documents',
            'document' => $document,
            'executorRecipients' => $executorRecipients,
            'recipientRecipients' => $recipientRecipients,
            'organizationPath' => $organizationPath,
        ]);
    }

    #[Route('/edit_outgoing_document/{id}', name: 'app_edit_outgoing_document', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editOutgoingDocument(
        int                    $id,
        Request                $request,
        DocumentRepository     $documentRepository,
        OrganizationRepository $organizationRepository,
        UserRepository         $userRepository,
        EntityManagerInterface $entityManager,
        ValidatorInterface     $validator,
        NotificationService    $notificationService,
    ): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            $this->addFlash('error', 'Необходимо войти в систему.');
            return $this->redirectToRoute('app_login');
        }

        $document = $documentRepository->findOneWithRelations($id);
        if (!$document) {
            throw $this->createNotFoundException('Документ не найден.');
        }

        // Проверка доступа: только создатель или админ могут редактировать
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $isCreator = $document->getCreatedBy() && $document->getCreatedBy()->getId() === $currentUser->getId();

        if (!$isAdmin && !$isCreator) {
            $this->addFlash('error', 'Нет доступа к редактированию этого документа.');
            return $this->redirectToRoute('app_view_outgoing_document', ['id' => $id]);
        }

        $userOrganization = $currentUser->getOrganization();
        $rootOrganization = $userOrganization && !$isAdmin ? $userOrganization->getRootOrganization() : null;

        $organizationTree = $organizationRepository->getOrganizationTree($isAdmin ? null : $rootOrganization);
        $organizationsWithChildren = [];
        if (!empty($organizationTree)) {
            foreach ($organizationTree as $org) {
                $loadedOrg = $organizationRepository->findWithChildren($org->getId());
                if ($loadedOrg) {
                    $organizationsWithChildren[] = $loadedOrg;
                }
            }
        }

        if (empty($organizationsWithChildren) && $userOrganization) {
            $loadedOrg = $organizationRepository->findWithChildren($userOrganization->getId());
            if ($loadedOrg) {
                $organizationsWithChildren[] = $loadedOrg;
            }
        }

        // Подготовка данных формы из документа
        $initialFormData = [
            'name' => $document->getName(),
            'description' => $document->getDescription(),
            'organization_id' => $document->getOrganizationCreator()->getId(),
            'status' => $document->getStatus()?->value,
            'deadline' => $document->getDeadline()?->format('Y-m-d'),
            'is_published' => $document->isPublished(),
        ];

        $renderForm = function (array $data = []) use ($document, $organizationsWithChildren, $initialFormData, $isAdmin, $userOrganization): Response {
            $formData = $data !== [] ? $data : $initialFormData;
            return $this->render('document/edit_outgoing_document.html.twig', [
                'active_tab' => 'outgoing_documents',
                'document' => $document,
                'organizations' => $organizationsWithChildren,
                'form_data' => $formData,
                'document_statuses' => DocumentStatus::getCreationChoices(),
                'is_admin' => $isAdmin,
                'user_organization' => $userOrganization,
            ]);
        };

        if (!$request->isMethod('POST')) {
            return $renderForm([]);
        }

        $formData = $request->request->all();

        // Валидация CSRF токена
        if (!$this->isCsrfTokenValid('edit_document', $formData['_csrf_token'] ?? '')) {
            $this->addFlash('error', 'Неверный CSRF токен.');
            return $this->redirectToRoute('app_edit_outgoing_document', ['id' => $id]);
        }

        // Валидация названия
        $name = trim((string)($formData['name'] ?? ''));
        if ($name === '') {
            $this->addFlash('error', 'Название документа обязательно для заполнения.');
            return $renderForm($formData);
        }

        // Валидация организации
        $organizationId = (int)($formData['organization_id'] ?? 0);
        if ($organizationId <= 0) {
            $this->addFlash('error', 'Организация обязательна для заполнения.');
            return $renderForm($formData);
        }

        $organization = $organizationRepository->find($organizationId);
        if (!$organization) {
            $this->addFlash('error', 'Выбранная организация не найдена.');
            return $renderForm($formData);
        }

        // Валидация статуса
        $statusValue = trim((string)($formData['status'] ?? ''));
        $status = DocumentStatus::tryFrom($statusValue);
        if (!$status) {
            $this->addFlash('error', 'Некорректный статус документа.');
            return $renderForm($formData);
        }

        // Парсинг deadline
        $deadlineStr = trim((string)($formData['deadline'] ?? ''));
        $deadline = null;
        if ($deadlineStr !== '') {
            $deadline = \DateTime::createFromFormat('Y-m-d', $deadlineStr);
            if (!$deadline) {
                $this->addFlash('error', 'Неверный формат даты срока выполнения.');
                return $renderForm($formData);
            }
        }

        $wantsPublish = isset($formData['is_published']) && (bool) $formData['is_published'];
        if ($wantsPublish) {
            if ($status === DocumentStatus::DRAFT) {
                $this->addFlash('error', 'Документ нельзя опубликовать в статусе черновик.');
                return $renderForm($formData);
            }
            if ($document->getUserRecipients()->isEmpty()) {
                $this->addFlash('error', 'Документ нельзя опубликовать без получателей.');
                return $renderForm($formData);
            }
        }

        // Обновление полей документа
        $wasAlreadyPublished = $document->isPublished();
        $document->setName($name);
        $document->setDescription(trim((string)($formData['description'] ?? '')));
        $document->setOrganizationCreator($organization);
        $document->setStatus($status);
        $document->setDeadline($deadline);
        $document->setIsPublished($wantsPublish);

        // Валидация сущности
        $errors = $validator->validate($document);
        if (count($errors) > 0) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error->getMessage());
            }
            return $renderForm($formData);
        }

        // Обновление получателей — только если форма их передаёт (страница редактирования получателей отдельно)
        if (array_key_exists('executor_user_ids', $formData) || array_key_exists('recipient_user_ids', $formData) || array_key_exists('user_ids', $formData)) {
            $executorUserIds = $formData['executor_user_ids'] ?? [];
            $recipientUserIds = $formData['recipient_user_ids'] ?? [];
            $legacyUserIds = $formData['user_ids'] ?? [];

            if (!is_array($executorUserIds)) {
                $executorUserIds = [];
            }
            if (!is_array($recipientUserIds)) {
                $recipientUserIds = [];
            }
            if (!is_array($legacyUserIds)) {
                $legacyUserIds = [];
            }

            $executorUserIds = array_values(array_unique(array_filter(array_map('intval', array_merge($executorUserIds, $legacyUserIds)), fn($id) => $id > 0)));
            $recipientUserIds = array_values(array_unique(array_filter(array_map('intval', $recipientUserIds), fn($id) => $id > 0)));

            $newRecipientKeys = [];
            foreach ($executorUserIds as $userId) {
                $newRecipientKeys[] = $userId . '|' . DocumentRecipientRole::EXECUTOR->value;
            }
            foreach ($recipientUserIds as $userId) {
                $newRecipientKeys[] = $userId . '|' . DocumentRecipientRole::RECIPIENT->value;
            }
            sort($newRecipientKeys);

            $currentRecipientKeys = [];
            foreach ($document->getUserRecipients() as $recipient) {
                $user = $recipient->getUser();
                if ($user) {
                    $currentRecipientKeys[] = $user->getId() . '|' . $recipient->getRole()->value;
                }
            }
            sort($currentRecipientKeys);

            $recipientsChanged = ($newRecipientKeys !== $currentRecipientKeys);

            if ($recipientsChanged) {
                foreach ($document->getUserRecipients()->toArray() as $recipient) {
                    $entityManager->remove($recipient);
                }
                $document->getUserRecipients()->clear();
                $entityManager->flush();

                foreach ($executorUserIds as $userId) {
                    $user = $userRepository->find($userId);
                    if (!$user) {
                        continue;
                    }

                    $recipient = new DocumentUserRecipient();
                    $recipient->setDocument($document);
                    $recipient->setUser($user);
                    $recipient->setRole(DocumentRecipientRole::EXECUTOR);
                    $recipient->setStatus(DocumentStatus::NEW);
                    $document->addUserRecipient($recipient);
                    $entityManager->persist($recipient);
                }

                foreach ($recipientUserIds as $userId) {
                    $user = $userRepository->find($userId);
                    if (!$user) {
                        continue;
                    }

                    $recipient = new DocumentUserRecipient();
                    $recipient->setDocument($document);
                    $recipient->setUser($user);
                    $recipient->setRole(DocumentRecipientRole::RECIPIENT);
                    $recipient->setStatus(DocumentStatus::NEW);
                    $document->addUserRecipient($recipient);
                    $entityManager->persist($recipient);
                }
            }
        }

        $entityManager->flush();

        if (!$wasAlreadyPublished && $wantsPublish) {
            $recipientsById = [];
            foreach ($document->getUserRecipients() as $recipient) {
                $user = $recipient->getUser();
                if ($user !== null) {
                    $recipientsById[$user->getId()] = $user;
                }
            }
            $recipients = array_values($recipientsById);
            if ($recipients !== []) {
                $link = $this->generateUrl('app_view_incoming_document', ['id' => $document->getId()]);
                $notificationService->notifyNewIncomingDocumentToRecipients($recipients, $document->getName(), $link);
            }
        }

        $this->addFlash('success', 'Документ успешно обновлён.');
        return $this->redirectToRoute('app_view_outgoing_document', ['id' => $document->getId()]);
    }

    #[Route('/document/{id}/publish', name: 'app_publish_outgoing_document', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function publishOutgoingDocument(
        int                    $id,
        Request                $request,
        DocumentRepository     $documentRepository,
        EntityManagerInterface $entityManager,
        NotificationService    $notificationService,
    ): Response {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            $this->addFlash('error', 'Необходимо войти в систему.');
            return $this->redirectToRoute('app_login');
        }

        $document = $documentRepository->findOneWithRelations($id);
        if (!$document) {
            throw $this->createNotFoundException('Документ не найден.');
        }

        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $isCreator = $document->getCreatedBy() && $document->getCreatedBy()->getId() === $currentUser->getId();
        if (!$isAdmin && !$isCreator) {
            $this->addFlash('error', 'Нет доступа к публикации этого документа.');
            return $this->redirectToRoute('app_view_outgoing_document', ['id' => $id]);
        }

        if (!$this->isCsrfTokenValid('publish_document_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Неверный CSRF токен.');
            return $this->redirectToRoute('app_view_outgoing_document', ['id' => $id]);
        }

        if ($document->getStatus() === DocumentStatus::DRAFT) {
            $this->addFlash('error', 'Документ нельзя опубликовать в статусе черновик.');
            return $this->redirectToRoute('app_view_outgoing_document', ['id' => $id]);
        }

        if ($document->getUserRecipients()->isEmpty()) {
            $this->addFlash('error', 'Документ нельзя опубликовать без получателей.');
            return $this->redirectToRoute('app_view_outgoing_document', ['id' => $id]);
        }

        if ($document->isPublished()) {
            $this->addFlash('info', 'Документ уже опубликован.');
            return $this->redirectToRoute('app_view_outgoing_document', ['id' => $id]);
        }

        $document->setIsPublished(true);
        $entityManager->flush();

        $recipientsById = [];
        foreach ($document->getUserRecipients() as $recipient) {
            $user = $recipient->getUser();
            if ($user !== null) {
                $recipientsById[$user->getId()] = $user;
            }
        }
        $recipients = array_values($recipientsById);
        if ($recipients !== []) {
            $link = $this->generateUrl('app_view_incoming_document', ['id' => $document->getId()]);
            $notificationService->notifyNewIncomingDocumentToRecipients($recipients, $document->getName(), $link);
        }

        $this->addFlash('success', 'Документ успешно опубликован.');
        return $this->redirectToRoute('app_view_outgoing_document', ['id' => $id]);
    }

    #[Route('/edit_recipients_outgoing_document/{id}', name: 'app_edit_recipients_outgoing_document', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editRecipientsDocument(
        int                    $id,
        Request                $request,
        DocumentRepository     $documentRepository,
        OrganizationRepository $organizationRepository,
        UserRepository         $userRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            $this->addFlash('error', 'Необходимо войти в систему.');
            return $this->redirectToRoute('app_login');
        }

        $document = $documentRepository->findOneWithRelations($id);
        if (!$document) {
            throw $this->createNotFoundException('Документ не найден.');
        }

        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $isCreator = $document->getCreatedBy() && $document->getCreatedBy()->getId() === $currentUser->getId();
        if (!$isAdmin && !$isCreator) {
            $this->addFlash('error', 'Нет доступа к редактированию этого документа.');
            return $this->redirectToRoute('app_view_outgoing_document', ['id' => $id]);
        }

        $userOrganization = $currentUser->getOrganization();
        $rootOrganization = $userOrganization && !$isAdmin ? $userOrganization->getRootOrganization() : null;

        $organizationTree = $organizationRepository->getOrganizationTree($isAdmin ? null : $rootOrganization);
        $organizationsWithChildren = [];
        if (!empty($organizationTree)) {
            foreach ($organizationTree as $org) {
                $loadedOrg = $organizationRepository->findWithChildren($org->getId());
                if ($loadedOrg) {
                    $organizationsWithChildren[] = $loadedOrg;
                }
            }
        }
        if (empty($organizationsWithChildren) && $userOrganization) {
            $loadedOrg = $organizationRepository->findWithChildren($userOrganization->getId());
            if ($loadedOrg) {
                $organizationsWithChildren[] = $loadedOrg;
            }
        }

        $currentExecutorRecipientIds = [];
        $currentRecipientRecipientIds = [];
        foreach ($document->getUserRecipients() as $recipient) {
            if ($recipient->getUser()) {
                if ($recipient->getRole() === DocumentRecipientRole::RECIPIENT) {
                    $currentRecipientRecipientIds[] = $recipient->getUser()->getId();
                    continue;
                }
                $currentExecutorRecipientIds[] = $recipient->getUser()->getId();
            }
        }
        $initialFormData = [
            'executor_user_ids' => $currentExecutorRecipientIds,
            'recipient_user_ids' => $currentRecipientRecipientIds,
        ];
        $initialExecutorUsers = $userRepository->findByIds($currentExecutorRecipientIds);
        $initialRecipientUsers = $userRepository->findByIds($currentRecipientRecipientIds);

        $renderForm = function (array $data = [], array $executorUsers = [], array $recipientUsers = []) use ($document, $organizationsWithChildren, $initialFormData, $userRepository): Response {
            $formData = $data !== [] ? $data : $initialFormData;
            if ($executorUsers === [] && !empty($formData['executor_user_ids'])) {
                $executorUsers = $userRepository->findByIds((array) $formData['executor_user_ids']);
            } elseif ($executorUsers === [] && !empty($formData['user_ids'])) {
                $executorUsers = $userRepository->findByIds((array) $formData['user_ids']);
            }
            if ($recipientUsers === [] && !empty($formData['recipient_user_ids'])) {
                $recipientUsers = $userRepository->findByIds((array) $formData['recipient_user_ids']);
            }
            return $this->render('document/edit_recipients_outgoing_document.html.twig', [
                'active_tab' => 'outgoing_documents',
                'document' => $document,
                'organizations' => $organizationsWithChildren,
                'form_data' => $formData,
                'executor_users' => $executorUsers,
                'recipient_users' => $recipientUsers,
            ]);
        };

        if (!$request->isMethod('POST')) {
            return $renderForm([], $initialExecutorUsers, $initialRecipientUsers);
        }

        $formData = $request->request->all();
        if (!$this->isCsrfTokenValid('edit_recipients_outgoing_document', $formData['_csrf_token'] ?? '')) {
            $this->addFlash('error', 'Неверный CSRF токен.');
            return $this->redirectToRoute('app_edit_recipients_outgoing_document', ['id' => $id]);
        }

        $executorUserIds = $formData['executor_user_ids'] ?? [];
        $recipientUserIds = $formData['recipient_user_ids'] ?? [];
        $legacyUserIds = $formData['user_ids'] ?? [];
        if (!is_array($executorUserIds)) {
            $executorUserIds = [];
        }
        if (!is_array($recipientUserIds)) {
            $recipientUserIds = [];
        }
        if (!is_array($legacyUserIds)) {
            $legacyUserIds = [];
        }
        $executorUserIds = array_values(array_unique(array_filter(array_map('intval', array_merge($executorUserIds, $legacyUserIds)), fn($id) => $id > 0)));
        $recipientUserIds = array_values(array_unique(array_filter(array_map('intval', $recipientUserIds), fn($id) => $id > 0)));

        $newRecipientKeys = [];
        foreach ($executorUserIds as $userId) {
            $newRecipientKeys[] = $userId . '|' . DocumentRecipientRole::EXECUTOR->value;
        }
        foreach ($recipientUserIds as $userId) {
            $newRecipientKeys[] = $userId . '|' . DocumentRecipientRole::RECIPIENT->value;
        }
        sort($newRecipientKeys);

        $currentRecipientKeys = [];
        foreach ($document->getUserRecipients() as $recipient) {
            $user = $recipient->getUser();
            if ($user) {
                $currentRecipientKeys[] = $user->getId() . '|' . $recipient->getRole()->value;
            }
        }
        sort($currentRecipientKeys);
        $recipientsChanged = ($newRecipientKeys !== $currentRecipientKeys);

        if ($recipientsChanged) {
            foreach ($document->getUserRecipients()->toArray() as $recipient) {
                $entityManager->remove($recipient);
            }
            $document->getUserRecipients()->clear();
            $entityManager->flush();

            foreach ($executorUserIds as $userId) {
                $user = $userRepository->find($userId);
                if (!$user) {
                    continue;
                }
                $recipient = new DocumentUserRecipient();
                $recipient->setDocument($document);
                $recipient->setUser($user);
                $recipient->setRole(DocumentRecipientRole::EXECUTOR);
                $recipient->setStatus(DocumentStatus::NEW);
                $document->addUserRecipient($recipient);
                $entityManager->persist($recipient);
            }

            foreach ($recipientUserIds as $userId) {
                $user = $userRepository->find($userId);
                if (!$user) {
                    continue;
                }
                $recipient = new DocumentUserRecipient();
                $recipient->setDocument($document);
                $recipient->setUser($user);
                $recipient->setRole(DocumentRecipientRole::RECIPIENT);
                $recipient->setStatus(DocumentStatus::NEW);
                $document->addUserRecipient($recipient);
                $entityManager->persist($recipient);
            }
            $document->setUpdatedAt(new \DateTimeImmutable());
        }

        $entityManager->flush();
        $this->addFlash('success', 'Получатели документа обновлены.');
        return $this->redirectToRoute('app_view_outgoing_document', ['id' => $document->getId()]);
    }

    #[Route('/document/{id}/file/{type}', name: 'app_document_download_file', requirements: ['id' => '\d+', 'type' => 'original|updated'])]
    public function downloadDocumentFile(
        int $id,
        string $type,
        Request $request,
        DocumentRepository $documentRepository,
        #[Autowire('%private_upload_dir_documents_originals%')] string $originalsDir,
        #[Autowire('%private_upload_dir_documents_updated%')] string $updatedDir,
    ): Response {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            $this->addFlash('error', 'Необходимо войти в систему.');
            return $this->redirectToRoute('app_login');
        }

        $document = $documentRepository->findOneWithRelations($id);
        if (!$document) {
            throw $this->createNotFoundException('Документ не найден.');
        }

        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $isCreator = $document->getCreatedBy() && $document->getCreatedBy()->getId() === $currentUser->getId();
        $isRecipient = false;
        foreach ($document->getUserRecipients() as $recipient) {
            if ($recipient->getUser() && $recipient->getUser()->getId() === $currentUser->getId()) {
                $isRecipient = true;
                break;
            }
        }

        if (!$isAdmin && !$isCreator && !$isRecipient) {
            $this->addFlash('error', 'Нет доступа к этому документу.');
            return $this->redirectToRoute('app_incoming_documents');
        }

        $filename = $type === 'original' ? $document->getOriginalFile() : $document->getUpdatedFile();
        if (!$filename) {
            throw $this->createNotFoundException('Файл не найден.');
        }

        $filename = basename($filename);
        $dir = $type === 'original' ? $originalsDir : $updatedDir;
        $path = $dir . \DIRECTORY_SEPARATOR . $filename;

        if (!is_file($path)) {
            throw $this->createNotFoundException('Файл не найден на диске.');
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(
            $request->query->getBoolean('inline') ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename
        );

        return $response;
    }

    #[Route('/document/{id}/status/update', name: 'app_document_status_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateDocumentStatus(
        int                    $id,
        Request                $request,
        DocumentRepository     $documentRepository,
        EntityManagerInterface $entityManager,
        NotificationService    $notificationService,
    ): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            $this->addFlash('error', 'Необходимо войти в систему.');
            return $this->redirectToRoute('app_login');
        }

        $document = $documentRepository->findOneWithRelations($id);
        if (!$document) {
            throw $this->createNotFoundException('Документ не найден.');
        }

        // Находим получателя для текущего пользователя
        $userRecipient = null;
        foreach ($document->getUserRecipients() as $recipient) {
            if ($recipient->getUser() && $recipient->getUser()->getId() === $currentUser->getId()) {
                $userRecipient = $recipient;
                break;
            }
        }

        // Проверяем, что пользователь является получателем документа
        if (!$userRecipient) {
            $this->addFlash('error', 'Вы можете изменять статус только для входящих документов.');
            return $this->redirectToRoute('app_view_incoming_document', ['id' => $id]);
        }

        // Валидация CSRF токена
        if (!$this->isCsrfTokenValid('document_status_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Неверный CSRF токен.');
            return $this->redirectToRoute('app_view_incoming_document', ['id' => $id]);
        }

        // Получаем статус из запроса
        $statusValue = trim((string)($request->request->get('status') ?? ''));
        if ($statusValue === '') {
            $this->addFlash('error', 'Необходимо выбрать статус.');
            return $this->redirectToRoute('app_view_incoming_document', ['id' => $id]);
        }

        // Валидация и установка статуса
        try {
            $status = DocumentStatus::from($statusValue);
        } catch (\ValueError $e) {
            $this->addFlash('error', 'Неверный статус документа.');
            return $this->redirectToRoute('app_view_incoming_document', ['id' => $id]);
        }

        if (!in_array($status, DocumentStatus::getReceiverAllowedStatuses(), true)) {
            $this->addFlash('error', 'Выбранный статус недоступен для изменения.');
            return $this->redirectToRoute('app_view_incoming_document', ['id' => $id]);
        }

        // Проверяем, не совпадает ли новый статус с текущим
        $oldStatus = $userRecipient->getStatus();
        if ($oldStatus === $status) {
            $this->addFlash('info', sprintf('Документ уже находится в статусе "%s".', $status->getLabel()));
            return $this->redirectToRoute('app_view_incoming_document', ['id' => $id]);
        }

        // Сохраняем старый статус перед изменением
        $oldStatusForHistory = $oldStatus;

        // Обновляем статус получателя
        $userRecipient->setStatus($status);
        $userRecipient->setUpdatedAt(new \DateTimeImmutable());

        // Записываем историю изменения статуса
        $history = new DocumentHistory();
        $history->setDocument($document);
        $history->setUser($currentUser);
        $history->setAction('Изменение статуса получателя');
        $history->setOldStatus($oldStatusForHistory ?? DocumentStatus::NEW);
        $history->setNewStatus($status);
        $history->setCreatedAt(new \DateTimeImmutable());

        $entityManager->persist($history);
        $entityManager->flush();

        $authorFullName = trim(sprintf(
            '%s %s %s',
            (string) $currentUser->getLastname(),
            (string) $currentUser->getFirstname(),
            (string) ($currentUser->getPatronymic() ?? '')
        ));
        if ($authorFullName === '') {
            $authorFullName = 'Пользователь';
        }
        $notificationTitle = sprintf(
            '%s изменил статус документа «%s» на «%s».',
            $authorFullName,
            $document->getName(),
            $status->getLabel()
        );

        $recipientsById = [];
        $creator = $document->getCreatedBy();
        if ($creator && $creator->getId() !== $currentUser->getId()) {
            $recipientsById[$creator->getId()] = [
                'user' => $creator,
                'link' => $this->generateUrl('app_view_outgoing_document', ['id' => $document->getId()]),
            ];
        }
        foreach ($document->getUserRecipients() as $recipient) {
            $participant = $recipient->getUser();
            if (!$participant || $participant->getId() === $currentUser->getId()) {
                continue;
            }
            $recipientsById[$participant->getId()] = [
                'user' => $participant,
                'link' => $this->generateUrl('app_view_incoming_document', ['id' => $document->getId()]),
            ];
        }
        foreach ($recipientsById as $item) {
            $notificationService->notifyGeneric($item['user'], $notificationTitle, $item['link']);
        }

        $this->addFlash('success', sprintf('Статус документа изменен на "%s".', $status->getLabel()));
        return $this->redirectToRoute('app_view_incoming_document', ['id' => $id]);
    }

    #[Route('/incoming_document/{id}/history/{userId}', name: 'app_history_incoming_document', requirements: ['id' => '\d+', 'userId' => '\d+'])]
    public function historyIncomingDocument(
        int                       $id,
        int                       $userId,
        Request                   $request,
        DocumentRepository        $documentRepository,
        DocumentHistoryRepository $historyRepository,
        UserRepository            $userRepository
    ): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            $this->addFlash('error', 'Необходимо войти в систему.');
            return $this->redirectToRoute('app_login');
        }

        $document = $documentRepository->findOneWithRelations($id);
        if (!$document) {
            throw $this->createNotFoundException('Документ не найден.');
        }

        $historyUser = $userRepository->find($userId);
        if (!$historyUser) {
            throw $this->createNotFoundException('Пользователь не найден.');
        }

        // Доступ: создатель, получатель или админ
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $isCreator = $document->getCreatedBy() && $document->getCreatedBy()->getId() === $currentUser->getId();
        $isRecipient = false;
        foreach ($document->getUserRecipients() as $recipient) {
            if ($recipient->getUser() && $recipient->getUser()->getId() === $currentUser->getId()) {
                $isRecipient = true;
                break;
            }
        }

        // Проверяем, что запрашиваемая история принадлежит текущему пользователю (если не админ)
//        if (!$isAdmin && $userId !== $currentUser->getId()) {
//            $this->addFlash('error', 'Нет доступа к истории этого пользователя.');
//            return $this->redirectToRoute('app_view_incoming_document', ['id' => $id]);
//        }

        if (!$isAdmin && !$isCreator && !$isRecipient) {
            $this->addFlash('error', 'Нет доступа к этому документу.');
            return $this->redirectToRoute('app_incoming_documents');
        }

        $history = $historyRepository->findByDocumentAndUserOrderByCreatedAtDesc($id, $userId);

        return $this->render('document/history_incoming_document.html.twig', [
            'active_tab' => 'view_document',
            'document' => $document,
            'historyUser' => $historyUser,
            'history' => $history,
        ]);
    }

    #[Route('/outgoing_document/{id}/history/{userId}', name: 'app_history_outgoing_document', requirements: ['id' => '\d+', 'userId' => '\d+'])]
    public function historyOutgoingDocument(
        int                       $id,
        int                       $userId,
        DocumentRepository        $documentRepository,
        DocumentHistoryRepository $historyRepository,
        UserRepository            $userRepository
    ): Response {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            $this->addFlash('error', 'Необходимо войти в систему.');
            return $this->redirectToRoute('app_login');
        }

        $document = $documentRepository->findOneWithRelations($id);
        if (!$document) {
            throw $this->createNotFoundException('Документ не найден.');
        }

        $historyUser = $userRepository->find($userId);
        if (!$historyUser) {
            throw $this->createNotFoundException('Пользователь не найден.');
        }

        // Доступ: создатель документа или админ
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $isCreator = $document->getCreatedBy() && $document->getCreatedBy()->getId() === $currentUser->getId();
        $isRecipientForUser = false;
        foreach ($document->getUserRecipients() as $recipient) {
            if ($recipient->getUser() && $recipient->getUser()->getId() === $userId) {
                $isRecipientForUser = true;
                break;
            }
        }

        if (!$isAdmin && !$isCreator) {
            $this->addFlash('error', 'Нет доступа к этому документу.');
            return $this->redirectToRoute('app_outgoing_documents');
        }

        if (!$isRecipientForUser) {
            $this->addFlash('error', 'Указанный пользователь не является получателем этого документа.');
            return $this->redirectToRoute('app_view_outgoing_document', ['id' => $id]);
        }

        $history = $historyRepository->findByDocumentAndUserOrderByCreatedAtDesc($id, $userId);

        return $this->render('document/history_outgoing_document.html.twig', [
            'active_tab' => 'outgoing_documents',
            'document' => $document,
            'historyUser' => $historyUser,
            'history' => $history,
        ]);
    }

    #[Route('/history_outgoing_documents', name: 'app_history_outgoing_documents')]
    public function getHistoryOutgoingDocuments(DocumentRepository $documentRepository): Response
    {
        return $this->render('document/history_outgoing_document.html.twig', [
            'active_tab' => 'outgoing_documents',
        ]);
    }

    /**
     * @param DocumentUserRecipient[] $recipients
     * @return array{0: DocumentUserRecipient[], 1: DocumentUserRecipient[]}
     */
    private function splitRecipientsByRole(array $recipients): array
    {
        $executorRecipients = [];
        $recipientRecipients = [];

        foreach ($recipients as $recipient) {
            if ($recipient->getRole() === DocumentRecipientRole::RECIPIENT) {
                $recipientRecipients[] = $recipient;
                continue;
            }

            $executorRecipients[] = $recipient;
        }

        $sortByFullName = static function (DocumentUserRecipient $left, DocumentUserRecipient $right): int {
            $leftUser = $left->getUser();
            $rightUser = $right->getUser();

            $leftName = trim(sprintf(
                '%s %s %s',
                (string) ($leftUser?->getLastname() ?? ''),
                (string) ($leftUser?->getFirstname() ?? ''),
                (string) ($leftUser?->getPatronymic() ?? '')
            ));
            $rightName = trim(sprintf(
                '%s %s %s',
                (string) ($rightUser?->getLastname() ?? ''),
                (string) ($rightUser?->getFirstname() ?? ''),
                (string) ($rightUser?->getPatronymic() ?? '')
            ));

            return strcasecmp($leftName, $rightName);
        };

        usort($executorRecipients, $sortByFullName);
        usort($recipientRecipients, $sortByFullName);

        return [$executorRecipients, $recipientRecipients];
    }

    private function buildOrganizationPath(?AbstractOrganization $organization): string
    {
        if ($organization === null) {
            return '—';
        }

        $parts = [];
        $current = $organization;

        while ($current !== null) {
            $name = trim((string) $current->getName());
            if ($name !== '') {
                $parts[] = $name;
            }
            $current = $current->getParent();
        }

        if ($parts === []) {
            return '—';
        }

        return implode(' / ', array_reverse($parts));
    }
}
