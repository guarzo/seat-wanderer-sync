<?php

namespace Guarzo\Seat\WandererSync\Tests\Unit\Support;

use Guarzo\Seat\WandererSync\Support\WandererUrlValidator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_accepts_uppercase_scheme(): void
    {
        $this->assertSame(
            'HTTPS://wanderer.ltd',
            WandererUrlValidator::validate('HTTPS://wanderer.ltd')
        );
    }

    public function test_strips_trailing_slashes(): void
    {
        $this->assertSame(
            'https://wanderer.ltd',
            WandererUrlValidator::validate('https://wanderer.ltd///')
        );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidUrlProvider(): iterable
    {
        yield 'empty'             => [''];
        yield 'unsupported scheme' => ['ftp://wanderer.ltd'];
        yield 'missing host'      => ['https://'];
        yield 'localhost'         => ['http://localhost'];
        yield 'ipv4 loopback'     => ['http://127.0.0.1'];
        yield 'private 10/8'      => ['http://10.1.2.3'];
        yield 'private 192.168'   => ['http://192.168.1.1'];
        yield 'link-local'        => ['http://169.254.1.1'];
        yield 'ipv6 loopback'     => ['http://[::1]'];
    }

    #[DataProvider('invalidUrlProvider')]
    public function test_rejects_invalid_url(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        WandererUrlValidator::validate($url);
    }
}
