<?php

namespace App\Services\PdfMerger\Providers;

use App\Services\PdfMerger\PdfMergerInterface;
use setasign\Fpdi\Fpdi;
use Illuminate\Support\Facades\Log;

class FpdiPdfMerger implements PdfMergerInterface
{
    /**
     * Объединяет несколько PDF файлов в один используя FPDI
     *
     * @param array $filePaths Массив путей к PDF файлам для объединения
     * @param string $outputPath Путь для сохранения объединенного PDF
     * @return void
     * @throws \Exception
     */
    public function merge(array $filePaths, string $outputPath): void
    {
        if (empty($filePaths)) {
            throw new \Exception('Не указаны файлы для объединения');
        }

        Log::debug('FpdiPdfMerger: Starting merge', [
            'output_path' => $outputPath,
            'files_count' => count($filePaths),
            'files' => $filePaths,
        ]);

        try {
            $pdf = new Fpdi();
            $totalPages = 0;
            $processedFiles = 0;

            foreach ($filePaths as $index => $filePath) {
                if (!file_exists($filePath)) {
                    Log::warning('PDF file not found, skipping', [
                        'file' => $filePath,
                        'index' => $index,
                    ]);
                    continue;
                }

                $fileSize = filesize($filePath);
                Log::debug('Processing PDF file for merge', [
                    'file' => $filePath,
                    'index' => $index,
                    'file_size' => $fileSize,
                ]);

                try {
                    $pageCount = $pdf->setSourceFile($filePath);
                    Log::debug('PDF file opened', [
                        'file' => $filePath,
                        'page_count' => $pageCount,
                    ]);

                    for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                        $templateId = $pdf->importPage($pageNo);
                        $size = $pdf->getTemplateSize($templateId);

                        // Добавляем страницу с ориентацией из исходного файла
                        $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
                        $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                        $pdf->useTemplate($templateId);
                        $totalPages++;
                    }

                    $processedFiles++;
                    Log::debug('PDF file processed successfully', [
                        'file' => $filePath,
                        'pages_added' => $pageCount,
                    ]);
                } catch (\Exception $e) {
                    // Проверяем, является ли это ошибкой сжатия
                    $isCompressionError = strpos($e->getMessage(), 'compression technique') !== false
                        || strpos($e->getMessage(), 'compression') !== false;

                    if ($isCompressionError) {
                        Log::error('PDF file uses unsupported compression, cannot merge with FPDI', [
                            'file' => $filePath,
                            'index' => $index,
                            'error' => $e->getMessage(),
                        ]);
                        // Для файлов с неподдерживаемым сжатием выбрасываем исключение,
                        // чтобы можно было переключиться на другой провайдер
                        throw new \Exception("PDF файл использует неподдерживаемое сжатие: {$filePath}. Ошибка: {$e->getMessage()}", 0, $e);
                    }

                    Log::error('Failed to process PDF file', [
                        'file' => $filePath,
                        'index' => $index,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    // Продолжаем обработку остальных файлов для других ошибок
                    continue;
                }
            }

            if ($processedFiles === 0) {
                throw new \Exception('Не удалось обработать ни одного PDF файла для объединения');
            }

            // Создаем директорию, если её нет
            $directory = dirname($outputPath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
                Log::debug('Created directory for merged PDF', ['directory' => $directory]);
            }

            // Сохраняем объединенный PDF
            Log::debug('Saving merged PDF', [
                'output_path' => $outputPath,
                'total_pages' => $totalPages,
                'processed_files' => $processedFiles,
            ]);

            $pdf->Output('F', $outputPath);

            // Проверяем, что файл создан
            if (!file_exists($outputPath)) {
                throw new \Exception("Объединенный PDF файл не был создан: {$outputPath}");
            }

            $outputFileSize = filesize($outputPath);
            Log::debug('PDF files merged successfully', [
                'output_path' => $outputPath,
                'output_file_size' => $outputFileSize,
                'total_pages' => $totalPages,
                'processed_files' => $processedFiles,
                'total_files' => count($filePaths),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to merge PDF files', [
                'output_path' => $outputPath,
                'files' => $filePaths,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \Exception("Ошибка объединения PDF файлов: {$e->getMessage()}", 0, $e);
        }
    }
}
