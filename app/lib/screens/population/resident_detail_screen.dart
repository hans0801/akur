import 'package:flutter/material.dart';

import 'movement_form_screen.dart';
import 'population_common.dart';
import 'resident_form_screen.dart';
import '../../widgets/async_view.dart';

/// Biodata warga (NIK tersamar), riwayat mutasi, dan aksi pengurus.
class ResidentDetailScreen extends StatefulWidget {
  const ResidentDetailScreen({super.key, required this.scope, required this.residentId});

  final PopulationScope scope;
  final String residentId;

  @override
  State<ResidentDetailScreen> createState() => _ResidentDetailScreenState();
}

class _ResidentDetailScreenState extends State<ResidentDetailScreen> {
  late Future<Map<String, dynamic>> _resident = _load();

  Future<Map<String, dynamic>> _load() async =>
      (await widget.scope.api.get('/residents/${widget.residentId}'))['data'] as Map<String, dynamic>;

  void _reload() => setState(() => _resident = _load());

  Future<void> _push(Widget screen) async {
    final saved = await Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => screen));
    if (saved == true) _reload();
  }

  Future<void> _delete(Map<String, dynamic> r) async {
    final reason = await askReason(
      context,
      title: 'Hapus data warga?',
      message: 'Hanya untuk data yang salah input. Warga yang pindah atau meninggal dicatat lewat mutasi.',
      action: 'Hapus',
    );
    if (reason == null || !mounted) return;
    final ok = await runApi(
      context,
      () => widget.scope.api.delete('/residents/${widget.residentId}', {'reason': reason}),
      'Data warga dihapus.',
    );
    if (ok && mounted) Navigator.pop(context, true);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return FutureBuilder<Map<String, dynamic>>(
      future: _resident,
      builder: (context, snapshot) {
        final r = snapshot.data;
        final canWrite = widget.scope.canWrite && r != null;
        return Scaffold(
          appBar: AppBar(
            title: const Text('Detail warga'),
            actions: [
              if (canWrite) ...[
                IconButton(
                  tooltip: 'Ubah biodata',
                  icon: const Icon(Icons.edit_outlined),
                  onPressed: () => _push(ResidentFormScreen(scope: widget.scope, householdId: r['household_id'] as String?, resident: r)),
                ),
                if (r['is_linked'] != true)
                  IconButton(tooltip: 'Hapus (salah input)', icon: const Icon(Icons.delete_outline), onPressed: () => _delete(r)),
              ],
            ],
          ),
          floatingActionButton: canWrite
              ? FloatingActionButton.extended(
                  onPressed: () => _push(MovementFormScreen(scope: widget.scope, resident: r)),
                  icon: const Icon(Icons.swap_horiz),
                  label: const Text('Catat mutasi'),
                )
              : null,
          body: switch (snapshot) {
            _ when snapshot.connectionState != ConnectionState.done => const Center(child: CircularProgressIndicator()),
            _ when snapshot.hasError => ErrorView(error: snapshot.error, onRetry: _reload),
            _ => RefreshIndicator(
                onRefresh: () async => _reload(),
                child: ListView(
                  padding: const EdgeInsets.fromLTRB(16, 16, 16, 96),
                  children: [
                    Text(r!['full_name'] as String, style: theme.textTheme.headlineSmall),
                    const SizedBox(height: 4),
                    Text(r['address_text'] as String? ?? '-', style: theme.textTheme.bodyMedium),
                    const SizedBox(height: 8),
                    Wrap(spacing: 8, runSpacing: 8, children: [
                      Chip(label: Text(residencyStatusLabels[r['residency_status']] ?? r['residency_status'] as String)),
                      if (r['is_household_head'] == true) const Chip(label: Text('Kepala keluarga')),
                      if (r['is_linked'] == true) const Chip(label: Text('Punya akun')),
                    ]),
                    const SizedBox(height: 8),
                    Card(
                      child: ListTile(
                        leading: const Icon(Icons.badge_outlined),
                        title: Text(r['nik_masked'] as String? ?? 'Belum diisi'),
                        subtitle: const Text('NIK (tersamar)'),
                        trailing: r['has_nik'] == true && widget.scope.canWrite
                            ? TextButton(
                                onPressed: () => revealSensitive(context, widget.scope.api,
                                    path: '/residents/${widget.residentId}/nik/reveal', field: 'nik', label: 'NIK'),
                                child: const Text('Tampilkan'),
                              )
                            : null,
                      ),
                    ),
                    Card(
                      child: Column(
                        children: [
                          for (final row in [
                            ('Tempat lahir', r['birth_place']),
                            ('Tanggal lahir', r['birth_date']),
                            ('Jenis kelamin', genderLabels[r['gender']] ?? r['gender']),
                            ('Status perkawinan', r['marital_status']),
                            ('Pekerjaan', r['occupation']),
                            ('Pendidikan', r['education']),
                            ('Tanggal masuk', r['moved_in_at']),
                            ('Tanggal keluar', r['moved_out_at']),
                          ])
                            ListTile(dense: true, title: Text(row.$1), trailing: Text((row.$2 as String?) ?? '-')),
                        ],
                      ),
                    ),
                    const SizedBox(height: 16),
                    Text('Riwayat mutasi', style: theme.textTheme.titleMedium),
                    const SizedBox(height: 8),
                    if ((r['movements'] as List<dynamic>).isEmpty) const Text('Belum ada mutasi.'),
                    for (final m in (r['movements'] as List<dynamic>).cast<Map<String, dynamic>>())
                      Card(
                        child: ListTile(
                          leading: const Icon(Icons.history),
                          title: Text(movementTypeLabels[m['movement_type']] ?? m['movement_type'] as String),
                          subtitle: Text([
                            m['effective_date'],
                            if (m['reason'] != null) m['reason'],
                            if (m['recorded_by'] != null) 'oleh ${m['recorded_by']}',
                          ].join(' · ')),
                        ),
                      ),
                  ],
                ),
              ),
          },
        );
      },
    );
  }
}
