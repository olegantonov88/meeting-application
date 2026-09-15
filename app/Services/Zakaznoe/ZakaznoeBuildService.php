<?php

namespace App\Services\Zakaznoe;

use App\Services\PdfMerger\PdfMergerService;
use App\Services\Zakaznoe\Exceptions\ZakaznoeBuildAborted;
use App\Services\Zakaznoe\Exceptions\ZakaznoeBuildException;
use App\Services\Zakaznoe\Exceptions\ZakaznoeCallbackRejected;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Сборка архива для zakaznoe.pochta.ru по заданию CRM.
 *
 * 1. started - сколько писем в сборке.
 * 2. По каждому письму: скачать PDF, склеить в один файл, проверить лимит страниц,
 *    отправить progress со строкой письма. Ошибка письма не останавливает сборку -
 *    строка уходит с result=failed и причиной.
 * 3. Собранные письма - частями по letters_per_part: в каждой части PDF и опись.
 *    Одна часть и есть архив; несколько - упаковываются в один архив частей.
 * 4. Архив - в папку реестра в хранилище, completed с частями и архивом.
 *
 * Фатальная ошибка - failed с понятным пользователю текстом. CRM ответила 409/404
 * (сборку перезапустили, реестр удалён) - просто останавливаемся. Залитый архив,
 * который CRM не приняла, удаляется. Временная папка удаляется всегда.
 */
class ZakaznoeBuildService
{
    public const REGISTRY_FILE_NAME = 'reestr.xlsx';

    public function __construct(
        private readonly ZakaznoeStorage $storage,
        private readonly PdfMergerService $merger,
        private readonly ZakaznoePageFormat $pageFormat,
        private readonly ZakaznoeRegistryWriter $registryWriter,
        private readonly ZakaznoeArchiver $archiver,
    ) {
    }

    /**
     * @param  array<string, mixed>  $task  провалидированный ZakaznoeBuildStoreRequest
     */
    public function build(array $task): void
    {
        $callback = $this->callbackFor($task);
        $workDir = $this->workDir($task);
        $uploadedPath = null;

        Log::info('Zakaznoe build started', [
            'mail_registry_id' => $task['mail_registry_id'],
            'build_uuid' => $task['build_uuid'],
            'letters' => count($task['letters']),
        ]);

        try {
            File::ensureDirectoryExists($workDir);

            $letters = $task['letters'];
            $callback->send('started', ['total' => count($letters)]);

            $fileNames = ZakaznoeFileNames::forLetters($letters);
            $items = [];
            $built = [];
            $processed = 0;

            foreach ($letters as $letter) {
                $letterId = (int) $letter['letter_id'];

                [$item, $path] = $this->buildLetter($task, $letter, $workDir, count($built), $fileNames[$letterId]);

                $items[$letterId] = $item;

                if ($path !== null) {
                    $built[$letterId] = ['letter' => $letter, 'path' => $path, 'file_name' => $fileNames[$letterId]];
                }

                $processed++;
                $callback->send('progress', ['processed' => $processed, 'items' => [$letterId => $item]]);
            }

            if ($built === []) {
                throw new ZakaznoeBuildException('no_letters_built',
                    'Не удалось собрать ни одного письма. Причины - у строк получателей.');
            }

            [$archivePath, $parts] = $this->pack($task, $built, $workDir);

            $remotePath = $this->remotePath($task);
            $this->storage->upload((int) $task['arbitrator_id'], $task['storage']['provider'], $archivePath, $remotePath);
            $uploadedPath = $remotePath;

            $callback->send('completed', [
                'processed' => $processed,
                'items' => $items,
                'parts' => $parts,
                'archive' => [
                    'provider' => $task['storage']['provider'],
                    'remote_path' => $remotePath,
                    'size' => filesize($archivePath),
                ],
            ]);

            // CRM приняла архив и завела на него запись - теперь это её файл
            $uploadedPath = null;

            Log::info('Zakaznoe build completed', [
                'build_uuid' => $task['build_uuid'],
                'built' => count($built),
                'failed' => $processed - count($built),
                'parts' => count($parts),
            ]);
        } catch (ZakaznoeBuildAborted $e) {
            Log::warning('Zakaznoe build aborted by CRM', [
                'build_uuid' => $task['build_uuid'],
                'reason' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            Log::error('Zakaznoe build failed', [
                'build_uuid' => $task['build_uuid'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Сначала убрать архив, который CRM не приняла, потом сообщить о сбое
            if ($uploadedPath !== null) {
                $this->deleteQuietly($task, $uploadedPath);
                $uploadedPath = null;
            }

            $this->reportFailure($callback, $e);
        } finally {
            if ($uploadedPath !== null) {
                $this->deleteQuietly($task, $uploadedPath);
            }

            $this->cleanup($workDir);
        }
    }

    /**
     * Сборку прервал таймаут или падение процесса - сообщить CRM, если получится.
     */
    public function reportCrash(array $task, ?Throwable $exception): void
    {
        Log::error('Zakaznoe build crashed', [
            'build_uuid' => $task['build_uuid'] ?? null,
            'error' => $exception?->getMessage(),
        ]);

        $this->reportFailure($this->callbackFor($task), new ZakaznoeBuildException('build_timeout',
            'Сборка не уложилась в отведённое время или прервалась. Запустите её ещё раз.'));

        $this->cleanup($this->workDir($task));
    }

    /**
     * Одно письмо: скачать, склеить, проверить.
     *
     * @return array{0: array<string, mixed>, 1: ?string} строка для CRM и путь к PDF (null - не собрано)
     */
    private function buildLetter(array $task, array $letter, string $workDir, int $builtSoFar, string $fileName): array
    {
        $letterId = (int) $letter['letter_id'];
        $files = array_map(fn (array $file) => ['id' => (int) $file['id'], 'name' => $file['name']], $letter['files']);
        $failed = fn (string $reason) => [
            ['result' => 'failed', 'reason' => Str::limit($reason, 900), 'files' => $files],
            null,
        ];

        $sourceDir = "{$workDir}/sources/{$letterId}";
        File::ensureDirectoryExists($sourceDir);

        try {
            $sources = [];
            $warnings = [];

            foreach (array_values($letter['files']) as $index => $file) {
                $local = "{$sourceDir}/{$index}.pdf";

                try {
                    $this->storage->download((int) $task['arbitrator_id'], $file['provider'], $file['remote_path'], $local);
                } catch (Throwable $e) {
                    return $failed("Не удалось скачать «{$file['name']}»: {$e->getMessage()}");
                }

                if (!$this->looksLikePdf($local)) {
                    return $failed("Файл «{$file['name']}» не является PDF");
                }

                // zakaznoe принимает только книжный A4 - остальное вписываем и поворачиваем
                try {
                    $formatted = $this->pageFormat->toA4($local, "{$sourceDir}/{$index}-a4.pdf");
                } catch (ZakaznoeBuildException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    return $failed("Не удалось привести «{$file['name']}» к формату A4: {$e->getMessage()}");
                }

                if ($warning = $this->formatWarning($file['name'], $formatted)) {
                    $warnings[] = $warning;
                }

                $sources[] = $formatted['path'];
            }

            $output = "{$workDir}/letters/{$fileName}";
            File::ensureDirectoryExists(dirname($output));

            try {
                // Один файл склеивать не с чем - Ghostscript только пережал бы его
                count($sources) === 1
                    ? File::copy($sources[0], $output)
                    : $this->merger->merge($sources, $output);
            } catch (Throwable $e) {
                return $failed('Не удалось склеить вложения: '.$e->getMessage());
            }

            try {
                $pageSizes = $this->pageFormat->inspect($output);
            } catch (ZakaznoeBuildException $e) {
                throw $e;
            } catch (Throwable) {
                File::delete($output);

                return $failed('Не удалось прочитать получившийся PDF');
            }

            // Склейка размеры не меняет, но на Почту уходит именно этот файл - проверяем его
            if (!$this->pageFormat->allA4($pageSizes)) {
                File::delete($output);

                return $failed('После склейки в письме остались страницы не формата A4');
            }

            $pages = count($pageSizes);

            $maxPages = (int) config('zakaznoe.max_pages_per_letter', 15);

            if ($pages > $maxPages) {
                File::delete($output);

                return $failed("В письме {$pages} стр., а zakaznoe.pochta.ru принимает не больше {$maxPages}");
            }

            return [[
                'result' => 'ok',
                // Номер части известен сразу: собранные письма идут в части по порядку
                'part' => intdiv($builtSoFar, (int) $task['letters_per_part']) + 1,
                'files' => $files,
                'pages' => $pages,
                'size' => filesize($output),
                // В CRM - жёлтым у строки письма; больше 20 предупреждений контракт не принимает
                'warnings' => array_slice($warnings, 0, 20),
            ], $output];
        } finally {
            File::deleteDirectory($sourceDir);
        }
    }

    /**
     * Разложить собранные письма по частям и упаковать в один архив.
     *
     * @param  array<int, array{letter: array, path: string, file_name: string}>  $built
     * @return array{0: string, 1: array<int, array{number: int, name: string, letters_count: int}>}
     */
    private function pack(array $task, array $built, string $workDir): array
    {
        $partFiles = [];
        $parts = [];

        foreach (array_chunk($built, (int) $task['letters_per_part'], true) as $index => $partLetters) {
            $number = $index + 1;
            $partDir = "{$workDir}/parts/{$number}";

            $registryPath = $this->registryWriter->write($task, $partLetters, "{$partDir}/".self::REGISTRY_FILE_NAME);

            $entries = [];
            foreach ($partLetters as $entry) {
                $entries[$entry['file_name']] = $entry['path'];
            }
            $entries[self::REGISTRY_FILE_NAME] = $registryPath;

            $name = "chast-{$number}.zip";
            $partFiles[$name] = $this->archiver->zip("{$workDir}/parts/{$name}", $entries);

            $parts[] = ['number' => $number, 'name' => $name, 'letters_count' => count($partLetters)];
        }

        // Одна часть - это и есть архив. Несколько - пакуем в один файл, чтобы
        // пользователь не гадал, где искать остальные
        $archivePath = count($partFiles) === 1
            ? reset($partFiles)
            : $this->archiver->zip("{$workDir}/archive.zip", $partFiles);

        return [$archivePath, $parts];
    }

    /**
     * Имя файла в хранилище - латиница: так его принимает CRM. Для пользователя
     * CRM назовёт архив по реестру.
     */
    private function remotePath(array $task): string
    {
        $suffix = substr(str_replace('-', '', $task['build_uuid']), 0, 12);

        return rtrim($task['storage']['directory'], '/')."/zakaznoe-{$suffix}.zip";
    }

    /**
     * «Счёт.pdf»: страницы 1-2 приведены к A4; страница 5 повёрнута в книжную ориентацию
     *
     * @param  array{resized: array<int, int>, rotated: array<int, int>}  $formatted
     */
    private function formatWarning(string $fileName, array $formatted): ?string
    {
        // Альбомная страница не формата A4 и повёрнута, и вписана - достаточно сказать «приведена»
        $rotatedOnly = array_values(array_diff($formatted['rotated'], $formatted['resized']));
        $parts = [];

        if ($formatted['resized'] !== []) {
            $parts[] = $this->pagesPhrase($formatted['resized'], 'приведена к A4', 'приведены к A4');
        }

        if ($rotatedOnly !== []) {
            $parts[] = $this->pagesPhrase($rotatedOnly, 'повёрнута в книжную ориентацию', 'повёрнуты в книжную ориентацию');
        }

        return $parts === [] ? null : Str::limit("«{$fileName}»: ".implode('; ', $parts), 900);
    }

    /**
     * @param  array<int, int>  $pages
     */
    private function pagesPhrase(array $pages, string $single, string $plural): string
    {
        sort($pages);

        if (count($pages) === 1) {
            return "страница {$pages[0]} {$single}";
        }

        // 1, 2, 3, 5 → 1-3, 5
        $ranges = [];
        $start = $previous = array_shift($pages);

        foreach ([...$pages, null] as $page) {
            if ($page !== null && $page === $previous + 1) {
                $previous = $page;

                continue;
            }

            $ranges[] = $start === $previous ? (string) $start : "{$start}-{$previous}";
            $start = $previous = $page;
        }

        return 'страницы '.implode(', ', $ranges)." {$plural}";
    }

    private function reportFailure(ZakaznoeCallbackClient $callback, Throwable $e): void
    {
        [$code, $message] = match (true) {
            $e instanceof ZakaznoeBuildException => [$e->errorCode, $e->getMessage()],
            $e instanceof ZakaznoeCallbackRejected => ['callback_rejected',
                'CRM не приняла результат сборки. Запустите сборку ещё раз, а если ошибка повторится - сообщите в поддержку.'],
            default => ['internal_error',
                'Внутренняя ошибка сервиса сборки. Запустите сборку ещё раз, а если ошибка повторится - сообщите в поддержку.'],
        };

        try {
            $callback->send('failed', ['error' => ['code' => $code, 'message' => Str::limit($message, 1900)]]);
        } catch (Throwable $inner) {
            // CRM через 30 минут тишины сама посчитает сборку зависшей
            Log::error('Zakaznoe build failure not reported', [
                'error' => $inner->getMessage(),
                'original_error' => $e->getMessage(),
            ]);
        }
    }

    private function deleteQuietly(array $task, string $remotePath): void
    {
        try {
            $this->storage->delete((int) $task['arbitrator_id'], $task['storage']['provider'], $remotePath);
        } catch (Throwable $e) {
            Log::warning('Zakaznoe build: uploaded archive not deleted', [
                'build_uuid' => $task['build_uuid'],
                'remote_path' => $remotePath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function looksLikePdf(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        // Сигнатура может стоять не с первого байта - спецификация допускает мусор до неё
        $head = (string) fread($handle, 1024);
        fclose($handle);

        return str_contains($head, '%PDF-');
    }

    private function callbackFor(array $task): ZakaznoeCallbackClient
    {
        return new ZakaznoeCallbackClient($task['callback_url'], (int) $task['mail_registry_id'], $task['build_uuid']);
    }

    private function workDir(array $task): string
    {
        // В имени только uuid из провалидированного задания - выйти за пределы папки нельзя
        return rtrim((string) config('zakaznoe.temp_path'), '/\\').'/'.$task['build_uuid'];
    }

    private function cleanup(string $workDir): void
    {
        if (!is_dir($workDir)) {
            return;
        }

        if (gc_enabled()) {
            gc_collect_cycles();
        }

        if (!File::deleteDirectory($workDir) && is_dir($workDir)) {
            Log::warning('Zakaznoe build: temp directory not deleted', ['path' => $workDir]);
        }
    }
}
