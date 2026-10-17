<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Buku kas tidak boleh diubah atau dihapus: koreksi selalu lewat transaksi `reversal`.
 * Sama seperti audit_logs, aturan ini dijaga di level database.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION finance_transactions_block_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'finance_transactions is append-only; record a reversal instead';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER finance_transactions_no_update_delete
                BEFORE UPDATE OR DELETE ON finance_transactions
                FOR EACH ROW EXECUTE FUNCTION finance_transactions_block_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS finance_transactions_no_update_delete ON finance_transactions; DROP FUNCTION IF EXISTS finance_transactions_block_mutation();');
    }
};
