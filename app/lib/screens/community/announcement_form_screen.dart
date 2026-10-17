import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../models/current_user.dart';
import '../population/population_common.dart';
import 'community_common.dart';

/// Buat/ubah pengumuman. "Terbit" tanpa jadwal langsung tayang dan mengirim notifikasi ke anggota unit.
class AnnouncementFormScreen extends StatefulWidget {
  const AnnouncementFormScreen({super.key, required this.api, required this.units, this.announcement});

  final ApiClient api;
  final List<UnitRef> units;
  final Map<String, dynamic>? announcement;

  @override
  State<AnnouncementFormScreen> createState() => _AnnouncementFormScreenState();
}

class _AnnouncementFormScreenState extends State<AnnouncementFormScreen> {
  final _form = GlobalKey<FormState>();
  late final _title = TextEditingController(text: widget.announcement?['title'] as String?);
  late final _body = TextEditingController(text: widget.announcement?['body'] as String?);
  late UnitRef? _unit = widget.units.isEmpty ? null : widget.units.first;
  late String _status = widget.announcement?['status'] as String? ?? 'draft';
  late DateTime? _publishAt = _parse(widget.announcement?['publish_at']);
  late DateTime? _expiresAt = _parse(widget.announcement?['expires_at']);
  bool _saving = false;

  bool get _isEdit => widget.announcement != null;

  static DateTime? _parse(Object? iso) => iso is String ? DateTime.tryParse(iso)?.toLocal() : null;

  Future<void> _save() async {
    if (!_form.currentState!.validate()) return;
    setState(() => _saving = true);

    final body = <String, dynamic>{
      'title': _title.text.trim(),
      'body': _body.text.trim(),
      'status': _status,
      'publish_at': _publishAt?.toUtc().toIso8601String(),
      'expires_at': _expiresAt?.toUtc().toIso8601String(),
    };
    final ok = await runApi(
      context,
      () => _isEdit
          ? widget.api.patch('/announcements/${widget.announcement!['id']}', body)
          : widget.api.post('/units/${_unit!.id}/announcements', body),
      _status == 'published' ? 'Pengumuman diterbitkan.' : 'Pengumuman disimpan.',
    );
    if (!mounted) return;
    setState(() => _saving = false);
    if (ok) Navigator.pop(context, true);
  }

  @override
  Widget build(BuildContext context) {
    final statuses = _isEdit ? announcementStatusLabels.keys.toList() : const ['draft', 'published'];
    return Scaffold(
      appBar: AppBar(title: Text(_isEdit ? 'Ubah pengumuman' : 'Buat pengumuman')),
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
            TextFormField(
              controller: _title,
              maxLength: 200,
              decoration: const InputDecoration(labelText: 'Judul *'),
              validator: (v) => (v == null || v.trim().isEmpty) ? 'Judul wajib diisi' : null,
            ),
            TextFormField(
              controller: _body,
              minLines: 5,
              maxLines: 12,
              decoration: const InputDecoration(labelText: 'Isi *', alignLabelWithHint: true),
              validator: (v) => (v == null || v.trim().isEmpty) ? 'Isi wajib diisi' : null,
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              initialValue: _status,
              decoration: const InputDecoration(labelText: 'Status'),
              items: [for (final s in statuses) DropdownMenuItem(value: s, child: Text(announcementStatusLabels[s]!))],
              onChanged: (v) => setState(() => _status = v ?? _status),
            ),
            const SizedBox(height: 12),
            DateTimeField(label: 'Jadwal terbit (opsional)', value: _publishAt, onChanged: (v) => setState(() => _publishAt = v)),
            const SizedBox(height: 12),
            DateTimeField(label: 'Berlaku sampai (opsional)', value: _expiresAt, onChanged: (v) => setState(() => _expiresAt = v)),
            const SizedBox(height: 8),
            Text(
              'Pengumuman terjadwal tampil otomatis pada waktunya, tetapi belum mengirim notifikasi.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
            const SizedBox(height: 24),
            FilledButton(onPressed: _saving ? null : _save, child: Text(_saving ? 'Menyimpan…' : 'Simpan')),
          ],
        ),
      ),
    );
  }
}
