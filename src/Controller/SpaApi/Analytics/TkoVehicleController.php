<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Analytics;

use App\Controller\SpaApi\SpaApiError;
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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/spa/api/analytics/tko/vehicles')]
#[IsGranted('ROLE_TKO')]
final class TkoVehicleController extends AbstractController
{
    private const PAGE_SIZE_DEFAULT = 20;
    private const PAGE_SIZE_MAX = 100;

    public function __construct(
        private readonly AnalyticsTkoVehicleRepository $vehicleRepository,
        private readonly AnalyticsTkoVehicleAttachmentRepository $attachmentRepository,
        private readonly OrganizationRepository $organizationRepository,
        private readonly UserRepository $userRepository,
        private readonly AnalyticsTkoVehicleAttachmentService $attachmentService,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'spa_api_analytics_tko_vehicles_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(1, min(self::PAGE_SIZE_MAX, $request->query->getInt('page_size', self::PAGE_SIZE_DEFAULT)));

        $organizationId = $this->optionalPositiveInt($request->query->getString('organization_id'));
        $statusRaw = trim($request->query->getString('status'));
        $status = '' !== $statusRaw ? AnalyticsTkoVehicleStatus::tryFrom($statusRaw) : null;
        if ('' !== $statusRaw && null === $status) {
            return $this->json(['error' => SpaApiError::TKO_VEHICLE_INVALID_STATUS], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->vehicleRepository->findPage($page, $limit, [
            'organizationId' => $organizationId,
            'status' => $status,
        ]);

        return $this->json([
            'items' => array_map(fn (AnalyticsTkoVehicle $v) => $this->presentVehicle($v), $result['items']),
            'pagination' => [
                'current_page' => $result['page'],
                'total_pages' => $result['totalPages'],
                'total_items' => $result['total'],
                'items_per_page' => $result['limit'],
            ],
        ]);
    }

    #[Route('/{id}', name: 'spa_api_analytics_tko_vehicles_view', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function view(int $id): JsonResponse
    {
        $vehicle = $this->vehicleRepository->find($id);
        if (!$vehicle instanceof AnalyticsTkoVehicle) {
            return $this->json(['error' => SpaApiError::TKO_VEHICLE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['data' => $this->presentVehicle($vehicle, full: true)]);
    }

    #[Route('', name: 'spa_api_analytics_tko_vehicles_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $vehicle = new AnalyticsTkoVehicle();
        $vehicle->setCreatedBy($user);
        $vehicle->setUpdatedBy($user);

        $error = $this->applyBody($vehicle, $body, required: true);
        if (null !== $error) {
            return $error;
        }

        $this->em->persist($vehicle);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->json(['error' => SpaApiError::TKO_VEHICLE_LICENSE_EXISTS], Response::HTTP_CONFLICT);
        }

        return $this->json(['data' => $this->presentVehicle($vehicle, full: true)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'spa_api_analytics_tko_vehicles_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $vehicle = $this->vehicleRepository->find($id);
        if (!$vehicle instanceof AnalyticsTkoVehicle) {
            return $this->json(['error' => SpaApiError::TKO_VEHICLE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }
        if ([] === $body) {
            return $this->json(['error' => SpaApiError::UPDATE_FIELDS_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        $error = $this->applyBody($vehicle, $body, required: false);
        if (null !== $error) {
            return $error;
        }

        $vehicle->setUpdatedBy($user);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->json(['error' => SpaApiError::TKO_VEHICLE_LICENSE_EXISTS], Response::HTTP_CONFLICT);
        }

        return $this->json(['data' => $this->presentVehicle($vehicle, full: true)]);
    }

    #[Route('/{id}', name: 'spa_api_analytics_tko_vehicles_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $vehicle = $this->vehicleRepository->find($id);
        if (!$vehicle instanceof AnalyticsTkoVehicle) {
            return $this->json(['error' => SpaApiError::TKO_VEHICLE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        foreach ($vehicle->getAttachments()->toArray() as $attachment) {
            $this->attachmentService->delete($attachment);
        }

        try {
            $this->em->remove($vehicle);
            $this->em->flush();
        } catch (ForeignKeyConstraintViolationException) {
            return $this->json(['error' => SpaApiError::TKO_VEHICLE_HAS_TRIPS], Response::HTTP_CONFLICT);
        }

        return $this->json(['success' => true]);
    }

    #[Route('/{id}/attachments', name: 'spa_api_analytics_tko_vehicles_attachment_upload', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function uploadAttachment(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $vehicle = $this->vehicleRepository->find($id);
        if (!$vehicle instanceof AnalyticsTkoVehicle) {
            return $this->json(['error' => SpaApiError::TKO_VEHICLE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $context = $request->request->getString('context');
        if (!\in_array($context, [AnalyticsTkoVehicleAttachment::CONTEXT_VEHICLE, AnalyticsTkoVehicleAttachment::CONTEXT_INTERNAL], true)) {
            return $this->json(['error' => SpaApiError::TKO_VEHICLE_INVALID_CONTEXT], Response::HTTP_BAD_REQUEST);
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->json(['error' => SpaApiError::FILE_NOT_PROVIDED], Response::HTTP_BAD_REQUEST);
        }

        $attachment = $this->attachmentService->upload($file, $vehicle, $context, $user);

        return $this->json(['data' => $this->presentAttachment($attachment)], Response::HTTP_CREATED);
    }

    #[Route('/{id}/attachments/{attachmentId}/download', name: 'spa_api_analytics_tko_vehicles_attachment_download', requirements: ['id' => '\d+', 'attachmentId' => '\d+'], methods: ['GET'])]
    public function downloadAttachment(int $id, int $attachmentId): Response
    {
        $attachment = $this->findAttachmentForVehicle($id, $attachmentId);
        if ($attachment instanceof JsonResponse) {
            return $attachment;
        }

        $stream = $this->attachmentService->getObjectStream($attachment);

        return new StreamedResponse(static function () use ($stream): void {
            while (!$stream->eof()) {
                echo $stream->read(1024 * 1024);
            }
        }, Response::HTTP_OK, [
            'Content-Type' => $attachment->getContentType(),
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $attachment->getFilename()).'"',
            'Content-Length' => (string) $attachment->getSizeBytes(),
        ]);
    }

    #[Route('/{id}/attachments/{attachmentId}', name: 'spa_api_analytics_tko_vehicles_attachment_delete', requirements: ['id' => '\d+', 'attachmentId' => '\d+'], methods: ['DELETE'])]
    public function deleteAttachment(int $id, int $attachmentId): JsonResponse
    {
        $attachment = $this->findAttachmentForVehicle($id, $attachmentId);
        if ($attachment instanceof JsonResponse) {
            return $attachment;
        }

        $this->attachmentService->delete($attachment);

        return $this->json(['success' => true]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyBody(AnalyticsTkoVehicle $vehicle, array $body, bool $required): ?JsonResponse
    {
        if ($required || \array_key_exists('model', $body)) {
            $model = trim((string) ($body['model'] ?? ''));
            if ('' === $model) {
                return $this->json(['error' => SpaApiError::TKO_VEHICLE_MODEL_REQUIRED], Response::HTTP_BAD_REQUEST);
            }
            $vehicle->setModel($model);
        }

        if ($required || \array_key_exists('type', $body)) {
            $type = trim((string) ($body['type'] ?? ''));
            if ('' === $type) {
                return $this->json(['error' => SpaApiError::TKO_VEHICLE_TYPE_REQUIRED], Response::HTTP_BAD_REQUEST);
            }
            $vehicle->setType($type);
        }

        if ($required || \array_key_exists('license_number', $body)) {
            $license = trim((string) ($body['license_number'] ?? ''));
            if ('' === $license) {
                return $this->json(['error' => SpaApiError::TKO_VEHICLE_LICENSE_REQUIRED], Response::HTTP_BAD_REQUEST);
            }
            $vehicle->setLicenseNumber($license);
        }

        if ($required || \array_key_exists('status', $body)) {
            $statusRaw = (string) ($body['status'] ?? AnalyticsTkoVehicleStatus::Active->value);
            $status = AnalyticsTkoVehicleStatus::tryFrom($statusRaw);
            if (null === $status) {
                return $this->json(['error' => SpaApiError::TKO_VEHICLE_INVALID_STATUS], Response::HTTP_BAD_REQUEST);
            }
            $vehicle->setStatus($status);
        }

        if (\array_key_exists('organization_id', $body)) {
            $organizationId = $body['organization_id'];
            if (null === $organizationId || '' === $organizationId) {
                $vehicle->setOrganization(null);
            } else {
                $organization = $this->organizationRepository->find((int) $organizationId);
                if (!$organization instanceof AbstractOrganization || null !== $organization->getParent()) {
                    return $this->json(['error' => SpaApiError::TKO_VEHICLE_INVALID_ORGANIZATION], Response::HTTP_BAD_REQUEST);
                }
                $vehicle->setOrganization($organization);
            }
        }

        if (\array_key_exists('driver_id', $body)) {
            $driverId = $body['driver_id'];
            if (null === $driverId || '' === $driverId) {
                $vehicle->setDriver(null);
            } else {
                $driver = $this->userRepository->find((int) $driverId);
                if (!$driver instanceof User) {
                    return $this->json(['error' => SpaApiError::TKO_VEHICLE_INVALID_DRIVER], Response::HTTP_BAD_REQUEST);
                }
                $vehicle->setDriver($driver);
            }
        }

        if (\array_key_exists('volume', $body)) {
            $vehicle->setVolume($this->normalizeDecimal($body['volume']));
        }
        if (\array_key_exists('compaction_ratio', $body)) {
            $vehicle->setCompactionRatio($this->normalizeDecimal($body['compaction_ratio']));
        }
        if (\array_key_exists('fuel_consumption_norm', $body)) {
            $vehicle->setFuelConsumptionNorm($this->normalizeDecimal($body['fuel_consumption_norm']));
        }

        if (\array_key_exists('planned_write_off', $body)) {
            $raw = $body['planned_write_off'];
            if (null === $raw || '' === $raw) {
                $vehicle->setPlannedWriteOff(null);
            } else {
                $date = $this->parseDate((string) $raw);
                if (null === $date) {
                    return $this->json(['error' => SpaApiError::TKO_VEHICLE_INVALID_DATE], Response::HTTP_BAD_REQUEST);
                }
                $vehicle->setPlannedWriteOff($date);
            }
        }

        return null;
    }

    private function findAttachmentForVehicle(int $vehicleId, int $attachmentId): AnalyticsTkoVehicleAttachment|JsonResponse
    {
        $vehicle = $this->vehicleRepository->find($vehicleId);
        if (!$vehicle instanceof AnalyticsTkoVehicle) {
            return $this->json(['error' => SpaApiError::TKO_VEHICLE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $attachment = $this->attachmentRepository->find($attachmentId);
        if (!$attachment instanceof AnalyticsTkoVehicleAttachment || $attachment->getVehicle()?->getId() !== $vehicle->getId()) {
            return $this->json(['error' => SpaApiError::ATTACHMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        return $attachment;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentVehicle(AnalyticsTkoVehicle $vehicle, bool $full = false): array
    {
        $driver = $vehicle->getDriver();
        $organization = $vehicle->getOrganization();

        $data = [
            'id' => $vehicle->getId(),
            'license_number' => $vehicle->getLicenseNumber(),
            'model' => $vehicle->getModel(),
            'type' => $vehicle->getType(),
            'volume' => $this->presentDecimal($vehicle->getVolume()),
            'compaction_ratio' => $this->presentDecimal($vehicle->getCompactionRatio()),
            'fuel_consumption_norm' => $this->presentDecimal($vehicle->getFuelConsumptionNorm()),
            'planned_write_off' => $vehicle->getPlannedWriteOff()?->format('Y-m-d'),
            'status' => $vehicle->getStatus()->value,
            'status_label' => $vehicle->getStatus()->label(),
            'organization' => null !== $organization ? [
                'id' => $organization->getId(),
                'name' => $organization->getName(),
            ] : null,
            'driver' => null !== $driver ? [
                'id' => $driver->getId(),
                'lastname' => $driver->getLastname(),
                'firstname' => $driver->getFirstname(),
                'patronymic' => $driver->getPatronymic(),
            ] : null,
        ];

        if ($full) {
            $attachments = $this->attachmentRepository->createQueryBuilder('a')
                ->andWhere('a.vehicle = :vehicle')
                ->setParameter('vehicle', $vehicle)
                ->orderBy('a.createdAt', 'DESC')
                ->getQuery()
                ->getResult();

            $data['attachments'] = [
                'vehicle' => [],
                'internal' => [],
            ];
            foreach ($attachments as $attachment) {
                if (!$attachment instanceof AnalyticsTkoVehicleAttachment) {
                    continue;
                }
                $data['attachments'][$attachment->getContext()][] = $this->presentAttachment($attachment);
            }

            $data['created_at'] = $vehicle->getCreatedAt()?->format(\DateTimeInterface::ATOM);
            $data['updated_at'] = $vehicle->getUpdatedAt()?->format(\DateTimeInterface::ATOM);
            $data['created_by'] = $this->presentUserRef($vehicle->getCreatedBy());
            $data['updated_by'] = $this->presentUserRef($vehicle->getUpdatedBy());
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAttachment(AnalyticsTkoVehicleAttachment $attachment): array
    {
        return [
            'id' => $attachment->getId(),
            'filename' => $attachment->getFilename(),
            'content_type' => $attachment->getContentType(),
            'size_bytes' => $attachment->getSizeBytes(),
            'context' => $attachment->getContext(),
            'created_at' => $attachment->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array{id: int, lastname: ?string, firstname: ?string}|null
     */
    private function presentUserRef(?User $user): ?array
    {
        if (null === $user) {
            return null;
        }

        return [
            'id' => $user->getId(),
            'lastname' => $user->getLastname(),
            'firstname' => $user->getFirstname(),
        ];
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
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $date ? null : $date;
    }

    private function normalizeDecimal(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $normalized = str_replace([' ', ','], ['', '.'], trim((string) $value));
        if (!is_numeric($normalized)) {
            return null;
        }

        return $normalized;
    }

    private function presentDecimal(?string $value): ?float
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return (float) $value;
    }
}
