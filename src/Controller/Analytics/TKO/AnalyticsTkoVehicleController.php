<?php

declare(strict_types=1);

namespace App\Controller\Analytics\TKO;

use App\Entity\Analytics\TKO\AnalyticsTkoVehicle;
use App\Entity\Analytics\TKO\AnalyticsTkoVehicleAttachment;
use App\Entity\Organization\AbstractOrganization;
use App\Entity\User\User;
use App\Enum\Analytics\AnalyticsTkoVehicleStatus;
use App\Repository\Analytics\TKO\AnalyticsTkoVehicleAttachmentRepository;
use App\Repository\Analytics\TKO\AnalyticsTkoVehicleRepository;
use App\Repository\Organization\OrganizationRepository;
use App\Repository\User\UserRepository;
use App\Service\Analytics\TKO\AnalyticsTkoVehicleAttachmentService;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_TKO')]
final class AnalyticsTkoVehicleController extends AbstractController
{
    private const PAGE_SIZE = 10;

    #[Route('/analytics/tko/vehicles', name: 'app_analytics_tko_vehicles', methods: ['GET'])]
    public function index(
        Request $request,
        AnalyticsTkoVehicleRepository $vehicleRepository,
        OrganizationRepository $organizationRepository,
        UserRepository $userRepository,
        AnalyticsTkoVehicleAttachmentRepository $attachmentRepository,
    ): Response {
        $page = max(1, $request->query->getInt('page', 1));
        $filterOrganizationId = $this->optionalPositiveInt($request->query->getString('organization_id'));
        $filters = [
            'organizationId' => $filterOrganizationId,
        ];
        $filterQuery = array_filter([
            'organization_id' => $filterOrganizationId,
        ], static fn ($v) => null !== $v && 0 !== $v);

        $result = $vehicleRepository->findPage($page, self::PAGE_SIZE, $filters);
        $filials = $organizationRepository->findAllParentOrganizations();
        $drivers = $userRepository->createQueryBuilder('u')
            ->orderBy('u.lastname', 'ASC')
            ->addOrderBy('u.firstname', 'ASC')
            ->getQuery()
            ->getResult();

        $editing = null;
        $vehicleDocs = [];
        $internalDocs = [];
        $editId = $request->query->getInt('edit');
        if ($editId > 0) {
            $candidate = $vehicleRepository->find($editId);
            if ($candidate instanceof AnalyticsTkoVehicle) {
                $editing = $candidate;
                $vehicleDocs = $attachmentRepository->findByVehicleAndContext(
                    $candidate,
                    AnalyticsTkoVehicleAttachment::CONTEXT_VEHICLE,
                );
                $internalDocs = $attachmentRepository->findByVehicleAndContext(
                    $candidate,
                    AnalyticsTkoVehicleAttachment::CONTEXT_INTERNAL,
                );
            }
        }

        return $this->render('analytics/tko/fill_vehicles.html.twig', [
            'active_tab' => 'analytics_tko_vehicles',
            'vehicles' => $result['items'],
            'filials' => $filials,
            'drivers' => $drivers,
            'editing' => $editing,
            'statuses' => AnalyticsTkoVehicleStatus::cases(),
            'vehicleDocs' => $vehicleDocs,
            'internalDocs' => $internalDocs,
            'pagination' => [
                'current_page' => $result['page'],
                'total_pages' => $result['totalPages'],
                'total_items' => $result['total'],
                'items_per_page' => $result['limit'],
            ],
            'filters' => [
                'organization_id' => $filterOrganizationId,
            ],
            'filter_query' => $filterQuery,
        ]);
    }

    #[Route('/analytics/tko/vehicles/save', name: 'app_analytics_tko_vehicles_save', methods: ['POST'])]
    public function save(
        Request $request,
        AnalyticsTkoVehicleRepository $vehicleRepository,
        OrganizationRepository $organizationRepository,
        UserRepository $userRepository,
        AnalyticsTkoVehicleAttachmentService $attachmentService,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('analytics_tko_vehicle_save', $request->request->getString('_token'))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');

            return $this->redirectToRoute('app_analytics_tko_vehicles');
        }

        $vehicleId = $request->request->getInt('vehicle_id');
        $vehicle = $vehicleId > 0 ? $vehicleRepository->find($vehicleId) : null;
        if ($vehicleId > 0 && !$vehicle instanceof AnalyticsTkoVehicle) {
            $this->addFlash('error', 'ТС не найдено.');

            return $this->redirectToRoute('app_analytics_tko_vehicles');
        }

        $model = trim($request->request->getString('model'));
        if ('' === $model) {
            $this->addFlash('error', 'Укажите модель ТС.');

            return $this->redirectAfterSave($vehicleId);
        }

        $type = trim($request->request->getString('type'));
        if ('' === $type) {
            $this->addFlash('error', 'Укажите тип ТС.');

            return $this->redirectAfterSave($vehicleId);
        }

        $licenseRaw = trim($request->request->getString('license_number'));
        if ('' === $licenseRaw) {
            $this->addFlash('error', 'Укажите госномер.');

            return $this->redirectAfterSave($vehicleId);
        }

        $organizationId = $this->optionalPositiveInt($request->request->getString('organization_id'));
        $organization = null;
        if (null !== $organizationId) {
            $organization = $organizationRepository->find($organizationId);
            if (!$organization instanceof AbstractOrganization || null !== $organization->getParent()) {
                $this->addFlash('error', 'Выберите филиал.');

                return $this->redirectAfterSave($vehicleId);
            }
        }

        $driverId = $this->optionalPositiveInt($request->request->getString('driver_id'));
        $driver = null;
        if (null !== $driverId) {
            $driver = $userRepository->find($driverId);
            if (!$driver instanceof User) {
                $this->addFlash('error', 'Водитель не найден.');

                return $this->redirectAfterSave($vehicleId);
            }
        }

        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            $this->addFlash('error', 'Не удалось определить текущего пользователя.');

            return $this->redirectAfterSave($vehicleId);
        }

        $isNew = null === $vehicle;
        if ($isNew) {
            $vehicle = new AnalyticsTkoVehicle();
            $vehicle->setCreatedBy($currentUser);
        }

        $status = AnalyticsTkoVehicleStatus::tryFrom($request->request->getString('status'))
            ?? AnalyticsTkoVehicleStatus::Active;

        $vehicle->setModel($model);
        $vehicle->setType($type);
        $vehicle->setLicenseNumber($licenseRaw);
        $vehicle->setOrganization($organization);
        $vehicle->setDriver($driver);
        $vehicle->setVolume($this->normalizeDecimal($request->request->getString('volume')));
        $vehicle->setCompactionRatio($this->normalizeDecimal($request->request->getString('compaction_ratio')));
        $vehicle->setFuelConsumptionNorm($this->normalizeDecimal($request->request->getString('fuel_consumption_norm')));
        $vehicle->setPlannedWriteOff($this->parseDate($request->request->getString('planned_write_off')));
        $vehicle->setStatus($status);
        $vehicle->setUpdatedBy($currentUser);

        $em->persist($vehicle);

        try {
            $em->flush();
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('error', 'ТС с таким госномером уже есть в справочнике.');

            return $this->redirectAfterSave($vehicleId);
        }

        $uploadedCount = $this->uploadFilesFromRequest(
            $request,
            $vehicle,
            $attachmentService,
            $currentUser,
        );

        $this->addFlash(
            'success',
            $isNew
                ? 'ТС добавлено.'.($uploadedCount > 0 ? sprintf(' Файлов: %d.', $uploadedCount) : '')
                : 'ТС сохранено.'.($uploadedCount > 0 ? sprintf(' Добавлено файлов: %d.', $uploadedCount) : ''),
        );

        return $this->redirectToRoute('app_analytics_tko_vehicles');
    }

    #[Route('/analytics/tko/vehicles/{id}/delete', name: 'app_analytics_tko_vehicles_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        int $id,
        Request $request,
        AnalyticsTkoVehicleRepository $vehicleRepository,
        AnalyticsTkoVehicleAttachmentService $attachmentService,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('analytics_tko_vehicle_delete_'.$id, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');

            return $this->redirectToRoute('app_analytics_tko_vehicles');
        }

        $vehicle = $vehicleRepository->find($id);
        if (!$vehicle instanceof AnalyticsTkoVehicle) {
            $this->addFlash('error', 'ТС не найдено.');

            return $this->redirectToRoute('app_analytics_tko_vehicles');
        }

        $label = sprintf('%s (%s)', $vehicle->getLicenseNumber() ?? '', $vehicle->getModel() ?? '');

        foreach ($vehicle->getAttachments()->toArray() as $attachment) {
            $attachmentService->delete($attachment);
        }

        try {
            $em->remove($vehicle);
            $em->flush();
        } catch (ForeignKeyConstraintViolationException) {
            $this->addFlash('error', sprintf('Нельзя удалить %s: есть связанные ходки.', $label));

            return $this->redirectToRoute('app_analytics_tko_vehicles');
        }

        $this->addFlash('success', sprintf('ТС удалено: %s.', $label));

        return $this->redirectToRoute('app_analytics_tko_vehicles', [
            'page' => max(1, $request->request->getInt('page', 1)),
        ]);
    }

    #[Route('/analytics/tko/vehicles/{id}/attachments', name: 'app_analytics_tko_vehicles_attachment_upload', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function uploadAttachment(
        int $id,
        Request $request,
        AnalyticsTkoVehicleRepository $vehicleRepository,
        AnalyticsTkoVehicleAttachmentService $attachmentService,
    ): Response {
        if (!$this->isCsrfTokenValid('analytics_tko_vehicle_attachment_upload_'.$id, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');

            return $this->redirectToRoute('app_analytics_tko_vehicles', ['edit' => $id]);
        }

        $vehicle = $vehicleRepository->find($id);
        if (!$vehicle instanceof AnalyticsTkoVehicle) {
            $this->addFlash('error', 'ТС не найдено.');

            return $this->redirectToRoute('app_analytics_tko_vehicles');
        }

        $context = $request->request->getString('context');
        if (!\in_array($context, [AnalyticsTkoVehicleAttachment::CONTEXT_VEHICLE, AnalyticsTkoVehicleAttachment::CONTEXT_INTERNAL], true)) {
            $this->addFlash('error', 'Некорректный тип документов.');

            return $this->redirectToRoute('app_analytics_tko_vehicles', ['edit' => $id]);
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', 'Выберите файл.');

            return $this->redirectToRoute('app_analytics_tko_vehicles', ['edit' => $id]);
        }

        $author = $this->getUser() instanceof User ? $this->getUser() : null;
        $attachmentService->upload($file, $vehicle, $context, $author);
        $this->addFlash('success', 'Файл загружен.');

        return $this->redirectToRoute('app_analytics_tko_vehicles', ['edit' => $id]);
    }

    #[Route('/analytics/tko/vehicles/attachments/{id}/download', name: 'app_analytics_tko_vehicles_attachment_download', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function downloadAttachment(
        int $id,
        AnalyticsTkoVehicleAttachmentRepository $attachmentRepository,
        AnalyticsTkoVehicleAttachmentService $attachmentService,
    ): Response {
        $attachment = $attachmentRepository->find($id);
        if (!$attachment instanceof AnalyticsTkoVehicleAttachment) {
            throw $this->createNotFoundException('Файл не найден.');
        }

        $stream = $attachmentService->getObjectStream($attachment);

        return new StreamedResponse(static function () use ($stream): void {
            while (!$stream->eof()) {
                echo $stream->read(1024 * 1024);
            }
        }, 200, [
            'Content-Type' => $attachment->getContentType(),
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $attachment->getFilename()).'"',
            'Content-Length' => (string) $attachment->getSizeBytes(),
        ]);
    }

    #[Route('/analytics/tko/vehicles/attachments/{id}/delete', name: 'app_analytics_tko_vehicles_attachment_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteAttachment(
        int $id,
        Request $request,
        AnalyticsTkoVehicleAttachmentRepository $attachmentRepository,
        AnalyticsTkoVehicleAttachmentService $attachmentService,
    ): Response {
        $attachment = $attachmentRepository->find($id);
        if (!$attachment instanceof AnalyticsTkoVehicleAttachment) {
            $this->addFlash('error', 'Файл не найден.');

            return $this->redirectToRoute('app_analytics_tko_vehicles');
        }

        $vehicleId = $attachment->getVehicle()?->getId();
        if (!$this->isCsrfTokenValid('analytics_tko_vehicle_attachment_delete_'.$id, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');

            return $this->redirectToRoute('app_analytics_tko_vehicles', $vehicleId ? ['edit' => $vehicleId] : []);
        }

        $attachmentService->delete($attachment);
        $this->addFlash('success', 'Файл удалён.');

        return $this->redirectToRoute('app_analytics_tko_vehicles', $vehicleId ? ['edit' => $vehicleId] : []);
    }

    private function uploadFilesFromRequest(
        Request $request,
        AnalyticsTkoVehicle $vehicle,
        AnalyticsTkoVehicleAttachmentService $attachmentService,
        User $author,
    ): int {
        $count = 0;
        $map = [
            'vehicle_files' => AnalyticsTkoVehicleAttachment::CONTEXT_VEHICLE,
            'internal_files' => AnalyticsTkoVehicleAttachment::CONTEXT_INTERNAL,
        ];

        foreach ($map as $field => $context) {
            $files = $request->files->get($field);
            if ($files instanceof UploadedFile) {
                $files = [$files];
            }
            if (!\is_array($files)) {
                continue;
            }
            foreach ($files as $file) {
                if (!$file instanceof UploadedFile || !$file->isValid()) {
                    continue;
                }
                $attachmentService->upload($file, $vehicle, $context, $author);
                ++$count;
            }
        }

        return $count;
    }

    private function redirectAfterSave(int $vehicleId): Response
    {
        if ($vehicleId > 0) {
            return $this->redirectToRoute('app_analytics_tko_vehicles', ['edit' => $vehicleId]);
        }

        return $this->redirectToRoute('app_analytics_tko_vehicles');
    }

    private function optionalPositiveInt(string $value): ?int
    {
        if ('' === $value || !ctype_digit($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $date ? null : $date;
    }

    private function normalizeDecimal(string $value): ?string
    {
        $raw = trim($value);
        if ('' === $raw) {
            return null;
        }

        $normalized = str_replace([' ', ','], ['', '.'], $raw);
        if (!is_numeric($normalized)) {
            return null;
        }

        return $normalized;
    }
}
