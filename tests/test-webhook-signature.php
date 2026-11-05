<?php
declare(strict_types=1);

use TribuneDesk\Protocol\WebhookSignature;

/**
 * Standard Webhooks signature check (spec 2026-11-03 open CMS API, D4).
 * The vector is the one Tribune Desk's own tests use (tests/services/test_webhook_signing.py).
 */
class Test_WebhookSignature extends WP_UnitTestCase
{
    private const SECRET = 'whsec_C2FVsBQIhrscChlQIMV+b5sSYspob7oD';
    private const ID     = 'msg_27UH4WbU6Z5A5EzD8u03UvzRbpk';
    private const TS     = 1649367553;
    private const BODY   = '{"email":"test@example.com","username":"test_user"}';
    private const SIG    = 'v1,tZ1I4/hDygAJgO5TYxiSd6Sd0kDW6hPenDe+bTa3Kkw=';

    /** @return array<string, string> */
    private function headers(string $signature = self::SIG, int $ts = self::TS): array
    {
        return [
            'webhook-id'        => self::ID,
            'webhook-timestamp' => (string) $ts,
            'webhook-signature' => $signature,
        ];
    }

    public function test_published_vector_verifies(): void
    {
        $this->assertTrue(WebhookSignature::verify(self::SECRET, $this->headers(), self::BODY, self::TS));
    }

    public function test_wordpress_style_header_keys_are_accepted(): void
    {
        // WP_REST_Request::get_headers() lower-cases keys and turns '-' into '_'.
        $headers = [
            'webhook_id'        => [self::ID],
            'webhook_timestamp' => [(string) self::TS],
            'webhook_signature' => [self::SIG],
        ];
        $this->assertTrue(WebhookSignature::verify(self::SECRET, $headers, self::BODY, self::TS));
    }

    public function test_changed_body_is_rejected(): void
    {
        $body = str_replace('test_user', 'test_usex', self::BODY);
        $this->assertFalse(WebhookSignature::verify(self::SECRET, $this->headers(), $body, self::TS));
    }

    public function test_timestamp_outside_tolerance_is_rejected_both_ways(): void
    {
        $this->assertFalse(WebhookSignature::verify(self::SECRET, $this->headers(), self::BODY, self::TS + 301));
        $this->assertFalse(WebhookSignature::verify(self::SECRET, $this->headers(), self::BODY, self::TS - 301));
        $this->assertTrue(WebhookSignature::verify(self::SECRET, $this->headers(), self::BODY, self::TS + 300));
    }

    public function test_missing_header_is_rejected(): void
    {
        $headers = $this->headers();
        unset($headers['webhook-signature']);
        $this->assertFalse(WebhookSignature::verify(self::SECRET, $headers, self::BODY, self::TS));
    }

    public function test_other_versions_are_ignored(): void
    {
        $sig = 'v2,' . substr(self::SIG, 3);
        $this->assertFalse(WebhookSignature::verify(self::SECRET, $this->headers($sig), self::BODY, self::TS));
    }

    public function test_second_of_two_signatures_is_enough(): void
    {
        $sig = 'v1,AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ' . self::SIG;
        $this->assertTrue(WebhookSignature::verify(self::SECRET, $this->headers($sig), self::BODY, self::TS));
    }

    public function test_secret_must_be_a_whsec_secret(): void
    {
        $this->assertFalse(WebhookSignature::verify('C2FVsBQIhrscChlQIMV+b5sSYspob7oD', $this->headers(), self::BODY, self::TS));
        $this->assertTrue(WebhookSignature::is_valid_secret(self::SECRET));
        $this->assertFalse(WebhookSignature::is_valid_secret('whsec_not base64!'));
        $this->assertFalse(WebhookSignature::is_valid_secret('C2FVsBQIhrscChlQIMV+b5sSYspob7oD'));
        $this->assertFalse(WebhookSignature::is_valid_secret('whsec_'));
    }
}
