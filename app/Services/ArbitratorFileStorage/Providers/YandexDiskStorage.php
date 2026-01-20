<?php

namespace App\Services\ArbitratorFileStorage\Providers;

use App\Services\ArbitratorFileStorage\Exceptions\ArbitratorFileStorageException;
use App\Services\ArbitratorFileStorage\ArbitratorFileStorageInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

class YandexDiskStorage implements ArbitratorFileStorageInterface
{
    private $client;
    private string $root;

    public function __construct(string $token, array $config = [])
    {
        $clientClass = 'Tigusigalpa\\YandexDisk\\YandexDiskClient';

        if (!class_exists($clientClass)) {
            throw new ArbitratorFileStorageException('Пакет tigusigalpa/yandex-disk-php не установлен.');
        }

        $this->client = new $clientClass($token);
        $this->root = $config['root'] ?? '/';
    }

    public function upload(UploadedFile $file, string $remotePath): array
    {
        $remote = $this->absolutePath($remotePath);
        $this->ensureDirectory(dirname($remote));

        try {
            $this->client->uploadFile($file->getRealPath(), $remote);
        } catch (Throwable $e) {
            throw new ArbitratorFileStorageException('Не удалось загрузить файл на Яндекс.Диск: '.$e->getMessage(), previous: $e);
        }

        return ['path' => $remote];
    }

    public function downloadTo(string $remotePath, string $localPath): void
    {
        try {
            $this->client->downloadFile($this->absolutePath($remotePath), $localPath);
        } catch (Throwable $e) {
            throw new ArbitratorFileStorageException('Не удалось скачать файл с Яндекс.Диска: '.$e->getMessage(), previous: $e);
        }
    }

    public function delete(string $remotePath): array
    {
        try {
            $this->client->delete($this->absolutePath($remotePath));
            return ['deleted' => true, 'not_found' => false];
        } catch (Throwable $e) {
            $code = method_exists($e, 'getCode') ? (int) $e->getCode() : 0;
            $message = $e->getMessage();

            // Если файл не найден (404), возвращаем информацию об этом
            if ($code === 404 || str_contains($message, 'not found') || str_contains($message, 'Not Found')) {
                return ['deleted' => false, 'not_found' => true];
            }

            throw new ArbitratorFileStorageException('Не удалось удалить файл на Яндекс.Диске: '.$e->getMessage(), previous: $e);
        }
    }

    public function list(string $path): array
    {
        try {
            return $this->client->listResources($this->absolutePath($path));
        } catch (Throwable $e) {
            throw new ArbitratorFileStorageException('Не удалось получить список файлов на Яндекс.Диске: '.$e->getMessage(), previous: $e);
        }
    }

    public function publish(string $remotePath): ?string
    {
        try {
            $result = $this->client->publish($this->absolutePath($remotePath));
        } catch (Throwable $e) {
            throw new ArbitratorFileStorageException('Не удалось опубликовать файл на Яндекс.Диске: '.$e->getMessage(), previous: $e);
        }

        return $result['public_url'] ?? null;
    }

    /**
     * Получает метаданные ресурса (папки или файла), включая размер
     *
     * @param string $path Путь к ресурсу
     * @return array Метаданные ресурса, включая поле 'size' для папок
     * @throws ArbitratorFileStorageException
     */
    public function getResourceMeta(string $path): array
    {
        try {
            $absolutePath = $this->absolutePath($path);

            // Используем метод getMeta() согласно документации библиотеки
            // https://github.com/tigusigalpa/yandex-disk-php
            if (method_exists($this->client, 'getMeta')) {
                return $this->client->getMeta($absolutePath);
            }

            // Fallback: проверяем другие возможные методы
            if (method_exists($this->client, 'getResourceMeta')) {
                return $this->client->getResourceMeta($absolutePath);
            }

            // Если методов нет, пробуем использовать list() как последний вариант
            $result = $this->list($path);

            // Если это массив с метаданными папки (обычно есть поле 'name' и 'type')
            if (isset($result['name']) && isset($result['type']) && $result['type'] === 'dir') {
                return $result;
            }

            // Если структура другая, возвращаем как есть
            return $result;
        } catch (Throwable $e) {
            Log::error('Ошибка получения метаданных ресурса Яндекс.Диска', [
                'path' => $path,
                'absolute_path' => $this->absolutePath($path),
                'error_message' => $e->getMessage(),
                'error_code' => method_exists($e, 'getCode') ? $e->getCode() : 0,
                'exception_class' => get_class($e),
            ]);
            throw new ArbitratorFileStorageException('Не удалось получить метаданные ресурса: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * Получает размер папки арбитражного управляющего
     *
     * @param string $arbitratorUuid UUID арбитражного управляющего
     * @return int Размер папки в байтах, или 0 если не удалось получить
     */
    public function getArbitratorFolderSize(string $arbitratorUuid): int
    {
        try {
            // Путь к папке арбитражного управляющего: {root}/{uuid}
            $folderPath = $arbitratorUuid;
            $meta = $this->getResourceMeta($folderPath);

            // API возвращает размер в поле 'size'
            return (int) ($meta['size'] ?? 0);
        } catch (Throwable $e) {
            Log::warning('Не удалось получить размер папки арбитражного управляющего из Яндекс.Диска', [
                'arbitrator_uuid' => $arbitratorUuid,
                'error_message' => $e->getMessage(),
            ]);
            // Возвращаем 0, чтобы можно было использовать fallback
            return 0;
        }
    }

    /**
     * Получает информацию о диске (общий объем, занятое место, свободное место)
     *
     * @return array{total: int, used: int, available_space: int}
     * @throws ArbitratorFileStorageException
     */
    public function getCapacity(): array
    {
        try {
            $diskData = $this->client->getCapacity();

            // Данные приходят в формате с ключами total_space, used_space
            // Пробуем использовать DiskInfo, если он есть, иначе работаем напрямую с массивом
            $diskInfoClass = 'Tigusigalpa\\YandexDisk\\DiskInfo';

            if (class_exists($diskInfoClass)) {
                // Если класс DiskInfo существует, используем его
                $diskInfo = $diskInfoClass::fromArray($diskData);
                $total = $diskInfo->getTotalSpace();
                $used = $diskInfo->getUsedSpace();
                $free = $diskInfo->getFreeSpace();
            } else {
                // Если класса нет, работаем напрямую с массивом
                // API возвращает: total_space, used_space, trash_size
                $total = $diskData['total_space'] ?? 0;
                $used = $diskData['used_space'] ?? 0;
                // Свободное место = общий объем - занятое место
                $free = $total - $used;
            }

            return [
                'total' => (int) $total,
                'used' => (int) $used,
                'available_space' => (int) $free,
            ];
        } catch (Throwable $e) {
            // Логируем ошибку для отслеживания проблем
            Log::error('Ошибка получения информации о диске Яндекс.Диска в YandexDiskStorage', [
                'error_message' => $e->getMessage(),
                'error_code' => method_exists($e, 'getCode') ? $e->getCode() : 0,
                'exception_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new ArbitratorFileStorageException('Не удалось получить информацию о диске Яндекс.Диска: '.$e->getMessage(), previous: $e);
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
        $parts = array_filter(explode('/', $path));
        $current = '';

        foreach ($parts as $part) {
            $current .= '/'.$part;

            try {
                $this->client->createFolder($current);
            } catch (Throwable $e) {
                $message = $e->getMessage();
                $code = method_exists($e, 'getCode') ? (int) $e->getCode() : 0;
                // createFolder может вернуть 409, если папка уже есть — игнорируем любые 409
                if ($code === 409) {
                    continue;
                }
                // fallback по тексту, если код не 409
                if (str_contains($message, 'already exists') || str_contains($message, 'points to existent directory')) {
                    continue;
                }

                throw new ArbitratorFileStorageException('Не удалось создать папку на Яндекс.Диске: '.$e->getMessage(), previous: $e);
            }
        }
    }
}
