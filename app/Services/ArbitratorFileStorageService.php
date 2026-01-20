<?php

namespace App\Services;

use App\Models\Meeting\MeetingApplication;
use App\Models\ArbitratorFiles\ArbitratorFileMeetingApplication;
use App\Enums\Arbitrator\FileStorageType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ArbitratorFileStorageService
{
    /**
     * Скачивает файл из хранилища во временную папку
     *
     * @param Model $file Модель файла (любая из ArbitratorFiles)
     * @param string $tempPath Путь для сохранения файла
     * @return void
     * @throws \Exception
     */
    public function downloadFile(Model $file, string $tempPath): void
    {
        try {
            $provider = $this->getProviderForFile($file);

            // Если провайдер недоступен, выбрасываем исключение с причиной
            if (!$provider) {
                // Получаем причину недоступности провайдера
                $reason = $this->getProviderUnavailableReason($file);
                throw new \Exception($reason);
            }

            $remotePath = $file->remote_path;

            if (!$remotePath) {
                throw new \Exception("У файла не указан remote_path");
            }

            $provider->downloadTo($remotePath, $tempPath);

            Log::debug('File downloaded successfully', [
                'file_id' => $file->id,
                'remote_path' => $remotePath,
                'temp_path' => $tempPath,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to download file', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
            throw new \Exception("Ошибка скачивания файла: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Загружает файл в хранилище и создает запись ArbitratorFileMeetingApplication
     *
     * @param MeetingApplication $application Приложение к собранию
     * @param string $filePath Путь к локальному файлу
     * @param string $filename Имя файла
     * @param int|null $userId ID пользователя
     * @return ArbitratorFileMeetingApplication
     * @throws \Exception
     */
    public function uploadFile(MeetingApplication $application, string $filePath, string $filename, ?int $userId = null): ArbitratorFileMeetingApplication
    {
        try {
            if (!file_exists($filePath)) {
                throw new \Exception("Файл не найден: {$filePath}");
            }

            // Получаем арбитра из приложения
            $arbitrator = $application->meeting?->procedure?->arbitrator ?? $application->arbitrator;
            if (!$arbitrator) {
                throw new \Exception("Не удалось определить арбитражного управляющего для приложения");
            }

            // Определяем тип хранилища
            $storageType = $this->getStorageTypeForArbitrator($arbitrator);
            $provider = $this->getProviderForArbitrator($storageType, $arbitrator);

            // Создаем UploadedFile из локального файла
            $uploadedFile = new UploadedFile(
                $filePath,
                $filename,
                mime_content_type($filePath),
                null,
                true // test mode
            );

            // Строим путь для сохранения
            $remotePath = $this->buildStoragePath($application, $filename, $storageType);

            // Загружаем файл
            $provider->upload($uploadedFile, $remotePath);

            // Создаем запись в БД
            $fileRecord = ArbitratorFileMeetingApplication::create([
                'meeting_application_id' => $application->id,
                'user_id' => $userId ?? 1,
                'workspace_id' => $arbitrator->workspace_id ?? null,
                'arbitrator_id' => $arbitrator->id,
                'procedure_id' => $application->meeting?->procedure_id ?? null,
                'provider' => $storageType,
                'remote_path' => $remotePath,
                'name' => $filename,
                'size' => filesize($filePath),
                'mime' => mime_content_type($filePath),
                'meta' => [],
            ]);

            Log::debug('File uploaded successfully', [
                'file_id' => $fileRecord->id,
                'remote_path' => $remotePath,
                'size' => $fileRecord->size,
            ]);

            return $fileRecord;
        } catch (\Exception $e) {
            Log::error('Failed to upload file', [
                'application_id' => $application->id,
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);
            throw new \Exception("Ошибка загрузки файла: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Получает провайдер для файла
     *
     * @param Model $file
     * @return \App\Services\ArbitratorFileStorage\ArbitratorFileStorageInterface|null
     * @throws \Exception
     */
    private function getProviderForFile(Model $file): ?\App\Services\ArbitratorFileStorage\ArbitratorFileStorageInterface
    {
        $arbitrator = $file->arbitrator;
        if (!$arbitrator) {
            throw new \Exception("Не найден арбитражный управляющий для файла");
        }

        $providerEnum = $file->provider instanceof FileStorageType
            ? $file->provider
            : FileStorageType::tryFrom($file->provider);

        if (!$providerEnum) {
            throw new \Exception("Неизвестный тип провайдера: {$file->provider}");
        }

        return $this->getProviderForArbitrator($providerEnum, $arbitrator);
    }

    /**
     * Получает провайдер для арбитра по типу хранилища
     *
     * @param FileStorageType $storageType
     * @param mixed $arbitrator
     * @return \App\Services\ArbitratorFileStorage\ArbitratorFileStorageInterface|null
     * @throws \Exception
     */
    private function getProviderForArbitrator(FileStorageType $storageType, $arbitrator): ?\App\Services\ArbitratorFileStorage\ArbitratorFileStorageInterface
    {
        // Пытаемся использовать ArbitratorFileStorageManager из auapp, если доступен
        if (class_exists('\App\Services\ArbitratorFileStorage\ArbitratorFileStorageManager')) {
            try {
                $manager = app('\App\Services\ArbitratorFileStorage\ArbitratorFileStorageManager');
                if (method_exists($manager, 'providerForArbitratorByType')) {
                    return $manager->providerForArbitratorByType($storageType, $arbitrator);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to use ArbitratorFileStorageManager from auapp', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Если не удалось использовать из auapp, создаем провайдер напрямую
        try {
            return $this->createProvider($storageType, $arbitrator);
        } catch (\Exception $e) {
            // Сохраняем причину недоступности для последующего использования
            $this->lastProviderError = $e->getMessage();
            // Логируем предупреждение с реальной причиной ошибки
            Log::warning('Provider not available, skipping file', [
                'storage_type' => $storageType->value,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Хранит последнюю ошибку при создании провайдера
     */
    private ?string $lastProviderError = null;

    /**
     * Создает провайдер напрямую
     *
     * @param FileStorageType $storageType
     * @param mixed $arbitrator
     * @return \App\Services\ArbitratorFileStorage\ArbitratorFileStorageInterface|null
     * @throws \Exception
     */
    private function createProvider(FileStorageType $storageType, $arbitrator): \App\Services\ArbitratorFileStorage\ArbitratorFileStorageInterface
    {
        $fileStorage = $arbitrator->file_storage ?? null;
        if (!$fileStorage) {
            throw new \Exception("Не настроено хранилище файлов для арбитражного управляющего");
        }

        $config = config("arbitrator_storage_providers.providers.{$storageType->toString()}", []);

        return match ($storageType) {
            FileStorageType::YANDEX_DISK => $this->createYandexProvider($fileStorage, $config),
            FileStorageType::ONB_STORAGE => $this->createOnbProvider($arbitrator, $config),
            default => throw new \Exception("Провайдер {$storageType->value} не поддерживается"),
        };
    }

    /**
     * Создает провайдер Яндекс.Диска
     */
    private function createYandexProvider($fileStorage, array $config): \App\Services\ArbitratorFileStorage\ArbitratorFileStorageInterface
    {
        $token = $fileStorage->getYandexDiskAccessToken();
        if (!$token) {
            throw new \Exception("Укажите токен Яндекс.Диска в настройках арбитражного управляющего");
        }

        $providerClass = '\App\Services\ArbitratorFileStorage\Providers\YandexDiskStorage';
        if (!class_exists($providerClass)) {
            throw new \Exception("Класс {$providerClass} не найден. Убедитесь, что классы из auapp доступны");
        }

        return new $providerClass($token, $config);
    }

    /**
     * Создает провайдер OnbStorage
     */
    private function createOnbProvider($arbitrator, array $config): \App\Services\ArbitratorFileStorage\ArbitratorFileStorageInterface
    {
        $accessKey = $config['access_key'] ?? null;
        $secretKey = $config['secret_key'] ?? null;
        $bucket = $config['bucket'] ?? null;
        $endpoint = $config['endpoint'] ?? null;

        if (!$accessKey || !$secretKey || !$bucket || !$endpoint) {
            throw new \Exception("Не указаны учетные данные хранилища ОнБанкрот в конфигурации");
        }

        $providerClass = '\App\Services\ArbitratorFileStorage\Providers\OnbStorage';
        if (!class_exists($providerClass)) {
            throw new \Exception("Класс {$providerClass} не найден. Убедитесь, что классы из auapp доступны");
        }

        return new $providerClass($arbitrator, $accessKey, $secretKey, $bucket, $endpoint, $config['region'] ?? null, $config);
    }

    /**
     * Получает тип хранилища для арбитра
     */
    private function getStorageTypeForArbitrator($arbitrator): FileStorageType
    {
        $fileStorage = $arbitrator->file_storage ?? null;
        if (!$fileStorage) {
            throw new \Exception("Не настроено хранилище файлов для арбитражного управляющего");
        }

        $storageType = $fileStorage->getStorageType();
        if (!$storageType) {
            throw new \Exception("Не указан тип хранилища для арбитражного управляющего");
        }

        return $storageType;
    }

    /**
     * Получает причину недоступности провайдера для файла
     *
     * @param Model $file
     * @return string
     */
    private function getProviderUnavailableReason(Model $file): string
    {
        // Если есть сохраненная ошибка, используем её
        if ($this->lastProviderError) {
            $reason = $this->lastProviderError;
            $this->lastProviderError = null; // Очищаем после использования
            return $reason;
        }

        // Пытаемся определить причину, пытаясь создать провайдер
        try {
            $arbitrator = $file->arbitrator;
            if (!$arbitrator) {
                return "Не найден арбитражный управляющий для файла";
            }

            $providerEnum = $file->provider instanceof FileStorageType
                ? $file->provider
                : FileStorageType::tryFrom($file->provider);

            if (!$providerEnum) {
                return "Неизвестный тип провайдера: {$file->provider}";
            }

            $fileStorage = $arbitrator->file_storage ?? null;
            if (!$fileStorage) {
                return "Не настроено хранилище файлов для арбитражного управляющего";
            }

            $config = config("arbitrator_storage_providers.providers.{$providerEnum->toString()}", []);

            try {
                match ($providerEnum) {
                    FileStorageType::YANDEX_DISK => $this->createYandexProvider($fileStorage, $config),
                    FileStorageType::ONB_STORAGE => $this->createOnbProvider($arbitrator, $config),
                    default => throw new \Exception("Провайдер {$providerEnum->value} не поддерживается"),
                };
                // Если провайдер создался успешно, значит причина в другом
                return "Провайдер хранилища недоступен";
            } catch (\Exception $e) {
                // Возвращаем реальную причину ошибки
                return $e->getMessage();
            }
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * Удаляет файл из хранилища и из БД
     *
     * @param ArbitratorFileMeetingApplication $file
     * @return void
     * @throws \Exception
     */
    public function deleteFile(ArbitratorFileMeetingApplication $file): void
    {
        try {
            /** @var \App\Services\ArbitratorFileStorage\ArbitratorFileStorageInterface|null $provider */
            $provider = $this->getProviderForFile($file);

            // Если провайдер недоступен, все равно удаляем запись из БД
            if ($provider !== null && $file->remote_path) {
                try {
                    $provider->delete($file->remote_path);
                    Log::debug('File deleted from storage', [
                        'file_id' => $file->id,
                        'remote_path' => $file->remote_path,
                    ]);
                } catch (\Exception $e) {
                    // Логируем ошибку, но продолжаем удаление записи из БД
                    Log::warning('Failed to delete file from storage, but will delete DB record', [
                        'file_id' => $file->id,
                        'remote_path' => $file->remote_path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Удаляем запись из БД в любом случае
            $file->delete();

            Log::debug('File record deleted from database', [
                'file_id' => $file->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete file', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
            throw new \Exception("Ошибка удаления файла: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Удаляет все существующие файлы для приложения к собранию
     *
     * @param MeetingApplication $application
     * @return int Количество удаленных файлов
     */
    public function deleteExistingFiles(MeetingApplication $application): int
    {
        $files = $application->arbitratorFiles()->get();
        $deletedCount = 0;

        foreach ($files as $file) {
            try {
                $this->deleteFile($file);
                $deletedCount++;
            } catch (\Exception $e) {
                // Логируем ошибку, но продолжаем удаление остальных файлов
                Log::error('Failed to delete file for application', [
                    'application_id' => $application->id,
                    'file_id' => $file->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($deletedCount > 0) {
            Log::debug('Deleted existing files for application', [
                'application_id' => $application->id,
                'deleted_count' => $deletedCount,
            ]);
        }

        return $deletedCount;
    }

    /**
     * Строит путь для сохранения файла в хранилище
     * Формат: {root}/{arbitrator_uuid}/procedures/{procedure_uuid}/meeting_applications/{date}/{filename}
     */
    private function buildStoragePath(MeetingApplication $application, string $filename, FileStorageType $storageType): string
    {
        $root = config("arbitrator_storage_providers.providers.{$storageType->toString()}.root", '/onb');
        // Нормализуем root: убираем начальный слэш, добавляем в конце
        $root = '/' . ltrim($root, '/');
        $root = rtrim($root, '/');

        // Загружаем связи, если они еще не загружены
        if (!$application->relationLoaded('meeting')) {
            $application->load('meeting');
        }

        $meeting = $application->meeting;
        if (!$meeting) {
            throw new \Exception("Не удалось определить собрание для приложения");
        }

        // Загружаем процедуру из собрания
        if (!$meeting->relationLoaded('procedure')) {
            $meeting->load('procedure');
        }

        $procedure = $meeting->procedure;
        if (!$procedure) {
            throw new \Exception("Не удалось определить процедуру для собрания");
        }

        // Загружаем арбитра из процедуры
        if (!$procedure->relationLoaded('arbitrator')) {
            $procedure->load('arbitrator');
        }

        $arbitrator = $procedure->arbitrator;
        if (!$arbitrator) {
            throw new \Exception("Не удалось определить арбитражного управляющего для процедуры");
        }

        // Форматируем дату создания (как в StoragePathBuilder из auapp)
        $created = $application->created_at ?? now();
        $date = $created->format('Y_m_d');

        // Получаем ID приложения (как в StoragePathBuilder: $model->getKey())
        $idPart = $application->getKey() ?? Str::uuid();

        // Очищаем имя файла (как в StoragePathBuilder из auapp)
        $safeFilename = Str::slug(pathinfo($filename, PATHINFO_FILENAME));
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $cleanFilename = $extension ? "{$safeFilename}.{$extension}" : $safeFilename;

        // Строим путь по формату из auapp: {root}/{arbitrator_uuid}/procedures/{procedure_uuid}/meeting_applications/{date}_{id}/{filename}
        return sprintf(
            '%s/%s/procedures/%s/meeting_applications/%s_%s/%s',
            $root,
            $arbitrator->uuid,
            $procedure->uuid,
            $date,
            $idPart,
            $cleanFilename
        );
    }
}
