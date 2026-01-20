<?php

namespace App\Services\PdfMerger;

use App\Services\PdfMerger\Providers\FpdiPdfMerger;
use App\Services\PdfMerger\Providers\GhostscriptPdfMerger;
use Illuminate\Support\Facades\Log;

class PdfMergerService
{
    private PdfMergerInterface $provider;

    public function __construct(?PdfMergerInterface $provider = null)
    {
        $this->provider = $provider ?? $this->getDefaultProvider();
    }

    /**
     * Получает провайдер по умолчанию
     * Пытается использовать Ghostscript, если доступен, иначе FPDI
     *
     * @return PdfMergerInterface
     */
    private function getDefaultProvider(): PdfMergerInterface
    {
        try {
            $gsMerger = new GhostscriptPdfMerger();
            // Проверяем доступность Ghostscript
            $gsPath = $gsMerger->findGhostscript();

            if ($gsPath) {
                Log::debug('Using Ghostscript for PDF merging', ['gs_path' => $gsPath]);
                return $gsMerger;
            }
        } catch (\Exception $e) {
            Log::debug('Ghostscript not available, falling back to FPDI', ['error' => $e->getMessage()]);
        }

        Log::debug('Using FPDI for PDF merging');
        return new FpdiPdfMerger();
    }

    /**
     * Объединяет несколько PDF файлов в один
     * Автоматически переключается на Ghostscript, если FPDI не может обработать файлы
     *
     * @param array $filePaths Массив путей к PDF файлам для объединения
     * @param string $outputPath Путь для сохранения объединенного PDF
     * @return void
     * @throws \Exception
     */
    public function merge(array $filePaths, string $outputPath): void
    {
        try {
            $this->provider->merge($filePaths, $outputPath);
        } catch (\Exception $e) {
            // Если текущий провайдер - FPDI и возникла ошибка со сжатием,
            // пытаемся использовать Ghostscript
            if ($this->provider instanceof FpdiPdfMerger &&
                (strpos($e->getMessage(), 'compression') !== false ||
                 strpos($e->getMessage(), 'compression technique') !== false)) {

                Log::warning('FPDI failed due to compression, trying Ghostscript', [
                    'error' => $e->getMessage(),
                ]);

                try {
                    $gsMerger = new GhostscriptPdfMerger();
                    $gsMerger->merge($filePaths, $outputPath);
                    // Успешно объединили через Ghostscript, сохраняем его как провайдер
                    $this->provider = $gsMerger;
                    Log::debug('Successfully merged PDFs using Ghostscript after FPDI failure');
                    return;
                } catch (\Exception $gsError) {
                    Log::error('Ghostscript merge also failed', [
                        'gs_error' => $gsError->getMessage(),
                        'original_error' => $e->getMessage(),
                    ]);
                    throw new \Exception("Не удалось объединить PDF файлы. FPDI: {$e->getMessage()}. Ghostscript: {$gsError->getMessage()}", 0, $e);
                }
            }

            // Для других ошибок просто пробрасываем исключение
            throw $e;
        }
    }

    /**
     * Устанавливает провайдер для объединения PDF
     *
     * @param PdfMergerInterface $provider
     * @return void
     */
    public function setProvider(PdfMergerInterface $provider): void
    {
        $this->provider = $provider;
    }
}
