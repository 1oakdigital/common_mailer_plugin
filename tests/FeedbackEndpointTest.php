<?php

namespace OneOakDigital\CommonMailer\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use OneOakDigital\CommonMailer\CommonMailerServiceProvider;
use OneOakDigital\CommonMailer\Enums\EMailerUndeliverableReason;
use OneOakDigital\CommonMailer\Events\MailerRecipientUndeliverableEvent;
use OneOakDigital\CommonMailer\Feedback\FeedbackSignature;
use PHPUnit\Framework\Attributes\DataProvider;

class FeedbackEndpointTest extends TestCase
{
    private const string FEEDBACK_ID = '019a9b2e-6a3c-7d1e-9f00-4c79c021fa02';

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([MailerRecipientUndeliverableEvent::class]);
    }

    /**
     * @return array<string, array{string, EMailerUndeliverableReason}>
     */
    public static function reasonProvider(): array
    {
        return [
            'does not exist' => ['does_not_exist', EMailerUndeliverableReason::DoesNotExist],
            'suppression list' => ['suppression_list', EMailerUndeliverableReason::SuppressionList],
            'account suppression list' => ['account_suppression_list', EMailerUndeliverableReason::AccountSuppressionList],
            'complained' => ['complained', EMailerUndeliverableReason::Complained],
            'repeated bounces' => ['repeated_bounces', EMailerUndeliverableReason::RepeatedBounces],
            'newer reason' => ['blocked_by_owner', EMailerUndeliverableReason::Unknown],
        ];
    }

    public function test_a_feedback_signed_with_another_token_is_unauthorized(): void
    {
        $this->postFeedback($this->feedback(), signingToken: 'other-token')->assertUnauthorized();

        Event::assertNotDispatched(MailerRecipientUndeliverableEvent::class);
    }

    public function test_a_feedback_without_a_signature_is_unauthorized(): void
    {
        $this->postJson('/api/v1/mailer/feedback', $this->feedback())->assertUnauthorized();

        Event::assertNotDispatched(MailerRecipientUndeliverableEvent::class);
    }

    public function test_a_signature_of_another_token_of_the_site_is_accepted(): void
    {
        $body = (string) json_encode($this->feedback());
        $header = FeedbackSignature::sign($body, time(), [FeedbackSignature::key('old-token'), FeedbackSignature::key('site-token')]);

        $this->call('POST', '/api/v1/mailer/feedback', server: ['HTTP_X_MAILER_SIGNATURE' => $header, 'CONTENT_TYPE' => 'application/json'], content: $body)
            ->assertAccepted();

        Event::assertDispatched(MailerRecipientUndeliverableEvent::class);
    }

    public function test_a_stale_signature_is_unauthorized(): void
    {
        $this->postFeedback($this->feedback(), timestamp: time() - 301)->assertUnauthorized();
    }

    public function test_an_invalid_recipient_is_rejected(): void
    {
        $this->postFeedback($this->feedback(['email' => 'not-an-address']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        Event::assertNotDispatched(MailerRecipientUndeliverableEvent::class);
    }

    public function test_an_unknown_event_is_accepted_and_ignored(): void
    {
        $this->postFeedback(['event' => 'recipient_moved'] + $this->feedback())
            ->assertAccepted()
            ->assertExactJson(['status' => 'ignored']);

        Event::assertNotDispatched(MailerRecipientUndeliverableEvent::class);
    }

    #[DataProvider('reasonProvider')]
    public function test_it_dispatches_the_undeliverable_event_with_its_reason(string $rawReason, EMailerUndeliverableReason $reason): void
    {
        $this->postFeedback($this->feedback(['reason' => $rawReason]))
            ->assertAccepted()
            ->assertExactJson(['status' => 'accepted']);

        Event::assertDispatched(MailerRecipientUndeliverableEvent::class, fn (MailerRecipientUndeliverableEvent $event): bool => 'jan+news@gmail.com' === $event->email
            && $reason === $event->reason
            && $rawReason === $event->rawReason
            && $event->occurredAt->equalTo(CarbonImmutable::parse('2026-11-19T10:00:00+00:00'))
            && self::FEEDBACK_ID === $event->feedbackId);
    }

    public function test_the_route_can_be_disabled(): void
    {
        $this->assertFalse(Route::has(CommonMailerServiceProvider::FEEDBACK_ROUTE));
    }

    public function test_without_a_configured_token_nothing_is_accepted(): void
    {
        $this->app['config']->set('common-mailer.token', null);

        $this->postFeedback($this->feedback(), signingToken: '')->assertUnauthorized();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        if ('test_the_route_can_be_disabled' === $this->name()) {
            $app['config']->set('common-mailer.feedback.enabled', false);
        }
    }

    /**
     * A feedback envelope; $data replaces fields of data.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function feedback(array $data = []): array
    {
        return [
            'id' => self::FEEDBACK_ID,
            'event' => 'recipient_undeliverable',
            'occurred_at' => '2026-11-19T10:00:00+00:00',
            'data' => array_merge(['email' => 'jan+news@gmail.com', 'reason' => 'does_not_exist'], $data),
        ];
    }

    /**
     * POST the feedback signed like the mailer does.
     *
     * @param  array<string, mixed>  $feedback
     */
    private function postFeedback(array $feedback, string $signingToken = 'site-token', ?int $timestamp = null): TestResponse
    {
        $body = (string) json_encode($feedback);
        $header = FeedbackSignature::sign($body, $timestamp ?? time(), [FeedbackSignature::key($signingToken)]);

        return $this->call('POST', '/api/v1/mailer/feedback', server: ['HTTP_X_MAILER_SIGNATURE' => $header, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: $body);
    }
}
