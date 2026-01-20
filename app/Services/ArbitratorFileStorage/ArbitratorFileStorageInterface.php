<?php

namespace App\Services\ArbitratorFileStorage;

interface ArbitratorFileStorageInterface
{
    /**
     * Скачивает файл из хранилища
     *
     * @param string $remotePath Путь к файлу в хранилище
     * @param string $localPath Локальный путь для сохранения
     * @return void
     */
    public function downloadTo(string $remotePath, string $localPath): void;

    /**
     * Загружает файл в хранилище
     *
     * @param \Illuminate\Http\UploadedFile $file Файл для загрузки
     * @param string $remotePath Путь в хранилище
     * @return array Массив с информацией о загруженном файле (например, ['path' => '...'])
     */
    public function upload(\Illuminate\Http\UploadedFile $file, string $remotePath): array;

    /**
     * Удаляет файл из хранилища
     *
     * @param string $remotePath Путь к файлу в хранилище
     * @return array{deleted: bool, not_found: bool} Результат удаления
     */
    public function delete(string $remotePath): array;
}
