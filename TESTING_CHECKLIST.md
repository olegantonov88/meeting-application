# Чеклист для тестирования генерации приложений к собранию кредиторов

## 1. Подготовка окружения

### 1.1. Установка зависимостей
```bash
composer install
```

### 1.2. Настройка переменных окружения (.env)
Добавьте следующие переменные в `.env`:

```env
# База данных meeting-application (основная)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=meeting_application
DB_USERNAME=root
DB_PASSWORD=

# База данных auapp (для моделей из auapp)
AUAPP_DB_CONNECTION=mysql
AUAPP_DB_HOST=127.0.0.1
AUAPP_DB_PORT=3306
AUAPP_DB_DATABASE=auapp
AUAPP_DB_USERNAME=root
AUAPP_DB_PASSWORD=

# Сервис efrsb-debtor-message
EFRSB_DEBTOR_MESSAGE_URL=http://localhost:8707
EFRSB_DEBTOR_MESSAGE_API_KEY=your_api_key_here

# Таймаут для сообщений ЕФРСБ (в минутах)
EFRSB_TIMEOUT_MINUTES=5

# Pusher (для уведомлений)
PUSHER_APP_ID=
PUSHER_APP_KEY=
PUSHER_APP_SECRET=
PUSHER_APP_CLUSTER=mt1
BROADCAST_DRIVER=pusher
```

### 1.3. Запуск миграций
```bash
php artisan migrate
```

Проверьте, что создана таблица `efrsb_message_requests` в БД meeting-application.

### 1.4. Настройка очередей
Убедитесь, что настроена очередь (database, redis, или другой драйвер):

```env
QUEUE_CONNECTION=database
```

Запустите воркер очереди:
```bash
php artisan queue:work
```

## 2. Проверка конфигурации

### 2.1. Проверка подключения к БД auapp
```bash
php artisan tinker
```
```php
// Проверка подключения к БД auapp
DB::connection('auapp')->table('meeting_applications')->count();
DB::connection('auapp')->table('efrsb_debtor_messages')->count();
```

### 2.2. Проверка моделей
```php
// В tinker
use App\Models\Meeting\MeetingApplication;
use App\Models\EfrsbMessage\EfrsbDebtorMessage;

// Проверка получения MeetingApplication
$app = MeetingApplication::find(1);
$app->arbitrator_files;
$app->efrsb_debtor_messages;

// Проверка получения EfrsbDebtorMessage
$message = EfrsbDebtorMessage::find(1);
```

### 2.3. Проверка конфигурации сервисов
```php
// В tinker
config('services.efrsb_debtor_message.url');
config('services.efrsb_debtor_message.api_key');
config('meeting_application.efrsb_timeout_minutes');
```

## 3. Тестирование API endpoints

### 3.1. Запуск генерации приложения
```bash
curl -X POST http://localhost:8000/api/meeting-applications/generate \
  -H "Content-Type: application/json" \
  -d '{"id": 1}'
```

Или через Postman/Insomnia:
- **URL**: `POST /api/meeting-applications/generate`
- **Method**: POST
- **Body** (JSON):
  ```json
  {
    "id": 1
  }
  ```

### 3.2. Проверка callback endpoint
```bash
curl -X POST http://localhost:8000/api/efrsb-message/callback \
  -H "Content-Type: application/json" \
  -d '{
    "message_id": 1,
    "message_uuid": "test-uuid",
    "status": "success"
  }'
```

## 4. Тестирование сценариев

### 4.1. Сценарий 1: Генерация с готовыми файлами и сообщениями
**Условия:**
- В `MeetingApplication` есть `arbitrator_files` с существующими файлами
- В `MeetingApplication` есть `efrsb_debtor_messages` с `body_html`

**Ожидаемый результат:**
- Файлы скачиваются
- PDF генерируются из HTML
- Все файлы объединяются в один PDF
- Создается запись `ArbitratorFileMeetingApplication`
- Статус `MeetingApplication` меняется на `GENERATED`

**Проверка:**
```php
// В tinker
$app = MeetingApplication::find(1);
$app->latest_status; // Должен быть GENERATED
$app->arbitratorFiles()->count(); // Должен быть > 0
```

### 4.2. Сценарий 2: Генерация с запросом body_html
**Условия:**
- В `MeetingApplication` есть `efrsb_debtor_messages` БЕЗ `body_html`

**Ожидаемый результат:**
1. Отправляется запрос в `efrsb-debtor-message`
2. Создается запись в `efrsb_message_requests` со статусом `PENDING`
3. Запускается Job для проверки таймаута
4. После получения callback:
   - Статус меняется на `COMPLETED` или `ERROR`
   - Если `COMPLETED` - читается `body_html` из БД и генерируется PDF
   - Если `ERROR` - сообщение пропускается
5. Генерация продолжается

**Проверка:**
```php
// В tinker
use Illuminate\Support\Facades\DB;

// Проверка запросов
DB::table('efrsb_message_requests')
    ->where('meeting_application_id', 1)
    ->get();

// Проверка статусов
DB::table('efrsb_message_requests')
    ->where('meeting_application_id', 1)
    ->where('status', 1) // PENDING
    ->count();
```

### 4.3. Сценарий 3: Таймаут запроса body_html
**Условия:**
- Запрос на `body_html` отправлен, но callback не пришел в течение 5 минут

**Ожидаемый результат:**
1. `CheckEfrsbMessageTimeoutJob` помечает запрос как `TIMEOUT`
2. Генерация продолжается без этого сообщения

**Проверка:**
```php
// В tinker
DB::table('efrsb_message_requests')
    ->where('status', 4) // TIMEOUT
    ->get();
```

### 4.4. Сценарий 4: Ошибка - нет файлов и сообщений
**Условия:**
- В `MeetingApplication` нет `arbitrator_files`
- В `MeetingApplication` нет `efrsb_debtor_messages` или все с ошибками

**Ожидаемый результат:**
- Статус `MeetingApplication` меняется на `ERROR`
- В `statuses` добавляется запись с описанием ошибки

**Проверка:**
```php
// В tinker
$app = MeetingApplication::find(1);
$app->latest_status; // Должен быть ERROR
$app->statuses; // Проверить последнюю запись
```

## 5. Проверка логов

### 5.1. Просмотр логов
```bash
tail -f storage/logs/laravel.log
```

Или используйте Laravel Pail:
```bash
php artisan pail
```

### 5.2. Ключевые события для проверки
- `Meeting application generation started`
- `Arbitrator files downloaded`
- `EFRSB message body request sent`
- `EFRSB message body processing completed`
- `All EFRSB messages processed, continuing generation`
- `PDF files merged successfully`
- `Meeting application generated successfully`

## 6. Проверка временных файлов

### 6.1. Проверка создания временных файлов
```bash
ls -la storage/app/tmp/
```

### 6.2. Проверка очистки временных файлов
После завершения генерации временные файлы должны быть удалены.

## 7. Проверка интеграции с efrsb-debtor-message

### 7.1. Проверка endpoint в efrsb-debtor-message
**ВАЖНО:** В `efrsb-debtor-message` используется endpoint:
```
POST /api/fedresurs/enqueue/message-tables
```

Этот endpoint:
- Принимает массив `messages` с `message_id` и `message_uuid`
- Может принимать `meeting_application_id` (опционально)
- Возвращает `job_id` и `parse_job_id`
- После завершения парсинга должен отправлять callback на `meeting-application`

### 7.2. Проверка отправки запроса
В логах `efrsb-debtor-message` должен появиться запрос:
```
POST /api/fedresurs/enqueue/message-tables
{
  "messages": [
    {
      "message_id": 1,
      "message_uuid": "..."
    },
    {
      "message_id": 2,
      "message_uuid": "..."
    }
  ],
  "meeting_application_id": 1
}
```

**Примечание:** Все сообщения без `body_html` отправляются одним запросом для оптимизации.

### 7.3. Проверка получения callback
После обработки `efrsb-debtor-message` должен отправить callback:
```
POST http://meeting-application/api/efrsb-message/callback
{
  "message_id": 1,
  "message_uuid": "...",
  "status": "success", // или "error"
  "error": null // или текст ошибки, если status = "error"
}
```

**Примечание:** `efrsb-debtor-message` сам обновляет `body_html` в БД auapp. `meeting-application` только читает его после получения callback.

## 8. Проверка Pusher уведомлений

### 8.1. Настройка Pusher
Убедитесь, что Pusher настроен в `.env` и `config/broadcasting.php`.

### 8.2. Проверка событий
Событие `MeetingApplicationStatusUpdated` должно отправляться при изменении статуса.

## 9. Тестирование производительности

### 9.1. Генерация с большим количеством файлов
- Создайте `MeetingApplication` с 10+ файлами
- Проверьте время генерации
- Проверьте использование памяти

### 9.2. Генерация с большим количеством сообщений
- Создайте `MeetingApplication` с 10+ сообщениями ЕФРСБ
- Проверьте обработку параллельных запросов

## 10. Отладка проблем

### 10.1. Проблемы с подключением к БД auapp
```php
// В tinker
try {
    DB::connection('auapp')->table('meeting_applications')->count();
} catch (\Exception $e) {
    echo $e->getMessage();
}
```

### 10.2. Проблемы с файлами
- Проверьте права доступа к `storage/app/tmp/`
- Проверьте наличие места на диске
- Проверьте логи на ошибки скачивания/загрузки файлов

### 10.3. Проблемы с очередями
```bash
# Проверка failed jobs
php artisan queue:failed

# Повторная попытка
php artisan queue:retry {job_id}
```

### 10.4. Проблемы с PDF
- Проверьте установку `dompdf` и `fpdi`
- Проверьте логи на ошибки генерации PDF
- Проверьте корректность HTML в `body_html`

## 11. Автоматизированное тестирование

### 11.1. Создание тестов
Создайте тесты в `tests/Feature/`:
- `MeetingApplicationGenerationTest.php`
- `EfrsbMessageCallbackTest.php`

### 11.2. Запуск тестов
```bash
php artisan test
```

## 12. Чеклист перед продакшеном

- [ ] Все миграции применены
- [ ] Переменные окружения настроены
- [ ] Очереди настроены и работают
- [ ] Логирование настроено
- [ ] Мониторинг настроен
- [ ] Резервное копирование настроено
- [ ] Документация обновлена
- [ ] Тесты написаны и проходят
- [ ] Производительность проверена
- [ ] Безопасность проверена (API ключи, валидация)
