<?php

namespace OneOakDigital\CommonMailer\Exceptions;

use Illuminate\Http\Client\Response;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * The mailer could not be reached, refused the request, or the email cannot
 * be expressed in the mailer's API.
 */
class CommonMailerException extends TransportException
{
    /**
     * HTTP status of the mailer's answer; null when there was no answer.
     */
    private ?int $status = null;

    /**
     * Validation errors of a 422 answer, by field.
     *
     * @var array<string, array<int, string>>
     */
    private array $errors = [];

    /**
     * The mailer answered with an error status.
     */
    public static function fromResponse(Response $response): self
    {
        $message = $response->json('message');
        $message = is_string($message) && '' !== $message ? $message : $response->reason();

        $errors = $response->json('errors');
        $errors = is_array($errors) ? $errors : [];

        $details = [];

        foreach ($errors as $field => $fieldErrors) {
            $details[] = $field . ': ' . implode(' ', (array) $fieldErrors);
        }

        $exception = new self(
            'The mailer rejected the email (HTTP ' . $response->status() . '): ' . $message
            . ([] === $details ? '' : ' [' . implode('; ', $details) . ']'),
            $response->status(),
        );

        $exception->status = $response->status();
        $exception->errors = $errors;

        return $exception;
    }

    /**
     * The email uses something the mailer's API does not support.
     */
    public static function unsupported(string $reason): self
    {
        return new self('The email cannot be sent through the mailer: ' . $reason);
    }

    /**
     * HTTP status of the mailer's answer; null when there was no answer.
     */
    public function status(): ?int
    {
        return $this->status;
    }

    /**
     * Validation errors of a 422 answer, by field.
     *
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
