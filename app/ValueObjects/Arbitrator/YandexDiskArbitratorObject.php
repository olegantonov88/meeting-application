<?php

namespace App\ValueObjects\Arbitrator;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Stringable;

class YandexDiskArbitratorObject implements Jsonable, Arrayable, Stringable
{
    private ?string $accessToken = null;
    private ?string $refreshToken = null;
    private ?string $expiresAt = null;
    private ?string $clientId = null;

    public static function fromArray(?array $data): self
    {
        $instance = new self();

        if (empty($data)) {
            return $instance;
        }

        $instance->setAccessToken($data['access_token'] ?? null);
        $instance->setRefreshToken($data['refresh_token'] ?? null);
        $instance->setExpiresAt($data['expires_at'] ?? null);
        $instance->setClientId($data['client_id'] ?? null);

        return $instance;
    }

    public function setAccessToken(?string $token): void
    {
        $this->accessToken = $token ? Crypt::encryptString($token) : null;
    }

    public function getAccessToken(): ?string
    {
        return $this->accessToken ? Crypt::decryptString($this->accessToken) : null;
    }

    public function setRefreshToken(?string $token): void
    {
        $this->refreshToken = $token ? Crypt::encryptString($token) : null;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken ? Crypt::decryptString($this->refreshToken) : null;
    }

    public function setExpiresAt(null|string|\DateTimeInterface $expiresAt): void
    {
        if ($expiresAt instanceof \DateTimeInterface) {
            $this->expiresAt = $expiresAt->format(DATE_ATOM);
            return;
        }

        $this->expiresAt = $expiresAt ? (string) $expiresAt : null;
    }

    public function getExpiresAt(): ?Carbon
    {
        return $this->expiresAt ? Carbon::parse($this->expiresAt) : null;
    }

    public function isExpired(): bool
    {
        $expiresAt = $this->getExpiresAt();

        return $expiresAt ? $expiresAt->isPast() : false;
    }

    public function setClientId(?string $clientId): void
    {
        $this->clientId = $clientId ?: null;
    }

    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    public function toJson($options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    public function __toString(): string
    {
        return $this->toJson();
    }

    public function toArray(): array
    {
        return [
            'access_token' => $this->getAccessToken(),
            'refresh_token' => $this->getRefreshToken(),
            'expires_at' => $this->expiresAt,
            'client_id' => $this->getClientId(),
        ];
    }
}

