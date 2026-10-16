<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Entity\Purchase\PurchaseApprovalStage;
use App\Entity\Purchase\PurchaseContract;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestContract;
use App\Entity\Purchase\PurchaseRequestFile;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseContractReview;
use App\Enum\Purchase\PurchaseContractStatus;
use App\Enum\Purchase\PurchaseFileType;
use App\Enum\Purchase\PurchaseRequestKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Загруженный файл сразу становится договором заявки, чтобы на шаге счёта
 * его можно было выбрать. В справочник он попадает, когда этот шаг закрыт
 * и к файлу уже есть подписанный скан.
 */
final class PurchaseContractCatalog
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PurchaseFileStorageService $storage,
    ) {
    }

    /**
     * Договор, заведённый в справочнике: заявки нет, обязателен подписанный скан.
     *
     * @param list<UploadedFile> $signed
     * @param list<UploadedFile> $other
     */
    public function createDetached(string $name, User $user, ?UploadedFile $contractFile, array $signed, array $other): PurchaseContract
    {
        $contract = new PurchaseContract();
        $contract->setName($name);
        $this->em->persist($contract);

        $keys = [];
        try {
            $document = $contractFile !== null
                ? $this->attach($contract, $user, $contractFile, PurchaseFileType::CONTRACT, null, $keys)
                : null;
            foreach ($signed as $file) {
                $this->attach($contract, $user, $file, PurchaseFileType::SIGNED_CONTRACT, $document, $keys);
            }
            foreach ($other as $file) {
                $this->attach($contract, $user, $file, PurchaseFileType::OTHER, null, $keys);
            }
            $this->em->flush();
        } catch (\Throwable $error) {
            foreach ($keys as $key) {
                $this->storage->delete($key);
            }
            throw $error;
        }

        return $contract;
    }

    /** Сносит файлы договора и сам договор. Ссылки заявок уходят каскадом, заявки остаются. */
    public function remove(PurchaseContract $contract): void
    {
        foreach ($contract->getFiles()->toArray() as $file) {
            $this->storage->delete($file->getStorageKey());
            $this->em->remove($file);
        }
        $this->em->remove($contract);
        $this->em->flush();
    }

    /**
     * @param list<string> $keys
     */
    private function attach(
        PurchaseContract $contract,
        User $user,
        UploadedFile $upload,
        PurchaseFileType $type,
        ?PurchaseRequestFile $contractFile,
        array &$keys,
    ): PurchaseRequestFile {
        $key = $this->storage->uploadDetached($upload);
        $keys[] = $key;

        $file = new PurchaseRequestFile();
        $file->setType($type);
        $file->setUploadedBy($user);
        $file->setOriginalName(mb_substr($upload->getClientOriginalName(), 0, 255));
        $file->setStorageKey($key);
        $file->setContractFile($contractFile);
        $contract->addFile($file);
        $this->em->persist($file);

        return $file;
    }

    /**
     * Файл договора на заявке — уже договор, просто ещё не из справочника.
     * Быстрой заявке договор не нужен.
     *
     * @return bool появились новые договоры, их надо сохранить
     */
    public function ensureDrafts(PurchaseRequest $request): bool
    {
        if ($request->getCreatedAs() === PurchaseRequestKind::FAST) {
            return false;
        }

        $created = false;
        foreach ($request->getFiles() as $file) {
            if ($file->getType() !== PurchaseFileType::CONTRACT || $file->getContract() !== null) {
                continue;
            }
            $contract = new PurchaseContract();
            $contract->setStatus(PurchaseContractStatus::DRAFT);
            $contract->setName(mb_substr((string) $file->getOriginalName(), 0, 255));
            $this->em->persist($contract);
            $contract->addFile($file);
            $this->link($request, $contract);
            $created = true;
        }

        return $created;
    }

    public function materializeIfDue(PurchaseRequest $request, PurchaseApprovalStage $closed): void
    {
        if (!$this->followsAccept($request, $closed)) {
            return;
        }

        $pending = [];
        foreach ($request->getFiles() as $file) {
            if ($file->getType() !== PurchaseFileType::CONTRACT) {
                continue;
            }
            $contract = $file->getContract();
            if ($contract === null || $contract->isDraft()) {
                $pending[] = $file;
            }
        }

        $loose = [];
        foreach ($request->getFiles() as $file) {
            if (
                $file->getType() === PurchaseFileType::SIGNED_CONTRACT
                && $file->getContractFile() === null
                && $file->getContract() === null
            ) {
                $loose[] = $file;
            }
        }

        foreach ($pending as $file) {
            $signed = $this->signedFor($request, $file);
            if ($signed === [] && count($pending) === 1) {
                $signed = $loose;
                foreach ($signed as $scan) {
                    $scan->setContractFile($file);
                }
            }
            if ($signed === []) {
                continue;
            }

            $contract = $file->getContract();
            if ($contract === null) {
                $contract = new PurchaseContract();
                $contract->setName(mb_substr((string) $file->getOriginalName(), 0, 255));
                $this->em->persist($contract);
                $contract->addFile($file);
                $this->link($request, $contract);
            }
            $contract->setStatus(PurchaseContractStatus::ACTIVE);
            foreach ($signed as $scan) {
                $contract->addFile($scan);
            }
        }
    }

    /** @return bool false — ссылка уже была */
    public function link(PurchaseRequest $request, PurchaseContract $contract): bool
    {
        foreach ($request->getContractLinks() as $existing) {
            if ($existing->getContract() === $contract) {
                return false;
            }
        }

        $link = new PurchaseRequestContract($request, $contract);
        $request->addContractLink($link);
        $contract->addLink($link);
        $this->em->persist($link);

        return true;
    }

    /**
     * @return list<PurchaseRequestFile>
     */
    private function signedFor(PurchaseRequest $request, PurchaseRequestFile $contractFile): array
    {
        $signed = [];
        foreach ($request->getFiles() as $other) {
            if ($other->getType() === PurchaseFileType::SIGNED_CONTRACT && $other->getContractFile() === $contractFile) {
                $signed[] = $other;
            }
        }

        return $signed;
    }

    /** Этап закрыт после утверждения рецензий, промежуточные уже пройдены. */
    private function followsAccept(PurchaseRequest $request, PurchaseApprovalStage $closed): bool
    {
        $acceptAt = null;
        foreach ($request->getStages() as $stage) {
            foreach ($stage->getTasks() as $task) {
                if ($task->getContractReview() === PurchaseContractReview::ACCEPT) {
                    $acceptAt = max($acceptAt ?? 0, $stage->getPosition());
                }
            }
        }
        if ($acceptAt === null || $closed->getPosition() <= $acceptAt) {
            return false;
        }

        foreach ($request->getStages() as $stage) {
            $position = $stage->getPosition();
            if ($position <= $acceptAt || $position >= $closed->getPosition()) {
                continue;
            }
            if (!$stage->getStatus()->isClosed()) {
                return false;
            }
        }

        return true;
    }
}
