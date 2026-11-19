<?php

namespace OneOakDigital\CommonMailer;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use OneOakDigital\CommonMailer\Http\Actions\ReceiveFeedbackAction;
use OneOakDigital\CommonMailer\Http\Middleware\VerifyFeedbackSignature;
use OneOakDigital\CommonMailer\Transport\CommonMailerTransport;

/**
 * Registers the "common-mailer" mail transport, CommonMailerClient and the
 * route receiving the mailer's feedback.
 */
class CommonMailerServiceProvider extends ServiceProvider
{
    /**
     * Name of the transport in config/mail.php ("transport" => "common-mailer").
     */
    public const string TRANSPORT = 'common-mailer';

    /**
     * Name of the feedback route.
     */
    public const string FEEDBACK_ROUTE = 'common-mailer.feedback';

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

        $this->registerFeedbackRoute();

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

    /**
     * POST common-mailer.feedback.path, unless feedback is disabled or the
     * routes are cached.
     */
    private function registerFeedbackRoute(): void
    {
        $config = (array) $this->app['config']->get('common-mailer.feedback');

        if ( ! ($config['enabled'] ?? false) || $this->app->routesAreCached()) {
            return;
        }

        Route::post((string) $config['path'])
            ->middleware([...(array) ($config['middleware'] ?? []), VerifyFeedbackSignature::class])
            ->uses([ReceiveFeedbackAction::class, 'handle'])
            ->name(self::FEEDBACK_ROUTE);
    }
}
