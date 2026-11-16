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

];
