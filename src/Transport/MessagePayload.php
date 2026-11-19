<?php

namespace OneOakDigital\CommonMailer\Transport;

use OneOakDigital\CommonMailer\Exceptions\CommonMailerException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Turns a Symfony Email into the body of POST /api/v1/messages.
 *
 * The mailer takes exactly one To recipient, no Cc/Bcc, one Reply-To,
 * no inline (cid:) attachments, and only X-* and a short list of other
 * headers; it sets From, To, Message-ID and the MIME headers itself.
 */
class MessagePayload
{
    /**
     * Chooses the mailer profile of one email; not sent on as a header.
     */
    public const string PROFILE_HEADER = 'X-Mailer-Profile';

    /**
     * Sets the priority of one email, 1 (most urgent) ... 5, instead of its
     * profile's default; not sent on as a header.
     */
    public const string PRIORITY_HEADER = 'X-Mailer-Priority';

    /**
     * Sets the Idempotency-Key of one email; not sent on as a header.
     */
    public const string IDEMPOTENCY_KEY_HEADER = 'X-Mailer-Idempotency-Key';

    /**
     * Non X-* headers the mailer accepts (its config('mailer.allowed_headers')).
     */
    private const array ALLOWED_HEADERS = [
        'list-unsubscribe',
        'list-unsubscribe-post',
        'list-id',
        'precedence',
        'auto-submitted',
        'in-reply-to',
        'references',
    ];

    /**
     * X-* headers that are never sent on: the three above and the mailer's own
     * X-Mailer-Id, which matches bounces to emails.
     */
    private const array RESERVED_HEADERS = [
        'x-mailer-profile',
        'x-mailer-priority',
        'x-mailer-idempotency-key',
        'x-mailer-id',
    ];

    /**
     * The request body of the email.
     *
     * @return array{
     *     profile: string,
     *     priority?: int,
     *     from: array{address: string, name?: string},
     *     to: array{address: string, name?: string},
     *     reply_to?: array{address: string, name?: string},
     *     subject: string,
     *     html?: string,
     *     text?: string,
     *     headers?: array<string, string>,
     *     attachments?: array<int, array{filename: string, content: string, mime_type: string}>
     * }
     *
     * @throws CommonMailerException
     */
    public static function fromEmail(Email $email, ?string $defaultProfile): array
    {
        $payload = ['profile' => self::profile($email, $defaultProfile)];

        $priority = self::priority($email);

        if (null !== $priority) {
            $payload['priority'] = $priority;
        }

        $payload['from'] = self::address(self::single($email->getFrom(), 'From', required: true));
        $payload['to'] = self::address(self::recipient($email));

        $replyTo = self::single($email->getReplyTo(), 'Reply-To', required: false);

        if (null !== $replyTo) {
            $payload['reply_to'] = self::address($replyTo);
        }

        $payload['subject'] = (string) $email->getSubject();

        $html = self::body($email->getHtmlBody());
        $text = self::body($email->getTextBody());

        if (null === $html && null === $text) {
            throw CommonMailerException::unsupported('it has neither an HTML nor a text body.');
        }

        if (null !== $html) {
            $payload['html'] = $html;
        }

        if (null !== $text) {
            $payload['text'] = $text;
        }

        $headers = self::headers($email);

        if ([] !== $headers) {
            $payload['headers'] = $headers;
        }

        $attachments = self::attachments($email);

        if ([] !== $attachments) {
            $payload['attachments'] = $attachments;
        }

        return $payload;
    }

    /**
     * The Idempotency-Key the email asks for with X-Mailer-Idempotency-Key.
     */
    public static function idempotencyKey(Email $email): ?string
    {
        $key = $email->getHeaders()->get(self::IDEMPOTENCY_KEY_HEADER)?->getBodyAsString();

        return null === $key || '' === $key ? null : $key;
    }

    /**
     * The X-Mailer-Profile header, or the mailer's default profile.
     *
     * @throws CommonMailerException
     */
    private static function profile(Email $email, ?string $defaultProfile): string
    {
        $profile = $email->getHeaders()->get(self::PROFILE_HEADER)?->getBodyAsString() ?: $defaultProfile;

        if (null === $profile || '' === $profile) {
            throw CommonMailerException::unsupported('no profile; set COMMON_MAILER_PROFILE, the mailer\'s "profile" or the ' . self::PROFILE_HEADER . ' header.');
        }

        return $profile;
    }

    /**
     * The X-Mailer-Priority header, or null to use the profile's priority.
     *
     * @throws CommonMailerException
     */
    private static function priority(Email $email): ?int
    {
        $priority = mb_trim((string) $email->getHeaders()->get(self::PRIORITY_HEADER)?->getBodyAsString());

        if ('' === $priority) {
            return null;
        }

        if (1 !== preg_match('/^[1-5]$/', $priority)) {
            throw CommonMailerException::unsupported('the ' . self::PRIORITY_HEADER . " header must be 1 (most urgent) to 5, \"{$priority}\" given.");
        }

        return (int) $priority;
    }

    /**
     * The one To recipient; Cc and Bcc are not supported.
     *
     * @throws CommonMailerException
     */
    private static function recipient(Email $email): Address
    {
        if ([] !== $email->getCc() || [] !== $email->getBcc()) {
            throw CommonMailerException::unsupported('Cc and Bcc recipients are not supported; send one email per recipient.');
        }

        return self::single($email->getTo(), 'To', required: true);
    }

    /**
     * The only address of a header.
     *
     * @param  array<int, Address>  $addresses
     * @return ($required is true ? Address : Address|null)
     *
     * @throws CommonMailerException
     */
    private static function single(array $addresses, string $header, bool $required): ?Address
    {
        if (count($addresses) > 1) {
            throw CommonMailerException::unsupported("it has more than one {$header} address.");
        }

        if ($required && [] === $addresses) {
            throw CommonMailerException::unsupported("it has no {$header} address.");
        }

        return $addresses[0] ?? null;
    }

    /**
     * @return array{address: string, name?: string}
     */
    private static function address(Address $address): array
    {
        $result = ['address' => $address->getAddress()];

        if ('' !== $address->getName()) {
            $result['name'] = $address->getName();
        }

        return $result;
    }

    /**
     * A body given as a string or a stream.
     *
     * @param  resource|string|null  $body
     */
    private static function body(mixed $body): ?string
    {
        if (is_resource($body)) {
            $body = (string) stream_get_contents($body, -1, 0);
        }

        return is_string($body) && '' !== $body ? $body : null;
    }

    /**
     * X-* headers and the allowed others, by name. Headers the mailer sets
     * itself (From, Subject, MIME, ...) and unsupported ones are left out.
     *
     * @return array<string, string>
     */
    private static function headers(Email $email): array
    {
        $headers = [];

        foreach ($email->getHeaders()->all() as $header) {
            $name = mb_strtolower($header->getName());
            $isCustom = str_starts_with($name, 'x-') && ! in_array($name, self::RESERVED_HEADERS, true);

            if ($isCustom || in_array($name, self::ALLOWED_HEADERS, true)) {
                $headers[$header->getName()] = $header->getBodyAsString();
            }
        }

        return $headers;
    }

    /**
     * Attachments with base64 content. Inline (cid:) parts are not supported:
     * the HTML would point at images the mailer does not embed.
     *
     * @return array<int, array{filename: string, content: string, mime_type: string}>
     *
     * @throws CommonMailerException
     */
    private static function attachments(Email $email): array
    {
        return array_map(static function (DataPart $part): array {
            if ('inline' === $part->getDisposition()) {
                throw CommonMailerException::unsupported('inline (embedded) attachments are not supported; link images by URL.');
            }

            return [
                'filename' => $part->getFilename() ?? 'attachment',
                'content' => base64_encode($part->getBody()),
                'mime_type' => $part->getMediaType() . '/' . $part->getMediaSubtype(),
            ];
        }, array_values($email->getAttachments()));
    }
}
