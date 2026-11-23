<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    // Ronda B, parte 2: "Continuar con Google" (Laravel Socialite). Sin
    // GOOGLE_CLIENT_ID y GOOGLE_CLIENT_SECRET el botón no aparece. El
    // callback pasa por el front (que reenvía /api al back) para que la
    // cookie de sesión quede en el dominio del front.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        // Vacía en el .env = se arma con FRONTEND_URL.
        'redirect' => env('GOOGLE_REDIRECT_URI') ?: rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/').'/api/v1/auth/google/callback',
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
