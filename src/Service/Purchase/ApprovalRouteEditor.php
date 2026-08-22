<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseRouteTemplate;
use App\Entity\Purchase\PurchaseRouteTemplateStage;
use App\Entity\Purchase\PurchaseRouteTemplateTask;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseFileType;
use App\Enum\Purchase\PurchaseRequestKind;
use App\Enum\Purchase\PurchaseRoleCode;
use App\Enum\Purchase\PurchaseStagePurpose;
use App\Enum\Purchase\PurchaseTaskAssignment;
use App\Repository\Purchase\PurchaseRouteDefaultRepository;
use App\Repository\Purchase\PurchaseRouteTemplateRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;

/**
 * Правка заготовок маршрутов из админки.
 *
 * Заготовка приходит деревом и заменяется целиком: частичных правок нет, потому
 * что порядок этапов и состав задач — свойства всего маршрута, а не отдельной
 * строки, и «подвинь один этап» неизбежно разъезжается с тем, что видит админ.
 *
 * Порядок этапов задаётся их порядком в присланном списке, а параллельность —
 * тем, что задачи лежат в одном этапе. Номера позиций фронт больше не считает:
 * прежде он присылал их сам, а бэк восстанавливал группы, потому что
 * параллельность выражалась совпадением чисел.
 *
 * Здесь же живут правила, без которых редактор молча ломает модуль. Каждое
 * закрывает конкретную поломку, а не стоит «на всякий случай».
 */
final class ApprovalRouteEditor
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PurchaseRouteTemplateRepository $templates,
        private readonly PurchaseRouteDefaultRepository $defaults,
    ) {
    }

    /**
     * Создать заготовку.
     *
     * @param array<string, mixed> $payload
     * @throws PurchaseRouteException
     */
    public function create(array $payload, User $actor): PurchaseRouteTemplate
    {
        $code = $this->parseCode($payload['code'] ?? null);
        if ($this->templates->findByCode($code) !== null) {
            throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_CODE_TAKEN);
        }

        $template = new PurchaseRouteTemplate();
        $this->em->persist($template);
        $template->setCode($code)
            // Выключена, как и копия: маршрут, появившийся в списке выбора
            // готовым к работе, — это регламент, который никто не просматривал.
            // Клиент вправе прислать isActive и включить сразу.
            ->setActive(false);

        return $this->fill($template, $payload, $actor);
    }

    /**
     * Заменить заготовку присланным деревом.
     *
     * @param array<string, mixed> $payload
     * @throws PurchaseRouteException
     */
    public function update(PurchaseRouteTemplate $template, array $payload, User $actor): PurchaseRouteTemplate
    {
        $this->assertNobodyGotThereFirst($template, $payload['version'] ?? null);

        if (isset($payload['code'])) {
            $code = $this->parseCode($payload['code']);
            $taken = $this->templates->findByCode($code);
            if ($taken !== null && $taken->getId() !== $template->getId()) {
                throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_CODE_TAKEN);
            }
            $template->setCode($code);
        }

        return $this->fill($template, $payload, $actor);
    }

    /**
     * Копия заготовки со всеми этапами — так добавляют маршрут-вариант.
     *
     * Наборы маршрутов в большой компании отличаются на один-два этапа, и
     * собирать похожий с нуля значит переписывать десяток строк ради одной.
     *
     * @throws PurchaseRouteException
     */
    public function clone(PurchaseRouteTemplate $source, string $code, string $name, User $actor): PurchaseRouteTemplate
    {
        $code = $this->parseCode($code);
        if ($this->templates->findByCode($code) !== null) {
            throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_CODE_TAKEN);
        }

        $copy = new PurchaseRouteTemplate();
        $this->em->persist($copy);
        $copy->setCode($code)
            ->setName(trim($name) !== '' ? trim($name) : $source->getName() . ' (копия)')
            ->setDescription($source->getDescription())
            ->setAllowedKinds($source->getAllowedKinds())
            ->setSortOrder($source->getSortOrder())
            // Копия выключена: маршрут, появившийся в списке выбора готовым к
            // работе, — это регламент, который никто не просматривал.
            ->setActive(false)
            ->setUpdatedBy($actor)
            ->setUpdatedAt(new \DateTimeImmutable());

        foreach ($source->getStages() as $sourceStage) {
            $stage = (new PurchaseRouteTemplateStage())
                ->setPosition($sourceStage->getPosition())
                ->setTitle($sourceStage->getTitle())
                ->setPurpose($sourceStage->getPurpose())
                ->setAllowsReject($sourceStage->allowsReject());
            $copy->addStage($stage);
            $this->em->persist($stage);

            foreach ($sourceStage->getTasks() as $sourceTask) {
                $task = (new PurchaseRouteTemplateTask())
                    ->setPosition($sourceTask->getPosition())
                    ->setAssignmentType($sourceTask->getAssignmentType())
                    ->setRoleCode($sourceTask->getRoleCode())
                    ->setCandidateRoleCode($sourceTask->getCandidateRoleCode())
                    ->setTitle($sourceTask->getTitle())
                    ->setRequiresFileType($sourceTask->getRequiresFileType());
                $stage->addTask($task);
                $this->em->persist($task);
            }
        }

        $this->save();

        return $copy;
    }

    /**
     * Включить или выключить заготовку.
     *
     * Выключить назначенную дефолтом нельзя: вид заявки остался бы без маршрута,
     * и подача перестала бы работать — без внятной причины для того, кто нажал
     * «выключить». Сначала дефолт переназначают.
     *
     * @throws PurchaseRouteException
     */
    public function setActive(PurchaseRouteTemplate $template, bool $active, User $actor): PurchaseRouteTemplate
    {
        if (!$active && $this->defaults->kindsDefaultingTo($template) !== []) {
            throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_IS_DEFAULT);
        }

        $template->setActive($active)
            ->setUpdatedBy($actor)
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->save();

        return $template;
    }

    /**
     * Назначить заготовку маршрутом по умолчанию для вида заявки.
     *
     * Касается только будущих подач: у заявки в пути свой снимок.
     *
     * @throws PurchaseRouteException
     */
    public function setDefault(PurchaseRequestKind $kind, PurchaseRouteTemplate $template, User $actor): void
    {
        if (!$template->isActive() || $template->isEmpty() || !$template->allowsKind($kind)) {
            throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_NOT_CONFIGURED);
        }

        $this->defaults->set($kind, $template)
            ->setUpdatedBy($actor)
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->save();
    }

    /**
     * Правка идёт по версии, которую админ видел, когда открывал форму.
     *
     * Одного #[ORM\Version] для этого не хватает: он ловит только столкновение
     * записей внутри пересекающихся транзакций, а два админа — это два запроса,
     * каждый из которых читает строку заново и видит уже чужую версию как свою.
     * Поэтому версию сообщает клиент, и сверяет её em->lock().
     *
     * @throws PurchaseRouteException
     */
    private function assertNobodyGotThereFirst(PurchaseRouteTemplate $template, mixed $expected): void
    {
        if (!is_numeric($expected)) {
            throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_VERSION_REQUIRED);
        }

        try {
            $this->em->lock($template, LockMode::OPTIMISTIC, (int) $expected);
        } catch (OptimisticLockException) {
            throw new PurchaseRouteException(SpaApiError::PURCHASE_CONCURRENT_UPDATE);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @throws PurchaseRouteException
     */
    private function fill(PurchaseRouteTemplate $template, array $payload, User $actor): PurchaseRouteTemplate
    {
        $stages = $this->parseStages($payload['stages'] ?? []);
        $this->assertRulesHold($stages);

        $template->setName($this->parseName($payload['name'] ?? $template->getName()))
            ->setDescription(is_string($payload['description'] ?? null) ? $payload['description'] : null)
            ->setAllowedKinds($this->parseKinds($payload['allowedKinds'] ?? null))
            ->setSortOrder((int) ($payload['sortOrder'] ?? $template->getSortOrder()))
            ->setUpdatedBy($actor)
            ->setUpdatedAt(new \DateTimeImmutable());

        if (isset($payload['isActive'])) {
            $template->setActive((bool) $payload['isActive']);
        }

        $this->replaceStages($template, $stages);

        return $template;
    }

    /**
     * Заменить дерево этапов целиком: старое снести, присланное записать.
     *
     * Целиком, а не по частям: порядок этапов — свойство всего маршрута, и
     * «подвинь один этап» неизбежно разъезжается с тем, что видит админ.
     *
     * Две записи, и порядок между ними обязателен. У этапа уникальны (шаблон,
     * позиция), а Doctrine всегда пишет INSERT раньше DELETE: новый этап на
     * позиции 1 упирался в старый, ещё не удалённый, и правка любого непустого
     * маршрута падала на уникальном ключе. Транзакция нужна, чтобы отказ на
     * второй записи не оставил регламент вообще без этапов.
     *
     * @param list<PurchaseRouteTemplateStage> $stages
     * @throws PurchaseRouteException
     */
    private function replaceStages(PurchaseRouteTemplate $template, array $stages): void
    {
        try {
            $this->em->wrapInTransaction(function () use ($template, $stages): void {
                foreach ($template->getStages()->toArray() as $old) {
                    $template->removeStage($old);
                    $this->em->remove($old);
                }
                $this->em->flush();

                foreach ($stages as $stage) {
                    $template->addStage($stage);
                    $this->em->persist($stage);
                    foreach ($stage->getTasks() as $task) {
                        $this->em->persist($task);
                    }
                }
                $this->em->flush();
            });
        } catch (OptimisticLockException) {
            throw new PurchaseRouteException(SpaApiError::PURCHASE_CONCURRENT_UPDATE);
        }
    }

    /**
     * Записать правку регламента, поймав столкновение с параллельной.
     *
     * @throws PurchaseRouteException двое правили заготовку одновременно
     */
    private function save(): void
    {
        try {
            $this->em->flush();
        } catch (OptimisticLockException) {
            throw new PurchaseRouteException(SpaApiError::PURCHASE_CONCURRENT_UPDATE);
        }
    }

    /**
     * Правила заготовки:
     *
     *   маршрут без этапов не сохраняем — по нему заявка не пойдёт никуда, а
     *   «убрать маршрут» это не правка регламента, а его отмена;
     *
     *   разбор в маршруте не больше одного — с него правят состав, выбирают
     *   согласантов и меняют маршрут, и два разбора означали бы два места с этими
     *   правами. У быстрого маршрута разбора нет вовсе, поэтому «не больше», а не
     *   «ровно один»;
     *
     *   динамический этап только позже разбора — иначе выбирать на него людей
     *   некому, и заявка встала бы, ожидая тех, кого никто не назначит;
     *
     *   исполнение только после согласования — маршрут, где оплата стоит перед
     *   подписью финдиректора, это ошибка настройки, а не редкий регламент;
     *
     *   задачу заявителю только на исполнении — на согласовании это значило бы,
     *   что автор согласует собственную заявку.
     *
     * Этап ресёрча не требуется и по количеству не ограничен: маршрут из одних
     * подписей законен. Модуль это переживает — исполнителя у заявки не будет,
     * поставщика и цены править негде, а кнопка возврата документов в закупки не
     * появится, потому что возвращать некуда.
     *
     * @param list<PurchaseRouteTemplateStage> $stages
     * @throws PurchaseRouteException
     */
    private function assertRulesHold(array $stages): void
    {
        if ($stages === []) {
            throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_EMPTY);
        }

        $triagePosition = null;
        $triages = 0;
        $firstExecution = null;
        $lastApproval = null;

        foreach ($stages as $stage) {
            $purpose = $stage->getPurpose();

            if ($purpose === PurchaseStagePurpose::TRIAGE) {
                ++$triages;
                $triagePosition = $stage->getPosition();
            }
            if ($purpose->isExecution()) {
                $firstExecution ??= $stage->getPosition();
            } else {
                $lastApproval = $stage->getPosition();
            }
            if ($stage->hasAuthorTask() && !$purpose->isExecution()) {
                throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_TASK_INVALID);
            }
        }

        if ($triages > 1) {
            throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_STAGE_ORDER_INVALID);
        }
        if ($firstExecution !== null && $lastApproval !== null && $firstExecution < $lastApproval) {
            throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_STAGE_ORDER_INVALID);
        }

        foreach ($stages as $stage) {
            if (!$stage->isDynamic()) {
                continue;
            }
            if ($triagePosition === null || $triagePosition >= $stage->getPosition()) {
                throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_STAGE_ORDER_INVALID);
            }
        }
    }

    /**
     * Разобрать дерево этапов. Позиции нумеруются по порядку присланного списка:
     * фронт присылает структуру, номера считает бэк.
     *
     * @param mixed $rows
     * @return list<PurchaseRouteTemplateStage>
     * @throws PurchaseRouteException
     */
    private function parseStages(mixed $rows): array
    {
        if (!is_array($rows)) {
            throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_STAGE_INVALID);
        }

        $stages = [];
        $position = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_STAGE_INVALID);
            }

            $purpose = PurchaseStagePurpose::tryFrom(
                (string) ($row['purpose'] ?? PurchaseStagePurpose::SIGN_OFF->value),
            );
            if ($purpose === null) {
                throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_STAGE_INVALID);
            }

            $stage = (new PurchaseRouteTemplateStage())
                ->setPosition(++$position)
                ->setTitle($this->parseTitle($row['title'] ?? null))
                ->setPurpose($purpose)
                // Отказ на исполнении не имеет смысла: деньги уже потрачены.
                ->setAllowsReject((bool) ($row['allowsReject'] ?? !$purpose->isExecution()));

            foreach ($this->parseTasks($row['tasks'] ?? []) as $task) {
                $stage->addTask($task);
            }

            // Пустой этап — дырка в маршруте: заявка встанет на нём и никого не
            // будет ждать. Динамический этап пуст только в снимке заявки, до
            // выбора людей; в заготовке в нём стоит задача-место.
            if ($stage->getTasks()->isEmpty()) {
                throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_STAGE_INVALID);
            }
            // Динамический этап заполняется целиком выбором разбирающего, поэтому
            // ролевых задач рядом быть не может: они бы остались ждать вместе с
            // теми, кого ещё не выбрали, и этап нельзя было бы ни закрыть, ни
            // показать как «ожидает назначения».
            if ($stage->isDynamic() && $stage->getTasks()->count() > 1) {
                throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_STAGE_INVALID);
            }

            $stages[] = $stage;
        }

        return $stages;
    }

    /**
     * @param mixed $rows
     * @return list<PurchaseRouteTemplateTask>
     * @throws PurchaseRouteException
     */
    private function parseTasks(mixed $rows): array
    {
        if (!is_array($rows)) {
            throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_TASK_INVALID);
        }

        $tasks = [];
        $position = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_TASK_INVALID);
            }

            $type = PurchaseTaskAssignment::tryFrom(
                (string) ($row['assignmentType'] ?? PurchaseTaskAssignment::ROLE->value),
            );
            // USER в заготовке недопустим: сотрудников поимённо в маршрут не
            // вписывают, они появляются только в снимке заявки.
            if ($type === null || !$type->isAllowedInTemplate()) {
                throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_TASK_INVALID);
            }

            $task = (new PurchaseRouteTemplateTask())
                ->setPosition(++$position)
                ->setAssignmentType($type)
                ->setTitle($this->parseTitle($row['title'] ?? null))
                ->setRequiresFileType($this->parseFileType($row['requiresFileType'] ?? null));

            // Ролью зама задача не адресуется: её закрыл бы любой зам, а замов
            // подбирают под заявку поимённо — для этого этап делают динамическим,
            // и там та же роль законна как пул.
            match ($type) {
                PurchaseTaskAssignment::ROLE => $task->setRoleCode(
                    $this->parseRole($row['roleCode'] ?? null, PurchaseRoleCode::taskRoles()),
                ),
                PurchaseTaskAssignment::DYNAMIC_USERS => $task->setCandidateRoleCode(
                    $this->parseRole($row['candidateRoleCode'] ?? null, PurchaseRoleCode::cases()),
                ),
                PurchaseTaskAssignment::AUTHOR, PurchaseTaskAssignment::USER => $task,
            };

            $tasks[] = $task;
        }

        return $tasks;
    }

    /**
     * @param list<PurchaseRoleCode> $allowed
     * @throws PurchaseRouteException
     */
    private function parseRole(mixed $value, array $allowed): PurchaseRoleCode
    {
        $code = PurchaseRoleCode::tryFrom((string) ($value ?? ''));
        if ($code === null || !in_array($code, $allowed, true)) {
            throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_TASK_INVALID);
        }

        return $code;
    }

    /**
     * @return list<PurchaseRequestKind>
     * @throws PurchaseRouteException
     */
    private function parseKinds(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_META_INVALID);
        }

        $kinds = [];
        foreach ($value as $raw) {
            $kind = PurchaseRequestKind::tryFrom((string) $raw);
            if ($kind === null) {
                throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_META_INVALID);
            }
            $kinds[] = $kind;
        }

        return $kinds;
    }

    /** @throws PurchaseRouteException */
    private function parseCode(mixed $value): string
    {
        $code = is_string($value) ? strtoupper(trim($value)) : '';
        if ($code === '' || mb_strlen($code) > 50 || preg_match('/^[A-Z0-9_]+$/', $code) !== 1) {
            throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_META_INVALID);
        }

        return $code;
    }

    /** @throws PurchaseRouteException */
    private function parseName(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > 255) {
            throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_META_INVALID);
        }

        return trim($value);
    }

    /**
     * Пустой заголовок — это его отсутствие: подставится адресат задачи.
     *
     * @throws PurchaseRouteException
     */
    private function parseTitle(mixed $title): ?string
    {
        if (!is_string($title)) {
            return null;
        }
        if (mb_strlen(trim($title)) > 255) {
            throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_STAGE_INVALID);
        }

        return trim($title) !== '' ? trim($title) : null;
    }

    /** @throws PurchaseRouteException */
    private function parseFileType(mixed $value): ?PurchaseFileType
    {
        if ($value === null || $value === '') {
            return null;
        }

        $type = PurchaseFileType::tryFrom((string) $value);
        if ($type === null) {
            throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_TASK_INVALID);
        }

        return $type;
    }
}
