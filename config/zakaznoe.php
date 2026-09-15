<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Сборка архивов для zakaznoe.pochta.ru
    |--------------------------------------------------------------------------
    |
    | CRM присылает задание, воркер склеивает вложения писем, раскладывает их
    | по частям с описью, пакует в один архив и кладёт в хранилище управляющего.
    | Ход и итог уходят в CRM callback'ами.
    |
    */

    // Больше страниц в одном письме zakaznoe.pochta.ru не принимает
    'max_pages_per_letter' => (int) env('ZAKAZNOE_MAX_PAGES_PER_LETTER', 15),

    // Сайт принимает только книжный A4. Допуск в пунктах (3 pt ≈ 1 мм): A4 сохраняют
    // и как 595×842, и как 595,28×841,89 - такие страницы не переделываем
    'a4_tolerance_pt' => 3,

    // Больше писем за одну сессию zakaznoe не принимает - и в шаблоне описи
    // проверки заведены ровно на 50 строк
    'max_letters_per_part' => 50,

    'template_path' => resource_path('templates/zakaznoe-registry.xlsx'),
    'template_sheet' => 'Реестр для отправки',

    'temp_path' => storage_path('app/tmp/zakaznoe'),

    // Временные папки старше этого возраста удаляет zakaznoe:cleanup-temp.
    // Живая сборка чистит за собой сама, это страховка от упавших процессов
    'temp_ttl_hours' => 24,

    // Сколько задание может идти, секунд. retry_after очереди zakaznoe должен быть больше
    'job_timeout' => (int) env('ZAKAZNOE_JOB_TIMEOUT', 3600),

    // Адрес CRM. Задание принимается, только если callback_url ведёт сюда:
    // callback уходит с ключом, и на чужой адрес его отправлять нельзя
    'crm_url' => env('AUAPP_URL'),

    'callback' => [
        'timeout' => (int) env('ZAKAZNOE_CALLBACK_TIMEOUT', 30),
        // Повторы - только при сетевой ошибке и 5xx
        'attempts' => (int) env('ZAKAZNOE_CALLBACK_ATTEMPTS', 4),
    ],
];
