<?php

namespace OneOakDigital\CommonMailer\Tests;

use OneOakDigital\CommonMailer\Feedback\FeedbackSignature;
use PHPUnit\Framework\TestCase as BaseTestCase;

class FeedbackSignatureTest extends BaseTestCase
{
    private const string BODY = '{"event":"recipient_undeliverable"}';

    private const int TIMESTAMP = 1763550000;

    public function test_a_signature_by_another_token_is_refused(): void
    {
        $header = FeedbackSignature::sign(self::BODY, self::TIMESTAMP, [FeedbackSignature::key('other-token')]);

        $this->assertFalse(FeedbackSignature::verify($header, self::BODY, 'site-token', 300, self::TIMESTAMP));
    }

    public function test_a_signature_of_another_body_is_refused(): void
    {
        $header = FeedbackSignature::sign('{"event":"other"}', self::TIMESTAMP, [FeedbackSignature::key('site-token')]);

        $this->assertFalse(FeedbackSignature::verify($header, self::BODY, 'site-token', 300, self::TIMESTAMP));
    }

    public function test_any_of_several_signatures_may_match(): void
    {
        $header = FeedbackSignature::sign(self::BODY, self::TIMESTAMP, [FeedbackSignature::key('old-token'), FeedbackSignature::key('site-token')]);

        $this->assertTrue(FeedbackSignature::verify($header, self::BODY, 'site-token', 300, self::TIMESTAMP));
    }

    public function test_it_matches_the_mailers_signature(): void
    {
        // The same vector is asserted in common_mailer's SiteFeedbackTest.
        $this->assertSame('c8411df959a2a269e5e59740b597ac0d27c295110ac3acd8e856e7bcb352a724', FeedbackSignature::key('site-token'));
        $this->assertSame(
            't=1763550000,v1=14283da6bcda6ebd68485b4470fcc9a1dc71661b41db7ce9638f546ee3a4a5d7',
            FeedbackSignature::sign(self::BODY, self::TIMESTAMP, [FeedbackSignature::key('site-token')]),
        );
    }

    public function test_missing_or_malformed_headers_are_refused(): void
    {
        foreach ([null, '', 'v1=abc', 't=1763550000', 'garbage'] as $header) {
            $this->assertFalse(FeedbackSignature::verify($header, self::BODY, 'site-token', 300, self::TIMESTAMP), (string) $header);
        }
    }

    public function test_without_a_token_nothing_is_genuine(): void
    {
        $header = FeedbackSignature::sign(self::BODY, self::TIMESTAMP, [FeedbackSignature::key('')]);

        $this->assertFalse(FeedbackSignature::verify($header, self::BODY, '', 300, self::TIMESTAMP));
    }

    public function test_the_time_must_be_within_the_tolerance(): void
    {
        $header = FeedbackSignature::sign(self::BODY, self::TIMESTAMP, [FeedbackSignature::key('site-token')]);

        $this->assertTrue(FeedbackSignature::verify($header, self::BODY, 'site-token', 300, self::TIMESTAMP + 300));
        $this->assertFalse(FeedbackSignature::verify($header, self::BODY, 'site-token', 300, self::TIMESTAMP + 301));
        $this->assertFalse(FeedbackSignature::verify($header, self::BODY, 'site-token', 300, self::TIMESTAMP - 301));
    }
}
