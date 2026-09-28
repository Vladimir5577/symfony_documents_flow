<?php

declare(strict_types=1);

namespace App\Tests\Service\Purchase;

use App\Entity\Purchase\PurchaseApprovalStage;
use App\Entity\Purchase\PurchaseApprovalTask;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestFile;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseContractReview;
use App\Enum\Purchase\PurchaseFileType;
use App\Enum\Purchase\PurchaseHistoryAction;
use App\Enum\Purchase\PurchaseRoleCode;
use App\Enum\Purchase\PurchaseStagePurpose;
use App\Enum\Purchase\PurchaseStageStatus;
use App\Enum\Purchase\PurchaseStatus;
use App\Enum\Purchase\PurchaseTaskAssignment;
use App\Enum\Purchase\PurchaseTaskDecision;
use App\Repository\Purchase\PurchaseApproverRoleRepository;
use App\Service\Notification\NotificationPublisher;
use App\Service\Purchase\PurchaseAccess;
use App\Service\Purchase\PurchaseFileCoEditing;
use App\Service\Purchase\PurchaseFileStorageService;
use App\Service\Purchase\PurchaseHistoryLogger;
use App\Service\Purchase\PurchaseNotificationPublisher;
use App\Service\Purchase\PurchaseRoster;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Совместная правка договора: общий ключ, режим рецензирования с отделом,
 * запись итога и уведомление тех, кто договор уже согласовал.
 *
 * Сценарий — параллельный этап «закупки + бухгалтерия + юристы» после ресёрча:
 * на нём все трое правят один документ одновременно.
 */
final class PurchaseFileCoEditingTest extends TestCase
{
    use PurchaseNotificationAssertions;

    private const PURCHASER = 102;
    private const ACCOUNTANT = 104;
    private const LAWYER = 105;

    /** @var list<CommandInterface> */
    private array $s3Calls = [];

    /** Что лежит в хранилище до записи — docx, с которым сравнивается новый текст. */
    private string $stored = '';

    public function testAllParticipantsShareOneKeyUntilTheSessionIsCommitted(): void
    {
        $file = $this->contract();
        $coEditing = $this->coEditing();

        $key = $coEditing->documentKey($file);
        self::assertSame($key, $coEditing->documentKey($file), 'ключ один на всех, кто открыл эту редакцию');
        self::assertStringStartsWith('pf77r1-', $key);

        $file->nextEditorRevision();
        self::assertNotSame($key, $coEditing->documentKey($file), 'после закрытой сессии — новый ключ');
        self::assertNotSame(
            $coEditing->documentKey($file),
            $this->coEditing('другой секрет')->documentKey($file),
            'хвост ключа — подпись: по номеру файла ключ не угадать',
        );
    }

    public function testParticipantEditsInReviewModeWithDepartment(): void
    {
        [$purchase, , $legalTask] = $this->negotiation();
        $file = $this->contract($purchase);

        $config = $this->coEditing()->editorConfig($file, $this->user(self::LAWYER), $legalTask, 'http://content', 'http://callback');

        self::assertSame('edit', $config['editorConfig']['mode']);
        self::assertSame('http://callback', $config['editorConfig']['callbackUrl']);
        // Только рецензирование: без него правка осталась бы без автора и цвета.
        self::assertSame(['edit' => false, 'review' => true, 'comment' => true], $config['document']['permissions']);
        self::assertSame(['mode' => 'fast', 'change' => false], $config['editorConfig']['coEditing']);
        self::assertTrue($config['editorConfig']['customization']['review']['trackChanges']);
        self::assertSame('Юристы', $config['editorConfig']['user']['name']);
        self::assertSame('LEGAL', $config['editorConfig']['user']['id']);
        self::assertArrayNotHasKey('group', $config['editorConfig']['user']);
        self::assertSame(
            [PurchaseFileCoEditing::HIGHLIGHT_PLUGIN => ['department' => 'LEGAL']],
            $config['editorConfig']['plugins']['options'],
        );
        self::assertSame($this->coEditing()->documentKey($file), $config['document']['key']);
    }

    public function testDeputyAndDirectorEditWithTheirColours(): void
    {
        [$purchase] = $this->negotiation();
        $file = $this->contract($purchase);

        // Замов выбирает разбирающий: задача адресована человеку, роль — у пула этапа.
        $deputyTask = (new PurchaseApprovalTask())
            ->setPosition(1)
            ->setAssignmentType(PurchaseTaskAssignment::USER)
            ->setAssigneeUser($this->user(140));
        (new PurchaseApprovalStage())
            ->setPurpose(PurchaseStagePurpose::SIGN_OFF)
            ->setCandidateRoleCode(PurchaseRoleCode::PROFILE_DEPUTY)
            ->addTask($deputyTask);
        $deputy = $this->coEditing()->editorConfig($file, $this->user(140), $deputyTask, 'http://content', 'http://callback');

        self::assertSame('Профильный зам', $deputy['editorConfig']['user']['name']);
        self::assertSame('PROFILE_DEPUTY', $deputy['editorConfig']['user']['id']);
        self::assertSame(
            [PurchaseFileCoEditing::HIGHLIGHT_PLUGIN => ['department' => 'PROFILE_DEPUTY']],
            $deputy['editorConfig']['plugins']['options'],
        );

        $director = $this->coEditing()->editorConfig($file, $this->user(101), $this->roleTask(PurchaseRoleCode::DIRECTOR), 'http://content', 'http://callback');

        self::assertSame('Директор', $director['editorConfig']['user']['name']);
        self::assertSame('DIRECTOR', $director['editorConfig']['user']['id']);
        self::assertSame(
            [PurchaseFileCoEditing::HIGHLIGHT_PLUGIN => ['department' => 'DIRECTOR']],
            $director['editorConfig']['plugins']['options'],
        );
    }

    public function testDeputyWithAcceptMarkCanResolveOthersChanges(): void
    {
        [$purchase] = $this->negotiation();
        $file = $this->contract($purchase);
        $deputyTask = (new PurchaseApprovalTask())
            ->setAssignmentType(PurchaseTaskAssignment::USER)
            ->setAssigneeUser($this->user(140))
            ->setContractReview(PurchaseContractReview::ACCEPT);
        (new PurchaseApprovalStage())
            ->setCandidateRoleCode(PurchaseRoleCode::PROFILE_DEPUTY)
            ->addTask($deputyTask);

        $config = $this->coEditing()->editorConfig($file, $this->user(140), $deputyTask, 'http://content', 'http://callback');

        self::assertSame(
            ['edit' => true, 'review' => true, 'comment' => true],
            $config['document']['permissions'],
        );
        self::assertFalse($config['editorConfig']['customization']['review']['trackChanges']);
        self::assertArrayNotHasKey('plugins', $config['editorConfig']);
    }

    public function testViewerJoinsTheSameSessionWithoutReviewOrHighlighting(): void
    {
        [$purchase] = $this->negotiation();
        $file = $this->contract($purchase);

        $config = $this->coEditing()->editorConfig($file, $this->user(1), null, 'http://content', null);

        self::assertSame('view', $config['editorConfig']['mode']);
        self::assertArrayNotHasKey('callbackUrl', $config['editorConfig'], 'зрителю писать нечем');
        self::assertSame(['edit' => false, 'review' => false, 'comment' => false], $config['document']['permissions']);
        self::assertArrayNotHasKey('group', $config['editorConfig']['user']);
        self::assertArrayNotHasKey('plugins', $config['editorConfig']);
        self::assertSame($this->coEditing()->documentKey($file), $config['document']['key'], 'зритель в той же сессии');
    }

    public function testClosedSessionIsStoredAndApproversOfTheContractAreNotified(): void
    {
        [$purchase, $accountingTask] = $this->negotiation();
        $accountingTask->decide(PurchaseTaskDecision::APPROVED, $this->user(self::ACCOUNTANT));
        $file = $this->contract($purchase);
        $lawyer = $this->user(self::LAWYER);
        $this->stored = self::docx('Оплата авансом 100 %.');

        $this->coEditing()->commit($purchase, $file, self::docx('Оплата в течение 7 дней после УПД.'), true, [$lawyer]);

        self::assertSame(['GetObject', 'PutObject'], array_map(static fn (CommandInterface $c): string => $c->getName(), $this->s3Calls));
        self::assertSame('500/contract.docx', $this->s3Calls[1]['Key']);
        self::assertSame(2, $file->getEditorRevision(), 'следующая сессия — под новым ключом');
        self::assertSame(
            [PurchaseHistoryAction::FILE_EDITED, PurchaseHistoryAction::CONTRACT_CHANGED],
            $this->actions($purchase),
        );

        // Бухгалтерия согласовала раньше — ей. Закупки ещё на этапе со своей
        // задачей, они договариваются, а не согласовали: им не надо, хотя ресёрч
        // закрыли они.
        self::assertCount(1, $this->purchaseNotifications);
        [$message, $event] = $this->purchaseNotifications[0];
        self::assertSame('contract_changed', $event);
        self::assertSame([self::ACCOUNTANT], $message->recipients);
        self::assertSame('/purchases/500', $message->link);
    }

    public function testSessionClosedWithoutNewTextDoesNotNotifyApprovers(): void
    {
        [$purchase, $accountingTask] = $this->negotiation();
        $accountingTask->decide(PurchaseTaskDecision::APPROVED, $this->user(self::ACCOUNTANT));
        $file = $this->contract($purchase);
        // Юрист нажал «Сохранить», бухгалтерия согласовала, потом все вышли:
        // status 2 приносит тот же текст, что уже лежит в хранилище.
        $this->stored = self::docx('Текст, который бухгалтерия и согласовала.');

        $this->coEditing()->commit($purchase, $file, self::docx('Текст, который бухгалтерия и согласовала.'), true, [$this->user(self::LAWYER)]);

        self::assertSame(2, $file->getEditorRevision());
        self::assertSame([PurchaseHistoryAction::FILE_EDITED], $this->actions($purchase));
        self::assertSame([], $this->purchaseNotifications, 'после согласования текст не менялся');
    }

    public function testAcceptedContractDropsDepartmentHighlight(): void
    {
        [$purchase] = $this->negotiation();
        $file = $this->contract($purchase);
        $this->stored = self::docx('было');
        $accepted = self::docxXml(
            '<w:document><w:body><w:p><w:r><w:rPr><w:shd w:val="clear" w:fill="E0D2F2"/></w:rPr><w:t>текст</w:t></w:r></w:p></w:body></w:document>',
        );

        $this->coEditing()->commit($purchase, $file, $accepted, false, [$this->user(self::LAWYER)]);

        self::assertStringNotContainsString('E0D2F2', self::documentXmlOf((string) $this->s3Calls[1]['Body']));
    }

    public function testOpenReviewKeepsDepartmentHighlight(): void
    {
        [$purchase] = $this->negotiation();
        $file = $this->contract($purchase);
        $this->stored = self::docx('было');
        $pending = self::docxXml(
            '<w:document><w:body><w:p><w:ins w:id="1"><w:r><w:rPr><w:shd w:val="clear" w:fill="C9E7CA"/></w:rPr><w:t>текст</w:t></w:r></w:ins></w:p></w:body></w:document>',
        );

        $this->coEditing()->commit($purchase, $file, $pending, false, [$this->user(self::LAWYER)]);

        self::assertStringContainsString('C9E7CA', self::documentXmlOf((string) $this->s3Calls[1]['Body']));
    }

    public function testAcceptedCopyShowsFinalTextWithoutDepartmentHighlight(): void
    {
        $source = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>
    <w:p>
      <w:r><w:t xml:space="preserve">Цена </w:t></w:r>
      <w:del w:id="1" w:author="Юристы" w:date="2026-09-28T10:00:00Z"><w:r><w:delText>100</w:delText></w:r></w:del>
      <w:ins w:id="2" w:author="Юристы" w:date="2026-09-28T10:00:00Z"><w:r><w:rPr><w:shd w:val="clear" w:color="auto" w:fill="E0D2F2"/></w:rPr><w:t>120</w:t></w:r></w:ins>
    </w:p>
    <w:p>
      <w:pPr><w:rPr><w:del w:id="3" w:author="Юристы" w:date="2026-09-28T10:00:00Z"/></w:rPr></w:pPr>
      <w:del w:id="4" w:author="Юристы" w:date="2026-09-28T10:00:00Z"><w:r><w:delText>старый пункт</w:delText></w:r></w:del>
    </w:p>
    <w:p><w:r><w:t>следующий</w:t></w:r></w:p>
  </w:body>
</w:document>
XML;

        $clean = self::documentXmlOf(PurchaseFileCoEditing::acceptedCopy(self::docxXml($source)));

        self::assertStringContainsString('Цена', $clean);
        self::assertStringContainsString('>120<', $clean);
        self::assertStringContainsString('следующий', $clean);
        self::assertStringNotContainsString('100', $clean);
        self::assertStringNotContainsString('старый пункт', $clean);
        self::assertStringNotContainsString('E0D2F2', $clean);
        self::assertStringNotContainsString('<w:ins', $clean);
        self::assertStringNotContainsString('<w:del', $clean);
    }

    public function testSaveInTheMiddleOfTheSessionKeepsTheKey(): void
    {
        [$purchase] = $this->negotiation();
        $file = $this->contract($purchase);
        $this->stored = self::docx('было');

        $this->coEditing()->commit($purchase, $file, self::docx('стало'), false, [$this->user(self::PURCHASER)]);

        self::assertSame(1, $file->getEditorRevision(), 'сессия продолжается — ключ прежний');
        self::assertSame([PurchaseHistoryAction::FILE_EDITED], $this->actions($purchase));
        self::assertSame([], $this->purchaseNotifications, 'никто ещё не согласовал');
    }

    public function testApproverWhoEditsHimselfIsNotNotified(): void
    {
        [$purchase, $accountingTask] = $this->negotiation();
        $accountant = $this->user(self::ACCOUNTANT);
        $accountingTask->decide(PurchaseTaskDecision::APPROVED, $accountant);
        $this->stored = self::docx('было');

        $this->coEditing()->commit($purchase, $this->contract($purchase), self::docx('стало'), true, [$accountant]);

        self::assertSame([], $this->purchaseNotifications);
        self::assertSame([PurchaseHistoryAction::FILE_EDITED], $this->actions($purchase));
    }

    public function testOtherDocumentsDoNotNotifyApprovers(): void
    {
        [$purchase, $accountingTask] = $this->negotiation();
        $accountingTask->decide(PurchaseTaskDecision::APPROVED, $this->user(self::ACCOUNTANT));
        $spec = $this->contract($purchase)->setType(PurchaseFileType::TECHNICAL_SPEC);

        $this->coEditing()->commit($purchase, $spec, self::docx('стало'), true, [$this->user(self::LAWYER)]);

        self::assertSame([], $this->purchaseNotifications, 'уведомление — про договор');
        self::assertSame(['PutObject'], array_map(static fn (CommandInterface $c): string => $c->getName(), $this->s3Calls));
    }

    // Обвязка

    private function coEditing(string $secret = 'test-secret'): PurchaseFileCoEditing
    {
        $em = $this->createStub(EntityManagerInterface::class);

        $mock = new MockHandler();
        for ($i = 0; $i < 5; ++$i) {
            $mock->append(function (CommandInterface $command): Result {
                $this->s3Calls[] = $command;

                return new Result($command->getName() === 'GetObject' ? ['Body' => Utils::streamFor($this->stored)] : []);
            });
        }
        $s3 = new S3Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => $mock,
        ]);

        return new PurchaseFileCoEditing(
            new PurchaseFileStorageService($s3, 'purchase'),
            new PurchaseHistoryLogger($em),
            new PurchaseNotificationPublisher(
                new NotificationPublisher($this->capturePurchaseBus()),
                $this->purchaseRoster(),
                $this->purchaseUsers(),
            ),
            $this->access(),
            $em,
            new NullLogger(),
            $secret,
        );
    }

    /** Права по ролям модуля: у каждого сотрудника своя. */
    private function access(): PurchaseAccess
    {
        $roles = [
            self::PURCHASER => [PurchaseRoleCode::PURCHASE_DEPARTMENT],
            self::ACCOUNTANT => [PurchaseRoleCode::ACCOUNTING],
            self::LAWYER => [PurchaseRoleCode::LEGAL],
        ];
        $repo = $this->createStub(PurchaseApproverRoleRepository::class);
        $repo->method('findRoleCodesForUser')->willReturnCallback(
            static fn (User $user): array => $roles[(int) $user->getId()] ?? [],
        );

        return new PurchaseAccess(new PurchaseRoster($repo));
    }

    /**
     * Заявка на этапе согласования договора: ресёрч закрыт закупками, дальше
     * параллельно закупки, бухгалтерия и юристы.
     *
     * @return array{0: PurchaseRequest, 1: PurchaseApprovalTask, 2: PurchaseApprovalTask}
     */
    private function negotiation(): array
    {
        $purchase = (new PurchaseRequest())
            ->setTitle('Серверы')
            ->setCreatedBy($this->user(1))
            ->setStatus(PurchaseStatus::ON_APPROVAL);
        self::setId($purchase, 500);

        $sourcingTask = $this->roleTask(PurchaseRoleCode::PURCHASE_DEPARTMENT);
        $sourcingTask->decide(PurchaseTaskDecision::APPROVED, $this->user(self::PURCHASER));
        $purchase->addStage((new PurchaseApprovalStage())
            ->setPosition(1)
            ->setPurpose(PurchaseStagePurpose::SOURCING)
            ->setStatus(PurchaseStageStatus::COMPLETED)
            ->addTask($sourcingTask));

        $accountingTask = $this->roleTask(PurchaseRoleCode::ACCOUNTING);
        $legalTask = $this->roleTask(PurchaseRoleCode::LEGAL);
        $purchase->addStage((new PurchaseApprovalStage())
            ->setPosition(2)
            ->setPurpose(PurchaseStagePurpose::SIGN_OFF)
            ->setTitle('Согласование договора')
            ->setStatus(PurchaseStageStatus::ACTIVE)
            ->addTask($this->roleTask(PurchaseRoleCode::PURCHASE_DEPARTMENT))
            ->addTask($accountingTask)
            ->addTask($legalTask));

        return [$purchase, $accountingTask, $legalTask];
    }

    private function roleTask(PurchaseRoleCode $code): PurchaseApprovalTask
    {
        return (new PurchaseApprovalTask())
            ->setPosition(1)
            ->setAssignmentType(PurchaseTaskAssignment::ROLE)
            ->setRoleCode($code);
    }

    private function contract(?PurchaseRequest $purchase = null): PurchaseRequestFile
    {
        $file = (new PurchaseRequestFile())
            ->setType(PurchaseFileType::CONTRACT)
            ->setOriginalName('Договор поставки.docx')
            ->setStorageKey('500/contract.docx');
        self::setId($file, 77);
        $purchase?->addFile($file);

        return $file;
    }

    /** Минимальный docx: сравнивается только word/document.xml. */
    private static function docx(string $text): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'docx');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<w:document><w:body><w:p><w:r><w:t>' . $text . '</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    private static function docxXml(string $xml): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'docx');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    private static function documentXmlOf(string $docx): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($path, $docx);
        $zip = new \ZipArchive();
        $zip->open($path);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($path);

        return $xml === false ? '' : $xml;
    }

    /** @return list<PurchaseHistoryAction|null> */
    private function actions(PurchaseRequest $purchase): array
    {
        return array_values(array_map(
            static fn ($entry): ?PurchaseHistoryAction => $entry->getAction(),
            $purchase->getHistory()->toArray(),
        ));
    }

    private function user(int $id): User
    {
        $user = new User();
        self::setId($user, $id);

        return $user;
    }

    private static function setId(object $entity, int $id): void
    {
        $class = new \ReflectionClass($entity);
        while (!$class->hasProperty('id')) {
            $parent = $class->getParentClass();
            self::assertNotFalse($parent);
            $class = $parent;
        }
        $class->getProperty('id')->setValue($entity, $id);
    }
}
