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
use setasign\Fpdi\Tcpdf\Fpdi;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
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
        ValidatorInterface     $validator
    ): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            $this->addFlash('error', 'Необходимо войти в систему для создания документа.');
            return $this->redirectToRoute('app_login');
        }

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

        // Пользователи, которые могут работать с документами
        $availableUsers = $userRepository->findAllWorkWithDocuments();

        $formData = [];
        $initialFormData = [
            'status' => DocumentStatus::NEW->value,
        ];
        // Устанавливаем организацию пользователя по умолчанию
        if ($userOrganization) {
            $initialFormData['organization_id'] = $userOrganization->getId();
        }

        $renderForm = function (array $data = []) use ($documentType, $organizationsWithChildren, $initialFormData, $availableUsers): Response {
            $formData = $data !== [] ? $data : $initialFormData;
            return $this->render('document/create_document.html.twig', [
                'active_tab' => 'new_document',
                'document_type' => $documentType,
                'organizations' => $organizationsWithChildren,
                'form_data' => $formData,
                'document_statuses' => DocumentStatus::getCreationChoices(),
                'users' => $availableUsers,
            ]);
        };

        if (!$request->isMethod('POST')) {
            return $renderForm([]);
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

        // Получаем организацию
        $organizationId = (int)($formData['organization_id'] ?? 0);
        if ($organizationId <= 0) {
            $this->addFlash('error', 'Необходимо выбрать организацию.');
            return $renderForm($formData);
        }

        $organization = $organizationRepository->find($organizationId);
        if (!$organization) {
            $this->addFlash('error', 'Организация не найдена.');
            return $renderForm($formData);
        }

        // Проверяем права доступа к выбранной организации
        if (!$isAdmin) {
            if (!$userOrganization) {
                $this->addFlash('error', 'Не удалось определить организацию. Обратитесь к администратору.');
                return $this->redirectToRoute('app_new_document');
            }

            // Разрешена организация пользователя или любая дочерняя к ней
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
                $this->addFlash('error', 'Вы не можете выбрать организацию вне вашего дерева организаций.');
                return $renderForm($formData);
            }
        }

        // Создаем документ
        $document = new Document();
        $document->setName($name);
        $document->setDescription(trim((string)($formData['description'] ?? '')) ?: null);
        $document->setOrganizationCreator($organization);
        $document->setDocumentType($documentType);
        // Обрабатываем статус
        $statusStr = trim((string)($formData['status'] ?? ''));
        if ($statusStr !== '') {
            try {
                $status = DocumentStatus::from($statusStr);
                $document->setStatus($status);
            } catch (\ValueError $e) {
                $this->addFlash('error', 'Неверный статус документа.');
                return $renderForm($formData);
            }
        }

        // upload file

        $file = $request->files->get('originalFile');

        if (!$file || !$file->isValid()) {
            $this->addFlash('error', 'Выберите файл (PDF, JPEG или PNG).');
            return $renderForm($formData);
        }

        if ($file->getSize() > 5 * 1024 * 1024) {
            $this->addFlash('error', 'Файл слишком большой (максимум 5 МБ).');
            return $renderForm($formData);
        }

        if (!in_array($file->getMimeType(), [
            'application/pdf',
            'image/jpeg',
            'image/png',
        ], true)) {
            $this->addFlash('error', 'Допустимые форматы: PDF, JPEG, PNG.');
            return $renderForm($formData);
        }

        $uploadDir = $this->getParameter('private_upload_dir');

        $filename = bin2hex(random_bytes(16))
            . '.' . ($file->guessExtension() ?? 'bin');

        $file->move($uploadDir, $filename);

        $document->setOriginalFile($filename);
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
                $recipient->setStatus($document->getStatus() ?? DocumentStatus::NEW);
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
    public function getIncomingDocuments(DocumentUserRecipientRepository $recipientRepository): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            $this->addFlash('error', 'Необходимо войти в систему.');
            return $this->redirectToRoute('app_login');
        }

        $recipients = $recipientRepository->findByUserWithDocument($currentUser);

        return $this->render('document/incoming_documents.html.twig', [
            'active_tab' => 'incoming_documents',
            'recipients' => $recipients,
        ]);
    }

    #[Route('/outgoing_documents', name: 'app_outgoing_documents')]
    public function getOutgoingDocuments(DocumentRepository $documentRepository): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            $this->addFlash('error', 'Необходимо войти в систему.');
            return $this->redirectToRoute('app_login');
        }

        $documents = $documentRepository->findByCreatedBy($currentUser);

        return $this->render('document/outgoing_documents.html.twig', [
            'active_tab' => 'outgoing_documents',
            'documents' => $documents,
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

        // Разрешенные статусы для получателей
        $allowedStatuses = [
            DocumentStatus::IN_PROGRESS,
            DocumentStatus::IN_REVIEW,
            DocumentStatus::APPROVED,
            DocumentStatus::REJECTED,
        ];

        if (!in_array($status, $allowedStatuses, true)) {
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

    #[Route('/history_outgoing_documents', name: 'app_history_outgoing_documents')]
    public function getHistoryOutgoingDocuments(DocumentRepository $documentRepository): Response
    {
        return $this->render('document/history_outgoing_document.html.twig', [
            'active_tab' => 'outgoing_documents',
        ]);
    }
}
