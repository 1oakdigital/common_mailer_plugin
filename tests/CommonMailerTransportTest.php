<?php

namespace OneOakDigital\CommonMailer\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use OneOakDigital\CommonMailer\Exceptions\CommonMailerException;
use OneOakDigital\CommonMailer\Transport\CommonMailerTransport;

class CommonMailerTransportTest extends TestCase
{
    public function test_it_posts_the_email_to_the_mailer(): void
    {
        Http::fake(['mailer.test/api/v1/messages' => Http::response($this->queuedResponse(), 202)]);

        Mail::html('<p>Hi Jan</p>', function (Message $message): void {
            $message->from('account@example.com', 'Example')
                ->to('jan@example.com', 'Jan')
                ->replyTo('support@example.com')
                ->subject('Welcome')
                ->text('Hi Jan');
            $message->getSymfonyMessage()->getHeaders()->addTextHeader('List-Unsubscribe', '<https://example.com/u/1>');
            $message->getSymfonyMessage()->getHeaders()->addTextHeader('X-Order', '123');
            $message->getSymfonyMessage()->getHeaders()->addTextHeader('Importance', 'high');
        });

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => 'POST' === $request->method()
                && self::MAILER_URL . '/api/v1/messages' === $request->url()
                && $request->hasHeader('Authorization', 'Bearer site-token')
                && $request->hasHeader('Accept', 'application/json')
                && 1 === preg_match('/^[0-9a-f-]{36}$/', $request->header('Idempotency-Key')[0] ?? '')
                && [
                    'profile' => 'default-profile',
                    'from' => ['address' => 'account@example.com', 'name' => 'Example'],
                    'to' => ['address' => 'jan@example.com', 'name' => 'Jan'],
                    'reply_to' => ['address' => 'support@example.com'],
                    'subject' => 'Welcome',
                    'html' => '<p>Hi Jan</p>',
                    'text' => 'Hi Jan',
                    'headers' => ['List-Unsubscribe' => '<https://example.com/u/1>', 'X-Order' => '123'],
                ] === $request->data());
    }

    public function test_the_sent_message_id_is_the_mailers_mail_id(): void
    {
        Http::fake(['mailer.test/*' => Http::response($this->queuedResponse('01a1e0df-0000-7e35-a1b0-4c79c021fa02'), 202)]);

        $sent = Mail::raw('Hi', fn (Message $message) => $message->from('a@example.com')->to('b@example.com')->subject('S'));

        $this->assertSame('01a1e0df-0000-7e35-a1b0-4c79c021fa02', $sent?->getMessageId());
    }

    public function test_headers_choose_the_profile_and_idempotency_key_and_are_not_sent_on(): void
    {
        Http::fake(['mailer.test/*' => Http::response($this->queuedResponse(), 202)]);

        Mail::raw('Hi', function (Message $message): void {
            $message->from('a@example.com')->to('b@example.com')->subject('S');
            $message->getHeaders()->addTextHeader('X-Mailer-Profile', 'signup');
            $message->getHeaders()->addTextHeader('X-Mailer-Idempotency-Key', 'order-123-welcome');
            $message->getHeaders()->addTextHeader('X-Mailer-Id', 'spoofed');
        });

        Http::assertSent(fn (Request $request): bool => 'signup' === $request['profile']
            && ['order-123-welcome'] === $request->header('Idempotency-Key')
            && ! isset($request['headers']));
    }

    public function test_the_x_mailer_priority_header_sets_the_priority_and_is_not_sent_on(): void
    {
        Http::fake(['mailer.test/*' => Http::response($this->queuedResponse(), 202)]);

        Mail::raw('Hi', function (Message $message): void {
            $message->from('a@example.com')->to('b@example.com')->subject('S');
            $message->getHeaders()->addTextHeader('X-Mailer-Priority', '4');
        });

        Http::assertSent(fn (Request $request): bool => 4 === $request['priority']
            && ! isset($request['headers']));
    }

    public function test_without_the_x_mailer_priority_header_no_priority_is_sent(): void
    {
        Http::fake(['mailer.test/*' => Http::response($this->queuedResponse(), 202)]);

        Mail::raw('Hi', fn (Message $message) => $message->from('a@example.com')->to('b@example.com')->subject('S'));

        Http::assertSent(fn (Request $request): bool => ! array_key_exists('priority', $request->data()));
    }

    public function test_an_invalid_x_mailer_priority_header_is_refused(): void
    {
        Http::fake();

        $this->expectException(CommonMailerException::class);
        $this->expectExceptionMessage('X-Mailer-Priority header must be 1 (most urgent) to 5, "6" given');

        Mail::raw('Hi', function (Message $message): void {
            $message->from('a@example.com')->to('b@example.com')->subject('S');
            $message->getHeaders()->addTextHeader('X-Mailer-Priority', '6');
        });
    }

    public function test_a_mailer_may_set_its_own_profile(): void
    {
        $this->app['config']->set('mail.mailers.newsletter', ['transport' => 'common-mailer', 'profile' => 'newsletter']);
        Http::fake(['mailer.test/*' => Http::response($this->queuedResponse(), 202)]);

        Mail::mailer('newsletter')->raw('Hi', fn (Message $message) => $message->from('a@example.com')->to('b@example.com')->subject('S'));

        Http::assertSent(fn (Request $request): bool => 'newsletter' === $request['profile']);
    }

    public function test_a_mailer_may_set_its_profile_in_options_like_the_ses_transport(): void
    {
        $this->app['config']->set('mail.mailers.signup', [
            'transport' => 'common-mailer',
            'profile' => 'ignored',
            'options' => ['profile' => 'signup'],
        ]);
        Http::fake(['mailer.test/*' => Http::response($this->queuedResponse(), 202)]);

        $transport = Mail::mailer('signup')->getSymfonyTransport();
        $this->assertInstanceOf(CommonMailerTransport::class, $transport);
        $this->assertSame(['profile' => 'signup'], $transport->getOptions());

        Mail::mailer('signup')->raw('Hi', fn (Message $message) => $message->from('a@example.com')->to('b@example.com')->subject('S'));

        Http::assertSent(fn (Request $request): bool => 'signup' === $request['profile']);
    }

    public function test_the_x_mailer_profile_header_wins_over_the_options_profile(): void
    {
        $this->app['config']->set('mail.mailers.signup', ['transport' => 'common-mailer', 'options' => ['profile' => 'signup']]);
        Http::fake(['mailer.test/*' => Http::response($this->queuedResponse(), 202)]);

        Mail::mailer('signup')->raw('Hi', fn (Message $message) => $message->from('a@example.com')->to('b@example.com')->subject('S')
            ->getHeaders()->addTextHeader('X-Mailer-Profile', 'notify'));

        Http::assertSent(fn (Request $request): bool => 'notify' === $request['profile']);
    }

    public function test_attachments_are_sent_base64_encoded(): void
    {
        Http::fake(['mailer.test/*' => Http::response($this->queuedResponse(), 202)]);

        Mail::raw('Hi', fn (Message $message) => $message->from('a@example.com')->to('b@example.com')->subject('S')
            ->attachData('Hello', 'terms.txt', ['mime' => 'text/plain']));

        Http::assertSent(fn (Request $request): bool => [['filename' => 'terms.txt', 'content' => 'SGVsbG8=', 'mime_type' => 'text/plain']] === $request['attachments']);
    }

    public function test_cc_and_bcc_are_refused_before_calling_the_mailer(): void
    {
        Http::fake();

        $this->expectException(CommonMailerException::class);
        $this->expectExceptionMessage('Cc and Bcc recipients are not supported');

        try {
            Mail::raw('Hi', fn (Message $message) => $message->from('a@example.com')->to('b@example.com')->cc('c@example.com')->subject('S'));
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_more_than_one_to_recipient_is_refused(): void
    {
        Http::fake();

        $this->expectException(CommonMailerException::class);
        $this->expectExceptionMessage('more than one To address');

        Mail::raw('Hi', fn (Message $message) => $message->from('a@example.com')->to(['b@example.com', 'c@example.com'])->subject('S'));
    }

    public function test_inline_attachments_are_refused(): void
    {
        Http::fake();

        $this->expectException(CommonMailerException::class);
        $this->expectExceptionMessage('inline (embedded) attachments are not supported');

        Mail::send([], [], function (Message $message): void {
            $message->from('a@example.com')->to('b@example.com')->subject('S');
            $message->html('<img src="' . $message->embedData('png', 'logo.png', 'image/png') . '">');
        });
    }

    public function test_an_email_without_a_profile_is_refused(): void
    {
        $this->app['config']->set('common-mailer.profile', null);
        Http::fake();

        $this->expectException(CommonMailerException::class);
        $this->expectExceptionMessage('no profile');

        Mail::raw('Hi', fn (Message $message) => $message->from('a@example.com')->to('b@example.com')->subject('S'));
    }

    public function test_a_validation_error_is_reported_with_its_fields(): void
    {
        Http::fake(['mailer.test/*' => Http::response([
            'message' => 'The selected from.address is invalid.',
            'errors' => ['from.address' => ['The selected from.address is invalid.']],
        ], 422)]);

        try {
            Mail::raw('Hi', fn (Message $message) => $message->from('a@example.com')->to('b@example.com')->subject('S'));
            $this->fail('No exception was thrown.');
        } catch (CommonMailerException $exception) {
            $this->assertSame(422, $exception->status());
            $this->assertSame(['from.address' => ['The selected from.address is invalid.']], $exception->errors());
            $this->assertStringContainsString('from.address: The selected from.address is invalid.', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_server_errors_are_retried_with_the_same_idempotency_key(): void
    {
        Http::fake(['mailer.test/*' => Http::sequence()
            ->push(['message' => 'Server Error'], 503)
            ->push(['message' => 'Too Many Attempts.'], 429)
            ->push($this->queuedResponse(), 202)]);

        Mail::raw('Hi', fn (Message $message) => $message->from('a@example.com')->to('b@example.com')->subject('S'));

        Http::assertSentCount(3);
        $keys = Http::recorded()->map(fn (array $pair) => $pair[0]->header('Idempotency-Key')[0])->unique();
        $this->assertCount(1, $keys);
    }

    public function test_it_gives_up_after_the_configured_retries(): void
    {
        $this->app['config']->set('common-mailer.retries', 1);
        Http::fake(['mailer.test/*' => Http::response(['message' => 'Server Error'], 500)]);

        try {
            Mail::raw('Hi', fn (Message $message) => $message->from('a@example.com')->to('b@example.com')->subject('S'));
            $this->fail('No exception was thrown.');
        } catch (CommonMailerException $exception) {
            $this->assertSame(500, $exception->status());
        }

        Http::assertSentCount(2);
    }

    public function test_client_errors_are_not_retried(): void
    {
        Http::fake(['mailer.test/*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $this->expectException(CommonMailerException::class);
        $this->expectExceptionMessage('HTTP 401');

        try {
            Mail::raw('Hi', fn (Message $message) => $message->from('a@example.com')->to('b@example.com')->subject('S'));
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_a_connection_error_becomes_a_transport_exception(): void
    {
        Http::fake(['mailer.test/*' => fn () => throw new ConnectionException('Connection refused')]);

        $this->expectException(CommonMailerException::class);
        $this->expectExceptionMessage('The mailer could not be reached');

        Mail::raw('Hi', fn (Message $message) => $message->from('a@example.com')->to('b@example.com')->subject('S'));
    }

    public function test_it_refuses_to_send_without_url_and_token(): void
    {
        $this->app['config']->set('common-mailer.token', null);

        $this->expectException(CommonMailerException::class);
        $this->expectExceptionMessage('COMMON_MAILER_TOKEN');

        Mail::raw('Hi', fn (Message $message) => $message->from('a@example.com')->to('b@example.com')->subject('S'));
    }

    public function test_the_mailer_uses_the_common_mailer_transport(): void
    {
        $this->assertSame('common-mailer', (string) Mail::mailer('common-mailer')->getSymfonyTransport());
    }
}
