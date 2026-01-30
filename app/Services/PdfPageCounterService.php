<?php

namespace App\Services;

use setasign\Fpdi\Fpdi;
use App\Services\PdfMerger\Providers\GhostscriptPdfMerger;
use Illuminate\Support\Facades\Log;

class PdfPageCounterService
{
    /**
     * Подсчитывает количество страниц в PDF файле
     * Пытается использовать несколько методов: FPDI, Ghostscript, простой парсинг
     *
     * @param string $filePath Путь к PDF файлу
     * @return int Количество страниц
     * @throws \Exception
     */
    public function countPages(string $filePath): int
    {
        if (!file_exists($filePath)) {
            throw new \Exception("PDF файл не найден: {$filePath}");
        }

        // Метод 1: Пытаемся использовать FPDI (быстрый метод)
        $pdf = null;
        try {
            $pdf = new Fpdi();
            $pageCount = $pdf->setSourceFile($filePath);

            Log::debug('PDF pages counted using FPDI', [
                'file_path' => $filePath,
                'page_count' => $pageCount,
            ]);

            return $pageCount;
        } catch (\Exception $e) {
            Log::debug('FPDI failed to count pages, trying alternative methods', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);
        } finally {
            if ($pdf !== null) {
                unset($pdf);
            }
            if (gc_enabled()) {
                gc_collect_cycles();
            }
        }

        // Метод 2: Пытаемся использовать Ghostscript (если доступен)
        try {
            $pageCount = $this->countPagesWithGhostscript($filePath);

            Log::debug('PDF pages counted using Ghostscript', [
                'file_path' => $filePath,
                'page_count' => $pageCount,
            ]);

            return $pageCount;
        } catch (\Exception $e) {
            Log::debug('Ghostscript failed to count pages, trying simple parsing', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);
        }

        // Метод 3: Простой парсинг PDF (fallback)
        try {
            $pageCount = $this->countPagesWithSimpleParsing($filePath);

            Log::debug('PDF pages counted using simple parsing', [
                'file_path' => $filePath,
                'page_count' => $pageCount,
            ]);

            return $pageCount;
        } catch (\Exception $e) {
            Log::error('All methods failed to count PDF pages', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);
            throw new \Exception("Ошибка подсчета страниц PDF: все методы не смогли обработать файл. Последняя ошибка: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Подсчитывает страницы используя Ghostscript
     * Использует более простой подход через вывод информации о PDF
     *
     * @param string $filePath
     * @return int
     * @throws \Exception
     */
    private function countPagesWithGhostscript(string $filePath): int
    {
        $gsMerger = new GhostscriptPdfMerger();
        $gsPath = $gsMerger->findGhostscript();

        if (!$gsPath) {
            throw new \Exception('Ghostscript не найден');
        }

        // Используем более простой способ - через вывод информации о PDF
        // Команда: gs -q -dNODISPLAY -c "($filePath) (r) file runpdfbegin pdfpagecount ="
        // Но нужно правильно экранировать путь

        // Нормализуем путь (заменяем обратные слеши на прямые для PostScript)
        $normalizedPath = str_replace('\\', '/', $filePath);

        // Создаем временный файл с PostScript командой
        $tempDir = sys_get_temp_dir();
        $tempPsFile = $tempDir . DIRECTORY_SEPARATOR . 'gs_count_' . uniqid() . '.ps';

        // Формируем PostScript команду
        // Экранируем скобки в пути, если они есть
        $escapedPath = str_replace(['(', ')'], ['\\(', '\\)'], $normalizedPath);
        $psCommand = "($escapedPath) (r) file runpdfbegin pdfpagecount = quit\n";

        file_put_contents($tempPsFile, $psCommand);

        try {
            // Выполняем команду Ghostscript
            $command = escapeshellarg($gsPath) . ' -q -dNODISPLAY ' . escapeshellarg($tempPsFile) . ' 2>&1';

            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);

            // Удаляем временный файл
            @unlink($tempPsFile);

            if ($returnCode !== 0) {
                $errorMessage = implode("\n", $output);
                // Если это не критическая ошибка, пробуем извлечь число из вывода
                if (preg_match('/(\d+)/', $errorMessage, $matches)) {
                    $num = (int)$matches[1];
                    if ($num > 0) {
                        return $num;
                    }
                }
                throw new \Exception("Ghostscript error: {$errorMessage}");
            }

            // Ищем число в выводе
            $outputText = implode("\n", $output);
            if (preg_match_all('/(\d+)/', $outputText, $matches)) {
                $numbers = $matches[1];
                // Берем последнее число, которое больше 0
                for ($i = count($numbers) - 1; $i >= 0; $i--) {
                    $num = (int)$numbers[$i];
                    if ($num > 0 && $num < 100000) { // Разумный лимит для количества страниц
                        return $num;
                    }
                }
            }

            throw new \Exception('Не удалось извлечь количество страниц из вывода Ghostscript');
        } catch (\Exception $e) {
            // Удаляем временный файл в случае ошибки
            @unlink($tempPsFile);
            throw $e;
        }
    }

    /**
     * Подсчитывает страницы используя простой парсинг PDF
     * Ищет /Count в объектах страниц
     *
     * @param string $filePath
     * @return int
     * @throws \Exception
     */
    private function countPagesWithSimpleParsing(string $filePath): int
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            throw new \Exception('Не удалось прочитать файл');
        }

        // Метод 1: Ищем /Count в объектах страниц
        // Паттерн: /Count\s+(\d+)
        if (preg_match('/\/Count\s+(\d+)/', $content, $matches)) {
            $count = (int)$matches[1];
            if ($count > 0) {
                return $count;
            }
        }

        // Метод 2: Ищем /Type\s*\/Page[^s] в содержимом (каждая страница имеет этот маркер)
        $pageMatches = preg_match_all('/\/Type\s*\/Page[^s]/', $content);
        if ($pageMatches > 0) {
            return $pageMatches;
        }

        // Метод 3: Ищем /Page\s+(\d+)\s+0\s+R (ссылки на страницы)
        if (preg_match_all('/\/Page\s+\d+\s+0\s+R/', $content, $matches)) {
            return count($matches[0]);
        }

        throw new \Exception('Не удалось определить количество страниц простым парсингом');
    }
}
