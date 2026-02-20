<?php


namespace App\Controller\Document;

use App\Entity\Document;
use App\Entity\DocumentHistory;
use App\Entity\DocumentUserRecipient;
use App\Entity\User;
use App\Enum\DocumentStatus;
use App\Repository\DocumentHistoryRepository;
use App\Repository\DocumentRepository;
use App\Repository\DocumentTypeRepository;
use App\Repository\DocumentUserRecipientRepository;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
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

        // Для обычного пользователя находим корневую организацию (если он в дочерней)
        $rootOrganization = $userOrganization;
        if ($userOrganization && !$isAdmin) {
            while ($rootOrganization->getParent() !== null) {
                $rootOrganization = $rootOrganization->getParent();
            }
        }

        // Получаем дерево организаций для пользователя
        $organizationTree = $organizationRepository->getOrganizationTree($isAdmin ? null : $rootOrganization);

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
        ];
        // Устанавливаем организацию пользователя по умолчанию
        if ($userOrganization) {
            $initialFormData['organization_id'] = $userOrganization->getId();
        }

        // Пользователей для мультиселекта не грузим — выбор только через модалку по организациям.
        // При повторном отображении формы (ошибка валидации) подгружаем только выбранных по id.
        $renderForm = function (array $data = [], array $users = []) use ($documentType, $organizationsWithChildren, $initialFormData, $userRepository, $isAdmin, $userOrganization): Response {
            $formData = $data !== [] ? $data : $initialFormData;
            if ($users === [] && !empty($formData['user_ids'])) {
                $users = $userRepository->findByIds((array) $formData['user_ids']);
            }
            return $this->render('document/create_document.html.twig', [
                'active_tab' => 'new_document',
                'document_type' => $documentType,
                'organizations' => $organizationsWithChildren,
                'form_data' => $formData,
                'document_statuses' => DocumentStatus::getCreationChoices(),
                'users' => $users,
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

        // Валидация документа
        $errors = $validator->validate($document);
        if (count($errors) > 0) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error->getMessage());
            }
            return $renderForm($formData);
        }

        // Сохраняем документ
        $entityManager->persist($document);

        // Присваиваем документ выбранным пользователям
        $userIds = $formData['user_ids'] ?? [];
        if (is_array($userIds) && !empty($userIds)) {
            $now = new \DateTimeImmutable();
            foreach ($userIds as $userId) {
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
                $recipient->setStatus($document->getStatus() ?? DocumentStatus::DRAFT);
                $recipient->setCreatedAt($now);
                $recipient->setUpdatedAt($now);

                $entityManager->persist($recipient);
            }
        }

        $entityManager->flush();

        $this->addFlash('success', 'Документ успешно создан.');

        return $this->redirectToRoute('app_view_outgoing_document', ['id' => $document->getId()]);
    }

    #[Route('/document/organization-users/{id}', name: 'app_document_org_users', methods: ['GET'])]
    public function getOrganizationUsers(
        int                    $id,
        Request                $request,
        OrganizationRepository $organizationRepository,
        UserRepository         $userRepository
    ): JsonResponse
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $organization = $organizationRepository->find($id);
        if (!$organization) {
            return new JsonResponse(['error' => 'Organization not found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $userOrganization = $currentUser->getOrganization();

        if (!$isAdmin) {
            if (!$userOrganization) {
                return new JsonResponse(['error' => 'Organization not defined'], JsonResponse::HTTP_FORBIDDEN);
            }

            $isValid = $organization->getId() === $userOrganization->getId();
            if (!$isValid) {
                $checkOrg = $organization;
                while ($checkOrg->getParent() !== null) {
                    $checkOrg = $checkOrg->getParent();
                    if ($checkOrg->getId() === $userOrganization->getId()) {
                        $isValid = true;
                        break;
                    }
                }
            }

            if (!$isValid) {
                return new JsonResponse(['error' => 'Forbidden'], JsonResponse::HTTP_FORBIDDEN);
            }
        }

        $users = $userRepository->findWorkWithDocumentsByOrganization($organization);
        $data = [];
        foreach ($users as $user) {
            $data[] = [
                'id' => $user->getId(),
                'name' => trim(sprintf(
                    '%s %s %s',
                    (string)$user->getLastname(),
                    (string)$user->getFirstname(),
                    (string)($user->getPatronymic() ?? '')
                )),
            ];
        }

        return new JsonResponse($data);
    }

    #[Route('/incoming_documents', name: 'app_incoming_documents')]
    public function getIncomingDocuments(Request $request, DocumentUserRecipientRepository $recipientRepository): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            $this->addFlash('error', 'Необходимо войти в систему.');
            return $this->redirectToRoute('app_login');
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 10;

        $pagination = $recipientRepository->findPaginatedByUser($currentUser, $page, $limit);

        return $this->render('document/incoming_documents.html.twig', [
            'active_tab' => 'incoming_documents',
            'recipients' => $pagination['recipients'],
            'pagination' => [
                'current_page' => $pagination['page'],
                'total_pages' => $pagination['totalPages'],
                'total_items' => $pagination['total'],
                'items_per_page' => $pagination['limit'],
            ],
        ]);
    }

    #[Route('/outgoing_documents', name: 'app_outgoing_documents')]
    public function getOutgoingDocuments(Request $request, DocumentRepository $documentRepository): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            $this->addFlash('error', 'Необходимо войти в систему.');
            return $this->redirectToRoute('app_login');
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 10;

        $pagination = $documentRepository->findPaginatedByCreatedBy($currentUser, $page, $limit);

        return $this->render('document/outgoing_documents.html.twig', [
            'active_tab' => 'outgoing_documents',
            'documents' => $pagination['documents'],
            'pagination' => [
                'current_page' => $pagination['page'],
                'total_pages' => $pagination['totalPages'],
                'total_items' => $pagination['total'],
                'items_per_page' => $pagination['limit'],
            ],
        ]);
    }

    #[Route('/view_incoming_document/{id}', name: 'app_view_incoming_document', requirements: ['id' => '\d+'])]
    public function viewIncomingDocument(int $id, DocumentRepository $documentRepository): Response
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
                break;
            }
        }

        if (!$isAdmin && !$isCreator && !$isRecipient) {
            $this->addFlash('error', 'Нет доступа к этому документу.');
            return $this->redirectToRoute('app_incoming_documents');
        }

        return $this->render('document/view_incoming_document.html.twig', [
            'active_tab' => 'incoming_documents',
            'document' => $document,
            'isRecipient' => $isRecipient,
            'userRecipient' => $userRecipient,
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

        return $this->render('document/view_outgoing_document.html.twig', [
            'active_tab' => 'outgoing_documents',
            'document' => $document,
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
        $rootOrganization = $userOrganization;
        if ($userOrganization && !$isAdmin) {
            while ($rootOrganization->getParent() !== null) {
                $rootOrganization = $rootOrganization->getParent();
            }
        }

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
        ];

        $renderForm = function (array $data = []) use ($document, $organizationsWithChildren, $initialFormData): Response {
            $formData = $data !== [] ? $data : $initialFormData;
            return $this->render('document/edit_outgoing_document.html.twig', [
                'active_tab' => 'outgoing_documents',
                'document' => $document,
                'organizations' => $organizationsWithChildren,
                'form_data' => $formData,
                'document_statuses' => DocumentStatus::getCreationChoices(),
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

        // Обновление полей документа
        $document->setName($name);
        $document->setDescription(trim((string)($formData['description'] ?? '')));
        $document->setOrganizationCreator($organization);
        $document->setStatus($status);
        $document->setDeadline($deadline);

        // Валидация сущности
        $errors = $validator->validate($document);
        if (count($errors) > 0) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error->getMessage());
            }
            return $renderForm($formData);
        }

        // Обновление получателей — только если форма их передаёт (страница редактирования получателей отдельно)
        if (array_key_exists('user_ids', $formData)) {
            $userIds = $formData['user_ids'] ?? [];
            if (!is_array($userIds)) {
                $userIds = [];
            }
            $userIds = array_map('intval', $userIds);
            $userIds = array_values(array_filter($userIds, fn($id) => $id > 0));
            sort($userIds);

            $currentRecipientIds = [];
            foreach ($document->getUserRecipients() as $recipient) {
                if ($recipient->getUser()) {
                    $currentRecipientIds[] = $recipient->getUser()->getId();
                }
            }
            sort($currentRecipientIds);

            $recipientsChanged = ($userIds !== $currentRecipientIds);

            if ($recipientsChanged) {
                // Удаляем все текущие записи и сразу выполняем
                foreach ($document->getUserRecipients()->toArray() as $recipient) {
                    $entityManager->remove($recipient);
                }
                $document->getUserRecipients()->clear();
                $entityManager->flush();

                // Создаём новые записи
                foreach ($userIds as $userId) {
                    $user = $userRepository->find($userId);
                    if (!$user) {
                        continue;
                    }

                    $recipient = new DocumentUserRecipient();
                    $recipient->setDocument($document);
                    $recipient->setUser($user);
                    $recipient->setStatus(DocumentStatus::NEW);
                    $document->addUserRecipient($recipient);
                    $entityManager->persist($recipient);
                }
            }
        }

        $entityManager->flush();

        $this->addFlash('success', 'Документ успешно обновлён.');
        return $this->redirectToRoute('app_view_outgoing_document', ['id' => $document->getId()]);
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
        $rootOrganization = $userOrganization;
        if ($userOrganization && !$isAdmin) {
            while ($rootOrganization->getParent() !== null) {
                $rootOrganization = $rootOrganization->getParent();
            }
        }

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

        $currentRecipientIds = [];
        foreach ($document->getUserRecipients() as $recipient) {
            if ($recipient->getUser()) {
                $currentRecipientIds[] = $recipient->getUser()->getId();
            }
        }
        $initialFormData = ['user_ids' => $currentRecipientIds];
        $initialUsers = $userRepository->findByIds($currentRecipientIds);

        $renderForm = function (array $data = [], array $users = []) use ($document, $organizationsWithChildren, $initialFormData, $userRepository): Response {
            $formData = $data !== [] ? $data : $initialFormData;
            if ($users === [] && !empty($formData['user_ids'])) {
                $users = $userRepository->findByIds((array) $formData['user_ids']);
            }
            return $this->render('document/edit_recipients_outgoing_document.html.twig', [
                'active_tab' => 'outgoing_documents',
                'document' => $document,
                'organizations' => $organizationsWithChildren,
                'form_data' => $formData,
                'users' => $users,
            ]);
        };

        if (!$request->isMethod('POST')) {
            return $renderForm([], $initialUsers);
        }

        $formData = $request->request->all();
        if (!$this->isCsrfTokenValid('edit_recipients_outgoing_document', $formData['_csrf_token'] ?? '')) {
            $this->addFlash('error', 'Неверный CSRF токен.');
            return $this->redirectToRoute('app_edit_recipients_outgoing_document', ['id' => $id]);
        }

        $userIds = $formData['user_ids'] ?? [];
        if (!is_array($userIds)) {
            $userIds = [];
        }
        $userIds = array_map('intval', $userIds);
        $userIds = array_values(array_filter($userIds, fn($id) => $id > 0));
        sort($userIds);

        $currentRecipientIdsSorted = $currentRecipientIds;
        sort($currentRecipientIdsSorted);
        $recipientsChanged = ($userIds !== $currentRecipientIdsSorted);

        if ($recipientsChanged) {
            foreach ($document->getUserRecipients()->toArray() as $recipient) {
                $entityManager->remove($recipient);
            }
            $document->getUserRecipients()->clear();
            $entityManager->flush();

            foreach ($userIds as $userId) {
                $user = $userRepository->find($userId);
                if (!$user) {
                    continue;
                }
                $recipient = new DocumentUserRecipient();
                $recipient->setDocument($document);
                $recipient->setUser($user);
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
        EntityManagerInterface $entityManager
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
}
