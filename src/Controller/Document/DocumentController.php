<?php

namespace App\Controller\Document;

use App\Entity\Document;
use App\Entity\DocumentUserRecipient;
use App\Entity\User;
use App\Enum\DocumentStatus;
use App\Repository\DocumentRepository;
use App\Repository\DocumentTypeRepository;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        int $type_id,
        Request $request,
        DocumentTypeRepository $documentTypeRepository,
        OrganizationRepository $organizationRepository,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator
    ): Response {
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
        $name = trim((string) ($formData['name'] ?? ''));
        if ($name === '') {
            $this->addFlash('error', 'Название документа обязательно для заполнения.');
            return $renderForm($formData);
        }

        // Получаем организацию
        $organizationId = (int) ($formData['organization_id'] ?? 0);
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
        $document->setDescription(trim((string) ($formData['description'] ?? '')) ?: null);
        $document->setOrganizationCreator($organization);
        $document->setDocumentType($documentType);
        // Обрабатываем статус
        $statusStr = trim((string) ($formData['status'] ?? ''));
        if ($statusStr !== '') {
            try {
                $status = DocumentStatus::from($statusStr);
                $document->setStatus($status);
            } catch (\ValueError $e) {
                $this->addFlash('error', 'Неверный статус документа.');
                return $renderForm($formData);
            }
        }
        $document->setFile(trim((string) ($formData['file'] ?? '')) ?: null);
        $document->setCreatedBy($currentUser);

        // Обрабатываем deadline
        $deadlineStr = trim((string) ($formData['deadline'] ?? ''));
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
                $userId = (int) $userId;
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
        return $this->redirectToRoute('app_new_document');
    }

    #[Route('/document/organization-users/{id}', name: 'app_document_org_users', methods: ['GET'])]
    public function getOrganizationUsers(
        int $id,
        Request $request,
        OrganizationRepository $organizationRepository,
        UserRepository $userRepository
    ): JsonResponse {
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
                    (string) $user->getLastname(),
                    (string) $user->getFirstname(),
                    (string) ($user->getPatronymic() ?? '')
                )),
            ];
        }

        return new JsonResponse($data);
    }

    #[Route('/incoming_documents', name: 'app_incoming_documents')]
    public function getIncomingDocuments(): Response
    {

        return $this->render('document/incoming_documents.html.twig', [
            'active_tab' => 'incoming_documents',
        ]);
    }

    #[Route('/outgoing_documents', name: 'app_outgoing_documents')]
    public function getOutgoingDocuments(): Response
    {

        return $this->render('document/outgoing_documents.html.twig', [
            'active_tab' => 'outgoing_documents',
        ]);
    }
}
