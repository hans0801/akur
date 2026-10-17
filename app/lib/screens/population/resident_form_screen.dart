import 'package:flutter/material.dart';

import 'population_common.dart';

/// Tambah warga ke keluarga (tercatat sebagai mutasi pindah masuk) atau ubah biodata.
/// Alamat dan status kependudukan tidak diubah di sini — gunakan "Catat mutasi".
class ResidentFormScreen extends StatefulWidget {
  const ResidentFormScreen({super.key, required this.scope, required this.householdId, this.resident});

  final PopulationScope scope;
  final String? householdId;
  final Map<String, dynamic>? resident;

  @override
  State<ResidentFormScreen> createState() => _ResidentFormScreenState();
}

class _ResidentFormScreenState extends State<ResidentFormScreen> {
  final _form = GlobalKey<FormState>();
  late final _name = TextEditingController(text: widget.resident?['full_name'] as String?);
  final _nik = TextEditingController();
  late final _birthPlace = TextEditingController(text: widget.resident?['birth_place'] as String?);
  late final _marital = TextEditingController(text: widget.resident?['marital_status'] as String?);
  late final _occupation = TextEditingController(text: widget.resident?['occupation'] as String?);
  late final _education = TextEditingController(text: widget.resident?['education'] as String?);
  final _reason = TextEditingController();
  late String? _birthDate = widget.resident?['birth_date'] as String?;
  late String? _gender = widget.resident?['gender'] as String?;
  String _residency = 'permanent';
  String? _movedInAt;
  bool _isHead = false;
  bool _saving = false;

  bool get _isEdit => widget.resident != null;

  String? _text(TextEditingController c) => c.text.trim().isEmpty ? null : c.text.trim();

  Future<void> _save() async {
    if (!_form.currentState!.validate()) return;
    setState(() => _saving = true);

    final body = <String, dynamic>{
      'full_name': _name.text.trim(),
      'birth_place': _text(_birthPlace),
      'birth_date': _birthDate,
      'gender': _gender,
      'marital_status': _text(_marital),
      'occupation': _text(_occupation),
      'education': _text(_education),
      if (_nik.text.trim().isNotEmpty) 'nik': _nik.text.trim(),
      if (_text(_reason) != null) 'reason': _text(_reason),
      if (!_isEdit) ...{
        'residency_status': _residency,
        'is_household_head': _isHead,
        'moved_in_at': _movedInAt,
      },
    };

    final ok = await runApi(
      context,
      () => _isEdit
          ? widget.scope.api.patch('/residents/${widget.resident!['id']}', body)
          : widget.scope.api.post('/households/${widget.householdId}/residents', body),
      _isEdit ? 'Biodata diperbarui.' : 'Warga ditambahkan.',
    );
    if (!mounted) return;
    setState(() => _saving = false);
    if (ok) Navigator.pop(context, true);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(_isEdit ? 'Ubah biodata' : 'Tambah anggota keluarga')),
      body: Form(
        key: _form,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            TextFormField(
              controller: _name,
              decoration: const InputDecoration(labelText: 'Nama lengkap *'),
              validator: (v) => (v == null || v.trim().isEmpty) ? 'Nama wajib diisi' : null,
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _nik,
              keyboardType: TextInputType.number,
              obscureText: true,
              autocorrect: false,
              enableSuggestions: false,
              decoration: InputDecoration(
                labelText: 'NIK (16 digit)',
                helperText: _isEdit
                    ? 'Saat ini: ${widget.resident!['nik_masked'] ?? 'belum diisi'}. Kosongkan jika tidak diubah.'
                    : 'Disimpan terenkripsi dan selalu ditampilkan tersamar.',
              ),
              validator: (v) =>
                  (v != null && v.trim().isNotEmpty && !RegExp(r'^\d{16}$').hasMatch(v.trim())) ? 'NIK harus 16 digit' : null,
            ),
            const SizedBox(height: 12),
            TextFormField(controller: _birthPlace, decoration: const InputDecoration(labelText: 'Tempat lahir')),
            const SizedBox(height: 12),
            DateField(label: 'Tanggal lahir', value: _birthDate, onChanged: (v) => setState(() => _birthDate = v)),
            const SizedBox(height: 12),
            DropdownButtonFormField<String?>(
              initialValue: _gender,
              decoration: const InputDecoration(labelText: 'Jenis kelamin'),
              items: [
                const DropdownMenuItem(value: null, child: Text('-')),
                for (final e in genderLabels.entries) DropdownMenuItem(value: e.key, child: Text(e.value)),
              ],
              onChanged: (v) => setState(() => _gender = v),
            ),
            const SizedBox(height: 12),
            TextFormField(controller: _marital, decoration: const InputDecoration(labelText: 'Status perkawinan')),
            const SizedBox(height: 12),
            TextFormField(controller: _occupation, decoration: const InputDecoration(labelText: 'Pekerjaan')),
            const SizedBox(height: 12),
            TextFormField(controller: _education, decoration: const InputDecoration(labelText: 'Pendidikan')),
            if (!_isEdit) ...[
              const SizedBox(height: 12),
              DropdownButtonFormField<String>(
                initialValue: _residency,
                decoration: const InputDecoration(labelText: 'Status tinggal *'),
                items: [
                  for (final key in const ['permanent', 'contract', 'temporary'])
                    DropdownMenuItem(value: key, child: Text(residencyStatusLabels[key]!)),
                ],
                onChanged: (v) => setState(() => _residency = v ?? _residency),
              ),
              const SizedBox(height: 12),
              DateField(label: 'Tanggal mulai tinggal', value: _movedInAt, onChanged: (v) => setState(() => _movedInAt = v)),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('Kepala keluarga'),
                value: _isHead,
                onChanged: (v) => setState(() => _isHead = v),
              ),
            ],
            const SizedBox(height: 12),
            TextFormField(controller: _reason, decoration: const InputDecoration(labelText: 'Catatan / alasan')),
            const SizedBox(height: 24),
            FilledButton(onPressed: _saving ? null : _save, child: Text(_saving ? 'Menyimpan…' : 'Simpan')),
          ],
        ),
      ),
    );
  }
}
