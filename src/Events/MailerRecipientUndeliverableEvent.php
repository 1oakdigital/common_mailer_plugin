<?php

namespace OneOakDigital\CommonMailer\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;
use OneOakDigital\CommonMailer\Enums\EMailerUndeliverableReason;

/**
 * The mailer will not send to this address any more (feedback event
 * "recipient_undeliverable"): it does not exist, is suppressed, complained or
 * bounced too often. Listen to it to stop mailing the address, e.g. disable
 * the user's email.
 *
 * The mailer may send the same feedback again (retries); $feedbackId is the
 * same each time.
 */
class MailerRecipientUndeliverableEvent
{
    use Dispatchable;

    /**
     * @param  string|null  $rawReason  the reason as the mailer sent it (useful when $reason is Unknown)
     */
    public function __construct(
        public readonly string $email,
        public readonly EMailerUndeliverableReason $reason,
        public readonly ?string $rawReason,
        public readonly CarbonImmutable $occurredAt,
        public readonly string $feedbackId,
    ) {}
}
