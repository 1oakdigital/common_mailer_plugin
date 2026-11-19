<?php

namespace OneOakDigital\CommonMailer\Http\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OneOakDigital\CommonMailer\Enums\EMailerUndeliverableReason;
use OneOakDigital\CommonMailer\Events\MailerRecipientUndeliverableEvent;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST {common-mailer.feedback.path}: feedback from the mailer (signature
 * already checked by VerifyFeedbackSignature), turned into a Laravel event.
 *
 *     {"id": "<uuid>", "event": "recipient_undeliverable", "occurred_at": "<ISO 8601>",
 *      "data": {"email": "jan@gmail.com", "reason": "does_not_exist"}}
 *
 * An event this version does not know is accepted and ignored, so the mailer
 * never retries it.
 */
class ReceiveFeedbackAction
{
    /**
     * The event of the "recipient_undeliverable" feedback.
     */
    public const string RECIPIENT_UNDELIVERABLE = 'recipient_undeliverable';

    public function __construct(private Factory $validator) {}

    public function handle(Request $request): JsonResponse
    {
        $envelope = $this->validated($request->all(), [
            'id' => ['required', 'string', 'max:64'],
            'event' => ['required', 'string', 'max:64'],
            'occurred_at' => ['required', 'date'],
            'data' => ['present', 'array'],
        ]);

        if ($envelope instanceof JsonResponse) {
            return $envelope;
        }

        if (self::RECIPIENT_UNDELIVERABLE !== $envelope['event']) {
            return new JsonResponse(['status' => 'ignored'], Response::HTTP_ACCEPTED);
        }

        $data = $this->validated((array) $envelope['data'], [
            'email' => ['required', 'string', 'max:254', 'email'],
            'reason' => ['nullable', 'string', 'max:64'],
        ]);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        MailerRecipientUndeliverableEvent::dispatch(
            (string) $data['email'],
            EMailerUndeliverableReason::fromFeedback($data['reason'] ?? null),
            $data['reason'] ?? null,
            CarbonImmutable::parse((string) $envelope['occurred_at']),
            (string) $envelope['id'],
        );

        return new JsonResponse(['status' => 'accepted'], Response::HTTP_ACCEPTED);
    }

    /**
     * The validated input, or a 422 answer with the errors (JSON whatever the
     * request's Accept header, unlike validate()).
     *
     * @param  array<mixed>  $input
     * @param  array<string, list<string>>  $rules
     * @return array<string, mixed>|JsonResponse
     */
    private function validated(array $input, array $rules): array|JsonResponse
    {
        $validator = $this->validator->make($input, $rules);

        if ($validator->fails()) {
            return new JsonResponse(['message' => 'The feedback is invalid.', 'errors' => $validator->errors()->toArray()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $validator->validated();
    }
}
