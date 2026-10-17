<?php

namespace Tests\Unit;

use App\Support\IpPrefix;
use PHPUnit\Framework\TestCase;

class IpPrefixTest extends TestCase
{
    public function test_ipv4_is_truncated_to_24(): void
    {
        $this->assertSame('203.0.113.0/24', IpPrefix::from('203.0.113.77'));
    }

    public function test_ipv6_is_truncated_to_48(): void
    {
        $this->assertSame('2001:db8:abcd::/48', IpPrefix::from('2001:db8:abcd:12::1'));
    }

    public function test_invalid_ip_returns_null(): void
    {
        $this->assertNull(IpPrefix::from('not-an-ip'));
        $this->assertNull(IpPrefix::from(null));
    }
}
