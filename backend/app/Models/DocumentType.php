<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Jenis surat pengantar. `required_fields`: daftar isian tambahan
 * [{key, label, type: text|textarea|date|number, required: bool}].
 */
class DocumentType extends Model
{
    use HasUuids;

    public const FIELD_TYPES = ['text', 'textarea', 'date', 'number'];

    protected $fillable = ['code', 'name', 'description', 'required_fields', 'is_active'];

    protected function casts(): array
    {
        return ['required_fields' => 'array', 'is_active' => 'boolean'];
    }

    /** @return list<array{key: string, label: string, type: string, required: bool}> */
    public function fields(): array
    {
        return array_values($this->required_fields ?? []);
    }
}
