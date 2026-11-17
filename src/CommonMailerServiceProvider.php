<?php

namespace OneOakDigital\CommonMailer;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;
use OneOakDigital\CommonMailer\Transport\CommonMailerTransport;

/**
 * Registers the "common-mailer" mail transport and CommonMailerClient.
 */
class CommonMailerServiceProvider extends ServiceProvider
{
    /**
     * Name of the transport in config/mail.php ("transport" => "common-mailer").
     */
    public const string TRANSPORT = 'common-mailer';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/common-mailer.php', 'common-mailer');

        $this->app->singleton(
            CommonMailerClient::class,
            fn (Application $app): CommonMailerClient => new CommonMailerClient(
                $app->make(Factory::class),
                (array) $app['config']->get('common-mailer'),
            ),
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/common-mailer.php' => $this->app->configPath('common-mailer.php'),
            ], 'common-mailer-config');
        }

        $this->callAfterResolving('mail.manager', function (MailManager $manager): void {
            $manager->extend(self::TRANSPORT, function (array $mailerConfig): CommonMailerTransport {
                $config = array_merge(
                    (array) $this->app['config']->get('common-mailer'),
                    Arr::except($mailerConfig, ['transport', 'options']),
                );

                // Like the ses transport's "options": the mailer's options
                // win over its own keys and config/common-mailer.php.
                $options = array_merge(
                    ['profile' => $config['profile'] ?? null],
                    (array) ($mailerConfig['options'] ?? []),
                );

                return new CommonMailerTransport(
                    new CommonMailerClient($this->app->make(Factory::class), $config),
                    $options,
                );
            });
        });
    }
}
