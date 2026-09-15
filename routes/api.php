<?php

use App\Http\Controllers\MeetingApplicationController;
use App\Http\Controllers\ZakaznoeBuildController;
use App\Http\Controllers\EfrsbMessageCallbackController;
use App\Http\Controllers\TestEfrsbPdfGenerationController;
use App\Http\Controllers\Api\GenerateMeetingApplicationJobApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('meeting-applications')->middleware('verify.api.key')->group(function () {
    Route::post('/generate', [MeetingApplicationController::class, 'generate'])->name('api.meeting-applications.generate');
});

// Просмотр состояния задач генерации приложений с пагинацией
Route::get('/generate-meeting-application-jobs', [GenerateMeetingApplicationJobApiController::class, 'index'])
    ->middleware('verify.api.key')
    ->name('api.generate-meeting-application-jobs.index');

Route::prefix('efrsb-message')->group(function () {
    // Callback не требует авторизации, так как это просто уведомление от efrsb-debtor-message
    Route::post('/callback', [EfrsbMessageCallbackController::class, 'callback'])
        ->name('api.efrsb-message.callback');
});

// Тестовые роуты для генерации PDF (будут удалены после тестирования)
Route::prefix('test/efrsb-pdf')->group(function () {
    Route::post('/generate', [TestEfrsbPdfGenerationController::class, 'testGeneratePdf'])->name('api.test.efrsb-pdf.generate');
    Route::get('/message-info', [TestEfrsbPdfGenerationController::class, 'getMessageInfo'])->name('api.test.efrsb-pdf.message-info');
});

// Сборка архивов для zakaznoe.pochta.ru: CRM ставит задание, ход и итог уходят в CRM callback'ами
Route::post('/zakaznoe-builds', [ZakaznoeBuildController::class, 'store'])
    ->middleware('verify.api.key')
    ->name('api.zakaznoe-builds.store');

Route::get('/ping', function () {
    return response()->json(['message' => 'pong']);
});
