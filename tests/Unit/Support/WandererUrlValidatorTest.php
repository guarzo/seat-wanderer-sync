<?php

namespace Guarzo\Seat\WandererSync\Tests\Unit\Support;

use Guarzo\Seat\WandererSync\Support\WandererUrlValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WandererUrlValidatorTest extends TestCase
{
    public function test_accepts_https_public_host(): void
    {
        $this->assertSame(
            'https://wanderer.ltd',
            WandererUrlValidator::validate('https://wanderer.ltd')
        );
    }

    public function test_accepts_http_public_host(): void
    {
        $this->assertSame(
            'http://wanderer.example.com',
            WandererUrlValidator::validate('http://wanderer.example.com')
        );
    }

    public function test_strips_trailing_slashes(): void
    {
        $this->assertSame(
            'https://wanderer.ltd',
            WandererUrlValidator::validate('https://wanderer.ltd///')
        );
    }

    public function test_rejects_empty_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WandererUrlValidator::validate('');
    }

    public function test_rejects_non_http_scheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WandererUrlValidator::validate('ftp://wanderer.ltd');
    }

    public function test_rejects_missing_host(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WandererUrlValidator::validate('https://');
    }

    public function test_rejects_localhost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WandererUrlValidator::validate('http://localhost');
    }

    public function test_rejects_loopback_ipv4(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WandererUrlValidator::validate('http://127.0.0.1');
    }

    public function test_rejects_private_network_10_0_0_0(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WandererUrlValidator::validate('http://10.1.2.3');
    }

    public function test_rejects_private_network_192_168(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WandererUrlValidator::validate('http://192.168.1.1');
    }

    public function test_rejects_link_local(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WandererUrlValidator::validate('http://169.254.1.1');
    }

    public function test_rejects_ipv6_loopback(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WandererUrlValidator::validate('http://[::1]');
    }
}
