<?php

namespace App\ValueObjects\Arbitrator;

use App\Enums\Arbitrator\FileStorageType;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Stringable;

class FileStorageArbitratorObject implements Jsonable, Arrayable, Stringable
{
    private ?FileStorageType $storageType = null;
    private ?string $yandexDiskAccessToken = null;
    private ?string $yandexDiskRefreshToken = null;
    private ?string $yandexDiskExpiresAt = null;
    private ?string $yandexDiskClientId = null;
    private ?string $subscriptionExpiresAt = null;
    private ?int $onbStorageTotal = null;

    // Константа размера хранилища ОнБанкрот по умолчанию: 50 GB в байтах
    private const DEFAULT_ONB_STORAGE_TOTAL = 50 * 1024 * 1024 * 1024; // 53687091200

    public static function fromArray(?array $data): self
    {
        $instance = new self();

        if (empty($data)) {
            return $instance;
        }

        if (isset($data['storage_type'])) {
            $instance->setStorageType($data['storage_type']);
        }
        $instance->setSubscriptionExpiresAt($data['subscription_expires_at'] ?? null);
        $instance->setOnbStorageTotal($data['onb_storage_total'] ?? null);

        if (isset($data['yandex_disk']) && is_array($data['yandex_disk'])) {
            $instance->yandexDiskClientId = $data['yandex_disk']['client_id'] ?? null;
            // При чтении из БД токены уже зашифрованы, сохраняем как есть
            $instance->yandexDiskAccessToken = $data['yandex_disk']['access_token'] ?? null;
            $instance->yandexDiskRefreshToken = $data['yandex_disk']['refresh_token'] ?? null;
            $instance->yandexDiskExpiresAt = $data['yandex_disk']['expires_at'] ?? null;
        }

        return $instance;
    }

    public function setStorageType(null|string|FileStorageType $storageType): void
    {
        if ($storageType === null || $storageType === '') {
            $this->storageType = null;
            return;
        }

        if ($storageType instanceof FileStorageType) {
            $this->storageType = $storageType;
            return;
        }

        // Если передана строка, пытаемся найти соответствующий Enum
        // Сначала пробуем через fromString (для строковых значений 'yandex_disk', 'onb_storage')
        $this->storageType = FileStorageType::fromString($storageType);

        // Если не найдено через fromString, пробуем через tryFrom (для integer значений)
        if ($this->storageType === null) {
            $this->storageType = FileStorageType::tryFrom((int) $storageType);
        }

        if ($this->storageType === null) {
            throw new \InvalidArgumentException("Недопустимый тип хранилища: {$storageType}. Разрешены: " . implode(', ', array_map(fn($case) => $case->toString(), FileStorageType::cases())));
        }
    }

    public function getStorageType(): ?FileStorageType
    {
        return $this->storageType;
    }

    public function setYandexDiskAccessToken(?string $token): void
    {
        $this->yandexDiskAccessToken = $token ? Crypt::encryptString($token) : null;
    }

    public function getYandexDiskAccessToken(): ?string
    {
        return $this->yandexDiskAccessToken ? Crypt::decryptString($this->yandexDiskAccessToken) : null;
    }

    public function setYandexDiskRefreshToken(?string $token): void
    {
        $this->yandexDiskRefreshToken = $token ? Crypt::encryptString($token) : null;
    }

    public function getYandexDiskRefreshToken(): ?string
    {
        return $this->yandexDiskRefreshToken ? Crypt::decryptString($this->yandexDiskRefreshToken) : null;
    }

    public function setYandexDiskExpiresAt(null|string|\DateTimeInterface $expiresAt): void
    {
        if ($expiresAt instanceof \DateTimeInterface) {
            $this->yandexDiskExpiresAt = $expiresAt->format(DATE_ATOM);
            return;
        }

        $this->yandexDiskExpiresAt = $expiresAt ? (string) $expiresAt : null;
    }

    public function getYandexDiskExpiresAt(): ?Carbon
    {
        return $this->yandexDiskExpiresAt ? Carbon::parse($this->yandexDiskExpiresAt) : null;
    }

    public function isYandexDiskExpired(): bool
    {
        $expiresAt = $this->getYandexDiskExpiresAt();

        return $expiresAt ? $expiresAt->isPast() : false;
    }

    public function setYandexDiskClientId(?string $clientId): void
    {
        $this->yandexDiskClientId = $clientId ?: null;
    }

    public function getYandexDiskClientId(): ?string
    {
        return $this->yandexDiskClientId;
    }

    public function setSubscriptionExpiresAt(null|string|\DateTimeInterface $expiresAt): void
    {
        if ($expiresAt instanceof \DateTimeInterface) {
            $this->subscriptionExpiresAt = $expiresAt->format(DATE_ATOM);
            return;
        }

        $this->subscriptionExpiresAt = $expiresAt ? (string) $expiresAt : null;
    }

    public function getSubscriptionExpiresAt(): ?Carbon
    {
        return $this->subscriptionExpiresAt ? Carbon::parse($this->subscriptionExpiresAt) : null;
    }

    public function isSubscriptionExpired(): bool
    {
        $expiresAt = $this->getSubscriptionExpiresAt();

        return $expiresAt ? $expiresAt->isPast() : false;
    }

    public function setOnbStorageTotal(?int $total): void
    {
        $this->onbStorageTotal = $total;
    }

    public function getOnbStorageTotal(): int
    {
        return $this->onbStorageTotal ?? self::DEFAULT_ONB_STORAGE_TOTAL;
    }

    public function toJson($options = 0): string
    {
        return json_encode($this->toStorageArray(), $options);
    }

    public function __toString(): string
    {
        return $this->toJson();
    }

    public function toArray(): array
    {
        $array = [
            'storage_type' => $this->storageType?->value,
        ];

        $yandexDiskData = [];
        if ($this->yandexDiskClientId !== null) {
            $yandexDiskData['client_id'] = $this->yandexDiskClientId;
        }
        if ($this->yandexDiskAccessToken !== null) {
            $yandexDiskData['access_token'] = $this->getYandexDiskAccessToken();
        }
        if ($this->yandexDiskRefreshToken !== null) {
            $yandexDiskData['refresh_token'] = $this->getYandexDiskRefreshToken();
        }
        if ($this->yandexDiskExpiresAt !== null) {
            $yandexDiskData['expires_at'] = $this->yandexDiskExpiresAt;
        }

        if (!empty($yandexDiskData)) {
            $array['yandex_disk'] = $yandexDiskData;
        }

        if ($this->subscriptionExpiresAt) {
            $array['subscription_expires_at'] = $this->subscriptionExpiresAt;
        }

        if ($this->onbStorageTotal !== null) {
            $array['onb_storage_total'] = $this->onbStorageTotal;
        }

        return $array;
    }

    public function toStorageArray(): array
    {
        $array = [
            'storage_type' => $this->storageType?->value,
        ];

        $yandexDiskData = [];
        if ($this->yandexDiskClientId !== null) {
            $yandexDiskData['client_id'] = $this->yandexDiskClientId;
        }
        if ($this->yandexDiskAccessToken !== null) {
            $yandexDiskData['access_token'] = $this->yandexDiskAccessToken;
        }
        if ($this->yandexDiskRefreshToken !== null) {
            $yandexDiskData['refresh_token'] = $this->yandexDiskRefreshToken;
        }
        if ($this->yandexDiskExpiresAt !== null) {
            $yandexDiskData['expires_at'] = $this->yandexDiskExpiresAt;
        }

        if (!empty($yandexDiskData)) {
            $array['yandex_disk'] = $yandexDiskData;
        }

        if ($this->subscriptionExpiresAt) {
            $array['subscription_expires_at'] = $this->subscriptionExpiresAt;
        }

        if ($this->onbStorageTotal !== null) {
            $array['onb_storage_total'] = $this->onbStorageTotal;
        }

        return $array;
    }
}

