<?php

namespace App\Services\PdfMerger;

interface PdfMergerInterface
{
    /**
     * Объединяет несколько PDF файлов в один
     *
     * @param array $filePaths Массив путей к PDF файлам для объединения
     * @param string $outputPath Путь для сохранения объединенного PDF
     * @return void
     * @throws \Exception
     */
    public function merge(array $filePaths, string $outputPath): void;
}
