<?php

declare(strict_types=1);

namespace App\Service\Inventory;

/**
 * Дерево категорий в памяти.
 *
 * Категорий десятки, поэтому дерево целиком приходит одним плоским запросом
 * (ItemCategoryRepository::loadTree), а поддеревья, пути и проверки считаются здесь.
 * Рекурсии в SQL нет: тяжёлые запросы списка получают готовый список id для IN.
 *
 * Циклов в данных быть не должно — их не пускает CategoryController. Обходы всё равно
 * помнят посещённые узлы: цикл, заведённый сырым SQL, не должен вешать запрос.
 */
final class CategoryTree
{
    /** Сколько уровней допускается, считая корень: «Оргтехника › ПК › Мини ПК» — это 3. */
    public const MAX_DEPTH = 5;

    /** @var array<int, int[]>|null */
    private ?array $children = null;

    /**
     * @param array<int, array{parentId: int|null, name: string, isActive: bool}> $nodes ключ — id категории
     */
    public function __construct(private readonly array $nodes)
    {
    }

    public function parentId(int $id): ?int
    {
        return $this->nodes[$id]['parentId'] ?? null;
    }

    public function name(int $id): string
    {
        return $this->nodes[$id]['name'] ?? '';
    }

    /** Флаг самой категории, без учёта предков. */
    public function isOwnActive(int $id): bool
    {
        return $this->nodes[$id]['isActive'] ?? false;
    }

    /**
     * Узел и все его потомки. Неизвестный id даёт пустой список.
     *
     * @return int[]
     */
    public function subtreeIds(int $id): array
    {
        $children = $this->children();
        $ids = [];
        $stack = [$id];
        while ($stack !== []) {
            $current = array_pop($stack);
            if (!isset($this->nodes[$current]) || isset($ids[$current])) {
                continue;
            }

            $ids[$current] = $current;
            array_push($stack, ...($children[$current] ?? []));
        }

        return array_values($ids);
    }

    /**
     * Цепочка от корня до узла включительно.
     *
     * @return int[]
     */
    public function ancestorIds(int $id): array
    {
        $chain = [];
        for ($current = $id; $current !== null && isset($this->nodes[$current]); $current = $this->nodes[$current]['parentId']) {
            if (in_array($current, $chain, true)) {
                break;
            }

            array_unshift($chain, $current);
        }

        return $chain;
    }

    /** Уровень узла: корень — 1. */
    public function depth(int $id): int
    {
        return count($this->ancestorIds($id));
    }

    /** Сколько уровней занимает поддерево узла: лист — 1. */
    public function height(int $id): int
    {
        $depth = $this->depth($id);

        return max($depth, ...array_map(fn (int $node): int => $this->depth($node), $this->subtreeIds($id))) - $depth + 1;
    }

    /**
     * Активна ли категория с учётом предков: отключённая «Оргтехника» убирает
     * из форм и «ПК», и «Мини ПК», иначе пришлось бы отключать всю ветку руками.
     */
    public function isActive(int $id): bool
    {
        $chain = $this->ancestorIds($id);
        if ($chain === []) {
            return false;
        }

        foreach ($chain as $node) {
            if (!$this->nodes[$node]['isActive']) {
                return false;
            }
        }

        return true;
    }

    /** «Оргтехника / ПК / Мини ПК» — тот же разделитель, что у пути организации. */
    public function path(int $id): string
    {
        return implode(' / ', array_map(fn (int $node): string => $this->nodes[$node]['name'], $this->ancestorIds($id)));
    }

    /**
     * Все id в порядке обхода: родитель, затем его дети по алфавиту. В таком порядке
     * справочник и показывается — ветка целиком, а не вперемешку.
     *
     * @return int[]
     */
    public function orderedIds(): array
    {
        $children = $this->children();
        $roots = array_keys(array_filter(
            $this->nodes,
            fn (array $node): bool => $node['parentId'] === null || !isset($this->nodes[$node['parentId']]),
        ));

        $ordered = [];
        $stack = array_reverse($this->sortByName($roots));
        while ($stack !== []) {
            $current = array_pop($stack);
            if (isset($ordered[$current])) {
                continue;
            }

            $ordered[$current] = $current;
            array_push($stack, ...array_reverse($this->sortByName($children[$current] ?? [])));
        }

        return array_values($ordered);
    }

    /**
     * @return array<int, int[]>
     */
    private function children(): array
    {
        if ($this->children === null) {
            $this->children = [];
            foreach ($this->nodes as $id => $node) {
                if ($node['parentId'] !== null) {
                    $this->children[$node['parentId']][] = $id;
                }
            }
        }

        return $this->children;
    }

    /**
     * @param int[] $ids
     *
     * @return int[]
     */
    private function sortByName(array $ids): array
    {
        usort($ids, fn (int $a, int $b): int => mb_strtolower($this->nodes[$a]['name']) <=> mb_strtolower($this->nodes[$b]['name']));

        return $ids;
    }
}
