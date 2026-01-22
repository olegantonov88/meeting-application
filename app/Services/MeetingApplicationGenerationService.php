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
        $application = MeetingApplication::find($meetingApplicationId);
        if (!$application) {
            throw new \Exception("Приложение к собранию не найдено: {$meetingApplicationId}");
        }

        $tempFiles = [];
        $generationTask = null;

        try {
            // 1. Создаем или получаем задачу генерации
            if (!$continueAfterCallback) {
                $generationTask = MeetingApplicationGenerationTask::create([
                    'meeting_application_id' => $application->id,
                    'user_id' => $userId,
                    'started_at' => now(),
                    'status' => MeetingApplicationGenerationTaskStatus::GENERATING,
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
                }
            }

            // 2. Сбрасываем статусы и ошибки всех файлов перед началом генерации
            $this->resetFileStatuses($application);

            // Сбрасываем статусы и ошибки всех ЕФРСБ сообщений перед началом генерации
            $this->resetEfrsbMessageStatuses($application);

            $application->save();

            // 3. Получить список файлов из arbitrator_files и скачать их
            $downloadedFiles = $this->downloadArbitratorFiles($application, $generationTask);
            $tempFiles = array_merge($tempFiles, array_column($downloadedFiles, 'path'));

            // 4. Получить список сообщений из efrsb_debtor_messages
            $messages = $this->getEfrsbDebtorMessages($application);

            // 5. Для сообщений без body_html - отправить запрос в efrsb-debtor-message
            // 6. Ожидать получения body_html (с таймаутом 5 минут)
            if ($continueAfterCallback) {
                // Продолжаем генерацию после получения callback - обрабатываем только сообщения с body_html
                $messagePdfs = $this->generatePdfsFromReadyMessages($application, $messages, $generationTask);
            } else {
                $messagePdfs = $this->processEfrsbMessages($application, $messages, $generationTask);
            }
            $tempFiles = array_merge($tempFiles, array_column($messagePdfs, 'path'));

            // Проверяем, есть ли ожидающие запросы на получение body_html
            // Если есть, выходим и ждем callback, даже если уже есть файлы или сообщения
            $pendingCount = DB::table('efrsb_message_requests')
                ->where('meeting_application_id', $application->id)
                ->where('status', EfrsbMessageRequestStatus::PENDING->value)
                ->count();

            if ($pendingCount > 0) {
                // Есть ожидающие сообщения, выходим и ждем callback
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
            $allPdfFiles = array_merge(
                array_column($downloadedFiles, 'path'),
                array_column($messagePdfs, 'path')
            );

            if (empty($allPdfFiles)) {
                throw new \Exception("Нет файлов для объединения");
            }

            $mergedPdfPath = $this->getTempFilePath('merged_' . $application->id . '.pdf');

            $this->pdfMergerService->merge($allPdfFiles, $mergedPdfPath);

            // Проверяем, что объединенный файл создан
            if (!file_exists($mergedPdfPath)) {
                throw new \Exception("Объединенный PDF файл не был создан: {$mergedPdfPath}");
            }

            $mergedFileSize = filesize($mergedPdfPath);

            // Подсчитываем количество страниц ДО загрузки на сервер
            $pageCount = null;
            try {
                $pageCount = $this->pageCounterService->countPages($mergedPdfPath);
                Log::debug('PDF pages counted before upload', [
                    'file_path' => $mergedPdfPath,
                    'page_count' => $pageCount,
                ]);
            } catch (\Exception $e) {
                // Логируем ошибку, но не прерываем процесс генерации
                Log::warning('Failed to count PDF pages before upload, continuing without page count', [
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
            $deletedCount = $this->fileStorageService->deleteExistingFiles($application);

            // 9. Сохранить итоговый файл
            $filename = 'meeting_application_' . $application->id . '_' . now('Europe/Moscow')->format('Y-m-d_H-i-s') . '.pdf';

            try {
                $fileRecord = $this->fileStorageService->uploadFile($application, $mergedPdfPath, $filename, $userId);
            } catch (\Exception $uploadException) {
                // Очистка временных файлов при ошибке загрузки
                $this->cleanupTempFiles($tempFiles);

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
            if ($pageCount !== null) {
                $meta = $application->meta ?? [];
                $meta['pages'] = $pageCount;
                $application->meta = $meta;
                $application->save();

                Log::debug('PDF pages count saved to MeetingApplication meta', [
                    'application_id' => $application->id,
                    'page_count' => $pageCount,
                ]);
            }

            // 10. Очистить все временные файлы
            $this->cleanupTempFiles($tempFiles);

            // 11. Обновить статусы и отправить уведомление
            // Проверяем наличие ошибок в файлах и сообщениях ЕФРСБ
            $hasErrors = $this->hasErrorsInFilesOrMessages($application);

            if ($hasErrors) {
                $application->latest_status = MeetingApplicationStatus::PARTIALLY_GENERATED;
                $application->end_generation = now();
                $application->addStatus(MeetingApplicationStatus::PARTIALLY_GENERATED, null, 'Приложение сгенерировано частично. Некоторые файлы или сообщения ЕФРСБ не были обработаны.');
            } else {
                $application->latest_status = MeetingApplicationStatus::GENERATED;
                $application->end_generation = now();
                $application->addStatus(MeetingApplicationStatus::GENERATED, null, 'Приложение успешно сгенерировано');
            }
            $application->save();

            // Обновляем статус задачи на COMPLETED
            if ($generationTask) {
                $generationTask->update([
                    'status' => MeetingApplicationGenerationTaskStatus::COMPLETED,
                    'finished_at' => now(),
                ]);
            }

            // Отправляем уведомление через Pusher
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
                } else {
                    // Полная генерация - отправляем успех
                    event(new MeetingApplicationStatusNotification(
                        userId: $userId,
                        title: 'Приложение сгенерировано',
                        message: 'Приложение к собранию успешно сгенерировано',
                        type: 'success',
                        life: 6000
                    ));
                }
            }

        } catch (\Exception $e) {
            // Очистка временных файлов даже при ошибке
            $this->cleanupTempFiles($tempFiles);

            // Получаем свежую версию приложения из БД для обновления статуса
            $applicationId = $application->id;
            try {
                $application = MeetingApplication::find($applicationId);
                if ($application) {
                    // Установить статус ошибки
                    $application->latest_status = MeetingApplicationStatus::ERROR;
                    $application->end_generation = now();
                    $application->addStatus(MeetingApplicationStatus::ERROR, null, "Ошибка генерации: {$e->getMessage()}");
                    $application->save();
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
        $downloadedFiles = [];
        $arbitratorFiles = $application->arbitrator_files;

        if (!$arbitratorFiles || !$arbitratorFiles->value) {
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

                    try {
                        $tempPath = $this->getTempFilePath('arbitrator_file_' . $file->id . '.pdf');
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
        $messagePdfs = [];
        $messagesToRequest = [];

        // Разделяем сообщения на те, у которых есть body_html, и те, у которых нет
        foreach ($messages as $message) {
            try {
                // Проверяем наличие body_html
                if (empty($message->body_html)) {
                    // Собираем сообщения для запроса
                    $messagesToRequest[] = [
                        'message_id' => $message->id,
                        'message_uuid' => $message->uuid ?? '',
                    ];
                    // Сохраняем запрос для отслеживания таймаута
                    $this->saveMessageRequest($application->id, $message->id);
                } else {
                    // Генерируем PDF из HTML для сообщений, у которых уже есть body_html
                    $html = base64_decode($message->body_html, true);
                    if ($html === false) {
                        $html = $message->body_html; // Если не base64, используем как есть
                    }

                    $tempPath = $this->getTempFilePath('efrsb_message_' . $message->id . '.pdf');
                    $this->htmlToPdfService->generate($html, $tempPath, [], $message->title ?? null);

                    $messagePdfs[] = [
                        'path' => $tempPath,
                        'message_id' => $message->id,
                    ];

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
            $result = $this->efrsbService->requestMessageBodies($messagesToRequest, $application->id);

            if ($result && ($result['success'] ?? false)) {
                // Запускаем Job для проверки таймаута
                $timeoutMinutes = (int) config('meeting_application.efrsb_timeout_minutes', 5);
                CheckEfrsbMessageTimeoutJob::dispatch($application->id)
                    ->delay(now()->addMinutes($timeoutMinutes));
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
     * Генерирует PDF из сообщений, которые уже имеют body_html
     * Используется при продолжении генерации после получения callback
     *
     * @param Collection $messages
     * @param MeetingApplicationGenerationTask|null $generationTask
     * @return array
     */
    private function generatePdfsFromReadyMessages(MeetingApplication $application, Collection $messages, ?MeetingApplicationGenerationTask $generationTask = null): array
    {
        $messagePdfs = [];

        foreach ($messages as $message) {
            try {
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

                $tempPath = $this->getTempFilePath('efrsb_message_' . $message->id . '.pdf');
                $this->htmlToPdfService->generate($html, $tempPath, [], $message->title ?? null);

                $messagePdfs[] = [
                    'path' => $tempPath,
                    'message_id' => $message->id,
                ];

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

        return $messagePdfs;
    }

    /**
     * Получает путь для временного файла
     *
     * @param string $filename
     * @return string
     */
    private function getTempFilePath(string $filename): string
    {
        $tmpDir = storage_path('app/tmp');
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
