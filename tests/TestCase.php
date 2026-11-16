<?php

namespace OneOakDigital\CommonMailer\Tests;

use OneOakDigital\CommonMailer\CommonMailerServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Base URL of the mailer in the tests.
     */
    protected const string MAILER_URL = 'https://mailer.test';

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [CommonMailerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mail.default', 'common-mailer');
        $app['config']->set('mail.mailers.common-mailer', ['transport' => 'common-mailer']);
        $app['config']->set('common-mailer.url', self::MAILER_URL . '/');
        $app['config']->set('common-mailer.token', 'site-token');
        $app['config']->set('common-mailer.profile', 'default-profile');
        $app['config']->set('common-mailer.retry_sleep_ms', 0);
    }

    /**
     * The mailer's answer for a queued email.
     *
     * @return array{data: array<string, mixed>}
     */
    protected function queuedResponse(string $id = '01a1e0df-3936-7e35-a1b0-4c79c021fa02'): array
    {
        return [
            'data' => [
                'id' => $id,
                'status' => 'queued',
                'delivery_status' => 'queued',
                'delivery_status_at' => '2026-11-16T10:00:00+00:00',
                'message_id' => $id . '.abc@example.com',
                'profile' => 'default-profile',
                'to' => 'jan@example.com',
                'attempts' => 0,
                'provider_message_id' => null,
                'last_error' => null,
                'created_at' => '2026-11-16T10:00:00+00:00',
                'sending_at' => null,
                'sent_at' => null,
                'failed_at' => null,
            ],
        ];
    }
}
