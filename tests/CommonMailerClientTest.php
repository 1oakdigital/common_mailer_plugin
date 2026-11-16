<?php

namespace OneOakDigital\CommonMailer\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use OneOakDigital\CommonMailer\CommonMailerClient;
use OneOakDigital\CommonMailer\Exceptions\CommonMailerException;

class CommonMailerClientTest extends TestCase
{
    public function test_it_reads_the_state_of_an_email(): void
    {
        Http::fake(['mailer.test/api/v1/messages/01a1e0df-3936-7e35-a1b0-4c79c021fa02' => Http::response($this->queuedResponse())]);

        $state = $this->app->make(CommonMailerClient::class)->message('01a1e0df-3936-7e35-a1b0-4c79c021fa02');

        $this->assertSame('queued', $state['status']);
        Http::assertSent(fn (Request $request): bool => 'GET' === $request->method()
            && $request->hasHeader('Authorization', 'Bearer site-token'));
    }

    public function test_an_unknown_email_is_an_exception(): void
    {
        Http::fake(['mailer.test/*' => Http::response(['message' => 'Not Found'], 404)]);

        $this->expectException(CommonMailerException::class);
        $this->expectExceptionMessage('HTTP 404');

        $this->app->make(CommonMailerClient::class)->message('01a1e0df-3936-7e35-a1b0-4c79c021fa02');
    }
}
