# Сборка архивов для zakaznoe.pochta.ru

CRM (auapp) присылает задание на сборку реестра заказных писем. Воркер:

1. скачивает PDF-вложения писем из хранилища управляющего (Яндекс.Диск или ОнБанкрот);
2. склеивает вложения каждого письма в один PDF `letter-{номер исходящего}.pdf`
   (без номера - `letter-id{id письма}.pdf`);
   Перед склейкой каждый файл приводится к книжному A4: страницы другого размера
   вписываются в A4 с сохранением пропорций, альбомные поворачиваются. Иначе сайт
   Почты отвечает `INCORRECT_LETTER_PAGE_FORMAT`. В строке письма в CRM остаётся
   предупреждение с номерами страниц;
3. отбраковывает письма больше 15 страниц - больше zakaznoe.pochta.ru не принимает;
4. раскладывает собранные письма на части по 50 (лимит одной сессии zakaznoe) -
   в каждой части PDF и опись `reestr.xlsx` по шаблону Почты;
5. одну часть отдаёт как архив, несколько - пакует в один архив `chast-1.zip`, `chast-2.zip`, …;
6. кладёт архив в папку реестра в хранилище управляющего;
7. сообщает CRM ход и итог callback'ами. Запись о файле и имя архива для пользователя
   заводит CRM.

Письмо, которое не собралось (нет файла, не PDF, больше 15 страниц), не останавливает
сборку: CRM показывает причину в строке получателя.

## Где что лежит

| Что | Где |
|---|---|
| Приём задания | `POST /api/zakaznoe-builds` → `ZakaznoeBuildController`, контракт - `ZakaznoeBuildStoreRequest` |
| Сборка | `App\Services\Zakaznoe\ZakaznoeBuildService` |
| Опись | `ZakaznoeRegistryWriter`, шаблон `resources/templates/zakaznoe-registry.xlsx` |
| Callback'и в CRM | `ZakaznoeCallbackClient` |
| Задание очереди | `App\Jobs\ZakaznoeBuildJob` - подключение и очередь `zakaznoe` |
| Настройки | `config/zakaznoe.php` |

Контракт с обеих сторон строгий: лишнее поле - 422. В CRM ему соответствуют
`ZakaznoeBuildTask` (задание) и `ZakaznoeBuildCallbackRequest` (callback'и).

## Переменные окружения

```dotenv
# Тот же ключ, что MEETING_APPLICATION_GENERATION_API_KEY в CRM.
# Им CRM обращается к воркеру, им же воркер подписывает callback'и.
# Пустой - все запросы к API воркера отклоняются.
MEETING_APPLICATION_API_KEY=

# Адрес CRM. Задание с callback_url на другой адрес не принимается.
AUAPP_URL=https://crm.example.ru

ZAKAZNOE_JOB_TIMEOUT=3600          # сколько может идти одна сборка, секунд
ZAKAZNOE_QUEUE_RETRY_AFTER=4200    # обязательно больше ZAKAZNOE_JOB_TIMEOUT
ZAKAZNOE_MAX_PAGES_PER_LETTER=15
ZAKAZNOE_CALLBACK_TIMEOUT=30
ZAKAZNOE_CALLBACK_ATTEMPTS=4
```

## Очередь на проде

Сборка идёт в **отдельной очереди** `zakaznoe` на подключении `zakaznoe` (драйвер
`database`, таблица `jobs` воркера). Отдельно - потому что сборка идёт минуты:
в общей очереди с `retry_after` 90 секунд её запустили бы второй раз параллельно,
и она придерживала бы генерацию приложений к собранию.

Нужен **ещё один процесс** рядом с существующим `queue:work`. Существующий процесс
не меняется: задания `zakaznoe` он не берёт.

```ini
[program:meeting-application-zakaznoe]
command=php /path/to/meeting-application/artisan queue:work zakaznoe --queue=zakaznoe --tries=1 --timeout=3600 --memory=512 --sleep=3
directory=/path/to/meeting-application
numprocs=1
autostart=true
autorestart=true
; дать текущей сборке закончиться при перезапуске: больше --timeout
stopwaitsecs=3700
stopasgroup=true
killasgroup=true
user=www-data
redirect_stderr=true
stdout_logfile=/path/to/meeting-application/storage/logs/zakaznoe-worker.log
```

- `numprocs=1` - сборка качает файлы и гоняет Ghostscript; одной параллельной сборки хватает.
  Если реестров станет много, можно поднять до 2: сборки разных реестров друг другу не мешают.
- `--timeout` = `ZAKAZNOE_JOB_TIMEOUT`, `retry_after` очереди больше него.
- После выкладки перезапускать: `php artisan queue:restart` (перезапускает оба процесса).

## Планировщик

Запланированные консольные команды описаны в `routes/console.php`. Сейчас там одна:
`zakaznoe:cleanup-temp` раз в час удаляет временные папки упавших сборок старше суток.
Живая сборка удаляет свою папку сама, это страховка.

Расписание выполняет **отдельный процесс supervisor** рядом с очередями, cron не нужен.
`schedule:work` раз в минуту запускает `schedule:run` новым процессом, а тот выполняет
команды, которым пришло время. Код расписания каждый раз читается заново, поэтому после
выкладки процесс перезапускать не обязательно.

```ini
[program:meeting-application-scheduler]
command=php /path/to/meeting-application/artisan schedule:work
directory=/path/to/meeting-application
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=60
user=www-data
redirect_stderr=true
stdout_logfile=/path/to/meeting-application/storage/logs/scheduler.log
```

- `numprocs=1` и один такой процесс на все серверы воркера: второй `schedule:work` выполнит
  каждую команду дважды.
- Команды из расписания выполняются внутри этого процесса, а не в очереди. Долгую работу
  в расписании не делают: команда только ставит job в очередь, а выполняет его `queue:work`.
- Проверить, что и когда запустится: `php artisan schedule:list`.
- Запустить команду руками: `php artisan zakaznoe:cleanup-temp`.

Итого на сервере воркера три процесса supervisor: очередь `default` (генерация приложений
к собранию), очередь `zakaznoe` (сборка архивов) и планировщик.

## Требования к серверу

- PHP-расширения `zip`, `gd` (PhpSpreadsheet), `fileinfo`.
- Ghostscript - **обязателен**: им читается и приводится к A4 формат страниц, без него сборка
  завершается ошибкой `ghostscript_missing`. Склейка тоже идёт через него.
- Место под временные файлы: `storage/app/tmp/zakaznoe/{uuid}` - порядка суммарного размера
  вложений реестра, умноженного на два.

## Что будет, если…

| Ситуация | Что происходит |
|---|---|
| Процесс упал или сборка не уложилась в таймаут | `failed()` задания сообщает CRM `build_timeout`. Если и это не удалось - CRM через 30 минут без событий считает сборку зависшей, пользователь запускает заново |
| Пользователь перезапустил сборку, пока шла старая | CRM отвечает старой 409 - воркер молча останавливается |
| CRM не приняла `completed` (422) | Архив удаляется из хранилища, CRM получает `failed` с `callback_rejected`, ответ CRM - в логе |
| CRM недоступна | Повторы с паузой 2, 4, 8… секунд, затем сборка останавливается |
| Хранилище недоступно при заливке архива | `failed` с текстом ошибки хранилища |
