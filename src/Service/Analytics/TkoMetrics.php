<?php

declare(strict_types=1);

namespace App\Service\Analytics;

/**
 * Единый источник метрик аналитики ТКО для SSR и SPA.
 * Каждая метрика: key (колонка сущности), label/name (подпись), unit, type (num|text).
 */
final class TkoMetrics
{
    public const METRICS = [
        ['key' => 'garbage_trucks_volume',   'label' => 'Мусоровозы (м³)',               'name' => 'Мусоровозы',            'unit' => 'м³',  'type' => 'num'],
        ['key' => 'garbage_trucks_weight',   'label' => 'Вес ТКО мусоровозы (тонны)',    'name' => 'Вес ТКО мусоровозы',    'unit' => 'т',   'type' => 'num'],
        ['key' => 'containers_volume',       'label' => 'Контейнеры (м³)',               'name' => 'Контейнеры',            'unit' => 'м³',  'type' => 'num'],
        ['key' => 'scrap_trucks_volume',     'label' => 'Ломовозы (м³)',                 'name' => 'Ломовозы',              'unit' => 'м³',  'type' => 'num'],
        ['key' => 'containers_scrap_weight', 'label' => 'Вес ТКО конт., ломов (тонны)',  'name' => 'Вес ТКО конт., ломов',  'unit' => 'т',   'type' => 'num'],
        ['key' => 'vegetation_volume',       'label' => 'Растительные (м³)',             'name' => 'Растительные',          'unit' => 'м³',  'type' => 'num'],
        ['key' => 'construction_volume',     'label' => 'Строительные (тонны)',          'name' => 'Строительные',          'unit' => 'м³',  'type' => 'num'],
        ['key' => 'terminal_volume',         'label' => 'Терминал (рубли)',              'name' => 'Терминал',              'unit' => 'руб.','type' => 'num'],
        ['key' => 'machinery_work',          'label' => 'Работа техники (часы)',         'name' => 'Работа техники',        'unit' => 'дн.', 'type' => 'text'],
        ['key' => 'smoke',                   'label' => 'Задымление (случаи)',           'name' => 'Задымление',            'unit' => 'дн.', 'type' => 'text'],
        ['key' => 'fire_without_mchs',       'label' => 'Возгорание (случаи)',           'name' => 'Пожар без вызова МЧС',  'unit' => 'дн.', 'type' => 'text'],
        ['key' => 'fire_with_mchs',          'label' => 'Пожар с вызовом МЧС (случаи)',  'name' => 'Пожар с вызовом МЧС',   'unit' => 'дн.', 'type' => 'text'],
        ['key' => 'irrigation',              'label' => 'Орошение (случаи)',             'name' => 'Орошение',              'unit' => 'дн.', 'type' => 'text'],
    ];

    public const DOW = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

    public const MONTHS = [
        'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
        'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь',
    ];
}
