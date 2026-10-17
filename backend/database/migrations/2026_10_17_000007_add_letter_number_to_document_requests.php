<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Nomor surat diberikan saat surat diterbitkan; unik per unit. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_requests', function (Blueprint $table) {
            $table->string('letter_number', 60)->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->unique(['unit_id', 'letter_number']);
        });
    }

    public function down(): void
    {
        Schema::table('document_requests', function (Blueprint $table) {
            $table->dropUnique(['unit_id', 'letter_number']);
            $table->dropColumn(['letter_number', 'issued_at']);
        });
    }
};
