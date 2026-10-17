import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../api/api_client.dart';
import '../../models/current_user.dart';
import '../../widgets/async_view.dart';
import '../finance/attachments.dart';
import '../population/population_common.dart';

/// Pengaturan surat pengantar dalam satu tampilan per RT: kop surat (wilayah + logo), Ketua RT dan Ketua RW
/// (nama penanda tangan + tanda tangan/cap). Tiap bagian hanya bisa diubah pemiliknya: Ketua RT untuk bagian RT,
/// Ketua RW untuk bagian RW, kop oleh keduanya. Daftar unit dari server: RT keanggotaan pengguna beserta RT di bawah RW-nya.
class LetterSettingsScreen extends StatefulWidget {
  const LetterSettingsScreen({super.key, required this.api});

  final ApiClient api;

  @override
  State<LetterSettingsScreen> createState() => _LetterSettingsScreenState();
}

class _LetterSettingsScreenState extends State<LetterSettingsScreen> {
  static const _kopFields = {
    'kelurahan': 'Kelurahan',
    'kecamatan': 'Kecamatan',
    'city': 'Kota/Kabupaten (mis. Kota Tangerang)',
    'province': 'Provinsi',
  };

  late Future<List<UnitRef>> _units = _loadUnits();
  UnitRef? _selected;
  Future<Map<String, dynamic>>? _data;
  final Map<String, TextEditingController> _controllers = {};

  /// Berkas baru yang belum disimpan, per kunci: 'logo', 'rt', 'rw'.
  final Map<String, String?> _newFiles = {};
  int _loadCount = 0;
  bool _saving = false;

  UnitRef get _unit => _selected!;

  TextEditingController _controller(String key) => _controllers.putIfAbsent(key, TextEditingController.new);

  Future<List<UnitRef>> _loadUnits() async {
    final units = dataList(await widget.api.get('/letter-settings/units'))
        .map(
          (u) => UnitRef(id: u['id'] as String, unitType: u['unit_type'] as String, code: u['code'] as String, name: u['label'] as String),
        )
        .toList();
    if (units.isNotEmpty) {
      _selected = units.first;
      _data = _load();
    }
    return units;
  }

  Future<Map<String, dynamic>> _load() async {
    final data = (await widget.api.get('/units/${_unit.id}/letter-settings'))['data'] as Map<String, dynamic>;
    final kop = data['kop'] as Map<String, dynamic>;
    for (final key in _kopFields.keys) {
      _controller(key).text = kop[key] as String? ?? '';
    }
    for (final part in ['rt', 'rw']) {
      _controller('${part}_signer').text = (data[part] as Map<String, dynamic>?)?['signer_name'] as String? ?? '';
    }
    _newFiles.clear();
    _loadCount++;
    return data;
  }

  void _reload() => setState(() => _data = _load());

  bool _canEdit(Map<String, dynamic>? section) => section?['can_edit'] == true;

  Future<void> _save(Map<String, dynamic> data) async {
    setState(() => _saving = true);
    String? value(String key) => _controller(key).text.trim().isEmpty ? null : _controller(key).text.trim();
    Map<String, dynamic> signer(String part) => {'signer_name': value('${part}_signer'), 'signature_file_id': ?_newFiles[part]};
    final ok = await runApi(
      context,
      () => widget.api.put('/units/${_unit.id}/letter-settings', {
        if (_canEdit(data['kop'] as Map<String, dynamic>)) ...{
          for (final key in _kopFields.keys) key: value(key),
          'logo_file_id': ?_newFiles['logo'],
        },
        for (final part in ['rt', 'rw'])
          if (_canEdit(data[part] as Map<String, dynamic>?)) part: signer(part),
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

  Widget _imageRow({
    required String title,
    required String? current,
    required String fileKey,
    required String purpose,
    required bool canEdit,
  }) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(title, style: Theme.of(context).textTheme.titleSmall),
        Row(
          children: [
            Expanded(child: Text(current == null ? 'Belum ada.' : 'Sudah terpasang.')),
            if (current != null) TextButton(onPressed: () => openAttachment(context, widget.api, current), child: const Text('Lihat')),
          ],
        ),
        if (canEdit)
          AttachmentPicker(
            key: ValueKey('$fileKey-$_loadCount'),
            api: widget.api,
            purpose: purpose,
            label: current == null ? 'Unggah:' : 'Ganti:',
            resize: false,
            onChanged: (id) => _newFiles[fileKey] = id,
          ),
      ],
    );
  }

  Widget _section(
    ThemeData theme, {
    required String title,
    required bool canEdit,
    required String lockedNote,
    required List<Widget> children,
  }) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(title, style: theme.textTheme.titleMedium),
            if (!canEdit) ...[
              const SizedBox(height: 4),
              Text(lockedNote, style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.error)),
            ],
            const SizedBox(height: 8),
            ...children,
          ],
        ),
      ),
    );
  }

  /// Bagian Ketua RT / Ketua RW: nama penanda tangan + tanda tangan dan cap.
  Widget _signerSection(ThemeData theme, String part, Map<String, dynamic> section) {
    final canEdit = _canEdit(section);
    final unitName = (section['unit'] as Map<String, dynamic>)['name'] as String;
    final role = part == 'rt' ? 'Ketua RT' : 'Ketua RW';
    return _section(
      theme,
      title: '$role ($unitName)',
      canEdit: canEdit,
      lockedNote: 'Hanya $role yang dapat mengubah bagian ini.',
      children: [
        TextField(
          controller: _controller('${part}_signer'),
          enabled: canEdit,
          decoration: InputDecoration(labelText: 'Nama $role (penanda tangan)'),
        ),
        const SizedBox(height: 12),
        _imageRow(
          title: 'Tanda tangan + cap',
          current: section['signature_file_id'] as String?,
          fileKey: part,
          purpose: 'letter_signature',
          canEdit: canEdit,
        ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('Pengaturan surat')),
      body: FutureBuilder<List<UnitRef>>(
        future: _units,
        builder: (context, unitsSnapshot) {
          if (unitsSnapshot.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
          if (unitsSnapshot.hasError) {
            return ErrorView(error: unitsSnapshot.error, onRetry: () => setState(() => _units = _loadUnits()));
          }
          final units = unitsSnapshot.data!;
          if (units.isEmpty) return const EmptyState('Tidak ada RT/RW yang pengaturan suratnya bisa Anda buka.');
          return _settingsBody(theme, units);
        },
      ),
    );
  }

  Widget _settingsBody(ThemeData theme, List<UnitRef> units) {
    return Column(
      children: [
        UnitDropdown(
          units: units,
          value: _unit,
          onChanged: (u) {
            _selected = u;
            _reload();
          },
        ),
        Expanded(
          child: FutureBuilder<Map<String, dynamic>>(
            future: _data,
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
              if (snapshot.hasError) return ErrorView(error: snapshot.error, onRetry: _reload);
              final data = snapshot.data!;
              final kop = data['kop'] as Map<String, dynamic>;
              final kopEditable = _canEdit(kop);
              final rt = data['rt'] as Map<String, dynamic>?;
              final rw = data['rw'] as Map<String, dynamic>?;
              return ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  Text(
                    'Semua isian di bawah dipakai pada surat pengantar ${(data['unit'] as Map<String, dynamic>)['name']}: '
                    'kop surat, serta nama dan tanda tangan Ketua RT dan Ketua RW.',
                    style: theme.textTheme.bodySmall,
                  ),
                  const SizedBox(height: 12),
                  _section(
                    theme,
                    title: 'Kop surat',
                    canEdit: kopEditable,
                    lockedNote: 'Hanya Ketua RT atau Ketua RW yang dapat mengubah kop surat.',
                    children: [
                      for (final e in _kopFields.entries) ...[
                        TextField(
                          controller: _controller(e.key),
                          enabled: kopEditable,
                          decoration: InputDecoration(labelText: e.value),
                        ),
                        const SizedBox(height: 8),
                      ],
                      const SizedBox(height: 4),
                      _imageRow(
                        title: 'Logo kop surat (opsional)',
                        current: kop['logo_file_id'] as String?,
                        fileKey: 'logo',
                        purpose: 'letter_logo',
                        canEdit: kopEditable,
                      ),
                    ],
                  ),
                  if (rt != null) ...[const SizedBox(height: 8), _signerSection(theme, 'rt', rt)],
                  if (rw != null) ...[const SizedBox(height: 8), _signerSection(theme, 'rw', rw)],
                  Padding(
                    padding: const EdgeInsets.fromLTRB(4, 8, 4, 0),
                    child: Text(
                      'Gunakan PNG berlatar transparan (maks 2 MB) untuk tanda tangan + cap agar tidak menutupi teks.',
                      style: theme.textTheme.bodySmall,
                    ),
                  ),
                  const SizedBox(height: 16),
                  if (data['can_edit'] == true)
                    FilledButton(onPressed: _saving ? null : () => _save(data), child: Text(_saving ? 'Menyimpan…' : 'Simpan')),
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
      ],
    );
  }
}
