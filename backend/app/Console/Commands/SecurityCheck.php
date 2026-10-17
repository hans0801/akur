<?php

namespace App\Console\Commands;

use App\Logging\RedactSensitiveLogs;
use App\Services\Pii\PiiCipher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * W11: pemeriksaan konfigurasi keamanan otomatis untuk checklist rilis (docs/security/checklist.md).
 * Tidak pernah mencetak nilai rahasia; hanya lulus/gagal dan alasannya. Exit code 1 bila ada yang gagal.
 */
class SecurityCheck extends Command
{
    protected $signature = 'akur:security-check {--production : Terapkan aturan produksi walau APP_ENV bukan production}';

    protected $description = 'Periksa konfigurasi keamanan (debug, HTTPS, cookie, kunci PII, log, trigger DB)';

    public function handle(): int
    {
        $production = $this->option('production') || app()->isProduction();
        $checks = [
            ['APP_KEY diatur', filled(config('app.key')), 'Jalankan php artisan key:generate.'],
            ['Kunci PII valid dan berbeda dari kunci lookup', $this->piiReady(), 'Atur AKUR_PII_ENCRYPTION_KEY/AKUR_PII_KEYS dan AKUR_PII_LOOKUP_KEY (32 byte, berbeda).'],
            ['GOOGLE_CLIENT_IDS diatur', config('akur.google.client_ids') !== [], 'Isi Web client ID Google.'],
            ['Trigger append-only audit_logs ada', $this->triggerExists('audit_logs_no_update_delete'), 'Jalankan migrasi.'],
            ['Trigger append-only finance_transactions ada', $this->triggerExists('finance_transactions_no_update_delete'), 'Jalankan migrasi.'],
            ['Semua channel log memakai redaksi data sensitif', $this->logsRedacted(), 'Tambahkan tap RedactSensitiveLogs di config/logging.php.'],
        ];

        if ($production) {
            $checks = [
                ...$checks,
                ['APP_DEBUG mati', ! config('app.debug'), 'Set APP_DEBUG=false.'],
                ['APP_URL memakai HTTPS', str_starts_with((string) config('app.url'), 'https://'), 'Set APP_URL ke https://...'],
                ['Cookie sesi Secure', (bool) config('akur.session.cookie_secure'), 'Set AKUR_SESSION_COOKIE_SECURE=true.'],
                ['Origin web hanya HTTPS', collect(config('akur.web_origins'))->every(fn ($o) => str_starts_with($o, 'https://')), 'AKUR_WEB_ORIGINS hanya https://.'],
                ['LOG_LEVEL bukan debug', config('logging.channels.'.config('logging.default').'.level') !== 'debug', 'Set LOG_LEVEL=info atau lebih tinggi.'],
                ['Password DB bukan password dev', ! in_array(config('database.connections.pgsql.password'), ['', 'akur_dev_only', 'secret', 'password'], true), 'Gunakan password acak (openssl rand -base64 32).'],
            ];
        }

        $failed = 0;
        foreach ($checks as [$label, $ok, $fix]) {
            $this->line(($ok ? '<info>[LULUS]</info> ' : '<error>[GAGAL]</error> ').$label.($ok ? '' : " — {$fix}"));
            $failed += $ok ? 0 : 1;
        }

        $this->newLine();
        $failed === 0
            ? $this->info('Semua pemeriksaan lulus'.($production ? ' (mode produksi).' : '. Jalankan dengan --production untuk aturan produksi.'))
            : $this->error("{$failed} pemeriksaan gagal.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function piiReady(): bool
    {
        try {
            $cipher = app(PiiCipher::class);

            return $cipher->decrypt($cipher->encrypt('self-test', 'security.check'), 'security.check') === 'self-test';
        } catch (Throwable) {
            return false;
        }
    }

    private function triggerExists(string $name): bool
    {
        try {
            return DB::table('pg_trigger')->where('tgname', $name)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function logsRedacted(): bool
    {
        $default = config('logging.default');
        $channels = $default === 'stack' ? (array) config('logging.channels.stack.channels') : [$default];

        return collect($channels)->map(fn ($c) => trim($c))->filter()
            ->every(fn ($c) => in_array(RedactSensitiveLogs::class, (array) config("logging.channels.{$c}.tap"), true));
    }
}
