<?php

namespace OneOakDigital\CommonMailer;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use OneOakDigital\CommonMailer\Exceptions\CommonMailerException;
use Throwable;

/**
 * HTTP client of the mailer's messages API (/api/v1/messages).
 *
 * @phpstan-type MessageState array{
 *     id: string,
 *     status: string,
 *     delivery_status: string,
 *     delivery_status_at: string|null,
 *     message_id: string,
 *     profile: string|null,
 *     to: string,
 *     attempts: int,
 *     provider_message_id: string|null,
 *     last_error: string|null,
 *     created_at: string|null,
 *     sending_at: string|null,
 *     sent_at: string|null,
 *     failed_at: string|null
 * }
 */
class CommonMailerClient
{
    /**
     * @param  array{url?: string|null, token?: string|null, timeout?: int, connect_timeout?: int, retries?: int, retry_sleep_ms?: int}  $config
     */
    public function __construct(private Factory $http, private array $config) {}

    /**
     * Queue an email. Returns the queued email's state; the mailer answers
     * 202, or 200 when the Idempotency-Key replays an email queued earlier.
     *
     * @param  array<string, mixed>  $payload
     * @return MessageState
     *
     * @throws CommonMailerException
     */
    public function send(array $payload, string $idempotencyKey): array
    {
        return $this->data(fn (PendingRequest $request) => $request
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('messages', $payload));
    }

    /**
     * The state of an email queued by this site, by the id send() returned.
     *
     * @return MessageState
     *
     * @throws CommonMailerException
     */
    public function message(string $id): array
    {
        return $this->data(fn (PendingRequest $request) => $request->get('messages/' . rawurlencode($id)));
    }

    /**
     * Run the request and return the "data" of a successful answer.
     *
     * @param  callable(PendingRequest): Response  $call
     * @return MessageState
     *
     * @throws CommonMailerException
     */
    private function data(callable $call): array
    {
        try {
            $response = $call($this->request());
        } catch (ConnectionException $exception) {
            throw new CommonMailerException('The mailer could not be reached: ' . $exception->getMessage(), 0, $exception);
        }

        if ( ! $response->successful()) {
            throw CommonMailerException::fromResponse($response);
        }

        $data = $response->json('data');

        if ( ! is_array($data) || ! is_string($data['id'] ?? null)) {
            throw new CommonMailerException('The mailer answered without an email (HTTP ' . $response->status() . ').');
        }

        /** @var MessageState $data */
        return $data;
    }

    /**
     * Authenticated JSON request to /api/v1, retried on connection errors,
     * 429 and 5xx.
     *
     * @throws CommonMailerException
     */
    private function request(): PendingRequest
    {
        $url = $this->config['url'] ?? null;
        $token = $this->config['token'] ?? null;

        if ( ! is_string($url) || '' === $url || ! is_string($token) || '' === $token) {
            throw new CommonMailerException('The mailer URL and token are not configured (COMMON_MAILER_URL, COMMON_MAILER_TOKEN).');
        }

        return $this->http
            ->baseUrl(mb_rtrim($url, '/') . '/api/v1/')
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout((int) ($this->config['timeout'] ?? 10))
            ->connectTimeout((int) ($this->config['connect_timeout'] ?? 5))
            ->retry(
                1 + max(0, (int) ($this->config['retries'] ?? 2)),
                max(0, (int) ($this->config['retry_sleep_ms'] ?? 200)),
                fn (Throwable $exception): bool => $this->isRetryable($exception),
                throw: false,
            );
    }

    /**
     * Whether a failed attempt may be repeated.
     */
    private function isRetryable(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        return $exception instanceof RequestException
            && ($exception->response->serverError() || 429 === $exception->response->status());
    }
}
