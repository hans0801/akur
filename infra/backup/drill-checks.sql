-- Pemeriksaan integritas setelah restore (dipanggil restore-drill.sh dengan psql -v ON_ERROR_STOP=1).
-- Gagal (RAISE EXCEPTION) bila ada pelanggaran; bila lulus mencetak ringkasan jumlah baris.

DO $$
DECLARE
    n bigint;
BEGIN
    SELECT count(*) INTO n FROM migrations;
    IF n = 0 THEN RAISE EXCEPTION 'Tabel migrations kosong'; END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'audit_logs_no_update_delete') THEN
        RAISE EXCEPTION 'Trigger append-only audit_logs hilang';
    END IF;
    IF to_regclass('finance_transactions') IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'finance_transactions_no_update_delete')
       AND EXISTS (SELECT 1 FROM migrations WHERE migration LIKE '%make_finance_transactions_append_only') THEN
        RAISE EXCEPTION 'Trigger append-only finance_transactions hilang';
    END IF;

    -- NIK/KK harus tetap ciphertext berversi (0x01/0x02), bukan teks polos.
    SELECT count(*) INTO n FROM residents WHERE nik_ciphertext IS NOT NULL AND get_byte(nik_ciphertext, 0) NOT IN (1, 2);
    IF n > 0 THEN RAISE EXCEPTION '% NIK tidak berformat ciphertext', n; END IF;
    SELECT count(*) INTO n FROM households WHERE kk_number_ciphertext IS NOT NULL AND get_byte(kk_number_ciphertext, 0) NOT IN (1, 2);
    IF n > 0 THEN RAISE EXCEPTION '% No. KK tidak berformat ciphertext', n; END IF;

    -- Cache amount_paid harus sama dengan alokasi terverifikasi (sama dengan akur:reconcile-dues).
    SELECT count(*) INTO n FROM (
        SELECT a.id
        FROM dues_assessments a
        LEFT JOIN payment_allocations pa ON pa.dues_assessment_id = a.id
        LEFT JOIN payments p ON p.id = pa.payment_id
        GROUP BY a.id, a.amount_paid
        HAVING a.amount_paid <> COALESCE(SUM(pa.amount) FILTER (WHERE p.status = 'verified'), 0)
    ) mismatch;
    IF n > 0 THEN RAISE EXCEPTION '% tagihan iuran tidak cocok dengan pembayaran', n; END IF;

    -- Setiap pembalikan kas menunjuk transaksi yang ada.
    SELECT count(*) INTO n FROM finance_transactions r
        WHERE r.kind = 'reversal' AND NOT EXISTS (SELECT 1 FROM finance_transactions o WHERE o.id = r.reversed_transaction_id);
    IF n > 0 THEN RAISE EXCEPTION '% transaksi pembalik yatim', n; END IF;
END $$;

SELECT 'migrations' AS tabel, count(*) AS baris FROM migrations
UNION ALL SELECT 'users', count(*) FROM users
UNION ALL SELECT 'residents', count(*) FROM residents
UNION ALL SELECT 'households', count(*) FROM households
UNION ALL SELECT 'document_requests', count(*) FROM document_requests
UNION ALL SELECT 'payments', count(*) FROM payments
UNION ALL SELECT 'finance_transactions', count(*) FROM finance_transactions
UNION ALL SELECT 'audit_logs', count(*) FROM audit_logs;
