<?php

namespace OneOakDigital\CommonMailer\Feedback;

/**
 * The X-Mailer-Signature of the mailer's feedback requests.
 *
 * The mailer stores only sha256 of a site's access token, so that hash is the
 * HMAC key: the mailer signs with the hashes it stores, the site derives the
 * same key from COMMON_MAILER_TOKEN. The token itself never travels.
 *
 *     X-Mailer-Signature: t=<unix time>,v1=<hex>[,v1=<hex>...]
 *     v1 = hash_hmac('sha256', "<t>.<raw body>", sha256(token))
 *
 * One v1 per token of the site, so tokens can be rotated; a request is
 * genuine when any v1 matches and t is within the tolerance.
 */
class FeedbackSignature
{
    /**
     * The request header carrying the signature.
     */
    public const string HEADER = 'X-Mailer-Signature';

    /**
     * The HMAC key of a token: what the mailer stores for it.
     */
    public static function key(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The header value for the body, signed at $timestamp with each key.
     *
     * @param  list<string>  $keys
     */
    public static function sign(string $body, int $timestamp, array $keys): string
    {
        $parts = ["t={$timestamp}"];

        foreach ($keys as $key) {
            $parts[] = 'v1=' . self::hmac($body, $timestamp, $key);
        }

        return implode(',', $parts);
    }

    /**
     * Whether the header signs the body with the token's key, at most
     * $toleranceSeconds from $now.
     */
    public static function verify(?string $header, string $body, string $token, int $toleranceSeconds, ?int $now = null): bool
    {
        if (null === $header || '' === $token) {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', mb_trim($part), 2), 2, '');

            if ('t' === $name && 1 === preg_match('/^\d+$/', $value)) {
                $timestamp = (int) $value;
            } elseif ('v1' === $name && '' !== $value) {
                $signatures[] = $value;
            }
        }

        if (null === $timestamp || [] === $signatures || abs(($now ?? time()) - $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = self::hmac($body, $timestamp, self::key($token));

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    private static function hmac(string $body, int $timestamp, string $key): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$body}", $key);
    }
}
