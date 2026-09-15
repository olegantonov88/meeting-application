<?php

namespace Tests\Feature\Zakaznoe;

use App\Services\PdfMerger\Providers\GhostscriptPdfMerger;
use App\Services\Zakaznoe\ZakaznoePageFormat;
use FPDF;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class ZakaznoePageFormatTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        if (!(new GhostscriptPdfMerger())->findGhostscript()) {
            $this->markTestSkipped('Ghostscript не установлен');
        }

        $this->dir = storage_path('framework/testing/page-format-'.Str::random(8));
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_reads_page_sizes(): void
    {
        $pages = app(ZakaznoePageFormat::class)->inspect($this->pdf('mixed.pdf', [['P', 'A4'], ['L', 'A4'], ['P', 'Letter']]));

        $this->assertCount(3, $pages);
        $this->assertEqualsWithDelta(['width' => 595.28, 'height' => 841.89], $pages[1], 0.01);
        $this->assertEqualsWithDelta(['width' => 841.89, 'height' => 595.28], $pages[2], 0.01);
        $this->assertEqualsWithDelta(['width' => 612.0, 'height' => 792.0], $pages[3], 0.01);
    }

    public function test_leaves_portrait_a4_untouched(): void
    {
        $source = $this->pdf('a4.pdf', [['P', 'A4'], ['P', 'A4']]);

        $result = app(ZakaznoePageFormat::class)->toA4($source, "{$this->dir}/out.pdf");

        $this->assertSame(['path' => $source, 'resized' => [], 'rotated' => []], $result);
        $this->assertFileDoesNotExist("{$this->dir}/out.pdf");
    }

    public function test_rotates_landscape_and_fits_other_sizes(): void
    {
        $format = app(ZakaznoePageFormat::class);
        $source = $this->pdf('mixed.pdf', [['P', 'A4'], ['L', 'A4'], ['P', 'Letter'], ['L', 'A5']]);

        $result = $format->toA4($source, "{$this->dir}/out.pdf");

        $this->assertSame("{$this->dir}/out.pdf", $result['path']);
        $this->assertSame([3, 4], $result['resized']);
        $this->assertSame([2, 4], $result['rotated']);

        $pages = $format->inspect($result['path']);
        $this->assertCount(4, $pages);
        $this->assertTrue($format->allA4($pages));
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $pages  ориентация и формат FPDF
     */
    private function pdf(string $name, array $pages): string
    {
        $pdf = new FPDF();
        $pdf->SetFont('Arial', '', 14);

        foreach ($pages as $index => [$orientation, $size]) {
            $pdf->AddPage($orientation, $size);
            $pdf->Cell(40, 10, 'Page '.($index + 1));
        }

        $path = "{$this->dir}/{$name}";
        $pdf->Output('F', $path);

        return $path;
    }
}
