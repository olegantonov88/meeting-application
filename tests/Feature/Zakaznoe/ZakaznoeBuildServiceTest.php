<?php

namespace Tests\Feature\Zakaznoe;

use App\Services\ArbitratorFileStorageService;
use App\Services\Zakaznoe\ZakaznoeBuildService;
use App\Services\Zakaznoe\ZakaznoePageFormat;
use App\Services\Zakaznoe\ZakaznoeStorage;
use FPDF;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use ZipArchive;

/**
 * Сборка на настоящих PDF: склейка, подсчёт страниц, опись и zip - рабочие.
 * Подменены только хранилище (локальная папка вместо облака) и CRM (Http::fake).
 */
class ZakaznoeBuildServiceTest extends TestCase
{
    private const DIRECTORY = '/onb/arb/procedures/proc/mail-registries/2026_09_12_34';

    private string $root;

    private object $storage;

    /** @var array<int, array<string, mixed>> */
    private array $calls = [];

    /** @var callable(array): \GuzzleHttp\Promise\PromiseInterface */
    private $crm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/zakaznoe-'.Str::random(8));

        config([
            'services.meeting_application.api_key' => 'test-key',
            'zakaznoe.temp_path' => $this->root.'/tmp',
        ]);

        // Хранилище управляющего - локальная папка: пути те же, что в облаке
        $root = $this->root.'/remote';
        $this->storage = new class(app(ArbitratorFileStorageService::class), $root) extends ZakaznoeStorage {
            public array $uploaded = [];

            public array $deleted = [];

            public function __construct(ArbitratorFileStorageService $files, private string $root)
            {
                parent::__construct($files);
            }

            public function download(int $arbitratorId, string $provider, string $remotePath, string $localPath): void
            {
                if (!is_file($this->root.$remotePath)) {
                    throw new \RuntimeException('Файл не найден в хранилище');
                }

                File::ensureDirectoryExists(dirname($localPath));
                copy($this->root.$remotePath, $localPath);
            }

            public function upload(int $arbitratorId, string $provider, string $localPath, string $remotePath): void
            {
                File::ensureDirectoryExists(dirname($this->root.$remotePath));
                copy($localPath, $this->root.$remotePath);
                $this->uploaded[] = $remotePath;
            }

            public function delete(int $arbitratorId, string $provider, string $remotePath): void
            {
                @unlink($this->root.$remotePath);
                $this->deleted[] = $remotePath;
            }

            public function path(string $remotePath): string
            {
                return $this->root.$remotePath;
            }
        };

        $this->app->instance(ZakaznoeStorage::class, $this->storage);

        $this->crm = fn (array $data) => Http::response(['result' => 'applied']);

        Http::fake(function (HttpRequest $request) {
            $data = $request->data();
            $this->calls[] = $data;

            return ($this->crm)($data);
        });

        Sleep::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_builds_single_archive_and_reports_every_letter(): void
    {
        $this->putPdf('/src/a.pdf', 2);
        $this->putPdf('/src/b.pdf', 1);
        $this->putPdf('/src/long.pdf', 16);
        File::put($this->storage->path('/src/scan.pdf'), 'не pdf');
        $this->putPdf('/src/d.pdf', 1);

        $task = $this->task([
            $this->letter(690, '120', ['/src/a.pdf', '/src/b.pdf']),
            $this->letter(691, '121', ['/src/long.pdf']),
            $this->letter(692, null, ['/src/scan.pdf']),
            $this->letter(693, '123', ['/src/missing.pdf']),
            $this->letter(694, '122', ['/src/d.pdf'], person: true),
        ]);

        app(ZakaznoeBuildService::class)->build($task);

        $this->dumpCalls('single');

        $this->assertSame(
            ['started', 'progress', 'progress', 'progress', 'progress', 'progress', 'completed'],
            array_column($this->calls, 'event')
        );
        $this->assertSame(5, $this->calls[0]['total']);
        Http::assertSent(fn (HttpRequest $request) => $request->hasHeader('Authorization', 'Bearer test-key'));

        $completed = end($this->calls);

        // Склеены два файла в порядке задания
        $this->assertSame('ok', $completed['items'][690]['result']);
        $this->assertSame(3, $completed['items'][690]['pages']);
        $this->assertSame(1, $completed['items'][690]['part']);
        $this->assertSame([40690, 41690], array_column($completed['items'][690]['files'], 'id'));

        $this->assertSame('failed', $completed['items'][691]['result']);
        $this->assertStringContainsString('не больше 15', $completed['items'][691]['reason']);
        $this->assertStringContainsString('не является PDF', $completed['items'][692]['reason']);
        $this->assertStringContainsString('Не удалось скачать', $completed['items'][693]['reason']);
        $this->assertSame('ok', $completed['items'][694]['result']);

        $this->assertSame([['number' => 1, 'name' => 'chast-1.zip', 'letters_count' => 2]], $completed['parts']);
        $this->assertStringStartsWith(self::DIRECTORY.'/zakaznoe-', $completed['archive']['remote_path']);
        $this->assertSame('yandex_disk', $completed['archive']['provider']);

        $archive = $this->storage->path($completed['archive']['remote_path']);
        $this->assertSame(filesize($archive), $completed['archive']['size']);

        // Одна часть - это и есть архив: PDF и опись прямо в корне
        $this->assertSame(['letter-120.pdf', 'letter-122.pdf', 'reestr.xlsx'], $this->zipEntries($archive));

        $sheet = $this->registrySheet($archive);
        $this->assertSame(
            ['letter-120.pdf', 1, 1, 'ООО «Ромашка» 690', '0660000000', '660001000', null, null, null, '620000, г. Екатеринбург, ул. Малышева, д. 5', '620014, г. Екатеринбург, ул. Ленина, д. 1', 1],
            $sheet[2]
        );
        $this->assertSame(
            ['letter-122.pdf', 1, 0, null, null, null, 'Петров', 'Иван', 'Николаевич', '620000, г. Екатеринбург, ул. Малышева, д. 5', '620014, г. Екатеринбург, ул. Ленина, д. 1', 1],
            $sheet[3]
        );
        $this->assertSame(array_fill(0, 12, null), $sheet[4]);

        $this->assertSame([], $this->storage->deleted);
        $this->assertDirectoryDoesNotExist($this->root.'/tmp/'.$task['build_uuid']);
    }

    public function test_brings_letter_to_portrait_a4_and_warns(): void
    {
        $this->putPdfPages('/src/timing.pdf', [['L', 'A4'], ['P', 'Letter']]);
        $this->putPdf('/src/court.pdf', 2);

        app(ZakaznoeBuildService::class)->build($this->task([
            $this->letter(690, '56', ['/src/timing.pdf', '/src/court.pdf']),
        ]));

        $completed = end($this->calls);
        $item = $completed['items'][690];

        $this->assertSame('ok', $item['result']);
        $this->assertSame(4, $item['pages']);
        // Предупреждение только про файл, который пришлось менять
        $this->assertSame(['«timing.pdf»: страница 2 приведена к A4; страница 1 повёрнута в книжную ориентацию'], $item['warnings']);

        // В архив ушёл PDF, где все страницы - книжный A4
        $letter = $this->root.'/letter-56.pdf';
        $zip = new ZipArchive();
        $zip->open($this->storage->path($completed['archive']['remote_path']));
        file_put_contents($letter, $zip->getFromName('letter-56.pdf'));
        $zip->close();

        $format = app(ZakaznoePageFormat::class);
        $pages = $format->inspect($letter);
        $this->assertCount(4, $pages);
        $this->assertTrue($format->allA4($pages));
    }

    public function test_packs_several_parts_into_one_archive(): void
    {
        $this->putPdf('/src/a.pdf', 1);
        $this->putPdf('/src/b.pdf', 1);

        $task = $this->task([
            $this->letter(690, '120', ['/src/a.pdf']),
            $this->letter(691, '121', ['/src/b.pdf']),
        ], lettersPerPart: 1);

        app(ZakaznoeBuildService::class)->build($task);

        $this->dumpCalls('parts');

        $completed = end($this->calls);

        $this->assertSame('completed', $completed['event']);
        $this->assertSame(1, $completed['items'][690]['part']);
        $this->assertSame(2, $completed['items'][691]['part']);
        $this->assertSame([
            ['number' => 1, 'name' => 'chast-1.zip', 'letters_count' => 1],
            ['number' => 2, 'name' => 'chast-2.zip', 'letters_count' => 1],
        ], $completed['parts']);

        $archive = $this->storage->path($completed['archive']['remote_path']);
        $this->assertSame(['chast-1.zip', 'chast-2.zip'], $this->zipEntries($archive));

        // Внутри части - свои PDF и своя опись
        $inner = $this->root.'/chast-2.zip';
        $zip = new ZipArchive();
        $zip->open($archive);
        file_put_contents($inner, $zip->getFromName('chast-2.zip'));
        $zip->close();

        $this->assertSame(['letter-121.pdf', 'reestr.xlsx'], $this->zipEntries($inner));
    }

    public function test_stops_quietly_when_crm_says_build_is_stale(): void
    {
        $this->putPdf('/src/a.pdf', 1);

        $this->crm = fn (array $data) => $data['event'] === 'progress'
            ? Http::response(['message' => 'Сборка устарела'], 409)
            : Http::response(['result' => 'applied']);

        $task = $this->task([$this->letter(690, '120', ['/src/a.pdf']), $this->letter(691, '121', ['/src/a.pdf'])]);

        app(ZakaznoeBuildService::class)->build($task);

        // Ни следующего письма, ни архива, ни сообщения о сбое
        $this->assertSame(['started', 'progress'], array_column($this->calls, 'event'));
        $this->assertSame([], $this->storage->uploaded);
        $this->assertDirectoryDoesNotExist($this->root.'/tmp/'.$task['build_uuid']);
    }

    public function test_removes_archive_when_crm_rejects_completed(): void
    {
        $this->putPdf('/src/a.pdf', 1);

        $this->crm = fn (array $data) => $data['event'] === 'completed'
            ? Http::response(['message' => 'invalid', 'errors' => ['archive' => ['bad']]], 422)
            : Http::response(['result' => 'applied']);

        app(ZakaznoeBuildService::class)->build($this->task([$this->letter(690, '120', ['/src/a.pdf'])]));

        $this->assertSame(['started', 'progress', 'completed', 'failed'], array_column($this->calls, 'event'));
        $this->assertSame('callback_rejected', end($this->calls)['error']['code']);
        $this->assertSame($this->storage->uploaded, $this->storage->deleted);
        $this->assertFileDoesNotExist($this->storage->path($this->storage->uploaded[0]));
    }

    public function test_fails_when_no_letter_is_built(): void
    {
        app(ZakaznoeBuildService::class)->build($this->task([$this->letter(690, '120', ['/src/missing.pdf'])]));

        $failed = end($this->calls);

        $this->assertSame('failed', $failed['event']);
        $this->assertSame('no_letters_built', $failed['error']['code']);
        $this->assertSame([], $this->storage->uploaded);
    }

    public function test_retries_callback_on_server_error(): void
    {
        $this->putPdf('/src/a.pdf', 1);

        $attempts = 0;
        $this->crm = function (array $data) use (&$attempts) {
            if ($data['event'] === 'started' && ++$attempts < 3) {
                return Http::response('down', 503);
            }

            return Http::response(['result' => 'applied']);
        };

        app(ZakaznoeBuildService::class)->build($this->task([$this->letter(690, '120', ['/src/a.pdf'])]));

        $this->assertSame(3, $attempts);
        $this->assertSame('completed', end($this->calls)['event']);
        Sleep::assertSleptTimes(2);
    }

    private function task(array $letters, int $lettersPerPart = 50): array
    {
        return [
            'mail_registry_id' => 34,
            'build_uuid' => (string) Str::uuid(),
            'callback_url' => 'http://crm.test/api/mail-registry/zakaznoe-build/callback',
            'arbitrator_id' => 5,
            'storage' => ['provider' => 'yandex_disk', 'directory' => self::DIRECTORY],
            'letters_per_part' => $lettersPerPart,
            'registry' => ['name' => 'Реестр', 'date_departure' => '14.09.2026'],
            'sender' => ['name' => 'Иванов И. И.', 'address' => '620014, г. Екатеринбург, ул. Ленина, д. 1'],
            'settings' => ['letter_type' => 1, 'zuev' => true],
            'letters' => $letters,
        ];
    }

    private function letter(int $letterId, ?string $number, array $paths, bool $person = false): array
    {
        return [
            'letter_id' => $letterId,
            'number' => $number,
            'recipient' => $person
                ? ['recipient_type' => 0, 'org_name' => null, 'inn' => null, 'kpp' => null,
                    'lastname' => 'Петров', 'firstname' => 'Иван', 'middlename' => 'Николаевич',
                    'address' => '620000, г. Екатеринбург, ул. Малышева, д. 5']
                : ['recipient_type' => 1, 'org_name' => "ООО «Ромашка» {$letterId}", 'inn' => '0660000000', 'kpp' => '660001000',
                    'lastname' => null, 'firstname' => null, 'middlename' => null,
                    'address' => '620000, г. Екатеринбург, ул. Малышева, д. 5'],
            'files' => array_map(fn (string $path, int $index) => [
                'id' => (40 + $index) * 1000 + $letterId,
                'name' => basename($path),
                'size' => 1000,
                'provider' => 'yandex_disk',
                'remote_path' => $path,
            ], $paths, array_keys($paths)),
        ];
    }

    private function putPdf(string $remotePath, int $pages): void
    {
        $pdf = new FPDF();
        $pdf->SetFont('Arial', '', 14);

        for ($page = 1; $page <= $pages; $page++) {
            $pdf->AddPage();
            $pdf->Cell(40, 10, "Page {$page}");
        }

        File::ensureDirectoryExists(dirname($this->storage->path($remotePath)));
        $pdf->Output('F', $this->storage->path($remotePath));
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $pages  ориентация и формат FPDF
     */
    private function putPdfPages(string $remotePath, array $pages): void
    {
        $pdf = new FPDF();
        $pdf->SetFont('Arial', '', 14);

        foreach ($pages as $index => [$orientation, $size]) {
            $pdf->AddPage($orientation, $size);
            $pdf->Cell(40, 10, 'Page '.($index + 1));
        }

        File::ensureDirectoryExists(dirname($this->storage->path($remotePath)));
        $pdf->Output('F', $this->storage->path($remotePath));
    }

    private function zipEntries(string $path): array
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true, "Не открылся архив {$path}");

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        sort($names);

        return $names;
    }

    /**
     * Строки 2-4 описи из архива: A-L.
     *
     * @return array<int, array<int, mixed>>
     */
    private function registrySheet(string $archive): array
    {
        $local = $this->root.'/reestr.xlsx';

        $zip = new ZipArchive();
        $zip->open($archive);
        file_put_contents($local, $zip->getFromName('reestr.xlsx'));
        $zip->close();

        $book = IOFactory::load($local);
        $sheet = $book->getSheetByName('Реестр для отправки');

        $rows = [];
        foreach ([2, 3, 4] as $row) {
            $rows[$row] = array_map(
                fn (string $column) => $sheet->getCell("{$column}{$row}")->getValue(),
                range('A', 'L')
            );
        }

        // Лист инструкции из шаблона на месте
        $this->assertNotNull($book->getSheetByName('Инструкция'));
        $book->disconnectWorksheets();

        return $rows;
    }

    /**
     * Callback'и в файл - чтобы прогнать их через обработчик CRM.
     */
    private function dumpCalls(string $name): void
    {
        $dir = env('ZAKAZNOE_CALLBACK_DUMP_DIR');

        if ($dir) {
            File::ensureDirectoryExists($dir);
            file_put_contents("{$dir}/{$name}.json", json_encode($this->calls, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
    }
}
