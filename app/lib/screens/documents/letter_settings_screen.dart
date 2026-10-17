import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../api/api_client.dart';
import '../../models/current_user.dart';
import '../../widgets/async_view.dart';
import '../finance/attachments.dart';
import '../population/population_common.dart';

/// Pengaturan surat pengantar per RT/RW: nama Ketua (penanda tangan), wilayah untuk kop surat,
/// gambar tanda tangan + cap, dan logo kop. Ketua RT mengatur RT-nya, Ketua RW mengatur RW-nya.
class LetterSettingsScreen extends StatefulWidget {
  const LetterSettingsScreen({super.key, required this.api, required this.units});

  final ApiClient api;
  final List<UnitRef> units;

  @override
  State<LetterSettingsScreen> createState() => _LetterSettingsScreenState();
}

class _LetterSettingsScreenState extends State<LetterSettingsScreen> {
  static const _fields = {
    'kelurahan': 'Kelurahan',
    'kecamatan': 'Kecamatan',
    'city': 'Kota/Kabupaten (mis. Kota Tangerang)',
    'province': 'Provinsi',
  };

  late UnitRef _unit = widget.units.first;
  late Future<Map<String, dynamic>> _data = _load();
  final Map<String, TextEditingController> _controllers = {};
  String? _newSignatureId;
  String? _newLogoId;
  bool _saving = false;

  Future<Map<String, dynamic>> _load() async {
    final data = (await widget.api.get('/units/${_unit.id}/letter-settings'))['data'] as Map<String, dynamic>;
    for (final key in ['signer_name', ..._fields.keys]) {
      _controllers.putIfAbsent(key, TextEditingController.new).text = data[key] as String? ?? '';
    }
    _newSignatureId = null;
    _newLogoId = null;
    return data;
  }

  void _reload() => setState(() => _data = _load());

  Future<void> _save() async {
    setState(() => _saving = true);
    String? value(String key) => _controllers[key]!.text.trim().isEmpty ? null : _controllers[key]!.text.trim();
    final ok = await runApi(
      context,
      () => widget.api.put('/units/${_unit.id}/letter-settings', {
        'signer_name': value('signer_name'),
        for (final key in _fields.keys) key: value(key),
        'signature_file_id': ?_newSignatureId,
        'logo_file_id': ?_newLogoId,
      }),
      'Pengaturan surat disimpan.',
    );
    if (!mounted) return;
    setState(() => _saving = false);
    if (ok) _reload();
  }

  Future<void> _preview() async {
    final messenger = ScaffoldMessenger.of(context);
    try {
      final json = await widget.api.post('/units/${_unit.id}/letter-settings/preview-link');
      final url = Uri.parse((json['data'] as Map<String, dynamic>)['url'] as String);
      if (!await launchUrl(url, mode: LaunchMode.externalApplication)) {
        messenger.showSnackBar(const SnackBar(content: Text('Tidak dapat membuka browser.')));
      }
    } on ApiException catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(friendlyError(e))));
    }
  }

  Widget _imageRow(Map<String, dynamic> data, {required String title, required String column, required String purpose, required bool canEdit}) {
    final current = data[column] as String?;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(title, style: Theme.of(context).textTheme.titleSmall),
          const SizedBox(height: 4),
          Row(children: [
            Expanded(child: Text(current == null ? 'Belum ada.' : 'Sudah terpasang.')),
            if (current != null)
              TextButton(onPressed: () => openAttachment(context, widget.api, current), child: const Text('Lihat')),
          ]),
          if (canEdit)
            AttachmentPicker(
              api: widget.api,
              purpose: purpose,
              label: current == null ? 'Unggah:' : 'Ganti:',
              resize: false,
              onChanged: (id) => column == 'signature_file_id' ? _newSignatureId = id : _newLogoId = id,
            ),
        ]),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('Pengaturan surat')),
      body: Column(children: [
        UnitDropdown(units: widget.units, value: _unit, onChanged: (u) {
          _unit = u;
          _reload();
        }),
        Expanded(
          child: FutureBuilder<Map<String, dynamic>>(
            future: _data,
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
              if (snapshot.hasError) return ErrorView(error: snapshot.error, onRetry: _reload);
              final data = snapshot.data!;
              final canEdit = data['can_edit'] == true;
              final isRt = (data['unit'] as Map<String, dynamic>)['unit_type'] == 'rt';
              return ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  Text(
                    isRt
                        ? 'Dipakai pada kop dan tanda tangan "Pengurus RT" di surat pengantar. Wilayah yang kosong memakai '
                            'pengaturan RW induk.'
                        : 'Nama dan tanda tangan Ketua RW tampil di kolom kiri surat pengantar setiap RT di bawah RW ini; '
                            'wilayah dan logo RW menjadi bawaan untuk RT-nya.',
                    style: theme.textTheme.bodySmall,
                  ),
                  if (!canEdit) ...[
                    const SizedBox(height: 8),
                    Text(
                      isRt ? 'Hanya Ketua RT yang dapat mengubah pengaturan ini.' : 'Hanya Ketua RW yang dapat mengubah pengaturan ini.',
                      style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.error),
                    ),
                  ],
                  const SizedBox(height: 12),
                  TextField(
                    controller: _controllers['signer_name'],
                    enabled: canEdit,
                    decoration: InputDecoration(labelText: isRt ? 'Nama Ketua RT (penanda tangan)' : 'Nama Ketua RW (penanda tangan)'),
                  ),
                  for (final e in _fields.entries) ...[
                    const SizedBox(height: 8),
                    TextField(controller: _controllers[e.key], enabled: canEdit, decoration: InputDecoration(labelText: e.value)),
                  ],
                  const SizedBox(height: 16),
                  _imageRow(data, title: 'Tanda tangan + cap', column: 'signature_file_id', purpose: 'letter_signature', canEdit: canEdit),
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 4),
                    child: Text('Gunakan PNG berlatar transparan (maks 2 MB) agar tanda tangan tidak menutupi teks.', style: theme.textTheme.bodySmall),
                  ),
                  const SizedBox(height: 8),
                  _imageRow(data, title: 'Logo kop surat (opsional)', column: 'logo_file_id', purpose: 'letter_logo', canEdit: canEdit),
                  const SizedBox(height: 16),
                  if (canEdit)
                    FilledButton(onPressed: _saving ? null : _save, child: Text(_saving ? 'Menyimpan…' : 'Simpan')),
                  const SizedBox(height: 8),
                  OutlinedButton.icon(
                    onPressed: _preview,
                    icon: const Icon(Icons.picture_as_pdf_outlined),
                    label: const Text('Lihat contoh surat (PDF)'),
                  ),
                ],
              );
            },
          ),
        ),
      ]),
    );
  }
}
