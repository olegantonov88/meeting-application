<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Живая сборка чистит за собой сама. Здесь - папки процессов, упавших посреди сборки
Artisan::command('zakaznoe:cleanup-temp', function () {
    $root = (string) config('zakaznoe.temp_path');

    if (!is_dir($root)) {
        return;
    }

    $threshold = now()->subHours((int) config('zakaznoe.temp_ttl_hours', 24))->getTimestamp();

    foreach (File::directories($root) as $directory) {
        $modified = @filemtime($directory);

        if ($modified !== false && $modified < $threshold) {
            File::deleteDirectory($directory);
            $this->info("Удалена {$directory}");
        }
    }
})->purpose('Удалить временные папки упавших сборок архивов для zakaznoe.pochta.ru');

// Расписание выполняет процесс supervisor с php artisan schedule:work, см. docs/zakaznoe-build.md
Schedule::command('zakaznoe:cleanup-temp')->hourly();
