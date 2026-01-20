<?php

namespace App\Services\ArbitratorFileStorage\Providers;

use App\Models\Arbitrator\Arbitrator;
use App\Models\Procedure\Procedure;
use App\Models\ArbitratorFiles\ArbitratorFileInsurance;
use App\Models\ArbitratorFiles\ArbitratorFileEstimate;
use App\Models\ArbitratorFiles\ArbitratorFileInventory;
use App\Models\ArbitratorFiles\ArbitratorFileLetter;
use App\Models\ArbitratorFiles\ArbitratorFileTrade;
use App\Models\ArbitratorFiles\ArbitratorFileTradeContract;
use App\Enums\Arbitrator\FileStorageType;
use App\Services\ArbitratorFileStorage\Exceptions\ArbitratorFileStorageException;
use App\Services\ArbitratorFileStorage\ArbitratorFileStorageInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @phpstan-import-type S3Client from \Aws\S3\S3Client
 */
class OnbStorage implements ArbitratorFileStorageInterface
{
    private Arbitrator $arbitrator;
    /** @var \Aws\S3\S3Client */
    private $client;
    private string $bucket;
    private string $root;
    private int $signedUrlExpires;

    public function __construct(
        Arbitrator $arbitrator,
        string $accessKey,
        string $secretKey,
        string $bucket,
        string $endpoint,
        ?string $region = null,
        array $config = []
    ) {
        $this->arbitrator = $arbitrator;
        $this->bucket = $bucket;
        $this->root = $config['root'] ?? '/';
        $this->signedUrlExpires = $config['signed_url_expires'] ?? 3600;

        if (!class_exists('Aws\S3\S3Client')) {
            throw new ArbitratorFileStorageException('Пакет aws/aws-sdk-php не установлен. Установите его через composer: composer require aws/aws-sdk-php');
        }

        try {
            /** @var class-string<\Aws\S3\S3Client> $s3ClientClass */
            $s3ClientClass = 'Aws\S3\S3Client';
            $this->client = new $s3ClientClass([
                'version' => 'latest',
                'region' => $region ?? 'ru-central1',
                'endpoint' => $endpoint,
                'credentials' => [
                    'key' => $accessKey,
                    'secret' => $secretKey,
                ],
                'use_path_style_endpoint' => true,
            ]);
        } catch (Throwable $e) {
            throw new ArbitratorFileStorageException('Не удалось инициализировать клиент S3: '.$e->getMessage(), previous: $e);
        }
    }

    public function upload(UploadedFile $file, string $remotePath): array
    {
        $this->checkSubscription();
        $this->checkStorageLimit($file);

        $remote = $this->absolutePath($remotePath);
        $this->ensureDirectory(dirname($remote));

        try {
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => ltrim($remote, '/'),
                'Body' => fopen($file->getRealPath(), 'r'),
                'ContentType' => $file->getMimeType(),
            ]);
        } catch (Throwable $e) {
            // Для AwsException используем getMessage(), который содержит детальную информацию
            throw new ArbitratorFileStorageException('Не удалось загрузить файл в хранилище ОнБанкрот: '.$e->getMessage(), previous: $e);
        }

        return ['path' => $remote];
    }

    public function downloadTo(string $remotePath, string $localPath): void
    {
        $this->checkSubscription();

        $remote = $this->absolutePath($remotePath);
        $key = ltrim($remote, '/');

        try {
            $result = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);

            $directory = dirname($localPath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            file_put_contents($localPath, $result['Body']);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            // Проверяем код ошибки через сообщение, так как AwsException может не быть доступен в статическом анализе
            if (str_contains($message, 'NoSuchKey') || str_contains($message, '404') || str_contains($message, 'not found')) {
                throw new ArbitratorFileStorageException('Файл не найден в хранилище ОнБанкрот: '.$remotePath, previous: $e);
            }
            throw new ArbitratorFileStorageException('Не удалось скачать файл из хранилища ОнБанкрот: '.$message, previous: $e);
        }
    }

    public function delete(string $remotePath): array
    {
        $this->checkSubscription();

        $remote = $this->absolutePath($remotePath);
        $key = ltrim($remote, '/');

        try {
            // Проверяем существование файла перед удалением (как в YandexDiskStorage)
            // В S3 deleteObject не выбрасывает исключение для несуществующих файлов
            try {
                $this->client->headObject([
                    'Bucket' => $this->bucket,
                    'Key' => $key,
                ]);
            } catch (Throwable $e) {
                $message = $e->getMessage();
                // Если файл не найден, возвращаем информацию об этом
                if (str_contains($message, 'NoSuchKey') || str_contains($message, '404') || str_contains($message, 'not found')) {
                    return ['deleted' => false, 'not_found' => true];
                }
                // Если другая ошибка при проверке, пробрасываем дальше
                throw $e;
            }

            // Файл существует, удаляем его
            $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);

            return ['deleted' => true, 'not_found' => false];
        } catch (Throwable $e) {
            $message = $e->getMessage();
            // Проверяем код ошибки через сообщение
            if (str_contains($message, 'NoSuchKey') || str_contains($message, '404') || str_contains($message, 'not found')) {
                return ['deleted' => false, 'not_found' => true];
            }
            throw new ArbitratorFileStorageException('Не удалось удалить файл из хранилища ОнБанкрот: '.$message, previous: $e);
        }
    }

    public function list(string $path): array
    {
        $this->checkSubscription();

        $remote = $this->absolutePath($path);
        $prefix = ltrim($remote, '/');
        if ($prefix && !str_ends_with($prefix, '/')) {
            $prefix .= '/';
        }

        try {
            $result = $this->client->listObjectsV2([
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
            ]);

            $items = [];
            if (isset($result['Contents'])) {
                foreach ($result['Contents'] as $object) {
                    $key = $object['Key'];
                    $relativePath = str_replace($prefix, '', $key);

                    // Пропускаем сам префикс (если это директория)
                    if (empty($relativePath) || $relativePath === $key) {
                        continue;
                    }

                    $items[] = [
                        'name' => basename($key),
                        'path' => '/' . $key,
                        'type' => str_ends_with($key, '/') ? 'dir' : 'file',
                        'size' => $object['Size'] ?? 0,
                        'modified' => isset($object['LastModified']) ? $object['LastModified']->format('c') : null,
                    ];
                }
            }

            return $items;
        } catch (Throwable $e) {
            throw new ArbitratorFileStorageException('Не удалось получить список файлов из хранилища ОнБанкрот: '.$e->getMessage(), previous: $e);
        }
    }

    public function publish(string $remotePath): ?string
    {
        $this->checkSubscription();

        $remote = $this->absolutePath($remotePath);
        $key = ltrim($remote, '/');

        try {
            $cmd = $this->client->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);

            $request = $this->client->createPresignedRequest($cmd, '+' . $this->signedUrlExpires . ' seconds');
            $presignedUrl = (string) $request->getUri();

            return $presignedUrl;
        } catch (Throwable $e) {
            throw new ArbitratorFileStorageException('Не удалось создать публичную ссылку для файла в хранилище ОнБанкрот: '.$e->getMessage(), previous: $e);
        }
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        $root = rtrim($this->root, '/');

        return $root.'/'.$path;
    }

    private function ensureDirectory(string $path): void
    {
        // Для S3 директории создаются автоматически при загрузке файлов
        // Но можно создать пустой объект с ключом, заканчивающимся на '/'
        // Это необязательно, так как S3 не требует явного создания директорий
        // Оставляем метод пустым или можно добавить логику создания "папок" если нужно
    }

    /**
     * Получает размер папки арбитражного управляющего в хранилище ОнБанкрот
     *
     * @param string $arbitratorUuid UUID арбитражного управляющего
     * @return int Размер папки в байтах
     */
    public function getArbitratorFolderSize(string $arbitratorUuid): int
    {
        try {
            // Формируем префикс для папки арбитражного управляющего
            // Структура: {root}/{arbitrator->uuid}/...
            // Используем absolutePath для корректного формирования пути с учетом root
            $folderPath = $this->absolutePath($arbitratorUuid);
            $prefix = ltrim($folderPath, '/');
            if ($prefix && !str_ends_with($prefix, '/')) {
                $prefix .= '/';
            }

            $totalSize = 0;
            $continuationToken = null;

            // Используем пагинацию для получения всех объектов
            do {
                $params = [
                    'Bucket' => $this->bucket,
                    'Prefix' => $prefix,
                ];

                if ($continuationToken) {
                    $params['ContinuationToken'] = $continuationToken;
                }

                $result = $this->client->listObjectsV2($params);

                if (isset($result['Contents'])) {
                    foreach ($result['Contents'] as $object) {
                        // Суммируем размеры всех объектов в папке
                        $totalSize += (int) ($object['Size'] ?? 0);
                    }
                }

                // Проверяем, есть ли еще страницы результатов
                $continuationToken = $result['NextContinuationToken'] ?? null;
            } while ($continuationToken);

            return $totalSize;
        } catch (Throwable $e) {
            Log::error('Ошибка получения размера папки арбитражного управляющего в хранилище ОнБанкрот', [
                'arbitrator_uuid' => $arbitratorUuid,
                'folder_path' => $folderPath ?? null,
                'prefix' => $prefix ?? null,
                'error_message' => $e->getMessage(),
                'error_code' => method_exists($e, 'getCode') ? $e->getCode() : 0,
                'exception_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            return 0; // Возвращаем 0 в случае ошибки
        }
    }

    private function checkSubscription(): void
    {
        $fileStorage = $this->arbitrator->file_storage;

        if (!$fileStorage) {
            throw new ArbitratorFileStorageException('Не настроено хранилище файлов для арбитражного управляющего.');
        }

        if ($fileStorage->isSubscriptionExpired()) {
            throw new ArbitratorFileStorageException('Подписка на хранилище ОнБанкрот истекла. Продлите подписку для продолжения работы.');
        }
    }

    /**
     * Проверяет, что размер хранилища после загрузки файла не превысит предельный лимит
     *
     * @param UploadedFile $file Загружаемый файл
     * @throws ArbitratorFileStorageException Если загрузка файла приведет к превышению лимита хранилища
     */
    private function checkStorageLimit(UploadedFile $file): void
    {
        $fileStorage = $this->arbitrator->file_storage;

        if (!$fileStorage) {
            // Если хранилище не настроено, проверка будет выполнена в checkSubscription()
            return;
        }

        // Получаем лимит хранилища
        $storageLimit = $fileStorage->getOnbStorageTotal();

        // Получаем текущий размер хранилища из БД с кешированием
        $currentSize = $this->getArbitratorStorageSizeFromDb($this->arbitrator->id);

        // Размер загружаемого файла
        $fileSize = $file->getSize();

        // Проверяем, что после загрузки не превысим лимит
        if ($currentSize + $fileSize > $storageLimit) {
            $availableSpace = max(0, $storageLimit - $currentSize);
            $fileSizeMB = round($fileSize / (1024 * 1024), 2);
            $availableSpaceMB = round($availableSpace / (1024 * 1024), 2);
            $storageLimitGB = round($storageLimit / (1024 * 1024 * 1024), 2);

            throw new ArbitratorFileStorageException(
                "Недостаточно места в хранилище ОнБанкрот для загрузки файла. " .
                "Размер файла: {$fileSizeMB} МБ, доступно: {$availableSpaceMB} МБ, " .
                "лимит хранилища: {$storageLimitGB} ГБ."
            );
        }
    }

    /**
     * Получает размер хранилища арбитражного управляющего из БД с кешированием (публичный метод)
     *
     * @return int Размер хранилища в байтах
     */
    public function getArbitratorStorageSize(): int
    {
        return $this->getArbitratorStorageSizeFromDb($this->arbitrator->id);
    }

    /**
     * Получает размер хранилища арбитражного управляющего из БД с кешированием
     *
     * @param int $arbitratorId ID арбитражного управляющего
     * @return int Размер хранилища в байтах
     */
    private function getArbitratorStorageSizeFromDb(int $arbitratorId): int
    {
        $cacheKey = $this->getStorageSizeCacheKey($arbitratorId);

        /** @var int $cachedSize */
        $cachedSize = Cache::remember($cacheKey, now()->addHours(24), function () use ($arbitratorId) {
            $usedSize = 0;

            // Файлы страховок
            $usedSize += ArbitratorFileInsurance::where('arbitrator_id', $arbitratorId)
                ->where('provider', FileStorageType::ONB_STORAGE->value)
                ->sum('size');

            // Файлы процедур
            $procedureIds = Procedure::where('arbitrator_id', $arbitratorId)->pluck('id');

            if ($procedureIds->isNotEmpty()) {
                $usedSize += ArbitratorFileEstimate::whereIn('procedure_id', $procedureIds)
                    ->where('provider', FileStorageType::ONB_STORAGE->value)
                    ->sum('size');

                $usedSize += ArbitratorFileInventory::whereIn('procedure_id', $procedureIds)
                    ->where('provider', FileStorageType::ONB_STORAGE->value)
                    ->sum('size');

                $usedSize += ArbitratorFileLetter::whereIn('procedure_id', $procedureIds)
                    ->where('provider', FileStorageType::ONB_STORAGE->value)
                    ->sum('size');

                $usedSize += ArbitratorFileTrade::whereIn('procedure_id', $procedureIds)
                    ->where('provider', FileStorageType::ONB_STORAGE->value)
                    ->sum('size');

                $usedSize += ArbitratorFileTradeContract::whereIn('procedure_id', $procedureIds)
                    ->where('provider', FileStorageType::ONB_STORAGE->value)
                    ->sum('size');
            }

            return (int) $usedSize;
        });

        return (int) $cachedSize;
    }

    /**
     * Получает ключ кеша для размера хранилища арбитражного управляющего
     *
     * @param int $arbitratorId ID арбитражного управляющего
     * @return string Ключ кеша
     */
    private function getStorageSizeCacheKey(int $arbitratorId): string
    {
        return "arbitrator_storage_size_onb_{$arbitratorId}";
    }

    /**
     * Увеличивает размер хранилища в кеше при загрузке файла
     *
     * @param int $arbitratorId ID арбитражного управляющего
     * @param int $fileSize Размер загружаемого файла в байтах
     */
    public static function incrementStorageSizeCache(int $arbitratorId, int $fileSize): void
    {
        $cacheKey = "arbitrator_storage_size_onb_{$arbitratorId}";

        // Если в кеше есть значение, обновляем его
        if (Cache::has($cacheKey)) {
            /** @var int|float $currentSize */
            $currentSize = Cache::get($cacheKey, 0);
            Cache::put($cacheKey, (int) $currentSize + $fileSize, now()->addHours(24));
        } else {
            // Если кеша нет, сбрасываем его - при следующем запросе будет пересчитан из БД
            Cache::forget($cacheKey);
        }
    }

    /**
     * Уменьшает размер хранилища в кеше при удалении файла
     *
     * @param int $arbitratorId ID арбитражного управляющего
     * @param int $fileSize Размер удаляемого файла в байтах
     */
    public static function decrementStorageSizeCache(int $arbitratorId, int $fileSize): void
    {
        $cacheKey = "arbitrator_storage_size_onb_{$arbitratorId}";

        // Если в кеше есть значение, обновляем его
        if (Cache::has($cacheKey)) {
            /** @var int|float $currentSize */
            $currentSize = Cache::get($cacheKey, 0);
            $newSize = max(0, (int) $currentSize - $fileSize); // Не даем уйти в минус
            Cache::put($cacheKey, $newSize, now()->addHours(24));
        } else {
            // Если кеша нет, сбрасываем его - при следующем запросе будет пересчитан из БД
            Cache::forget($cacheKey);
        }
    }

    /**
     * Сбрасывает кеш размера хранилища для арбитражного управляющего
     *
     * @param int $arbitratorId ID арбитражного управляющего
     */
    public static function forgetStorageSizeCache(int $arbitratorId): void
    {
        $cacheKey = "arbitrator_storage_size_onb_{$arbitratorId}";
        Cache::forget($cacheKey);
    }
}
