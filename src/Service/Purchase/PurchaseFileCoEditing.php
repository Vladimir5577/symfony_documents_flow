<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Entity\Purchase\PurchaseApprovalTask;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestFile;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseFileType;
use App\Enum\Purchase\PurchaseHistoryAction;
use App\Enum\Purchase\PurchaseStagePurpose;
use App\Enum\Purchase\PurchaseTaskDecision;
use Aws\S3\Exception\S3Exception;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Совместная правка вложения в OnlyOffice: один документ на всех, кто сейчас
 * стоит на этапе, и правки видны сразу у всех.
 *
 * Правят в режиме рецензирования, выключить который нельзя: каждая вставка и
 * удаление остаются правкой с автором. Отдел автора едет в user.group — так
 * он попадает и в автора правки внутри docx («Юристы Иванов»), — а плагин
 * подсветки (docker_env/onlyoffice/plugins/dept-highlighter) красит свои правки
 * каждого участника фоном цвета его отдела.
 *
 * Права — те же, что у шага: правит тот, у кого задача на этапе, где заявка
 * стоит сейчас. На параллельном этапе «закупки + бухгалтерия + юристы» это
 * трое одновременно.
 */
final class PurchaseFileCoEditing
{
    /** Плагин подсветки правок по отделам; guid из его config.json. */
    public const HIGHLIGHT_PLUGIN = 'asc.{3F6C2B8E-7D14-4C5A-9E21-6B0D8F4A2C71}';

    public function __construct(
        private readonly PurchaseFileStorageService $storage,
        private readonly PurchaseHistoryLogger $history,
        private readonly PurchaseNotificationPublisher $notifier,
        private readonly PurchaseAccess $access,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.secret%')]
        private readonly string $appSecret,
    ) {
    }

    /**
     * Ключ документа — общий для всех, кто открыл эту редакцию файла.
     *
     * Хвост из подписи: ключ сессии Document Server — это и пропуск в неё, и
     * угадываемый «pf12r3» пускал бы в чужой договор любого, кто видит сервер
     * документов.
     */
    public function documentKey(PurchaseRequestFile $file): string
    {
        $base = sprintf('pf%dr%d', $file->getId(), $file->getEditorRevision());

        return $base . '-' . substr(hash_hmac('sha256', 'purchase-file-key|' . $base, $this->appSecret), 0, 16);
    }

    /**
     * Конфиг DocsAPI.DocEditor целиком: собирает сервер, чтобы его можно было
     * подписать, а браузер лишь добавляет обработчики событий.
     *
     * @param PurchaseApprovalTask|null $myTask моя задача на текущем этапе; null — просмотр
     * @param string|null $callbackUrl только правщику: по нему пишут файл
     * @return array<string, mixed>
     */
    public function editorConfig(
        PurchaseRequestFile $file,
        User $user,
        ?PurchaseApprovalTask $myTask,
        string $contentUrl,
        ?string $callbackUrl,
    ): array {
        $edit = $myTask !== null;
        // Задача замов адресована человеку, а не роли: отдел — пул их этапа.
        $role = $myTask?->getRoleCode() ?? $myTask?->getStage()?->getCandidateRoleCode();

        $person = ['id' => (string) $user->getId(), 'name' => PurchaseHistoryLogger::nameOf($user)];
        if ($edit && $role !== null) {
            $person['group'] = $role->getLabel();
        }

        $editorConfig = [
            'lang' => 'ru',
            'mode' => $edit ? 'edit' : 'view',
            'user' => $person,
            // Быстрый режим: правки соавторов видны сразу. change=false — иначе
            // человек переключит себе строгий режим, и выбор запомнит браузер.
            'coEditing' => ['mode' => 'fast', 'change' => false],
            'customization' => [
                'forcesave' => $edit,
                'review' => ['trackChanges' => true, 'reviewDisplay' => 'markup', 'hoverMode' => false],
            ],
        ];
        if ($edit && $callbackUrl !== null) {
            $editorConfig['callbackUrl'] = $callbackUrl;
        }
        if ($edit && $role !== null) {
            $editorConfig['plugins'] = [
                'autostart' => [self::HIGHLIGHT_PLUGIN],
                'options' => [self::HIGHLIGHT_PLUGIN => ['department' => $role->value]],
            ];
        }

        return [
            'width' => '100%',
            'height' => '100%',
            'type' => 'desktop',
            'documentType' => 'word',
            'document' => [
                'fileType' => 'docx',
                'key' => $this->documentKey($file),
                'title' => $file->getOriginalName() ?: 'document.docx',
                'url' => $contentUrl,
                // Только рецензирование: выключить отслеживание правок нельзя,
                // иначе правка останется без автора и без цвета отдела.
                'permissions' => $edit
                    ? ['edit' => false, 'review' => true, 'comment' => true]
                    : ['edit' => false, 'review' => false, 'comment' => false],
            ],
            'editorConfig' => $editorConfig,
        ];
    }

    /**
     * Итог правки из OnlyOffice — в хранилище, историю и уведомления.
     *
     * @param bool $sessionClosed все вышли и Document Server собрал итог:
     *                            следующая сессия откроется под новым ключом
     * @param list<User> $editors кто правил; первый — актор записи в истории
     */
    public function commit(
        PurchaseRequest $purchase,
        PurchaseRequestFile $file,
        string $content,
        bool $sessionClosed,
        array $editors,
    ): void {
        $isContract = $file->getType() === PurchaseFileType::CONTRACT;
        // Сравнить надо до записи: status 2 после «Сохранить» приносит тот же
        // текст, и «изменён после вашего согласования» было бы неправдой.
        $textChanged = $isContract && $this->textChanged($file, $content);

        $this->storage->replace($file->getStorageKey(), $content);
        if ($sessionClosed) {
            $file->nextEditorRevision();
        }

        $actor = $editors[0] ?? null;
        $approvers = [];
        if ($actor !== null) {
            $note = sprintf('%s: %s', $file->getType()->getLabel(), (string) $file->getOriginalName());
            if (count($editors) > 1) {
                $note .= ' (правили: ' . self::names($editors) . ')';
            }
            $this->history->log($purchase, $actor, PurchaseHistoryAction::FILE_EDITED, $note);

            if ($textChanged) {
                $approvers = $this->approversToNotify($purchase, $editors);
            }
            if ($approvers !== []) {
                $this->history->log(
                    $purchase,
                    $actor,
                    PurchaseHistoryAction::CONTRACT_CHANGED,
                    'Уведомлены согласовавшие: ' . self::names($approvers),
                );
            }
        }

        $this->em->flush();

        if ($actor === null || $approvers === []) {
            return;
        }
        // Файл уже записан: упавшая шина уведомлений не должна выглядеть для
        // Document Server как несохранённый документ — status 6 он не повторяет.
        try {
            $this->notifier->notifyContractChanged($purchase, $actor, $approvers, (string) $file->getOriginalName());
        } catch (\Throwable $e) {
            $this->logger->error('Purchase contract change notification failed', [
                'purchaseId' => $purchase->getId(),
                'fileId' => $file->getId(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Кому сказать, что договор изменился: тем, кто уже согласовал пакет
     * документов (ресёрч и подписи), и кто сейчас его не правит.
     *
     * Кто ещё стоит на текущем этапе со своей задачей, тот не согласовал, а
     * договаривается: изменения он видит в документе вживую, и «изменён после
     * вашего согласования» было бы для него неправдой. Разбор и исполнение не в
     * счёт: на разборе договора ещё нет, а на исполнении его не согласуют.
     *
     * @param list<User> $editors
     * @return list<User>
     */
    public function approversToNotify(PurchaseRequest $purchase, array $editors): array
    {
        if (!$purchase->getStatus()->isInRoute()) {
            return [];
        }

        $skip = [];
        foreach ($editors as $editor) {
            $skip[(int) $editor->getId()] = true;
        }

        $approvers = [];
        foreach ($purchase->getStages() as $stage) {
            $purpose = $stage->getPurpose();
            if ($purpose !== PurchaseStagePurpose::SOURCING && $purpose !== PurchaseStagePurpose::SIGN_OFF) {
                continue;
            }
            foreach ($stage->getTasks() as $task) {
                $approver = $task->getDecidedBy();
                if ($task->getDecision() !== PurchaseTaskDecision::APPROVED || $approver === null) {
                    continue;
                }
                $id = (int) $approver->getId();
                if (isset($skip[$id]) || isset($approvers[$id])) {
                    continue;
                }
                if ($this->access->findMyActiveTask($purchase, $approver) !== null) {
                    continue;
                }
                $approvers[$id] = $approver;
            }
        }

        return array_values($approvers);
    }

    /**
     * Текст договора отличается от лежащего в хранилище.
     *
     * Сравниваем word/document.xml, а не архив целиком: Document Server
     * пересобирает docx при каждом сохранении, и байты архива разные даже без
     * единой правки. Не удалось прочитать — считаем, что изменился: лишнее
     * уведомление лучше пропущенного.
     */
    private function textChanged(PurchaseRequestFile $file, string $content): bool
    {
        try {
            $stored = (string) $this->storage->getObject($file->getStorageKey())['Body'];
        } catch (S3Exception) {
            return true;
        }
        $before = self::documentXml($stored);

        return $before === null || $before !== self::documentXml($content);
    }

    private static function documentXml(string $docx): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'pfdocx');
        if ($path === false) {
            return null;
        }
        try {
            file_put_contents($path, $docx);
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                return null;
            }
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();

            return $xml === false ? null : $xml;
        } finally {
            @unlink($path);
        }
    }

    /** @param list<User> $users */
    private static function names(array $users): string
    {
        return implode(', ', array_map(static fn (User $user): string => PurchaseHistoryLogger::nameOf($user), $users));
    }
}
