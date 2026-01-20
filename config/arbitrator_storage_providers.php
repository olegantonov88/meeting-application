<?php

return [
    'default' => env('FILE_STORAGE_PROVIDER', 'yandex_disk'),

    'providers' => [
        'yandex_disk' => [
            'class' => \App\Services\ArbitratorFileStorage\Providers\YandexDiskStorage::class,
            'root' => env('YANDEX_DISK_ROOT', '/onb'),
        ],
        'onb_storage' => [
            'class' => \App\Services\ArbitratorFileStorage\Providers\OnbStorage::class,
            'root' => env('ONB_STORAGE_ROOT', '/onb'),
            'access_key' => env('ONB_STORAGE_ACCESS_KEY'),
            'secret_key' => env('ONB_STORAGE_SECRET_KEY'),
            'bucket' => env('ONB_STORAGE_BUCKET'),
            'endpoint' => env('ONB_STORAGE_ENDPOINT'),
            'region' => env('ONB_STORAGE_REGION', 'ru-central1'),
            'signed_url_expires' => env('ONB_STORAGE_SIGNED_URL_EXPIRES', 3600), // 1 час
        ],
    ],

    'entities' => [
        'meeting_application' => \App\Models\Meeting\MeetingApplication::class,
    ],
];
