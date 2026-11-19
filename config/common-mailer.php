<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mailer API
    |--------------------------------------------------------------------------
    |
    | Base URL of the mailer (without /api/v1), e.g.
    | https://mailer.prod.gleenetmedia.com, and the site's access token.
    |
    */

    'url' => env('COMMON_MAILER_URL'),

    'token' => env('COMMON_MAILER_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Default Profile
    |--------------------------------------------------------------------------
    |
    | Handle of the mailer profile used when an email does not choose one
    | with the X-Mailer-Profile header. A mailer in config/mail.php may set
    | its own "profile".
    |
    */

    'profile' => env('COMMON_MAILER_PROFILE'),

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    |
    | Timeouts in seconds. Connection errors, 429 and 5xx answers are retried
    | "retries" more times, "retry_sleep_ms" apart, with the same
    | Idempotency-Key, so a retry never queues the email twice.
    |
    */

    'timeout' => (int) env('COMMON_MAILER_TIMEOUT', 10),

    'connect_timeout' => (int) env('COMMON_MAILER_CONNECT_TIMEOUT', 5),

    'retries' => (int) env('COMMON_MAILER_RETRIES', 2),

    'retry_sleep_ms' => (int) env('COMMON_MAILER_RETRY_SLEEP_MS', 200),

    /*
    |--------------------------------------------------------------------------
    | Feedback from the mailer
    |--------------------------------------------------------------------------
    |
    | The mailer POSTs feedback (e.g. a recipient became undeliverable) to
    | https://<site domain>/<path>, signed with the token above (see
    | FeedbackSignature); each one is dispatched as a Laravel event, e.g.
    | MailerRecipientUndeliverableEvent. "tolerance" is how far, in seconds,
    | the signature's time may be from now. "middleware" is added before the
    | signature check (none needed: no session, no CSRF).
    |
    */

    'feedback' => [
        'enabled' => (bool) env('COMMON_MAILER_FEEDBACK_ENABLED', true),
        'path' => env('COMMON_MAILER_FEEDBACK_PATH', 'api/v1/mailer/feedback'),
        'middleware' => [],
        'tolerance' => (int) env('COMMON_MAILER_FEEDBACK_TOLERANCE', 300),
    ],

];
