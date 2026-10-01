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

    // MTN SMS API (spec section 7.2). Built to the OAuth2 client-credentials flow and the
    // POST /sms + GET /sms/{id}/status contract in MTN's published Swagger. Every URL is configurable
    // because MTN Uganda issues sandbox and production values with your developer account.
    'mtn_sms' => [
        'base_url' => rtrim((string) env('MTN_SMS_BASE_URL', 'https://api.mtn.com/v1/messages'), '/'),
        'token_url' => env('MTN_SMS_TOKEN_URL', 'https://api.mtn.com/oauth/client_credential/accesstoken'),
        'client_id' => env('MTN_SMS_CLIENT_ID'),
        'client_secret' => env('MTN_SMS_CLIENT_SECRET'),
        // Sender ID / short code registered with MTN for the university.
        'sender' => env('MTN_SMS_SENDER'),
        // Free-text label MTN echoes back; identifies this application in their logs.
        'client_ref' => env('MTN_SMS_CLIENT_REF', 'sunates'),
    ],

    // WhatsApp Business Platform, Cloud API (spec section 7.3).
    'whatsapp' => [
        'graph_url' => rtrim((string) env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'), '/'),
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v21.0'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        // Shared secret Meta echoes when you register the webhook URL (you choose it).
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        // The Meta app secret; used to check the X-Hub-Signature-256 header on webhook posts.
        'app_secret' => env('WHATSAPP_APP_SECRET'),
    ],

];
