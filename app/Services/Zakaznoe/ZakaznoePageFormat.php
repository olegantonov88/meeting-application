<?php

namespace App\Services\Zakaznoe;

use App\Services\PdfMerger\Providers\GhostscriptPdfMerger;
use App\Services\Zakaznoe\Exceptions\ZakaznoeBuildException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Формат страниц для zakaznoe.pochta.ru: сайт принимает только книжный A4,
 * на остальное отвечает INCORRECT_LETTER_PAGE_FORMAT.
 *
 * Размеры читает Ghostscript: FPDI не открывает сжатые PDF, а их большинство.
 * Приводит тоже он: -dPDFFitPage при фиксированном A4 вписывает страницу
 * с сохранением пропорций, а альбомную поворачивает в книжную.
 */
class ZakaznoePageFormat
{
    public const A4_WIDTH = 595.28;

    public const A4_HEIGHT = 841.89;

    /**
     * Видимый размер страниц: CropBox, если задан, иначе MediaBox; /Rotate учитываем
     * отдельно, чтобы повёрнутый A4 распознавался как альбомный.
     */
    private const INSPECT_SCRIPT = <<<'PS'
({pdf}) (r) file runpdfbegin
1 1 pdfpagecount {
  dup pdfgetpage
  dup /MediaBox pget { } { [0 0 612 792] } ifelse
  1 index /CropBox pget { exch pop } if
  aload pop
  3 -1 roll sub abs
  3 1 roll exch sub abs exch
  2 index /Rotate pget not { 0 } if
  (PAGE ) print 4 index =only ( ) print 3 1 roll exch =only ( ) print =only ( ) print =only (\n) print
  pop pop
} for
quit
PS;

    private ?string $ghostscript = null;

    /**
     * @return array<int, array{width: float, height: float}> номер страницы → размер в пунктах, как её увидят
     */
    public function inspect(string $path): array
    {
        $pdf = $this->slashes($path);
        $script = dirname($path).'/inspect-'.Str::random(12).'.ps';

        file_put_contents($script, str_replace(
            '{pdf}',
            str_replace(['(', ')'], ['\\(', '\\)'], $pdf),
            self::INSPECT_SCRIPT
        ));

        try {
            // С 9.50 Ghostscript в SAFER не даёт PostScript открыть файл - разрешаем папку этого PDF
            exec($this->command(['-q', '-dNODISPLAY', '--permit-file-read='.dirname($pdf).'/', $script]), $output, $code);
        } finally {
            @unlink($script);
        }

        if ($code !== 0) {
            throw new RuntimeException('Ghostscript не прочитал PDF: '.Str::limit(implode(' ', $output), 300));
        }

        $pages = [];

        foreach ($output as $line) {
            if (!preg_match('/^PAGE (\d+) ([\d.]+) ([\d.]+) (-?\d+)$/', trim($line), $match)) {
                continue;
            }

            [$width, $height] = [(float) $match[2], (float) $match[3]];
            $rotate = (((int) $match[4]) % 360 + 360) % 360;

            $pages[(int) $match[1]] = in_array($rotate, [90, 270], true)
                ? ['width' => $height, 'height' => $width]
                : ['width' => $width, 'height' => $height];
        }

        if ($pages === []) {
            throw new RuntimeException('в PDF не найдено ни одной страницы');
        }

        return $pages;
    }

    /**
     * Привести все страницы к книжному A4. Если приводить нечего, файл не трогается.
     *
     * @return array{path: string, resized: array<int, int>, rotated: array<int, int>}
     *   путь к файлу для склейки, номера вписанных в A4 страниц и номера повёрнутых
     */
    public function toA4(string $source, string $output): array
    {
        $pages = $this->inspect($source);
        $resized = [];
        $rotated = [];

        foreach ($pages as $number => $page) {
            $landscape = $page['width'] > $page['height'];

            if ($landscape) {
                $rotated[] = $number;
            }

            [$short, $long] = $landscape
                ? [$page['height'], $page['width']]
                : [$page['width'], $page['height']];

            if (!$this->isA4($short, $long)) {
                $resized[] = $number;
            }
        }

        if ($resized === [] && $rotated === []) {
            return ['path' => $source, 'resized' => [], 'rotated' => []];
        }

        File::ensureDirectoryExists(dirname($output));

        exec($this->command([
            '-q', '-dSAFER', '-sDEVICE=pdfwrite',
            '-sPAPERSIZE=a4', '-dFIXEDMEDIA', '-dPDFFitPage', '-dAutoRotatePages=/None',
            '-o', $this->slashes($output), $this->slashes($source),
        ]), $log, $code);

        if ($code !== 0 || !is_file($output)) {
            throw new RuntimeException('Ghostscript не смог вписать страницы в A4: '.Str::limit(implode(' ', $log), 300));
        }

        // Отдаём на Почту только то, что проверили сами
        $result = $this->inspect($output);

        if (count($result) !== count($pages) || !$this->allA4($result)) {
            throw new RuntimeException('после обработки страницы всё ещё не A4');
        }

        return ['path' => $output, 'resized' => $resized, 'rotated' => $rotated];
    }

    /**
     * @param  array<int, array{width: float, height: float}>  $pages
     */
    public function allA4(array $pages): bool
    {
        foreach ($pages as $page) {
            if (!$this->isA4($page['width'], $page['height'])) {
                return false;
            }
        }

        return true;
    }

    private function isA4(float $width, float $height): bool
    {
        // A4 сохраняют и как 595×842, и как 595,28×841,89 - это один формат
        $tolerance = (float) config('zakaznoe.a4_tolerance_pt', 3);

        return abs($width - self::A4_WIDTH) <= $tolerance && abs($height - self::A4_HEIGHT) <= $tolerance;
    }

    /**
     * @param  array<int, string>  $args
     */
    private function command(array $args): string
    {
        $this->ghostscript ??= (new GhostscriptPdfMerger())->findGhostscript()
            ?? throw new ZakaznoeBuildException('ghostscript_missing',
                'На сервере сборки не установлен Ghostscript - без него нельзя проверить формат страниц.');

        return escapeshellarg($this->ghostscript).' '.implode(' ', array_map('escapeshellarg', $args)).' 2>&1';
    }

    private function slashes(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
