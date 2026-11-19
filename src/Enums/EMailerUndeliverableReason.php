<?php

namespace OneOakDigital\CommonMailer\Enums;

/**
 * Why the mailer stopped sending to a recipient (feedback data.reason).
 */
enum EMailerUndeliverableReason: string
{
    // The mailbox does not exist (hard bounce, NoEmail).
    case DoesNotExist = 'does_not_exist';

    // The address is on the provider's suppression list.
    case SuppressionList = 'suppression_list';

    // The address is on the sending account's suppression list.
    case AccountSuppressionList = 'account_suppression_list';

    // The recipient marked an email as spam.
    case Complained = 'complained';

    // Too many bounces in a row without a delivery.
    case RepeatedBounces = 'repeated_bounces';

    // A reason this version of the plugin does not know (see the raw value).
    case Unknown = 'unknown';

    /**
     * The reason sent by the mailer; Unknown for a missing or newer one.
     */
    public static function fromFeedback(?string $reason): self
    {
        return null === $reason ? self::Unknown : self::tryFrom($reason) ?? self::Unknown;
    }
}
