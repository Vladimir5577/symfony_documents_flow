<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Entity\Purchase\PurchaseApprovalTask;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestFile;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseContractReview;
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
 * удаление остаются правкой с автором. Автор — роль текущей задачи, не ФИО:
 * один человек с двух отделов оставляет две правки. Плагин подсветки
 * (docker_env/onlyoffice/plugins/dept-highlighter) красит их цветом отдела.
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
        // Утверждение рецензий — принять или отклонить чужие правки. В режиме
        // «только рецензирование» OnlyOffice пускает закрыть лишь свои.
        $acceptsReviews = $myTask?->getContractReview() === PurchaseContractReview::ACCEPT;
        // Задача замов адресована человеку, а не роли: отдел — пул их этапа.
        $role = $myTask?->getRoleCode() ?? $myTask?->getStage()?->getCandidateRoleCode();

        // group не ставим: OnlyOffice дописывает его к имени автора («Юристы Иванов»).
        // Зритель правок не создаёт, ему хватает своего id.
        $person = $edit && $role !== null
            ? ['id' => $role->value, 'name' => $role->getLabel()]
            : ['id' => (string) $user->getId(), 'name' => PurchaseHistoryLogger::nameOf($user)];

        $editorConfig = [
            'lang' => 'ru',
            'mode' => $edit ? 'edit' : 'view',
            'user' => $person,
            // Быстрый режим: правки соавторов видны сразу. change=false — иначе
            // человек переключит себе строгий режим, и выбор запомнит браузер.
            'coEditing' => ['mode' => 'fast', 'change' => false],
            'customization' => [
                // false: при true OnlyOffice шлёт файл в хранилище на каждом
                // автосохранении. Пишут только кнопки — командой forcesave.
                'forcesave' => false,
                'review' => [
                    'trackChanges' => $edit && !$acceptsReviews,
                    'reviewDisplay' => 'markup',
                    'hoverMode' => false,
                ],
            ],
        ];
        if ($edit && $callbackUrl !== null) {
            $editorConfig['callbackUrl'] = $callbackUrl;
        }
        if ($edit && $role !== null && !$acceptsReviews) {
            $editorConfig['plugins'] = [
                'autostart' => [self::HIGHLIGHT_PLUGIN],
                'options' => [self::HIGHLIGHT_PLUGIN => ['department' => $role->value]],
            ];
        } elseif ($edit && $acceptsReviews) {
            // Принятие правки снимает w:ins, заливка отдела остаётся. Плагин её убирает.
            $editorConfig['plugins'] = [
                'autostart' => [self::HIGHLIGHT_PLUGIN],
                'options' => [self::HIGHLIGHT_PLUGIN => ['clearAccepted' => true]],
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
                // Рецензирование: чужие правки принять нельзя. Утверждение — можно.
                'permissions' => match (true) {
                    $acceptsReviews => ['edit' => true, 'review' => true, 'comment' => true],
                    $edit => ['edit' => false, 'review' => true, 'comment' => true],
                    default => ['edit' => false, 'review' => false, 'comment' => false],
                },
            ],
            'editorConfig' => $editorConfig,
        ];
    }

    /**
     * Сессия закрыта без «Сохранить». Файл не трогаем, ключ сменяем:
     * иначе следующий заход получит кэш Document Server с теми правками.
     */
    public function dropSession(PurchaseRequestFile $file): void
    {
        $file->nextEditorRevision();
        $this->em->flush();
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
        // Подсветка отдела — заливка в самом файле, не правка. «Принять» её не
        // снимает: когда непринятых правок не осталось, заливку отделов убираем.
        $content = self::withoutApprovedHighlight($content);
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

    /**
     * Цвета подсветки отделов — те же, что красит плагин dept-highlighter.
     * Принятие правки снимает w:ins и оставляет w:shd на уже обычном тексте.
     */
    private const HIGHLIGHT_FILLS = 'C9E7CA|B8EAE6|E0D2F2|FFF1A6|F9C6C6';

    private const WML = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * Копия для скачивания и печати: правки приняты, заливка отделов снята.
     * В хранилище документ не меняется — в редакторе рецензии остаются.
     *
     * ponytail: правки в теле, колонтитулах и сносках. Обтекание в mc:AlternateContent
     * и правки внутри контент-контролов не разбираем — такой договор отдать как есть.
     */
    public static function acceptedCopy(string $docx): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pfdocx');
        if ($path === false) {
            return $docx;
        }
        try {
            file_put_contents($path, $docx);
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                return $docx;
            }
            $names = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = $zip->getNameIndex($i);
                if (is_string($name) && preg_match('#^word/(document|footnotes|endnotes|header\d+|footer\d+)\.xml$#', $name) === 1) {
                    $names[] = $name;
                }
            }
            $changed = false;
            foreach ($names as $name) {
                $xml = $zip->getFromName($name);
                if (!is_string($xml)) {
                    continue;
                }
                $clean = self::acceptRevisionsXml($xml);
                if ($clean === $xml) {
                    continue;
                }
                $zip->deleteName($name);
                $zip->addFromString($name, $clean);
                $changed = true;
            }
            $zip->close();
            if (!$changed) {
                return $docx;
            }
            $bytes = file_get_contents($path);

            return $bytes === false ? $docx : $bytes;
        } finally {
            @unlink($path);
        }
    }

    /**
     * Заливка отдела только на непринятой вставке. Принятый фрагмент её теряет,
     * даже если в договоре ещё есть другие правки.
     */
    private static function withoutApprovedHighlight(string $docx): string
    {
        $xml = self::documentXml($docx);
        // highlight none Word рисует чёрным, поэтому его тоже снимаем.
        if ($xml === null
            || (preg_match('/w:fill="(?:' . self::HIGHLIGHT_FILLS . ')"/i', $xml) !== 1
                && preg_match('/<w:highlight\b[^>]*\bw:val="none"/i', $xml) !== 1)
        ) {
            return $docx;
        }
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;
        if (!$dom->loadXML($xml, LIBXML_NONET)) {
            return $docx;
        }
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('w', self::WML);
        $fills = array_flip(explode('|', self::HIGHLIGHT_FILLS));
        $nodes = [];
        foreach ($xp->query('//w:shd') ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $fill = strtoupper($node->getAttribute('w:fill'));
            if (!isset($fills[$fill]) || self::insideRevision($node)) {
                continue;
            }
            $nodes[] = $node;
        }
        foreach ($nodes as $node) {
            $node->parentNode?->removeChild($node);
        }
        $strippedNone = self::stripNoneHighlight($xp);
        if ($nodes === [] && !$strippedNone) {
            return $docx;
        }
        $out = $dom->saveXML();

        return is_string($out) ? (self::replaceDocumentXml($docx, $out) ?? $docx) : $docx;
    }

    /** Заливка внутри непринятой правки. Снаружи — уже принятый текст. */
    private static function insideRevision(\DOMNode $node): bool
    {
        $parent = $node->parentNode;
        while ($parent instanceof \DOMElement) {
            if ($parent->namespaceURI === self::WML
                && in_array($parent->localName, ['ins', 'del', 'moveFrom', 'moveTo'], true)
            ) {
                return true;
            }
            $parent = $parent->parentNode;
        }

        return false;
    }

    /** Принять правки: вставки остаются, удаления и подсветка отделов уходят. */
    private static function acceptRevisionsXml(string $xml): string
    {
        if (preg_match('/<w:(?:ins|del|moveFrom|moveTo|shd|highlight)\b/', $xml) !== 1) {
            return $xml;
        }
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;
        if (!$dom->loadXML($xml, LIBXML_NONET)) {
            return $xml;
        }
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('w', self::WML);

        self::mergeDeletedParagraphs($xp);
        self::removeNodes($xp, '//w:tr[w:trPr/w:del]');
        self::removeNodes($xp, '//w:del|//w:moveFrom');
        self::unwrapNodes($xp, '//w:ins|//w:moveTo');
        self::removeNodes($xp, '//w:rPrChange|//w:pPrChange|//w:sectPrChange|//w:tblPrChange|//w:tblGridChange|//w:trPrChange|//w:tcPrChange');
        self::stripDepartmentHighlight($xp);

        $out = $dom->saveXML();

        return is_string($out) ? $out : $xml;
    }

    /** Удалённый абзац склеивается со следующим, иначе на печати останется пустая строка. */
    private static function mergeDeletedParagraphs(\DOMXPath $xp): void
    {
        $paras = [];
        foreach ($xp->query('//w:p[w:pPr/w:rPr/w:del]') ?: [] as $node) {
            $paras[] = $node;
        }
        foreach ($paras as $paragraph) {
            if (!$paragraph instanceof \DOMElement || $paragraph->parentNode === null) {
                continue;
            }
            $next = $paragraph->nextSibling;
            while ($next !== null && !($next instanceof \DOMElement && $next->localName === 'p')) {
                $next = $next->nextSibling;
            }
            if (!$next instanceof \DOMElement) {
                continue;
            }
            foreach (iterator_to_array($paragraph->childNodes) as $child) {
                if ($child instanceof \DOMElement && $child->localName === 'pPr') {
                    continue;
                }
                $next->appendChild($child);
            }
            $paragraph->parentNode->removeChild($paragraph);
        }
    }

    private static function removeNodes(\DOMXPath $xp, string $query): void
    {
        $nodes = [];
        foreach ($xp->query($query) ?: [] as $node) {
            $nodes[] = $node;
        }
        foreach ($nodes as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    private static function unwrapNodes(\DOMXPath $xp, string $query): void
    {
        $nodes = [];
        foreach ($xp->query($query) ?: [] as $node) {
            $nodes[] = $node;
        }
        usort($nodes, static function (\DOMNode $a, \DOMNode $b): int {
            return self::depth($b) <=> self::depth($a);
        });
        foreach ($nodes as $node) {
            $parent = $node->parentNode;
            if ($parent === null) {
                continue;
            }
            while ($node->firstChild !== null) {
                $parent->insertBefore($node->firstChild, $node);
            }
            $parent->removeChild($node);
        }
    }

    private static function depth(\DOMNode $node): int
    {
        $depth = 0;
        while ($node->parentNode !== null) {
            $node = $node->parentNode;
            ++$depth;
        }

        return $depth;
    }

    private static function stripDepartmentHighlight(\DOMXPath $xp): void
    {
        $fills = explode('|', self::HIGHLIGHT_FILLS);
        $nodes = [];
        foreach ($xp->query('//w:shd') ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $fill = strtoupper($node->getAttribute('w:fill'));
            if (in_array($fill, $fills, true)) {
                $nodes[] = $node;
            }
        }
        foreach ($nodes as $node) {
            $node->parentNode?->removeChild($node);
        }
        // Word показывает w:highlight w:val="none" чёрной заливкой. Снятие цвета — убрать тег.
        self::stripNoneHighlight($xp);
    }

    private static function stripNoneHighlight(\DOMXPath $xp): bool
    {
        $nodes = [];
        foreach ($xp->query('//w:highlight') ?: [] as $node) {
            if ($node instanceof \DOMElement && strcasecmp($node->getAttribute('w:val'), 'none') === 0) {
                $nodes[] = $node;
            }
        }
        foreach ($nodes as $node) {
            $node->parentNode?->removeChild($node);
        }

        return $nodes !== [];
    }

    private static function replaceDocumentXml(string $docx, string $xml): ?string
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
            $zip->deleteName('word/document.xml');
            $zip->addFromString('word/document.xml', $xml);
            $zip->close();
            $bytes = file_get_contents($path);

            return $bytes === false ? null : $bytes;
        } finally {
            @unlink($path);
        }
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
