<?php

namespace Tests\Unit\Support;

use App\Support\DataMasker;
use PHPUnit\Framework\TestCase;

class DataMaskerTest extends TestCase
{
    /**
     * Test phone masking with standard format
     */
    public function test_phone_masks_middle_four_digits(): void
    {
        $result = DataMasker::phone('13812345678');
        $this->assertEquals('138****5678', $result);
    }

    /**
     * Test phone masking with spaces
     */
    public function test_phone_masks_with_spaces(): void
    {
        $result = DataMasker::phone('138 1234 5678');
        $this->assertStringContainsString('****', $result);
    }

    /**
     * Test phone masking with dashes
     */
    public function test_phone_masks_with_dashes(): void
    {
        $result = DataMasker::phone('138-1234-5678');
        $this->assertStringContainsString('****', $result);
    }

    /**
     * Test phone masking with country code
     */
    public function test_phone_masks_with_country_code(): void
    {
        $result = DataMasker::phone('+86 138 1234 5678');
        $this->assertStringContainsString('****', $result);
    }

    /**
     * Test phone returns original if invalid
     */
    public function test_phone_returns_original_if_invalid(): void
    {
        $invalid = 'not-a-phone';
        $result = DataMasker::phone($invalid);
        $this->assertEquals($invalid, $result);
    }

    /**
     * Test phone returns original if too short
     */
    public function test_phone_returns_original_if_too_short(): void
    {
        $invalid = '123';
        $result = DataMasker::phone($invalid);
        $this->assertEquals($invalid, $result);
    }

    /**
     * Test email masking basic format
     */
    public function test_email_masks_username_part(): void
    {
        $result = DataMasker::email('user@example.com');
        $this->assertEquals('u***@example.com', $result);
    }

    /**
     * Test email masking with long username
     */
    public function test_email_masks_long_username(): void
    {
        $result = DataMasker::email('verylongemailaddress@example.com');
        $this->assertEquals('v***@example.com', $result);
    }

    /**
     * Test email masking with subdomain
     */
    public function test_email_masks_with_subdomain(): void
    {
        $result = DataMasker::email('user@mail.example.com');
        $this->assertEquals('u***@mail.example.com', $result);
    }

    /**
     * Test email returns original if invalid
     */
    public function test_email_returns_original_if_invalid(): void
    {
        $invalid = 'not-an-email';
        $result = DataMasker::email($invalid);
        $this->assertEquals($invalid, $result);
    }

    /**
     * Test email returns original if no @ symbol
     */
    public function test_email_returns_original_if_no_at_symbol(): void
    {
        $invalid = 'userexample.com';
        $result = DataMasker::email($invalid);
        $this->assertEquals($invalid, $result);
    }

    /**
     * Test IPv4 masking
     */
    public function test_ip_masks_ipv4_last_octet(): void
    {
        $result = DataMasker::ip('192.168.1.100');
        $this->assertEquals('192.168.1.*', $result);
    }

    /**
     * Test IPv4 masking with different values
     */
    public function test_ip_masks_ipv4_various_values(): void
    {
        $result = DataMasker::ip('10.0.0.1');
        $this->assertEquals('10.0.0.*', $result);
    }

    /**
     * Test IPv6 masking
     */
    public function test_ip_masks_ipv6_last_groups(): void
    {
        $result = DataMasker::ip('2001:0db8:85a3:0000:0000:8a2e:0370:7334');
        $this->assertStringContainsString('****', $result);
        $this->assertStringStartsWith('2001:', $result);
    }

    /**
     * Test IPv6 masking with compressed format
     */
    public function test_ip_masks_ipv6_compressed(): void
    {
        $result = DataMasker::ip('2001:db8:85a3::8a2e:370:7334');
        $this->assertStringContainsString('****', $result);
    }

    /**
     * Test IP returns original if invalid
     */
    public function test_ip_returns_original_if_invalid(): void
    {
        $invalid = 'not-an-ip';
        $result = DataMasker::ip($invalid);
        $this->assertEquals($invalid, $result);
    }

    /**
     * Test token masking
     */
    public function test_token_shows_first_eight_and_last_four(): void
    {
        $result = DataMasker::token('abcdefghijklmnopqrstuvwxyz');
        $this->assertEquals('abcdefgh...wxyz', $result);
    }

    /**
     * Test token masking with long token
     */
    public function test_token_masks_long_token(): void
    {
        $token = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIiwiaWF0IjoxNTE2MjM5MDIyfQ.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';
        $result = DataMasker::token($token);
        $this->assertStringStartsWith('eyJhbGci', $result);
        $this->assertStringEndsWith('w5c', $result);
        $this->assertStringContainsString('...', $result);
    }

    /**
     * Test token returns original if too short
     */
    public function test_token_returns_original_if_too_short(): void
    {
        $short = 'short';
        $result = DataMasker::token($short);
        $this->assertEquals($short, $result);
    }

    /**
     * Test token returns original if exactly 12 chars
     */
    public function test_token_returns_original_if_exactly_twelve_chars(): void
    {
        $token = '123456789012';
        $result = DataMasker::token($token);
        $this->assertEquals($token, $result);
    }

    /**
     * Test token masking with 13 chars
     */
    public function test_token_masks_with_thirteen_chars(): void
    {
        $token = '1234567890123';
        $result = DataMasker::token($token);
        $this->assertEquals('12345678...0123', $result);
    }
}
