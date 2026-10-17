import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../models/current_user.dart';
import '../population/population_common.dart';
import 'community_common.dart';

/// Buat/ubah kegiatan. Kegiatan baru dan perubahan waktu/lokasi mengirim notifikasi ke anggota unit.
class ActivityFormScreen extends StatefulWidget {
  const ActivityFormScreen({super.key, required this.api, required this.units, this.activity});

  final ApiClient api;
  final List<UnitRef> units;
  final Map<String, dynamic>? activity;

  @override
  State<ActivityFormScreen> createState() => _ActivityFormScreenState();
}

class _ActivityFormScreenState extends State<ActivityFormScreen> {
  final _form = GlobalKey<FormState>();
  late final _title = TextEditingController(text: widget.activity?['title'] as String?);
  late final _description = TextEditingController(text: widget.activity?['description'] as String?);
  late final _location = TextEditingController(text: widget.activity?['location_text'] as String?);
  late UnitRef? _unit = widget.units.isEmpty ? null : widget.units.first;
  late String _category = widget.activity?['category'] as String? ?? 'kerja_bakti';
  late DateTime? _startsAt = _parse(widget.activity?['starts_at']);
  late DateTime? _endsAt = _parse(widget.activity?['ends_at']);
  bool _saving = false;

  bool get _isEdit => widget.activity != null;

  static DateTime? _parse(Object? iso) => iso is String ? DateTime.tryParse(iso)?.toLocal() : null;

  Future<void> _save() async {
    if (!_form.currentState!.validate()) return;
    final messenger = ScaffoldMessenger.of(context);
    if (_startsAt == null) {
      messenger.showSnackBar(const SnackBar(content: Text('Waktu mulai wajib diisi.')));
      return;
    }
    if (_endsAt != null && _endsAt!.isBefore(_startsAt!)) {
      messenger.showSnackBar(const SnackBar(content: Text('Waktu selesai harus setelah waktu mulai.')));
      return;
    }
    setState(() => _saving = true);

    final body = <String, dynamic>{
      'title': _title.text.trim(),
      'description': _description.text.trim(),
      'location_text': _location.text.trim(),
      'category': _category,
      'starts_at': _startsAt!.toUtc().toIso8601String(),
      'ends_at': _endsAt?.toUtc().toIso8601String(),
    };
    final ok = await runApi(
      context,
      () => _isEdit
          ? widget.api.patch('/activities/${widget.activity!['id']}', body)
          : widget.api.post('/units/${_unit!.id}/activities', body),
      _isEdit ? 'Kegiatan diperbarui.' : 'Kegiatan dibuat.',
    );
    if (!mounted) return;
    setState(() => _saving = false);
    if (ok) Navigator.pop(context, true);
  }

  @override
  Widget build(BuildContext context) {
    String? required(String? v) => (v == null || v.trim().isEmpty) ? 'Wajib diisi' : null;

    return Scaffold(
      appBar: AppBar(title: Text(_isEdit ? 'Ubah kegiatan' : 'Buat kegiatan')),
      body: Form(
        key: _form,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (!_isEdit && widget.units.length > 1)
              DropdownButtonFormField<String>(
                initialValue: _unit?.id,
                decoration: const InputDecoration(labelText: 'Untuk unit *'),
                items: [for (final u in widget.units) DropdownMenuItem(value: u.id, child: Text(u.name))],
                onChanged: (id) => setState(() => _unit = widget.units.firstWhere((u) => u.id == id)),
              ),
            TextFormField(controller: _title, maxLength: 200, decoration: const InputDecoration(labelText: 'Judul *'), validator: required),
            DropdownButtonFormField<String>(
              initialValue: _category,
              decoration: const InputDecoration(labelText: 'Kategori'),
              items: [for (final e in activityCategoryLabels.entries) DropdownMenuItem(value: e.key, child: Text(e.value))],
              onChanged: (v) => setState(() => _category = v ?? _category),
            ),
            const SizedBox(height: 12),
            DateTimeField(label: 'Mulai', required: true, value: _startsAt, onChanged: (v) => setState(() => _startsAt = v)),
            const SizedBox(height: 12),
            DateTimeField(label: 'Selesai (opsional)', value: _endsAt, onChanged: (v) => setState(() => _endsAt = v)),
            const SizedBox(height: 12),
            TextFormField(controller: _location, decoration: const InputDecoration(labelText: 'Lokasi *'), validator: required),
            const SizedBox(height: 12),
            TextFormField(
              controller: _description,
              minLines: 4,
              maxLines: 10,
              decoration: const InputDecoration(labelText: 'Deskripsi *', alignLabelWithHint: true),
              validator: required,
            ),
            const SizedBox(height: 24),
            FilledButton(onPressed: _saving ? null : _save, child: Text(_saving ? 'Menyimpan…' : 'Simpan')),
          ],
        ),
      ),
    );
  }
}
