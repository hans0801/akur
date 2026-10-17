<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * W11 log hygiene: menyamarkan data sensitif sebelum log ditulis ke mana pun.
 * - angka 16 digit (bentuk NIK/No. KK), token Bearer, JWT (ID token Google), signature tautan bertanda tangan;
 * - nilai pada kunci konteks bernama sensitif (nik, kk_number, token, password, secret, cookie, authorization, ...).
 * Ini lapisan pengaman; kode tetap tidak boleh sengaja mencatat PII.
 */
class RedactSensitiveData implements ProcessorInterface
{
    private const KEY_PATTERN = '/(nik|kk_number|token|password|secret|credential|ciphertext|cookie|authorization|signature)/i';

    private const VALUE_PATTERNS = [
        '/(?<!\d)\d{16}(?!\d)/' => '[REDACTED-16DIGIT]',
        '/Bearer\s+[A-Za-z0-9._~+\/=-]+/i' => 'Bearer [REDACTED]',
        '/eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/' => '[REDACTED-JWT]',
        '/([?&]signature=)[A-Za-z0-9]+/i' => '$1[REDACTED]',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: self::scrub($record->message),
            context: self::scrubArray($record->context),
            extra: self::scrubArray($record->extra),
        );
    }

    public static function scrub(string $value): string
    {
        return preg_replace(array_keys(self::VALUE_PATTERNS), array_values(self::VALUE_PATTERNS), $value) ?? $value;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function scrubArray(array $data, int $depth = 0): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::KEY_PATTERN, $key)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_string($value)) {
                $data[$key] = self::scrub($value);
            } elseif (is_array($value) && $depth < 5) {
                $data[$key] = self::scrubArray($value, $depth + 1);
            } elseif ($value instanceof \Throwable) {
                // Pesan exception (mis. QueryException berisi SQL + binding) ikut disamarkan.
                $data[$key] = self::scrub(get_class($value).': '.$value->getMessage().' @ '.$value->getFile().':'.$value->getLine());
            }
        }

        return $data;
    }
}
