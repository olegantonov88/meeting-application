<?php

namespace App\Services\Zakaznoe;

use App\Services\Zakaznoe\Exceptions\ZakaznoeBuildException;
use Illuminate\Support\Facades\File;
use ZipArchive;

class ZakaznoeArchiver
{
    /**
     * Упаковать файлы в zip, все в корень архива.
     *
     * @param  array<string, string>  $entries  имя в архиве → локальный путь
     */
    public function zip(string $path, array $entries): string
    {
        File::ensureDirectoryExists(dirname($path));

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new ZakaznoeBuildException('archive_failed', 'Не удалось создать архив.');
        }

        foreach ($entries as $name => $local) {
            if (!$zip->addFile($local, $name)) {
                $zip->close();

                throw new ZakaznoeBuildException('archive_failed', "Не удалось добавить в архив файл {$name}.");
            }

            // PDF, xlsx и zip уже сжаты - повторное сжатие только тратит время
            $zip->setCompressionName($name, ZipArchive::CM_STORE);
        }

        if (!$zip->close()) {
            throw new ZakaznoeBuildException('archive_failed', 'Не удалось записать архив.');
        }

        return $path;
    }
}
