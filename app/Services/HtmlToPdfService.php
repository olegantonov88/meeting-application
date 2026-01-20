<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Log;

class HtmlToPdfService
{
    /**
     * Генерирует PDF файл из HTML строки
     *
     * @param string $html HTML содержимое
     * @param string $outputPath Путь для сохранения PDF файла
     * @param array $options Дополнительные опции для Dompdf
     * @param string|null $title Заголовок сообщения ЕФРСБ для вставки
     * @return void
     * @throws \Exception
     */
    public function generate(string $html, string $outputPath, array $options = [], ?string $title = null): void
    {
        try {
            $dompdfOptions = new Options();
            $dompdfOptions->set('isHtml5ParserEnabled', true);
            $dompdfOptions->set('isRemoteEnabled', true);
            $dompdfOptions->set('isPhpEnabled', false);
            $dompdfOptions->set('defaultFont', 'DejaVu Sans');

            // Устанавливаем размер страницы A4 по умолчанию
            $dompdfOptions->set('defaultPaperSize', 'a4');
            $dompdfOptions->set('defaultPaperOrientation', 'portrait');

            // Устанавливаем DPI для правильного масштабирования (96 DPI - стандарт для веб)
            $dompdfOptions->set('dpi', 96);

            // Применяем дополнительные опции (переопределяют значения по умолчанию)
            if (isset($options['paperSize'])) {
                $dompdfOptions->set('defaultPaperSize', $options['paperSize']);
            }
            if (isset($options['paperOrientation'])) {
                $dompdfOptions->set('defaultPaperOrientation', $options['paperOrientation']);
            }
            if (isset($options['dpi'])) {
                $dompdfOptions->set('dpi', $options['dpi']);
            }

            // Если HTML не содержит полную структуру документа, оборачиваем его
            $processedHtml = $this->prepareHtml($html, $title);

            $dompdf = new Dompdf($dompdfOptions);
            $dompdf->loadHtml($processedHtml, 'UTF-8');
            $dompdf->render();

            // Создаем директорию, если её нет
            $directory = dirname($outputPath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            // Сохраняем PDF в файл
            file_put_contents($outputPath, $dompdf->output());

            Log::debug('PDF generated successfully', [
                'output_path' => $outputPath,
                'size' => filesize($outputPath),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to generate PDF from HTML', [
                'output_path' => $outputPath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \Exception("Ошибка генерации PDF: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Формирует HTML-таблицу с заголовком сообщения ЕФРСБ
     *
     * @param string $title Заголовок сообщения
     * @return string HTML-таблица с заголовком
     */
    private function insertMessageTitle(string $title): string
    {
        $escapedTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<table cellspacing="10" cellpadding="0" width="100%">
    <tbody><tr>
        <td style="border-bottom: #005993 2px Solid">
            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                <tbody><tr>
                    <td>
                        <h1 class="red_small">
                            {$escapedTitle}
                        </h1>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" class="primary">
                        <div class="message_notarity_info">

                        </div>
                    </td>
                </tr>
            </tbody></table>
        </td>
    </tr>
</tbody></table>
HTML;
    }

    /**
     * Подготавливает HTML для генерации PDF
     * Оборачивает фрагмент HTML в полную структуру документа, если необходимо
     *
     * @param string $html
     * @param string|null $title Заголовок сообщения ЕФРСБ для вставки
     * @return string
     */
    private function prepareHtml(string $html, ?string $title = null): string
    {
        // Убираем лишние пробелы
        $html = trim($html);

        // CSS стили для исправления проблем с таблицами и шириной
        $fixStyles = <<<CSS
        @page {
            size: A4;
            margin-top: 1cm;
            margin-bottom: 1cm;
            margin-left: 1.5cm;
            margin-right: 1.5cm;
        }
        body {
            margin: 0;
            padding: 0;
            max-width: 100%;
            overflow-x: hidden;
        }
        * {
            box-sizing: border-box;
        }
        /* Исправление таблиц с фиксированной шириной */
        table {
            max-width: 100% !important;
            width: auto !important;
        }
        table[style*="width"] {
            width: 100% !important;
            max-width: 100% !important;
        }
        /* Исправление div с фиксированной шириной */
        div[style*="width"] {
            max-width: 100% !important;
        }
        div.msg[style*="width"] {
            width: 100% !important;
            max-width: 100% !important;
        }
        /* Обеспечиваем, что контент не выходит за границы */
        .containerInfo {
            max-width: 100%;
            overflow-x: hidden;
        }
        /* Стили для заголовка сообщения ЕФРСБ */
        h1.red_small {
            font-size: 90%;
            font-weight: bold;
            color: #C82F10;
            margin-top: 0px;
            margin-bottom: 10px;
        }
CSS;

        // Проверяем, содержит ли HTML полную структуру документа
        $hasFullStructure = stripos($html, '<!DOCTYPE') !== false || stripos($html, '<html') !== false;

        // Если HTML уже содержит полную структуру документа, добавляем стили в head
        if ($hasFullStructure) {
            // Пытаемся добавить стили в существующий head
            if (stripos($html, '</head>') !== false) {
                // Вставляем стили перед закрывающим тегом head
                $html = str_ireplace('</head>', "<style>{$fixStyles}</style></head>", $html);
            } elseif (stripos($html, '<head>') !== false) {
                // Если есть открывающий head, но нет закрывающего, добавляем стили после открывающего
                $html = str_ireplace('<head>', "<head><style>{$fixStyles}</style>", $html);
            } else {
                // Если нет head, добавляем его перед body
                if (stripos($html, '<body') !== false) {
                    $html = str_ireplace('<body', "<head><style>{$fixStyles}</style></head><body", $html);
                } else {
                    // Если нет ни head, ни body, оборачиваем в полную структуру
                    $bodyContent = $html;
                    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>{$fixStyles}</style>
</head>
<body>
{$bodyContent}
</body>
</html>
HTML;
                }
            }
            return $html;
        }

        // HTML не содержит полную структуру - оборачиваем в полную структуру документа
        // Если передан title, вставляем заголовок перед содержимым
        $titleHtml = '';
        if (!empty($title)) {
            $titleHtml = $this->insertMessageTitle($title);
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>{$fixStyles}</style>
</head>
<body>
{$titleHtml}
{$html}
</body>
</html>
HTML;
    }
}
