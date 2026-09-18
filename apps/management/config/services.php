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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'cloudflare_images' => [
        'account_id' => env('CLOUDFLARE_IMAGES_ACCOUNT_ID'),
        'api_token' => env('CLOUDFLARE_IMAGES_API_TOKEN'),
        'delivery_base_url' => env('CLOUDFLARE_IMAGES_DELIVERY_BASE_URL'),
        'variant' => env('CLOUDFLARE_IMAGES_VARIANT', 'public'),
        'direct_upload_expiry_minutes' => (int) env('CLOUDFLARE_IMAGES_DIRECT_UPLOAD_EXPIRY_MINUTES', 10),
    ],

    'property_images' => [
        'driver' => env('PROPERTY_IMAGE_UPLOAD_DRIVER', 'cloudflare_images'),
    ],

    'cloudflare_r2' => [
        'direct_upload_expiry_minutes' => (int) env('R2_DIRECT_UPLOAD_EXPIRY_MINUTES', 10),
    ],

    'ai' => [
        'token' => env('AI_SERVICE_TOKEN'),
    ],

    'admin' => [
        'email' => env('ADMIN_EMAIL', 'admin@example.com'),
        'password' => env('ADMIN_PASSWORD'),
        'name' => env('ADMIN_NAME', 'Administrator'),
        'seed_demo_data' => env('SEED_DEMO_DATA', false),
    ],

    'firebase' => [
        'project_id' => env('FIREBASE_PROJECT_ID', 'monica-88d72'),
    ],

];
