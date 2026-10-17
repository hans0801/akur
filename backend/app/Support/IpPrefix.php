<?php

namespace App\Support;

/**
 * Menyimpan prefiks jaringan, bukan IP lengkap (minimisasi data): IPv4 /24, IPv6 /48.
 */
final class IpPrefix
{
    public static function from(?string $ip): ?string
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);

            return "{$parts[0]}.{$parts[1]}.{$parts[2]}.0/24";
        }

        $packed = inet_pton($ip);
        $masked = substr($packed, 0, 6).str_repeat("\0", 10);

        return inet_ntop($masked).'/48';
    }
}
