<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where alumni open links from messages
    |--------------------------------------------------------------------------
    | The alumni PWA. Survey and nudge messages link here. In production this is normally the same
    | origin as the API. The "stop messaging me" link points at APP_URL (this Laravel app).
    */
    'pwa_url' => rtrim((string) env('SUNATES_PWA_URL', 'http://127.0.0.1:5173'), '/'),

    // Quiet hours and "due" dates are evaluated in Uganda time regardless of the server clock.
    'timezone' => env('SUNATES_TIMEZONE', 'Africa/Kampala'),

    /*
    |--------------------------------------------------------------------------
    | Tracer surveys (FR-3)
    |--------------------------------------------------------------------------
    */
    'surveys' => [
        // How long an invitation stays open after its milestone.
        'window_days' => (int) env('SURVEY_WINDOW_DAYS', 90),

        // Days after the first message to remind someone who has not answered (one reminder per entry).
        'reminder_days' => [7, 21],

        // Graduation date is optional in the Registrar's data. When only the year is known, assume the
        // end of that year, so a survey is never sent early.
        'fallback_graduation_month_day' => '12-31',

        // Alumni imported from Registrar spreadsheets who have not registered. Default off: they have
        // not been through the consent step, so switch this on only after the data-protection review
        // confirms the university may contact them for the tracer study.
        'include_unclaimed' => (bool) env('SURVEY_INCLUDE_UNCLAIMED', false),

        // "Strong" graduates for teaching-assistant consideration: classes of award, lower-case.
        // Confirm the exact wording of the Registrar's class-of-award values.
        'ta_eligible_classes' => ['first class', 'second class upper', 'upper second class'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Data-freshness nudges (FR-7)
    |--------------------------------------------------------------------------
    */
    'nudges' => [
        'enabled' => (bool) env('NUDGES_ENABLED', true),

        // A record is "stale" when the alumnus has not confirmed it (profile edit or survey) this long.
        'stale_after_days' => 365,

        // Never nudge the same person more often than this, or more than max_per_year times a year.
        'min_gap_days' => 90,
        'max_per_year' => 3,

        // Do not nudge someone who received any message from us in the last few days (e.g. a survey).
        'cooldown_after_any_message_days' => 14,

        // Protects the SMS budget: the most nudges one daily run will send.
        'daily_limit' => (int) env('NUDGES_DAILY_LIMIT', 200),

        // Invite Registrar-imported alumni who have not registered. Same consent caveat as surveys.
        'include_unclaimed' => (bool) env('NUDGES_INCLUDE_UNCLAIMED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Messaging
    |--------------------------------------------------------------------------
    */
    'messaging' => [
        // "log" writes to the Laravel log and sends nothing (safe default for development and CI).
        'sms_driver' => env('SMS_DRIVER', 'log'),        // log | mtn
        'whatsapp_driver' => env('WHATSAPP_DRIVER', 'log'), // log | cloud

        // Tried in this order; a permanent failure on one falls through to the next.
        'channel_priority' => ['whatsapp', 'sms'],

        // No messages between these hours (24h clock, Uganda time). Queued jobs wait until morning.
        'quiet_hours' => ['start' => 19, 'end' => 8],

        // Numbers typed without a country code (0700 123456) are assumed to be Ugandan.
        'default_country_code' => '256',

        // WhatsApp business-initiated messages must be pre-approved templates in Meta Business Manager.
        // Body parameters are sent in the order listed under "params" in MessageTemplate.
        'whatsapp_templates' => [
            'survey_invite' => env('WHATSAPP_TEMPLATE_SURVEY_INVITE', 'sunates_survey_invite'),
            'survey_reminder' => env('WHATSAPP_TEMPLATE_SURVEY_REMINDER', 'sunates_survey_reminder'),
            'profile_nudge' => env('WHATSAPP_TEMPLATE_PROFILE_NUDGE', 'sunates_profile_nudge'),
            'register_invite' => env('WHATSAPP_TEMPLATE_REGISTER_INVITE', 'sunates_register_invite'),
            'test' => env('WHATSAPP_TEMPLATE_TEST', 'hello_world'),
        ],
        'whatsapp_language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'en'),
    ],
];
