import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import 'population_common.dart';

/// Tambah/ubah keluarga (KK). No. KK tidak pernah diisi ulang dari server: kosongkan untuk tidak mengubah.
class HouseholdFormScreen extends StatefulWidget {
  const HouseholdFormScreen({super.key, required this.scope, this.household});

  final PopulationScope scope;
  final Map<String, dynamic>? household;

  @override
  State<HouseholdFormScreen> createState() => _HouseholdFormScreenState();
}

class _HouseholdFormScreenState extends State<HouseholdFormScreen> {
  final _form = GlobalKey<FormState>();
  late final _address = TextEditingController(text: widget.household?['address_text'] as String?);
  late final _number = TextEditingController(text: widget.household?['household_number'] as String?);
  final _kk = TextEditingController();
  final _reason = TextEditingController();
  late String? _streetId = widget.household?['street_id'] as String?;
  late String? _houseId = widget.household?['house_id'] as String?;
  late String _status = widget.household?['status'] as String? ?? 'active';

  List<Map<String, dynamic>> _streets = [];
  List<Map<String, dynamic>> _houses = [];
  bool _saving = false;

  bool get _isEdit => widget.household != null;

  String get _unitId => widget.household?['unit_id'] as String? ?? widget.scope.unit.id;

  @override
  void initState() {
    super.initState();
    _loadStreets();
  }

  Future<void> _loadStreets() async {
    try {
      final streets = dataList(await widget.scope.api.get('/units/$_unitId/streets'));
      setState(() => _streets = streets.where((s) => s['unit_id'] == _unitId).toList());
      await _loadHouses();
    } on ApiException {
      // Daftar jalan opsional; formulir tetap bisa dipakai dengan alamat teks.
    }
  }

  Future<void> _loadHouses() async {
    if (_streetId == null) {
      setState(() => _houses = []);
      return;
    }
    final houses = dataList(await widget.scope.api.get('/units/$_unitId/houses?street_id=$_streetId'));
    if (mounted) setState(() => _houses = houses);
  }

  Future<void> _save() async {
    if (!_form.currentState!.validate()) return;
    setState(() => _saving = true);

    final body = <String, dynamic>{
      'address_text': _address.text.trim(),
      'household_number': _number.text.trim().isEmpty ? null : _number.text.trim(),
      'street_id': _streetId,
      'house_id': _houseId,
      if (_kk.text.trim().isNotEmpty) 'kk_number': _kk.text.trim(),
      if (_isEdit) 'status': _status,
      if (_isEdit && _reason.text.trim().isNotEmpty) 'reason': _reason.text.trim(),
    };

    final ok = await runApi(
      context,
      () => _isEdit
          ? widget.scope.api.patch('/households/${widget.household!['id']}', body)
          : widget.scope.api.post('/units/${widget.scope.unit.id}/households', body),
      _isEdit ? 'Data keluarga diperbarui.' : 'Keluarga ditambahkan.',
    );
    if (!mounted) return;
    setState(() => _saving = false);
    if (ok) Navigator.pop(context, true);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(_isEdit ? 'Ubah keluarga' : 'Tambah keluarga')),
      body: Form(
        key: _form,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            TextFormField(
              controller: _address,
              decoration: const InputDecoration(labelText: 'Alamat *'),
              maxLines: 2,
              validator: (v) => (v == null || v.trim().isEmpty) ? 'Alamat wajib diisi' : null,
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<String?>(
              key: ValueKey('street-${_streets.length}'),
              initialValue: _streets.any((s) => s['id'] == _streetId) ? _streetId : null,
              decoration: const InputDecoration(labelText: 'Jalan'),
              items: [
                const DropdownMenuItem(value: null, child: Text('-')),
                for (final s in _streets) DropdownMenuItem(value: s['id'] as String, child: Text(s['name'] as String)),
              ],
              onChanged: (v) {
                setState(() {
                  _streetId = v;
                  _houseId = null;
                });
                _loadHouses();
              },
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<String?>(
              key: ValueKey('house-$_streetId-${_houses.length}'),
              initialValue: _houses.any((h) => h['id'] == _houseId) ? _houseId : null,
              decoration: const InputDecoration(labelText: 'Rumah'),
              items: [
                const DropdownMenuItem(value: null, child: Text('-')),
                for (final h in _houses)
                  DropdownMenuItem(
                    value: h['id'] as String,
                    child: Text('No. ${h['house_number']}${h['block_label'] != null ? ' Blok ${h['block_label']}' : ''}'),
                  ),
              ],
              onChanged: (v) => setState(() => _houseId = v),
            ),
            const SizedBox(height: 12),
            TextFormField(controller: _number, decoration: const InputDecoration(labelText: 'Nomor keluarga (internal RT)')),
            const SizedBox(height: 12),
            TextFormField(
              controller: _kk,
              keyboardType: TextInputType.number,
              obscureText: true,
              autocorrect: false,
              enableSuggestions: false,
              decoration: InputDecoration(
                labelText: 'No. KK (16 digit)',
                helperText: _isEdit
                    ? 'Saat ini: ${widget.household!['kk_masked'] ?? 'belum diisi'}. Kosongkan jika tidak diubah.'
                    : 'Disimpan terenkripsi dan selalu ditampilkan tersamar.',
              ),
              validator: (v) =>
                  (v != null && v.trim().isNotEmpty && !RegExp(r'^\d{16}$').hasMatch(v.trim())) ? 'No. KK harus 16 digit' : null,
            ),
            if (_isEdit) ...[
              const SizedBox(height: 12),
              DropdownButtonFormField<String>(
                initialValue: _status,
                decoration: const InputDecoration(labelText: 'Status keluarga'),
                items: [
                  for (final e in householdStatusLabels.entries) DropdownMenuItem(value: e.key, child: Text(e.value)),
                ],
                onChanged: (v) => setState(() => _status = v ?? _status),
              ),
              const SizedBox(height: 12),
              TextFormField(controller: _reason, decoration: const InputDecoration(labelText: 'Alasan perubahan')),
            ],
            const SizedBox(height: 24),
            FilledButton(onPressed: _saving ? null : _save, child: Text(_saving ? 'Menyimpan…' : 'Simpan')),
          ],
        ),
      ),
    );
  }
}
