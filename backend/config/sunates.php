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
    | SorotiUniERP hook (FR-9, spec section 7.1)
    |--------------------------------------------------------------------------
    | "none" is standalone mode: alumni come from Registrar spreadsheets and nothing here runs.
    | Once SorotiUniERP is live, point this at it and graduating students become alumni records on
    | their own, through the same rules as a spreadsheet import. Two ways to read the ERP, as the
    | spec allows: its REST API, or a read-only view in its database.
    */
    'erp' => [
        'driver' => env('ERP_DRIVER', 'none'), // none | rest | database

        // When the daily sync runs (Uganda time).
        'sync_at' => env('ERP_SYNC_AT', '02:30'),

        // Each sync asks only for records changed since the last one, minus this overlap, so clock or
        // time-zone differences between the two systems cannot drop a change. Re-reading is harmless.
        'overlap_hours' => 24,

        // Refuse a feed bigger than this: it is almost certainly a mis-set filter or a paging fault.
        'max_records' => (int) env('ERP_MAX_RECORDS', 100000),

        // A run still "running" after this long is presumed dead (worker stopped) and no longer blocks a new one.
        'stale_run_minutes' => 30,

        // Values of the ERP's status field that mean "has graduated". Only used if a status field is mapped
        // below; otherwise every record the ERP exposes is taken to be a graduate, so expose only graduates.
        'graduated_values' => ['graduated', 'alumnus', 'alumna', 'alumni'],

        // Our column => the ERP's field (REST: a key, dot notation allowed for nested data; database: a column).
        // null = the ERP does not provide it. Override any of these with ERP_FIELD_MAP, a JSON object, e.g.
        // ERP_FIELD_MAP='{"student_number":"regNo","last_name":"surname","graduation_date":"conferredOn"}'
        'fields' => array_replace([
            'student_number' => 'student_number',
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'other_names' => 'other_names',
            'gender' => 'gender',
            'date_of_birth' => 'date_of_birth',
            'school' => 'school',
            'department' => 'department',
            'programme' => 'programme',
            'graduation_year' => 'graduation_year',
            'graduation_date' => 'graduation_date',
            'class_of_award' => 'class_of_award',
            'email' => 'email',
            'phone' => 'phone',
            'status' => null,
            // Without a "last changed" field every sync re-reads the whole feed: fine, just slower.
            'updated_at' => null,
        ], (array) json_decode((string) env('ERP_FIELD_MAP', '[]'), true)),

        'rest' => [
            'base_url' => env('ERP_REST_BASE_URL'),
            'path' => env('ERP_REST_PATH', '/api/graduates'),
            'auth' => env('ERP_REST_AUTH', 'bearer'), // bearer | header | none
            'token' => env('ERP_REST_TOKEN'),
            'token_header' => env('ERP_REST_TOKEN_HEADER', 'X-Api-Key'),
            // Where the list sits in the JSON answer; an empty string means the answer is the list itself.
            'data_key' => env('ERP_REST_DATA_KEY', 'data'),
            'since_param' => env('ERP_REST_SINCE_PARAM', 'updated_since'),
            // Paging: the ERP is asked for page 1, 2, 3 ... until it answers with an empty list.
            'page_param' => env('ERP_REST_PAGE_PARAM', 'page'),
            'size_param' => env('ERP_REST_SIZE_PARAM', 'per_page'),
            'page_size' => (int) env('ERP_REST_PAGE_SIZE', 200),
            'timeout' => (int) env('ERP_REST_TIMEOUT', 30),
        ],

        'database' => [
            // A connection from config/database.php, ideally with a read-only database user.
            'connection' => env('ERP_DB_CONNECTION'),
            'table' => env('ERP_DB_TABLE', 'sunates_graduates'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Staff follow-up of non-responsive alumni (spec section 7.4 fallback)
    |--------------------------------------------------------------------------
    | LinkedIn offers no consented way to watch for job changes, so for alumni who never answer the
    | nudges, staff look them up by hand. This keeps that occasional, targeted and recorded.
    */
    'followup' => [
        // "Didn't respond": at least this many nudges in the last year and still no confirmation.
        'min_nudges' => 2,

        // Once staff have looked for someone, leave them alone this long before suggesting them again.
        'recheck_after_days' => (int) env('FOLLOWUP_RECHECK_AFTER_DAYS', 180),
    ],

    /*
    |--------------------------------------------------------------------------
    | Credential verification (FR-6)
    |--------------------------------------------------------------------------
    */
    'verification' => [
        // Spec section 9: lookup logs are kept apart from profile data. Name a second connection from
        // config/database.php to put them in their own database; null keeps them in the main one.
        'connection' => env('VERIFICATION_DB_CONNECTION'),

        // Lookup logs name employers and the people they asked about. Keep them as long as an audit
        // might need them, then delete them.
        'log_retention_days' => (int) env('VERIFICATION_LOG_RETENTION_DAYS', 730),

        // How long a link an alumnus gives an employer keeps working.
        'link_valid_days' => (int) env('VERIFICATION_LINK_VALID_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outcome dashboards (FR-4)
    |--------------------------------------------------------------------------
    */
    'dashboards' => [
        // Groups with fewer respondents than this are shown as "fewer than N" with no percentages, so
        // a table by small programme cannot reveal what one identifiable graduate answered.
        'min_cell_size' => (int) env('DASHBOARD_MIN_CELL_SIZE', 5),
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
