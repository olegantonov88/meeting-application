<?php

namespace App\Services\PdfMerger\Providers;

use App\Services\PdfMerger\PdfMergerInterface;
use Illuminate\Support\Facades\Log;

class GhostscriptPdfMerger implements PdfMergerInterface
{
    /**
     * Объединяет несколько PDF файлов в один используя Ghostscript
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

        Log::debug('GhostscriptPdfMerger: Starting merge', [
            'output_path' => $outputPath,
            'files_count' => count($filePaths),
            'files' => $filePaths,
        ]);

        // Проверяем наличие Ghostscript
        $gsPath = $this->findGhostscript();
        if (!$gsPath) {
            throw new \Exception('Ghostscript не найден. Установите Ghostscript для объединения PDF файлов.');
        }

        // Проверяем существование всех файлов
        foreach ($filePaths as $index => $filePath) {
            if (!file_exists($filePath)) {
                Log::warning('PDF file not found, skipping', [
                    'file' => $filePath,
                    'index' => $index,
                ]);
                unset($filePaths[$index]);
            }
        }

        if (empty($filePaths)) {
            throw new \Exception('Не найдено ни одного файла для объединения');
        }

        // Создаем директорию, если её нет
        $directory = dirname($outputPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
            Log::debug('Created directory for merged PDF', ['directory' => $directory]);
        }

        // Формируем команду Ghostscript
        // Используем параметры для объединения PDF
        $command = escapeshellarg($gsPath) . ' -dBATCH -dNOPAUSE -q -sDEVICE=pdfwrite -dPDFSETTINGS=/prepress -sOutputFile=' . escapeshellarg($outputPath) . ' ';

        // Добавляем все файлы
        foreach ($filePaths as $filePath) {
            $command .= escapeshellarg($filePath) . ' ';
        }

        Log::debug('Executing Ghostscript command', [
            'command' => $command,
        ]);

        // Выполняем команду
        $output = [];
        $returnCode = 0;
        exec($command . ' 2>&1', $output, $returnCode);

        if ($returnCode !== 0) {
            $errorMessage = implode("\n", $output);
            Log::error('Ghostscript merge failed', [
                'output_path' => $outputPath,
                'return_code' => $returnCode,
                'error' => $errorMessage,
                'command' => $command,
            ]);
            throw new \Exception("Ошибка объединения PDF файлов через Ghostscript: {$errorMessage}");
        }

        // Проверяем, что файл создан
        if (!file_exists($outputPath)) {
            throw new \Exception("Объединенный PDF файл не был создан: {$outputPath}");
        }

        $outputFileSize = filesize($outputPath);
        Log::debug('PDF files merged successfully using Ghostscript', [
            'output_path' => $outputPath,
            'output_file_size' => $outputFileSize,
            'files_merged_count' => count($filePaths),
        ]);
    }

    /**
     * Ищет путь к исполняемому файлу Ghostscript
     *
     * @return string|null
     */
    public function findGhostscript(): ?string
    {
        // Возможные пути к Ghostscript
        $possiblePaths = [
            'gs', // В PATH
            'gswin64c.exe', // Windows 64-bit
            'gswin32c.exe', // Windows 32-bit
            'C:\\Program Files\\gs\\gs*\\bin\\gswin64c.exe',
            'C:\\Program Files (x86)\\gs\\gs*\\bin\\gswin32c.exe',
            '/usr/bin/gs', // Linux
            '/usr/local/bin/gs', // Linux
        ];

        // Проверяем стандартные пути
        foreach ($possiblePaths as $path) {
            if (strpos($path, '*') !== false) {
                // Ищем по шаблону (для Windows с версией)
                $globPaths = glob($path);
                if (!empty($globPaths)) {
                    $testPath = $globPaths[0];
                    if ($this->isExecutable($testPath)) {
                        return $testPath;
                    }
                }
            } else {
                if ($this->isExecutable($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * Проверяет, является ли файл исполняемым и доступен ли он
     *
     * @param string $path
     * @return bool
     */
    private function isExecutable(string $path): bool
    {
        // Проверяем через which/where (в зависимости от ОС)
        if (PHP_OS_FAMILY === 'Windows') {
            $command = 'where ' . escapeshellarg($path) . ' 2>nul';
        } else {
            $command = 'which ' . escapeshellarg($path) . ' 2>/dev/null';
        }

        exec($command, $output, $returnCode);

        if ($returnCode === 0 && !empty($output)) {
            return file_exists(trim($output[0]));
        }

        // Если which/where не сработал, проверяем напрямую
        if (file_exists($path)) {
            // Проверяем, что это исполняемый файл
            if (PHP_OS_FAMILY === 'Windows') {
                return is_file($path) && (pathinfo($path, PATHINFO_EXTENSION) === 'exe' || pathinfo($path, PATHINFO_EXTENSION) === 'bat');
            } else {
                return is_executable($path);
            }
        }

        return false;
    }
}
