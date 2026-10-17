<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Surat pengantar PDF:
 *  - unit_letter_settings: data kop & penanda tangan per RT/RW (nama ketua, wilayah, logo, gambar tanda tangan + cap);
 *  - residents.religion: kolom "Agama" pada surat;
 *  - document_requests.output_file_id (sudah ada) dipakai untuk PDF yang dibekukan saat surat terbit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_letter_settings', function (Blueprint $table) {
            $table->foreignUuid('unit_id')->primary()->constrained('administrative_units');
            $table->string('signer_name', 160)->nullable();
            $table->string('kelurahan', 120)->nullable();
            $table->string('kecamatan', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('province', 120)->nullable();
            $table->foreignUuid('logo_file_id')->nullable()->constrained('file_objects');
            $table->foreignUuid('signature_file_id')->nullable()->constrained('file_objects');
            $table->foreignUuid('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        Schema::table('residents', function (Blueprint $table) {
            $table->string('religion', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('residents', function (Blueprint $table) {
            $table->dropColumn('religion');
        });
        Schema::dropIfExists('unit_letter_settings');
    }
};
