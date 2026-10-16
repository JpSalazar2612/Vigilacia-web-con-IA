<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Blynk IoT (maqueta casa 35x30, ESP32-CAM único)
    |--------------------------------------------------------------------------
    | Laravel empuja a Blynk por HTTP API (el ESP32-CAM solo hace streaming
    | MJPEG, no tiene RAM para llevar la librería Blynk + cámara a la vez).
    | - BLYNK_SERVER: blynk.cloud (o blynk.io según tu región)
    | - BLYNK_AUTH_TOKEN: token del Device (Device Info en consola/app)
    | - V0 texto alerta, V1 presencia (0/1), V2 foto URL (galería), V3 online
    | - BLYNK_EVENT_CODE: código del Evento "intruso_casa" (Events & Notifications)
    */
    'blynk' => [
        'server' => env('BLYNK_SERVER', 'blynk.cloud'),
        'auth_token' => env('BLYNK_AUTH_TOKEN'),
        'pin_texto' => env('BLYNK_PIN_TEXTO', 'V0'),
        'pin_presencia' => env('BLYNK_PIN_PRESENCIA', 'V1'),
        'pin_foto' => env('BLYNK_PIN_FOTO', 'V2'),
        'pin_online' => env('BLYNK_PIN_ONLINE', 'V3'),
        'event_code' => env('BLYNK_EVENT_CODE', 'intruso_casa'),
        'enabled' => env('BLYNK_ENABLED', false),
    ],

];
