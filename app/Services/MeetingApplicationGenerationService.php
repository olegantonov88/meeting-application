<?php

namespace App\Services;

use App\Models\Meeting\MeetingApplication;
use App\Models\EfrsbMessage\EfrsbDebtorMessage;
use App\Enums\Meeting\MeetingApplicationStatus;
use App\Models\ArbitratorFiles\ArbitratorFileInsurance;
use App\Models\ArbitratorFiles\ArbitratorFileLetter;
use App\Models\ArbitratorFiles\ArbitratorFileInventory;
use App\Models\ArbitratorFiles\ArbitratorFileEstimate;
use App\Models\ArbitratorFiles\ArbitratorFileTrade;
use App\Models\ArbitratorFiles\ArbitratorFileTradeContract;
use App\Models\Meeting\MeetingApplicationGenerationTask;
use App\Enums\EfrsbMessageRequestStatus;
use App\Enums\Meeting\MeetingApplicationGenerationTaskStatus;
use App\Events\MeetingApplicationStatusUpdated;
use App\Events\MeetingApplicationStatusNotification;
use App\Jobs\CheckEfrsbMessageTimeoutJob;
use App\Services\PdfMerger\PdfMergerService;
use App\Services\PdfPageCounterService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MeetingApplicationGenerationService
{
    public function __construct(
        private ArbitratorFileStorageService $fileStorageService,
        private HtmlToPdfService $htmlToPdfService,
        private PdfMergerService $pdfMergerService,
        private EfrsbDebtorMessageService $efrsbService,
        private PdfPageCounterService $pageCounterService
    ) {
    }

    /**
     * Главный метод генерации приложения
     *
     * @param int $meetingApplicationId
     * @param bool $continueAfterCallback Продолжать генерацию после получения callback
     * @param int|null $userId ID пользователя, запросившего генерацию
     * @return void
     * @throws \Exception
     */
    public function generate(int $meetingApplicationId, bool $continueAfterCallback = false, ?int $userId = null): void
    {
        Log::info('Starting meeting application generation', [
            'application_id' => $meetingApplicationId,
            'continue_after_callback' => $continueAfterCallback,
            'user_id' => $userId,
        ]);

        $application = MeetingApplication::find($meetingApplicationId);
        if (!$application) {
            throw new \Exception("Приложение к собранию не найдено: {$meetingApplicationId}");
        }

        $tempFiles = [];
        $generationTask = null;

        try {
            // 1. Создаем или получаем задачу генерации
            Log::debug('Step 1: Creating or retrieving generation task', [
                'application_id' => $application->id,
                'continue_after_callback' => $continueAfterCallback,
            ]);

            if (!$continueAfterCallback) {
                $generationTask = MeetingApplicationGenerationTask::create([
                    'meeting_application_id' => $application->id,
                    'user_id' => $userId,
                    'started_at' => now(),
                    'status' => MeetingApplicationGenerationTaskStatus::GENERATING,
                ]);
                Log::info('Generation task created', [
                    'task_id' => $generationTask->id,
                    'application_id' => $application->id,
                ]);
            } else {
                // При продолжении после callback ищем существующую задачу
                $generationTask = MeetingApplicationGenerationTask::where('meeting_application_id', $application->id)
                    ->orderBy('started_at', 'desc')
                    ->first();

                // Обновляем статус на GENERATING при продолжении
                if ($generationTask) {
                    $generationTask->update([
                        'status' => MeetingApplicationGenerationTaskStatus::GENERATING,
                    ]);
                    Log::info('Generation task resumed', [
                        'task_id' => $generationTask->id,
                        'application_id' => $application->id,
                    ]);
                } else {
                    Log::warning('Generation task not found when continuing after callback', [
                        'application_id' => $application->id,
                    ]);
                }
            }

            // 2. Сбрасываем статусы и ошибки всех файлов перед началом генерации
            Log::debug('Step 2: Resetting file and message statuses', [
                'application_id' => $application->id,
            ]);
            $this->resetFileStatuses($application);

            // Сбрасываем статусы и ошибки всех ЕФРСБ сообщений перед началом генерации
            $this->resetEfrsbMessageStatuses($application);

            $application->save();

            // 3. Получить список файлов из arbitrator_files и скачать их
            Log::debug('Step 3: Downloading arbitrator files', [
                'application_id' => $application->id,
            ]);
            $downloadedFiles = $this->downloadArbitratorFiles($application, $generationTask);
            $tempFiles = array_merge($tempFiles, array_column($downloadedFiles, 'path'));

            Log::info('Arbitrator files downloaded', [
                'application_id' => $application->id,
                'files_count' => count($downloadedFiles),
            ]);

            // 4. Получить список сообщений из efrsb_debtor_messages
            Log::debug('Step 4: Getting EFRSB debtor messages', [
                'application_id' => $application->id,
            ]);
            $messages = $this->getEfrsbDebtorMessages($application);

            Log::info('EFRSB messages retrieved', [
                'application_id' => $application->id,
                'messages_count' => $messages->count(),
            ]);

            // 5. Для сообщений без body_html - отправить запрос в efrsb-debtor-message
            // 6. Ожидать получения body_html (с таймаутом 5 минут)
            Log::debug('Step 5-6: Processing EFRSB messages', [
                'application_id' => $application->id,
                'continue_after_callback' => $continueAfterCallback,
            ]);
            if ($continueAfterCallback) {
                // Продолжаем генерацию после получения callback - обрабатываем только сообщения с body_html
                $messagePdfs = $this->generatePdfsFromReadyMessages($application, $messages, $generationTask);
            } else {
                $messagePdfs = $this->processEfrsbMessages($application, $messages, $generationTask);
            }
            $tempFiles = array_merge($tempFiles, array_column($messagePdfs, 'path'));

            Log::info('EFRSB messages processed', [
                'application_id' => $application->id,
                'pdfs_generated' => count($messagePdfs),
            ]);

            // Проверяем, есть ли ожидающие запросы на получение body_html
            // Если есть, выходим и ждем callback, даже если уже есть файлы или сообщения
            Log::debug('Step 7: Checking for pending EFRSB message requests', [
                'application_id' => $application->id,
            ]);

            // #region agent log
            // Проверяем pending запросы и исключаем те, для которых уже есть body_html
            $allPendingRequests = DB::table('efrsb_message_requests')
                ->where('meeting_application_id', $application->id)
                ->where('status', EfrsbMessageRequestStatus::PENDING->value)
                ->get();

            Log::debug('All pending requests found', [
                'application_id' => $application->id,
                'total_pending' => $allPendingRequests->count(),
                'request_ids' => $allPendingRequests->pluck('message_id')->toArray(),
            ]);

            // Проверяем, какие из pending запросов уже имеют body_html
            $pendingMessageIds = $allPendingRequests->pluck('message_id')->toArray();
            $messagesWithBody = EfrsbDebtorMessage::whereIn('id', $pendingMessageIds)
                ->whereNotNull('body_html')
                ->where('body_html', '!=', '')
                ->pluck('id')
                ->toArray();

            Log::debug('Pending requests with body_html found', [
                'application_id' => $application->id,
                'messages_with_body' => $messagesWithBody,
            ]);

            // Обновляем статус запросов для сообщений, у которых уже есть body_html
            if (!empty($messagesWithBody)) {
                $updated = DB::table('efrsb_message_requests')
                    ->where('meeting_application_id', $application->id)
                    ->whereIn('message_id', $messagesWithBody)
                    ->where('status', EfrsbMessageRequestStatus::PENDING->value)
                    ->update([
                        'status' => EfrsbMessageRequestStatus::COMPLETED->value,
                        'updated_at' => now(),
                    ]);

                Log::info('Cleaned up pending requests for messages with body_html', [
                    'application_id' => $application->id,
                    'updated_count' => $updated,
                    'message_ids' => $messagesWithBody,
                ]);
            }
            // #endregion

            $pendingCount = DB::table('efrsb_message_requests')
                ->where('meeting_application_id', $application->id)
                ->where('status', EfrsbMessageRequestStatus::PENDING->value)
                ->count();

            Log::info('Pending EFRSB requests check', [
                'application_id' => $application->id,
                'pending_count' => $pendingCount,
            ]);

            if ($pendingCount > 0) {
                // Есть ожидающие сообщения, выходим и ждем callback
                Log::info('Exiting generation - waiting for EFRSB callbacks', [
                    'application_id' => $application->id,
                    'pending_count' => $pendingCount,
                    'downloaded_files_count' => count($downloadedFiles),
                    'message_pdfs_count' => count($messagePdfs),
                ]);
                $this->cleanupTempDir($application->id);
                return;
            }

            // Проверка: если нет ни одного файла и ни одного сообщения - статус ERROR
            // (Ожидающие запросы уже проверены выше, если они есть - мы уже вышли)
            if (empty($downloadedFiles) && empty($messagePdfs)) {
                // Проверяем состояние генерации в свойствах application
                $hasFiles = $this->hasAnyFiles($application);
                $hasMessages = $this->hasAnyMessages($application);
                $hasSuccessfulFiles = $this->hasSuccessfulFiles($application);
                $hasSuccessfulMessages = $this->hasSuccessfulMessages($application);

                if (!$hasFiles && !$hasMessages) {
                    // Нет ни файлов, ни сообщений вообще
                    throw new \Exception("Нет файлов и сообщений для генерации приложения");
                } elseif (!$hasSuccessfulFiles && !$hasSuccessfulMessages) {
                    // Есть файлы или сообщения, но все с ошибками
                    throw new \Exception("Нет файлов для генерации. Все сообщения ЕФРСБ обработаны с ошибками.");
                }
                // Если есть хотя бы один успешно обработанный файл или сообщение, продолжаем генерацию
            }

            // 7. Объединить все PDF файлы
            Log::debug('Step 8: Merging PDF files', [
                'application_id' => $application->id,
                'downloaded_files_count' => count($downloadedFiles),
                'message_pdfs_count' => count($messagePdfs),
            ]);

            $allPdfFiles = array_merge(
                array_column($downloadedFiles, 'path'),
                array_column($messagePdfs, 'path')
            );

            if (empty($allPdfFiles)) {
                throw new \Exception("Нет файлов для объединения");
            }

            $mergedPdfPath = $this->getTempFilePath('merged_' . $application->id . '.pdf', $application->id);

            Log::info('Starting PDF merge', [
                'application_id' => $application->id,
                'files_to_merge' => count($allPdfFiles),
                'output_path' => $mergedPdfPath,
            ]);

            $this->pdfMergerService->merge($allPdfFiles, $mergedPdfPath);

            // Проверяем, что объединенный файл создан
            if (!file_exists($mergedPdfPath)) {
                throw new \Exception("Объединенный PDF файл не был создан: {$mergedPdfPath}");
            }

            $mergedFileSize = filesize($mergedPdfPath);

            Log::info('PDF merge completed', [
                'application_id' => $application->id,
                'merged_file_path' => $mergedPdfPath,
                'merged_file_size' => $mergedFileSize,
            ]);

            // Подсчитываем количество страниц ДО загрузки на сервер
            Log::debug('Step 9: Counting PDF pages', [
                'application_id' => $application->id,
                'file_path' => $mergedPdfPath,
            ]);

            $pageCount = null;
            try {
                $pageCount = $this->pageCounterService->countPages($mergedPdfPath);
                Log::info('PDF pages counted', [
                    'application_id' => $application->id,
                    'file_path' => $mergedPdfPath,
                    'page_count' => $pageCount,
                ]);
            } catch (\Exception $e) {
                // Логируем ошибку, но не прерываем процесс генерации
                Log::warning('Failed to count PDF pages, continuing without page count', [
                    'application_id' => $application->id,
                    'file_path' => $mergedPdfPath,
                    'error' => $e->getMessage(),
                ]);
            }

            // Обновляем статусы всех успешно скачанных файлов на 'generated'
            $this->markFilesAsGenerated($application, $downloadedFiles, $generationTask);

            // Сохраняем приложение после обновления статусов файлов и ЕФРСБ сообщений
            $application->save();

            $tempFiles[] = $mergedPdfPath;

            // 8. Удалить существующие файлы приложения (если есть)
            Log::debug('Step 10: Deleting existing application files', [
                'application_id' => $application->id,
            ]);
            $deletedCount = $this->fileStorageService->deleteExistingFiles($application);

            if ($deletedCount > 0) {
                Log::info('Existing application files deleted', [
                    'application_id' => $application->id,
                    'deleted_count' => $deletedCount,
                ]);
            }

            // 9. Сохранить итоговый файл
            $filename = 'meeting_application_' . $application->id . '_' . now('Europe/Moscow')->format('Y-m-d_H-i-s') . '.pdf';

            Log::info('Step 11: Uploading final file', [
                'application_id' => $application->id,
                'filename' => $filename,
                'file_path' => $mergedPdfPath,
                'file_size' => $mergedFileSize,
            ]);

            try {
                $fileRecord = $this->fileStorageService->uploadFile($application, $mergedPdfPath, $filename, $userId);

                Log::info('File uploaded successfully', [
                    'application_id' => $application->id,
                    'file_record_id' => $fileRecord->id,
                    'remote_path' => $fileRecord->remote_path ?? null,
                    'file_size' => $fileRecord->size ?? null,
                ]);
            } catch (\Exception $uploadException) {
                // Очистка временных файлов при ошибке загрузки
                $this->cleanupTempDir($application->id);

                // Получаем свежую версию приложения из БД для обновления статуса
                $applicationId = $application->id;
                try {
                    $application = MeetingApplication::find($applicationId);
                    if ($application) {
                        // Установить статус ошибки
                        $application->latest_status = MeetingApplicationStatus::ERROR;
                        $application->end_generation = now();
                        $application->addStatus(MeetingApplicationStatus::ERROR, 'Ошибка загрузки сгенерированного файла', "Ошибка загрузки файла: {$uploadException->getMessage()}");
                        $application->save();
                    }
                } catch (\Exception $saveException) {
                    Log::error('Failed to save application status to ERROR after upload failure', [
                        'application_id' => $applicationId,
                        'save_error' => $saveException->getMessage(),
                    ]);
                }

                // Обновляем статус задачи на ERROR
                if ($generationTask) {
                    $generationTask->update([
                        'status' => MeetingApplicationGenerationTaskStatus::ERROR,
                        'finished_at' => now(),
                    ]);
                }

                // Логируем ошибку
                Log::error('Failed to upload meeting application file', [
                    'application_id' => $applicationId,
                    'error' => $uploadException->getMessage(),
                    'trace' => $uploadException->getTraceAsString(),
                ]);

                // Отправляем уведомление об ошибке через Pusher
                if ($application && $userId) {
                    event(new MeetingApplicationStatusUpdated(
                        userId: $userId,
                        meetingApplication: $application->fresh()
                    ));

                    event(new MeetingApplicationStatusNotification(
                        userId: $userId,
                        title: 'Ошибка генерации приложения',
                        message: 'Не удалось загрузить файл приложения к собранию',
                        type: 'error',
                        life: 6000
                    ));
                }

                // Пробрасываем исключение дальше, чтобы оно было обработано внешним catch блоком
                throw $uploadException;
            }

            // Сохраняем количество страниц в meta приложения
            Log::debug('Step 12: Saving page count to application meta', [
                'application_id' => $application->id,
                'page_count' => $pageCount,
            ]);

            if ($pageCount !== null) {
                $meta = $application->meta ?? [];
                $meta['pages'] = $pageCount;
                $application->meta = $meta;
                $application->save();

                Log::info('Page count saved to application meta', [
                    'application_id' => $application->id,
                    'page_count' => $pageCount,
                ]);
            }

            // 10. Очистить все временные файлы
            Log::debug('Step 13: Cleaning up temporary files', [
                'application_id' => $application->id,
                'temp_files_count' => count($tempFiles),
            ]);
            $this->cleanupTempDir($application->id);

            Log::info('Temporary files cleaned up', [
                'application_id' => $application->id,
            ]);

            // 11. Обновить статусы и отправить уведомление
            // Проверяем наличие ошибок в файлах и сообщениях ЕФРСБ
            Log::debug('Step 14: Checking for errors and updating application status', [
                'application_id' => $application->id,
            ]);

            $hasErrors = $this->hasErrorsInFilesOrMessages($application);

            if ($hasErrors) {
                $application->latest_status = MeetingApplicationStatus::PARTIALLY_GENERATED;
                $application->end_generation = now();
                $application->addStatus(MeetingApplicationStatus::PARTIALLY_GENERATED, null, 'Приложение сгенерировано частично. Некоторые файлы или сообщения ЕФРСБ не были обработаны.');

                Log::info('Application status set to PARTIALLY_GENERATED', [
                    'application_id' => $application->id,
                ]);
            } else {
                $application->latest_status = MeetingApplicationStatus::GENERATED;
                $application->end_generation = now();
                $application->addStatus(MeetingApplicationStatus::GENERATED, null, 'Приложение успешно сгенерировано');

                Log::info('Application status set to GENERATED', [
                    'application_id' => $application->id,
                ]);
            }
            $application->save();

            // Обновляем статус задачи на COMPLETED
            Log::debug('Step 15: Updating generation task status to COMPLETED', [
                'application_id' => $application->id,
                'task_id' => $generationTask?->id,
            ]);

            if ($generationTask) {
                $generationTask->update([
                    'status' => MeetingApplicationGenerationTaskStatus::COMPLETED,
                    'finished_at' => now(),
                ]);

                Log::info('Generation task completed', [
                    'task_id' => $generationTask->id,
                    'application_id' => $application->id,
                ]);
            }

            // Отправляем уведомление через Pusher
            Log::debug('Step 16: Sending notifications', [
                'application_id' => $application->id,
                'user_id' => $userId,
                'has_errors' => $hasErrors,
            ]);

            if ($userId) {
                // Отправляем данные задачи для обновления на странице
                event(new MeetingApplicationStatusUpdated(
                    userId: $userId,
                    meetingApplication: $application->fresh()
                ));

                // Отправляем уведомление пользователю
                if ($hasErrors) {
                    // Частичная генерация - отправляем предупреждение
                    event(new MeetingApplicationStatusNotification(
                        userId: $userId,
                        title: 'Приложение сгенерировано частично',
                        message: 'Приложение к собранию сгенерировано частично. Некоторые файлы или сообщения ЕФРСБ не были обработаны.',
                        type: 'warn',
                        life: 6000
                    ));
                    Log::info('Partial generation notification sent', [
                        'application_id' => $application->id,
                        'user_id' => $userId,
                    ]);
                } else {
                    // Полная генерация - отправляем успех
                    event(new MeetingApplicationStatusNotification(
                        userId: $userId,
                        title: 'Приложение сгенерировано',
                        message: 'Приложение к собранию успешно сгенерировано',
                        type: 'success',
                        life: 6000
                    ));
                    Log::info('Success notification sent', [
                        'application_id' => $application->id,
                        'user_id' => $userId,
                    ]);
                }
            }

            Log::info('Meeting application generation completed successfully', [
                'application_id' => $application->id,
                'status' => $application->latest_status->value,
                'has_errors' => $hasErrors,
            ]);

        } catch (\Exception $e) {
            Log::error('Meeting application generation failed', [
                'application_id' => $application->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $applicationId = $application->id;

            // Очистка временных файлов даже при ошибке
            Log::debug('Cleaning up temporary files after error', [
                'application_id' => $applicationId,
                'temp_files_count' => count($tempFiles),
            ]);
            $this->cleanupTempDir($applicationId);

            // Получаем свежую версию приложения из БД для обновления статуса
            try {
                $application = MeetingApplication::find($applicationId);
                if ($application) {
                    // Установить статус ошибки
                    $application->latest_status = MeetingApplicationStatus::ERROR;
                    $application->end_generation = now();
                    $application->addStatus(MeetingApplicationStatus::ERROR, null, "Ошибка генерации: {$e->getMessage()}");
                    $application->save();

                    Log::info('Application status set to ERROR', [
                        'application_id' => $applicationId,
                    ]);
                } else {
                    Log::error('Application not found when trying to update status to ERROR', [
                        'application_id' => $applicationId,
                    ]);
                }
            } catch (\Exception $saveException) {
                Log::error('Failed to save application status to ERROR', [
                    'application_id' => $applicationId,
                    'save_error' => $saveException->getMessage(),
                ]);
            }

            // Обновляем статус задачи на ERROR
            if ($generationTask) {
                $generationTask->update([
                    'status' => MeetingApplicationGenerationTaskStatus::ERROR,
                    'finished_at' => now(),
                ]);

                Log::info('Generation task status set to ERROR', [
                    'task_id' => $generationTask->id,
                    'application_id' => $applicationId,
                ]);
            }

            // Определяем, является ли ошибка ожидаемой (бизнес-логика) или неожиданной (техническая)
            $expectedErrors = [
                'Нет файлов для объединения',
                'Нет файлов и сообщений для генерации приложения',
                'Нет файлов для генерации. Все сообщения ЕФРСБ обработаны с ошибками.',
            ];

            $isExpectedError = in_array($e->getMessage(), $expectedErrors);

            if ($isExpectedError) {
                // Для ожидаемых ошибок логируем без полного trace
                Log::warning('Failed to generate meeting application (expected error)', [
                    'application_id' => $application->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            } else {
                // Для неожиданных ошибок логируем с полным trace
                Log::error('Failed to generate meeting application', [
                    'application_id' => $application->id ?? null,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            // Отправляем уведомление об ошибке через Pusher
            if ($application) {
                if ($userId) {
                    // Отправляем данные задачи для обновления на странице
                    event(new MeetingApplicationStatusUpdated(
                        userId: $userId,
                        meetingApplication: $application->fresh()
                    ));

                    // Отправляем уведомление пользователю
                    event(new MeetingApplicationStatusNotification(
                        userId: $userId,
                        title: 'Ошибка генерации приложения',
                        message: 'Не удалось сгенерировать приложение к собранию',
                        type: 'error',
                        life: 6000
                    ));
                }
            }

            throw $e;
        }
    }

    /**
     * Скачивание файлов из хранилища
     *
     * @param MeetingApplication $application
     * @param MeetingApplicationGenerationTask|null $generationTask
     * @return array Массив с информацией о скачанных файлах
     */
    private function downloadArbitratorFiles(MeetingApplication $application, ?MeetingApplicationGenerationTask $generationTask = null): array
    {
        Log::debug('Starting download of arbitrator files', [
            'application_id' => $application->id,
        ]);

        $downloadedFiles = [];
        $arbitratorFiles = $application->arbitrator_files;

        if (!$arbitratorFiles || !$arbitratorFiles->value) {
            Log::debug('No arbitrator files to download', [
                'application_id' => $application->id,
            ]);
            return $downloadedFiles;
        }

        $fileTypes = [
            'insurance' => ArbitratorFileInsurance::class,
            'incoming_letters' => ArbitratorFileLetter::class,
            'outgoing_letters' => ArbitratorFileLetter::class,
            'inventory' => ArbitratorFileInventory::class,
            'estimate' => ArbitratorFileEstimate::class,
            'trade' => ArbitratorFileTrade::class,
            'trade_contract' => ArbitratorFileTradeContract::class,
        ];

        foreach ($fileTypes as $type => $modelClass) {
            Log::debug('Processing file type', [
                'application_id' => $application->id,
                'file_type' => $type,
            ]);
            $files = $arbitratorFiles->value[$type] ?? collect();

            // Собираем все ID файлов данного типа
            $fileIds = $files->pluck('id')->filter()->unique()->toArray();

            if (empty($fileIds)) {
                continue;
            }

            // Получаем все файлы одним запросом через whereIn
            try {
                $filesCollection = $modelClass::whereIn('id', $fileIds)->get();

                // Создаем маппинг ID -> файл для быстрого доступа
                $filesMap = $filesCollection->keyBy('id');

                // Обрабатываем каждый файл
                foreach ($files as $fileIndex => $fileData) {
                    $fileId = $fileData['id'] ?? ($fileData instanceof \Illuminate\Support\Collection ? $fileData->get('id') : null);
                    if (!$fileId) {
                        continue;
                    }

                    $file = $filesMap->get($fileId);
                    if (!$file) {
                        Log::warning('Arbitrator file not found', [
                            'type' => $type,
                            'file_id' => $fileId,
                        ]);
                        // Обновляем статус файла на error
                        $this->updateFileStatus($arbitratorFiles, $type, $fileId, 'error', 'Файл не найден в базе данных');
                        continue;
                    }

                    $fileExtension = strtolower(pathinfo($file->remote_path ?? '', PATHINFO_EXTENSION));
                    if ($fileExtension !== 'pdf') {
                        $this->updateFileStatus($arbitratorFiles, $type, $fileId, 'error', 'Для объединения доступны только pdf файлы');
                        continue;
                    }

                    try {
                        $tempPath = $this->getTempFilePath('arbitrator_file_' . $file->id . '.pdf', $application->id);
                        $this->fileStorageService->downloadFile($file, $tempPath);

                        $downloadedFiles[] = [
                            'path' => $tempPath,
                            'type' => $type,
                            'file_id' => $file->id,
                        ];

                        // Обновляем статус файла - скачан успешно (статус будет обновлен на generated после объединения)
                        // Пока оставляем статус как есть или ставим 'downloaded'
                    } catch (\Exception $e) {
                        Log::error('Failed to download arbitrator file', [
                            'type' => $type,
                            'file_id' => $fileId,
                            'error' => $e->getMessage(),
                        ]);
                        // Обновляем статус файла на error
                        $this->updateFileStatus($arbitratorFiles, $type, $fileId, 'error', $e->getMessage());
                        // Продолжаем обработку остальных файлов
                    }
                }
            } catch (\Exception $e) {
                Log::error('Failed to fetch arbitrator files', [
                    'type' => $type,
                    'file_ids' => $fileIds,
                    'error' => $e->getMessage(),
                ]);
                // Устанавливаем ошибку для всех файлов этого типа
                foreach ($files as $fileIndex => $fileData) {
                    $fileId = $fileData['id'] ?? ($fileData instanceof \Illuminate\Support\Collection ? $fileData->get('id') : null);
                    if ($fileId) {
                        $this->updateFileStatus($arbitratorFiles, $type, $fileId, 'error', "Ошибка получения файлов: {$e->getMessage()}");
                    }
                }
                // Продолжаем обработку остальных типов файлов
            }
        }

        // Сохраняем обновленные статусы файлов
        $application->arbitrator_files = $arbitratorFiles;
        $application->save();

        Log::info('Arbitrator files download completed', [
            'application_id' => $application->id,
            'downloaded_count' => count($downloadedFiles),
        ]);

        return $downloadedFiles;
    }

    /**
     * Сбрасывает статусы и ошибки всех файлов перед началом генерации
     *
     * @param MeetingApplication $application
     * @return void
     */
    private function resetFileStatuses(MeetingApplication $application): void
    {
        $arbitratorFiles = $application->arbitrator_files;
        if (!$arbitratorFiles || !$arbitratorFiles->value) {
            return;
        }

        $fileTypes = [
            'insurance',
            'incoming_letters',
            'outgoing_letters',
            'inventory',
            'estimate',
            'trade',
            'trade_contract',
        ];

        foreach ($fileTypes as $type) {
            $files = $arbitratorFiles->value[$type] ?? collect();

            foreach ($files as $fileIndex => $fileData) {
                if ($fileData instanceof \Illuminate\Support\Collection) {
                    // Удаляем статус и ошибку
                    $fileData->forget('status');
                    $fileData->forget('error');
                } elseif (is_array($fileData)) {
                    unset($fileData['status']);
                    unset($fileData['error']);
                    $files->put($fileIndex, $fileData);
                }
            }
        }
    }

    /**
     * Сбрасывает статусы и ошибки всех ЕФРСБ сообщений перед началом генерации
     *
     * @param MeetingApplication $application
     * @return void
     */
    private function resetEfrsbMessageStatuses(MeetingApplication $application): void
    {
        $efrsbMessages = $application->efrsb_debtor_messages;
        if (!$efrsbMessages || $efrsbMessages->isEmpty()) {
            return;
        }

        foreach ($efrsbMessages as $messageIndex => $messageData) {
            if ($messageData instanceof \Illuminate\Support\Collection) {
                // Устанавливаем статус "generating" и очищаем ошибку
                $messageData->put('status', 'generating');
                $messageData->forget('error');
            } elseif (is_array($messageData)) {
                $messageData['status'] = 'generating';
                unset($messageData['error']);
                $efrsbMessages->put($messageIndex, $messageData);
            }
        }
    }

    /**
     * Обновляет статус ЕФРСБ сообщения в EfrsbDebtorMessagesMeetingApplicationObject
     *
     * @param MeetingApplication $application
     * @param int $messageId ID сообщения
     * @param string $status Статус ('generated', 'error')
     * @param string|null $errorMessage Сообщение об ошибке (если статус 'error')
     * @return void
     */
    private function updateEfrsbMessageStatus(MeetingApplication $application, int $messageId, string $status, ?string $errorMessage = null): void
    {
        $efrsbMessages = $application->efrsb_debtor_messages;
        if (!$efrsbMessages || $efrsbMessages->isEmpty()) {
            return;
        }

        // Ищем сообщение по ID
        $messageIndex = $efrsbMessages->search(function ($messageData) use ($messageId) {
            if ($messageData instanceof \Illuminate\Support\Collection) {
                return $messageData->get('id') == $messageId;
            }
            return ($messageData['id'] ?? null) == $messageId;
        });

        if ($messageIndex !== false) {
            $messageData = $efrsbMessages->get($messageIndex);
            if ($messageData instanceof \Illuminate\Support\Collection) {
                $messageData->put('status', $status);
                if ($errorMessage) {
                    $messageData->put('error', $errorMessage);
                } elseif ($status === 'generated') {
                    // При успешной генерации удаляем ошибку, если она была
                    $messageData->forget('error');
                }
            } elseif (is_array($messageData)) {
                $messageData['status'] = $status;
                if ($errorMessage) {
                    $messageData['error'] = $errorMessage;
                } elseif ($status === 'generated') {
                    // При успешной генерации удаляем ошибку, если она была
                    unset($messageData['error']);
                }
                $efrsbMessages->put($messageIndex, $messageData);
            }
        }
    }

    /**
     * Обновляет статус файла в ArbitratorFilesMeetingApplicationObject
     *
     * @param ArbitratorFilesMeetingApplicationObject $arbitratorFiles
     * @param string $type Тип файла
     * @param int $fileId ID файла
     * @param string $status Статус ('generated', 'error')
     * @param string|null $errorMessage Сообщение об ошибке (если статус 'error')
     * @return void
     */
    private function updateFileStatus($arbitratorFiles, string $type, int $fileId, string $status, ?string $errorMessage = null): void
    {
        if (!$arbitratorFiles || !$arbitratorFiles->value) {
            return;
        }

        $files = $arbitratorFiles->value[$type] ?? collect();

        // Ищем файл по ID
        $fileIndex = $files->search(function ($fileData) use ($fileId) {
            if ($fileData instanceof \Illuminate\Support\Collection) {
                return $fileData->get('id') == $fileId;
            }
            return ($fileData['id'] ?? null) == $fileId;
        });

        if ($fileIndex !== false) {
            $fileData = $files->get($fileIndex);
            if ($fileData instanceof \Illuminate\Support\Collection) {
                $fileData->put('status', $status);
                if ($errorMessage) {
                    $fileData->put('error', $errorMessage);
                } elseif ($status === 'generated') {
                    // При успешной генерации удаляем ошибку, если она была
                    $fileData->forget('error');
                }
            } elseif (is_array($fileData)) {
                $fileData['status'] = $status;
                if ($errorMessage) {
                    $fileData['error'] = $errorMessage;
                } elseif ($status === 'generated') {
                    // При успешной генерации удаляем ошибку, если она была
                    unset($fileData['error']);
                }
                $files->put($fileIndex, $fileData);
            }
        }
    }

    /**
     * Помечает успешно скачанные файлы как 'generated' после объединения
     *
     * @param MeetingApplication $application
     * @param array $downloadedFiles Массив успешно скачанных файлов
     * @param MeetingApplicationGenerationTask|null $generationTask
     * @return void
     */
    private function markFilesAsGenerated(MeetingApplication $application, array $downloadedFiles, ?MeetingApplicationGenerationTask $generationTask = null): void
    {
        $arbitratorFiles = $application->arbitrator_files;
        if (!$arbitratorFiles || !$arbitratorFiles->value) {
            return;
        }

        foreach ($downloadedFiles as $downloadedFile) {
            $type = $downloadedFile['type'] ?? null;
            $fileId = $downloadedFile['file_id'] ?? null;

            if ($type && $fileId) {
                $this->updateFileStatus($arbitratorFiles, $type, $fileId, 'generated');
            }
        }

        // Сохраняем обновленные статусы файлов
        $application->arbitrator_files = $arbitratorFiles;
        $application->save();
    }

    /**
     * Получает список сообщений ЕФРСБ из приложения
     *
     * @param MeetingApplication $application
     * @return Collection
     */
    private function getEfrsbDebtorMessages(MeetingApplication $application): Collection
    {
        $efrsbMessages = $application->efrsb_debtor_messages;

        if (!$efrsbMessages) {
            return collect();
        }

        $messageIds = $efrsbMessages->map(function ($item) {
            return $item['id'] ?? null;
        })->filter();

        if ($messageIds->isEmpty()) {
            return collect();
        }

        return EfrsbDebtorMessage::whereIn('id', $messageIds->toArray())->get();
    }

    /**
     * Обрабатывает сообщения ЕФРСБ: запрашивает body_html и генерирует PDF
     *
     * @param MeetingApplication $application
     * @param Collection $messages
     * @param MeetingApplicationGenerationTask|null $generationTask
     * @return array Массив с информацией о сгенерированных PDF
     */
    private function processEfrsbMessages(MeetingApplication $application, Collection $messages, ?MeetingApplicationGenerationTask $generationTask = null): array
    {
        Log::debug('Processing EFRSB messages', [
            'application_id' => $application->id,
            'messages_count' => $messages->count(),
        ]);

        $messagePdfs = [];
        $messagesToRequest = [];

        // Разделяем сообщения на те, у которых есть body_html, и те, у которых нет
        foreach ($messages as $message) {
            Log::debug('Processing EFRSB message', [
                'application_id' => $application->id,
                'message_id' => $message->id,
                'has_body_html' => !empty($message->body_html),
            ]);
            try {
                // Проверяем наличие body_html
                if (empty($message->body_html)) {
                    // Собираем сообщения для запроса
                    Log::debug('EFRSB message needs body_html request', [
                        'application_id' => $application->id,
                        'message_id' => $message->id,
                    ]);
                    $messagesToRequest[] = [
                        'message_id' => $message->id,
                        'message_uuid' => $message->uuid ?? '',
                    ];
                    // Сохраняем запрос для отслеживания таймаута
                    $this->saveMessageRequest($application->id, $message->id);
                } else {
                    // Генерируем PDF из HTML для сообщений, у которых уже есть body_html
                    Log::debug('Generating PDF from EFRSB message', [
                        'application_id' => $application->id,
                        'message_id' => $message->id,
                    ]);

                    // #region agent log
                    $pendingRequest = DB::table('efrsb_message_requests')
                        ->where('meeting_application_id', $application->id)
                        ->where('message_id', $message->id)
                        ->where('status', EfrsbMessageRequestStatus::PENDING->value)
                        ->first();
                    if ($pendingRequest) {
                        Log::debug('Found pending request for message with body_html, cleaning up', [
                            'application_id' => $application->id,
                            'message_id' => $message->id,
                            'request_id' => $pendingRequest->id ?? null,
                        ]);
                        // Удаляем или обновляем старый pending запрос, так как body_html уже есть
                        DB::table('efrsb_message_requests')
                            ->where('meeting_application_id', $application->id)
                            ->where('message_id', $message->id)
                            ->where('status', EfrsbMessageRequestStatus::PENDING->value)
                            ->update([
                                'status' => EfrsbMessageRequestStatus::COMPLETED->value,
                                'updated_at' => now(),
                            ]);
                        Log::info('Pending request cleaned up for message with body_html', [
                            'application_id' => $application->id,
                            'message_id' => $message->id,
                        ]);
                    }
                    // #endregion

                    $html = base64_decode($message->body_html, true);
                    if ($html === false) {
                        $html = $message->body_html; // Если не base64, используем как есть
                    }

                    $tempPath = $this->getTempFilePath('efrsb_message_' . $message->id . '.pdf', $application->id);
                    $this->htmlToPdfService->generate($html, $tempPath, [], $message->title ?? null);

                    $messagePdfs[] = [
                        'path' => $tempPath,
                        'message_id' => $message->id,
                    ];

                    Log::info('PDF generated from EFRSB message', [
                        'application_id' => $application->id,
                        'message_id' => $message->id,
                        'pdf_path' => $tempPath,
                    ]);

                    // Обновляем статус сообщения на "generated"
                    $this->updateEfrsbMessageStatus($application, $message->id, 'generated');
                }
            } catch (\Exception $e) {
                Log::error('Failed to process EFRSB message', [
                    'message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);

                // Обновляем статус сообщения на "error"
                $this->updateEfrsbMessageStatus($application, $message->id, 'error', 'Не удалось получить текст сообщения');

                // Продолжаем обработку остальных сообщений
            }
        }

        // Если есть сообщения без body_html, отправляем запрос одним пакетом
        if (!empty($messagesToRequest)) {
            Log::info('Requesting EFRSB message bodies', [
                'application_id' => $application->id,
                'messages_count' => count($messagesToRequest),
            ]);

            $result = $this->efrsbService->requestMessageBodies($messagesToRequest, $application->id);

            if ($result && ($result['success'] ?? false)) {
                Log::info('EFRSB message bodies request sent successfully', [
                    'application_id' => $application->id,
                    'messages_count' => count($messagesToRequest),
                ]);
                // Запускаем Job для проверки таймаута
                $timeoutMinutes = (int) config('meeting_application.efrsb_timeout_minutes', 5);
                CheckEfrsbMessageTimeoutJob::dispatch($application->id)
                    ->delay(now()->addMinutes($timeoutMinutes));

                Log::info('EFRSB timeout job scheduled', [
                    'application_id' => $application->id,
                    'timeout_minutes' => $timeoutMinutes,
                ]);
            } else {
                Log::error('Failed to request message bodies', [
                    'messages_count' => count($messagesToRequest),
                    'meeting_application_id' => $application->id,
                ]);

                // Если запрос не прошел, обновляем статусы всех сообщений на "error"
                $errorMessage = 'Не удалось получить текст сообщения. Сервис недоступен.';
                foreach ($messagesToRequest as $messageRequest) {
                    $messageId = $messageRequest['message_id'] ?? null;
                    if ($messageId) {
                        $this->updateEfrsbMessageStatus($application, $messageId, 'error', $errorMessage);

                        // Удаляем запрос из БД, так как он не был отправлен
                        DB::table('efrsb_message_requests')
                            ->where('meeting_application_id', $application->id)
                            ->where('message_id', $messageId)
                            ->delete();
                    }
                }
            }
        }

        Log::info('EFRSB messages processing completed', [
            'application_id' => $application->id,
            'pdfs_generated' => count($messagePdfs),
            'messages_requested' => count($messagesToRequest),
        ]);

        return $messagePdfs;
    }

    /**
     * Сохраняет запрос на получение body_html для отслеживания таймаута
     */
    private function saveMessageRequest(int $applicationId, int $messageId): void
    {
        // Используем БД meeting-application для хранения запросов
        DB::table('efrsb_message_requests')->insert([
            'meeting_application_id' => $applicationId,
            'message_id' => $messageId,
            'requested_at' => now(),
            'status' => EfrsbMessageRequestStatus::PENDING->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Объединяет все PDF файлы
     *
     * @param array $filePaths
     * @param string $outputPath
     * @return void
     */
    private function mergePdfFiles(array $filePaths, string $outputPath): void
    {
        $this->pdfMergerService->merge($filePaths, $outputPath);
    }

    /**
     * Сохраняет итоговый PDF файл
     *
     * @param MeetingApplication $application
     * @param string $filePath
     * @return \App\Models\ArbitratorFiles\ArbitratorFileMeetingApplication
     */
    private function saveFinalPdf(MeetingApplication $application, string $filePath): \App\Models\ArbitratorFiles\ArbitratorFileMeetingApplication
    {
        $filename = 'meeting_application_' . $application->id . '_' . now('Europe/Moscow')->format('Y-m-d_H-i-s') . '.pdf';
        return $this->fileStorageService->uploadFile($application, $filePath, $filename);
    }

    /**
     * Очистка временных файлов
     *
     * @param array $filePaths
     * @return void
     */
    public function cleanupTempFiles(array $filePaths): void
    {
        foreach ($filePaths as $filePath) {
            try {
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to delete temporary file', [
                    'path' => $filePath,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Очистка временной папки приложения
     *
     * @param int $applicationId
     * @return void
     */
    public function cleanupTempDir(int $applicationId): void
    {
        $tempDir = storage_path('app/tmp/' . $applicationId);
        if (!is_dir($tempDir)) {
            return;
        }

        try {
            if (gc_enabled()) {
                gc_collect_cycles();
            }
            clearstatcache();

            $deleted = File::deleteDirectory($tempDir);
            if ($deleted || !is_dir($tempDir)) {
                return;
            }

            foreach (File::allFiles($tempDir) as $file) {
                try {
                    $filePath = $file->getPathname();
                    if (!File::delete($filePath) && file_exists($filePath)) {
                        Log::warning('Temporary file still exists after delete attempt', [
                            'path' => $filePath,
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to delete temporary file during cleanup', [
                        'path' => $file->getPathname(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            foreach (File::directories($tempDir) as $directory) {
                File::deleteDirectory($directory);
            }

            if (!@rmdir($tempDir) && is_dir($tempDir)) {
                Log::warning('Failed to delete temporary directory after cleanup', [
                    'path' => $tempDir,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to delete temporary directory', [
                'path' => $tempDir,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Генерирует PDF из сообщений, которые уже имеют body_html
     * Используется при продолжении генерации после получения callback
     *
     * @param Collection $messages
     * @param MeetingApplicationGenerationTask|null $generationTask
     * @return array
     */
    private function generatePdfsFromReadyMessages(MeetingApplication $application, Collection $messages, ?MeetingApplicationGenerationTask $generationTask = null): array
    {
        Log::debug('Generating PDFs from ready EFRSB messages', [
            'application_id' => $application->id,
            'messages_count' => $messages->count(),
        ]);

        $messagePdfs = [];

        foreach ($messages as $message) {
            try {
                Log::debug('Processing ready EFRSB message', [
                    'application_id' => $application->id,
                    'message_id' => $message->id,
                ]);

                // Обновляем сообщение из БД, чтобы получить актуальный body_html
                $message->refresh();

                if (empty($message->body_html)) {
                    // Если body_html нет, проверяем статус запроса
                    $request = DB::table('efrsb_message_requests')
                        ->where('message_id', $message->id)
                        ->first();

                    $errorMessage = null;
                    if ($request && $request->status === EfrsbMessageRequestStatus::ERROR->value) {
                        $errorMessage = $request->error ?? 'Не удалось получить текст сообщения';
                        Log::warning('EFRSB message body not available due to error', [
                            'message_id' => $message->id,
                            'error' => $errorMessage,
                        ]);
                    } else {
                        $errorMessage = 'Не удалось получить текст сообщения';
                        Log::warning('EFRSB message body not found in database', [
                            'message_id' => $message->id,
                        ]);
                    }

                    // Обновляем статус сообщения на "error"
                    $this->updateEfrsbMessageStatus($application, $message->id, 'error', $errorMessage);

                    // Пропускаем сообщения без body_html
                    continue;
                }

                // Генерируем PDF из HTML
                $html = base64_decode($message->body_html, true);
                if ($html === false) {
                    $html = $message->body_html; // Если не base64, используем как есть
                }

                $tempPath = $this->getTempFilePath('efrsb_message_' . $message->id . '.pdf', $application->id);

                Log::debug('Generating PDF from EFRSB message HTML', [
                    'application_id' => $application->id,
                    'message_id' => $message->id,
                    'pdf_path' => $tempPath,
                ]);

                $this->htmlToPdfService->generate($html, $tempPath, [], $message->title ?? null);

                $messagePdfs[] = [
                    'path' => $tempPath,
                    'message_id' => $message->id,
                ];

                Log::info('PDF generated from ready EFRSB message', [
                    'application_id' => $application->id,
                    'message_id' => $message->id,
                    'pdf_path' => $tempPath,
                ]);

                // Обновляем статус сообщения на "generated"
                $this->updateEfrsbMessageStatus($application, $message->id, 'generated');
            } catch (\Exception $e) {
                Log::error('Failed to generate PDF from EFRSB message', [
                    'message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);

                // Обновляем статус сообщения на "error"
                $this->updateEfrsbMessageStatus($application, $message->id, 'error', 'Не удалось сформировать pdf файл из текста сообщения');

                // Продолжаем обработку остальных сообщений
            }
        }

        Log::info('PDFs generation from ready EFRSB messages completed', [
            'application_id' => $application->id,
            'pdfs_generated' => count($messagePdfs),
        ]);

        return $messagePdfs;
    }

    /**
     * Получает путь для временного файла
     *
     * @param string $filename
     * @return string
     */
    private function getTempFilePath(string $filename, ?int $applicationId = null): string
    {
        $tmpDir = $applicationId
            ? storage_path('app/tmp/' . $applicationId)
            : storage_path('app/tmp');
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $safeName = Str::random(8) . '_' . Str::slug(pathinfo($filename, PATHINFO_FILENAME));
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        return $tmpDir . '/' . $safeName . ($extension ? ".{$extension}" : '');
    }

    /**
     * Проверяет наличие ошибок в файлах и сообщениях ЕФРСБ
     *
     * @param MeetingApplication $application
     * @return bool true если есть ошибки, false если ошибок нет
     */
    private function hasErrorsInFilesOrMessages(MeetingApplication $application): bool
    {
        // Проверяем ошибки в файлах
        $arbitratorFiles = $application->arbitrator_files;
        if ($arbitratorFiles && $arbitratorFiles->value) {
            $fileTypes = ['insurance', 'incoming_letters', 'outgoing_letters', 'inventory', 'estimate', 'trade', 'trade_contract'];

            foreach ($fileTypes as $type) {
                $files = $arbitratorFiles->value[$type] ?? collect();
                foreach ($files as $file) {
                    $fileData = $file instanceof \Illuminate\Support\Collection ? $file : collect($file);
                    $status = $fileData->get('status');
                    $error = $fileData->get('error');

                    // Если статус 'error' или есть сообщение об ошибке
                    if ($status === 'error' || !empty($error)) {
                        return true;
                    }
                }
            }
        }

        // Проверяем ошибки в сообщениях ЕФРСБ
        $efrsbMessages = $application->efrsb_debtor_messages;
        if ($efrsbMessages && !$efrsbMessages->isEmpty()) {
            foreach ($efrsbMessages as $message) {
                $messageData = $message instanceof \Illuminate\Support\Collection ? $message : collect($message);
                $status = $messageData->get('status');
                $error = $messageData->get('error');

                // Если статус 'error' или есть сообщение об ошибке
                if ($status === 'error' || !empty($error)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Проверяет, есть ли хотя бы один файл в arbitrator_files
     *
     * @param MeetingApplication $application
     * @return bool
     */
    private function hasAnyFiles(MeetingApplication $application): bool
    {
        $arbitratorFiles = $application->arbitrator_files;
        if (!$arbitratorFiles || !$arbitratorFiles->value) {
            return false;
        }

        $fileTypes = ['insurance', 'incoming_letters', 'outgoing_letters', 'inventory', 'estimate', 'trade', 'trade_contract'];

        foreach ($fileTypes as $type) {
            $files = $arbitratorFiles->value[$type] ?? collect();
            if ($files->isNotEmpty()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Проверяет, есть ли хотя бы одно сообщение в efrsb_debtor_messages
     *
     * @param MeetingApplication $application
     * @return bool
     */
    private function hasAnyMessages(MeetingApplication $application): bool
    {
        $efrsbMessages = $application->efrsb_debtor_messages;
        return $efrsbMessages && !$efrsbMessages->isEmpty();
    }

    /**
     * Проверяет, есть ли хотя бы один успешно обработанный файл
     *
     * @param MeetingApplication $application
     * @return bool
     */
    private function hasSuccessfulFiles(MeetingApplication $application): bool
    {
        $arbitratorFiles = $application->arbitrator_files;
        if (!$arbitratorFiles || !$arbitratorFiles->value) {
            return false;
        }

        $fileTypes = ['insurance', 'incoming_letters', 'outgoing_letters', 'inventory', 'estimate', 'trade', 'trade_contract'];

        foreach ($fileTypes as $type) {
            $files = $arbitratorFiles->value[$type] ?? collect();
            foreach ($files as $file) {
                $fileData = $file instanceof \Illuminate\Support\Collection ? $file : collect($file);
                $status = $fileData->get('status');

                if ($status === 'generated') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Проверяет, есть ли хотя бы одно успешно обработанное сообщение
     *
     * @param MeetingApplication $application
     * @return bool
     */
    private function hasSuccessfulMessages(MeetingApplication $application): bool
    {
        $efrsbMessages = $application->efrsb_debtor_messages;
        if (!$efrsbMessages || $efrsbMessages->isEmpty()) {
            return false;
        }

        foreach ($efrsbMessages as $message) {
            $messageData = $message instanceof \Illuminate\Support\Collection ? $message : collect($message);
            $status = $messageData->get('status');

            if ($status === 'generated') {
                return true;
            }
        }

        return false;
    }
}
