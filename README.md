# Common Mailer plugin

A Laravel 13 mail transport, `common-mailer`. It hands every email to the common mailer
(`common_mailer`, `POST /api/v1/messages`), which queues it and sends it through the chosen profile.
Mailables, notifications and `Mail::` calls work unchanged.

## Installation

```bash
composer require 1oakdigital/common_mailer_plugin
```

The service provider is auto-discovered. Add a mailer to `config/mail.php`:

```php
'mailers' => [
    'common-mailer' => [
        'transport' => 'common-mailer',
    ],
    // optional: more mailers on other profiles
    'newsletter' => [
        'transport' => 'common-mailer',
        'profile' => 'newsletter',
    ],
],
```

And set in `.env`:

```dotenv
MAIL_MAILER=common-mailer
COMMON_MAILER_URL=https://mailer.prod.gleenetmedia.com   # dev: https://mailer.dev.gleenetmedia.com
COMMON_MAILER_TOKEN=<the site's access token>
COMMON_MAILER_PROFILE=signup
```

| Key (`config/common-mailer.php`) | Env                            | Default | Notes |
|----------------------------------|--------------------------------|---------|-------|
| `url`                            | `COMMON_MAILER_URL`            | -       | Base URL, without `/api/v1`. |
| `token`                          | `COMMON_MAILER_TOKEN`          | -       | The site's access token (Bearer). |
| `profile`                        | `COMMON_MAILER_PROFILE`        | -       | Profile handle used when the email does not choose one. |
| `timeout`                        | `COMMON_MAILER_TIMEOUT`        | 10      | Seconds. |
| `connect_timeout`                | `COMMON_MAILER_CONNECT_TIMEOUT`| 5       | Seconds. |
| `retries`                        | `COMMON_MAILER_RETRIES`        | 2       | Extra attempts after a connection error, 429 or 5xx. |
| `retry_sleep_ms`                 | `COMMON_MAILER_RETRY_SLEEP_MS` | 200     | Pause between attempts. |

Any key may also be set on a mailer in `config/mail.php`; it wins over `config/common-mailer.php`.
Publish the config file with `php artisan vendor:publish --tag=common-mailer-config`.

## Choosing the profile and the idempotency key per email

Two headers control the request and are not sent on with the email:

| Header                     | Effect |
|----------------------------|--------|
| `X-Mailer-Profile`         | Profile handle for this email (otherwise the mailer's / the config's `profile`). |
| `X-Mailer-Idempotency-Key` | `Idempotency-Key` of the request (otherwise a random UUID per send). |

```php
use Illuminate\Mail\Mailables\Headers;

public function headers(): Headers
{
    return new Headers(text: [
        'X-Mailer-Profile' => 'signup',
        'X-Mailer-Idempotency-Key' => "welcome-{$this->user->id}",
    ]);
}
```

Use a stable idempotency key when the same email may be sent again (a queued mailable retried after a
timeout): the mailer then answers with the email it already queued instead of queueing it twice.

## What the mailer accepts

The transport maps the Symfony email to the API and refuses, before any request, what the API cannot take
(`CommonMailerException`, a Symfony `TransportException`):

- Exactly one `To` recipient, no `Cc` or `Bcc` - send one email per recipient.
- One `From` (one of the site's addresses in the mailer) and at most one `Reply-To`.
- An HTML and/or a text body.
- Attachments (sent base64); **inline / embedded (`cid:`) attachments are refused** - link images by URL.
- Headers: `X-*` and `List-Unsubscribe`, `List-Unsubscribe-Post`, `List-Id`, `Precedence`,
  `Auto-Submitted`, `In-Reply-To`, `References`. Others are dropped; the mailer sets From, To,
  Message-ID, Date, the MIME headers and `X-Mailer-Id` itself.

## Results and errors

- The mailer answers `202` (or `200` for a replayed idempotency key) once the email is **queued**, not sent.
  The sent message's id is the mailer's mail id:

  ```php
  $sent = Mail::to($user)->send(new Welcome($user));
  $mailId = $sent?->getMessageId();   // e.g. 01a1e0df-3936-7e35-a1b0-4c79c021fa02
  ```

  The same id is in `Illuminate\Mail\Events\MessageSent::$sent`.
- Read the email's state later (`status`: queued, sending, retrying, sent, failed; `delivery_status`:
  queued, sent, soft_bounce, mailbox_full, hard_bounce, timed_out, delivered):

  ```php
  use OneOakDigital\CommonMailer\CommonMailerClient;

  $state = app(CommonMailerClient::class)->message($mailId);
  ```

- Connection errors, `429` and `5xx` are retried `retries` times with the same idempotency key. After that,
  and for any other error status (`401` bad token, `409` key reused with a different email, `422` invalid
  email), a `CommonMailerException` is thrown; `status()` is the HTTP status and `errors()` the `422`
  validation errors by field.

## Development

```bash
composer install
usr/test   # PHPUnit
usr/lint   # Pint
```
