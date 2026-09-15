<?php

namespace App\Services\Zakaznoe;

use App\Enums\Arbitrator\FileStorageType;
use App\Models\Arbitrator\Arbitrator;
use App\Services\ArbitratorFileStorage\ArbitratorFileStorageInterface;
use App\Services\ArbitratorFileStorageService;
use App\Services\Zakaznoe\Exceptions\ZakaznoeBuildException;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Хранилище управляющего для сборки: файлы по пути, без записей в базе CRM.
 *
 * Записи о файлах заводит CRM: вложения уже её, архив она зарегистрирует сама,
 * когда примет completed.
 */
class ZakaznoeStorage
{
    /** @var array<string, ArbitratorFileStorageInterface> */
    private array $providers = [];

    public function __construct(private readonly ArbitratorFileStorageService $files)
    {
    }

    public function download(int $arbitratorId, string $provider, string $remotePath, string $localPath): void
    {
        $this->provider($arbitratorId, $provider)->downloadTo($remotePath, $localPath);
    }

    public function upload(int $arbitratorId, string $provider, string $localPath, string $remotePath): void
    {
        $file = new UploadedFile($localPath, basename($remotePath), 'application/zip', null, true);

        try {
            $this->provider($arbitratorId, $provider)->upload($file, $remotePath);
        } catch (ZakaznoeBuildException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ZakaznoeBuildException('storage_error', 'Не удалось сохранить архив в хранилище: '.$e->getMessage(), $e);
        }
    }

    public function delete(int $arbitratorId, string $provider, string $remotePath): void
    {
        $this->provider($arbitratorId, $provider)->delete($remotePath);
    }

    private function provider(int $arbitratorId, string $provider): ArbitratorFileStorageInterface
    {
        return $this->providers["{$arbitratorId}:{$provider}"] ??= $this->makeProvider($arbitratorId, $provider);
    }

    private function makeProvider(int $arbitratorId, string $provider): ArbitratorFileStorageInterface
    {
        $type = FileStorageType::fromString($provider)
            ?? throw new ZakaznoeBuildException('storage_error', "Неизвестное хранилище: {$provider}.");

        // Модель управляющего без своего подключения: напрямую - только через базу CRM
        $arbitrator = Arbitrator::on('auapp')->find($arbitratorId)
            ?? throw new ZakaznoeBuildException('storage_error', 'Арбитражный управляющий не найден.');

        try {
            return $this->files->providerFor($type, $arbitrator);
        } catch (Throwable $e) {
            throw new ZakaznoeBuildException('storage_error', $e->getMessage(), $e);
        }
    }
}
