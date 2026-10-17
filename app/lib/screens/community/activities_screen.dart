import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../models/current_user.dart';
import '../population/population_common.dart';
import 'activity_form_screen.dart';
import 'community_common.dart';
import '../../widgets/async_view.dart';

/// Agenda kegiatan RT/RW (akan datang / selesai) dengan konfirmasi kehadiran.
class ActivitiesScreen extends StatefulWidget {
  const ActivitiesScreen({super.key, required this.api, required this.user});

  final ApiClient api;
  final CurrentUser user;

  @override
  State<ActivitiesScreen> createState() => _ActivitiesScreenState();
}

class _ActivitiesScreenState extends State<ActivitiesScreen> {
  late final List<UnitRef> _manageUnits = widget.user.communityManageUnits;
  String _when = 'upcoming';
  late Future<List<Map<String, dynamic>>> _items = _load();

  Future<List<Map<String, dynamic>>> _load() async => dataList(await widget.api.get('/activities?when=$_when'));

  void _reload() => setState(() => _items = _load());

  Future<void> _create() async {
    final saved = await Navigator.of(context).push<bool>(MaterialPageRoute(
      builder: (_) => ActivityFormScreen(api: widget.api, units: _manageUnits),
    ));
    if (saved == true) _reload();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Kegiatan')),
      floatingActionButton: _manageUnits.isNotEmpty
          ? FloatingActionButton.extended(onPressed: _create, icon: const Icon(Icons.add), label: const Text('Buat kegiatan'))
          : null,
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: SegmentedButton<String>(
              segments: const [
                ButtonSegment(value: 'upcoming', label: Text('Akan datang')),
                ButtonSegment(value: 'past', label: Text('Sudah lewat')),
              ],
              selected: {_when},
              onSelectionChanged: (s) {
                _when = s.first;
                _reload();
              },
            ),
          ),
          Expanded(
            child: FutureBuilder<List<Map<String, dynamic>>>(
              future: _items,
              builder: (context, snapshot) {
                if (snapshot.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
                if (snapshot.hasError) return ErrorView(error: snapshot.error, onRetry: _reload);
                final items = snapshot.data!;
                return RefreshIndicator(
                  onRefresh: () async => _reload(),
                  child: items.isEmpty
                      ? EmptyState(_when == 'upcoming' ? 'Belum ada kegiatan terjadwal.' : 'Belum ada kegiatan yang lewat.')
                      : ListView.separated(
                          padding: const EdgeInsets.fromLTRB(16, 16, 16, 96),
                          itemCount: items.length,
                          separatorBuilder: (_, _) => const SizedBox(height: 8),
                          itemBuilder: (_, i) {
                            final a = items[i];
                            final cancelled = a['status'] == 'cancelled';
                            return Card(
                              child: ListTile(
                                leading: Icon(cancelled ? Icons.event_busy_outlined : Icons.event_outlined),
                                title: Text(
                                  a['title'] as String,
                                  style: cancelled ? const TextStyle(decoration: TextDecoration.lineThrough) : null,
                                ),
                                subtitle: Text(
                                  '${formatDateTime(a['starts_at'] as String)}\n'
                                  '${a['location_text']} · ${(a['unit'] as Map<String, dynamic>)['name']}',
                                ),
                                isThreeLine: true,
                                trailing: Text(
                                  cancelled
                                      ? 'Batal'
                                      : a['my_status'] != null
                                          ? participationLabels[a['my_status']]!
                                          : '${a['going_count']} hadir',
                                ),
                                onTap: () async {
                                  await Navigator.of(context).push(MaterialPageRoute(
                                    builder: (_) => ActivityDetailScreen(api: widget.api, activityId: a['id'] as String, units: _manageUnits),
                                  ));
                                  _reload();
                                },
                              ),
                            );
                          },
                        ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}

class ActivityDetailScreen extends StatefulWidget {
  const ActivityDetailScreen({super.key, required this.api, required this.activityId, required this.units});

  final ApiClient api;
  final String activityId;
  final List<UnitRef> units;

  @override
  State<ActivityDetailScreen> createState() => _ActivityDetailScreenState();
}

class _ActivityDetailScreenState extends State<ActivityDetailScreen> {
  late Future<Map<String, dynamic>> _item = _load();

  Future<Map<String, dynamic>> _load() async =>
      (await widget.api.get('/activities/${widget.activityId}'))['data'] as Map<String, dynamic>;

  void _reload() => setState(() => _item = _load());

  Future<void> _rsvp(String status) async {
    final ok = await runApi(
      context,
      () => widget.api.put('/activities/${widget.activityId}/participation', {'status': status}),
      'Konfirmasi tersimpan: ${participationLabels[status]}.',
    );
    if (ok) _reload();
  }

  Future<void> _cancel() async {
    final reason = await askReason(
      context,
      title: 'Batalkan kegiatan?',
      message: 'Semua anggota unit akan menerima notifikasi pembatalan.',
      action: 'Batalkan',
    );
    if (reason == null || !mounted) return;
    final ok = await runApi(
      context,
      () => widget.api.patch('/activities/${widget.activityId}', {'status': 'cancelled', 'reason': reason}),
      'Kegiatan dibatalkan.',
    );
    if (ok) _reload();
  }

  Future<void> _checkIn(Map<String, dynamic> p) async {
    final ok = await runApi(
      context,
      () => widget.api.post('/activities/${widget.activityId}/participants/${p['id']}/check-in'),
      '${p['name']} tercatat hadir.',
    );
    if (ok) _reload();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return FutureBuilder<Map<String, dynamic>>(
      future: _item,
      builder: (context, snapshot) {
        final a = snapshot.data;
        final canEdit = a != null && a['can_edit'] == true && a['status'] == 'planned';
        return Scaffold(
          appBar: AppBar(
            title: const Text('Detail kegiatan'),
            actions: [
              if (canEdit) ...[
                IconButton(
                  tooltip: 'Ubah',
                  icon: const Icon(Icons.edit_outlined),
                  onPressed: () async {
                    final saved = await Navigator.of(context).push<bool>(MaterialPageRoute(
                      builder: (_) => ActivityFormScreen(api: widget.api, units: widget.units, activity: a),
                    ));
                    if (saved == true) _reload();
                  },
                ),
                IconButton(tooltip: 'Batalkan', icon: const Icon(Icons.event_busy_outlined), onPressed: _cancel),
              ],
            ],
          ),
          body: switch (snapshot) {
            _ when snapshot.connectionState != ConnectionState.done => const Center(child: CircularProgressIndicator()),
            _ when snapshot.hasError => ErrorView(error: snapshot.error, onRetry: _reload),
            _ => RefreshIndicator(
                onRefresh: () async => _reload(),
                child: ListView(
                  padding: const EdgeInsets.all(16),
                  children: [
                    Text(a!['title'] as String, style: theme.textTheme.headlineSmall),
                    const SizedBox(height: 8),
                    Wrap(spacing: 8, runSpacing: 8, children: [
                      Chip(label: Text(activityStatusLabels[a['status']] ?? a['status'] as String)),
                      Chip(label: Text(activityCategoryLabels[a['category']] ?? a['category'] as String)),
                      Chip(label: Text((a['unit'] as Map<String, dynamic>)['name'] as String)),
                    ]),
                    const SizedBox(height: 8),
                    ListTile(
                      contentPadding: EdgeInsets.zero,
                      leading: const Icon(Icons.schedule),
                      title: Text(formatDateTime(a['starts_at'] as String)),
                      subtitle: a['ends_at'] != null ? Text('sampai ${formatDateTime(a['ends_at'] as String)}') : null,
                    ),
                    ListTile(
                      contentPadding: EdgeInsets.zero,
                      leading: const Icon(Icons.place_outlined),
                      title: Text(a['location_text'] as String),
                    ),
                    ListTile(
                      contentPadding: EdgeInsets.zero,
                      leading: const Icon(Icons.groups_outlined),
                      title: Text('${a['going_count']} orang akan hadir'),
                      subtitle: Text('Penyelenggara: ${a['organizer_name'] ?? '-'}'),
                    ),
                    const SizedBox(height: 8),
                    SelectableText(a['description'] as String, style: theme.textTheme.bodyLarge),
                    if (a['status'] == 'planned') ...[
                      const SizedBox(height: 24),
                      Text('Konfirmasi kehadiran Anda', style: theme.textTheme.titleMedium),
                      const SizedBox(height: 8),
                      SegmentedButton<String>(
                        emptySelectionAllowed: true,
                        segments: [
                          for (final e in participationLabels.entries) ButtonSegment(value: e.key, label: Text(e.value)),
                        ],
                        selected: {if (a['my_status'] != null) a['my_status'] as String},
                        onSelectionChanged: (s) {
                          if (s.isNotEmpty) _rsvp(s.first);
                        },
                      ),
                    ],
                    if (a['participants'] != null) ...[
                      const SizedBox(height: 24),
                      Text('Peserta', style: theme.textTheme.titleMedium),
                      const SizedBox(height: 8),
                      if ((a['participants'] as List<dynamic>).isEmpty) const Text('Belum ada konfirmasi.'),
                      for (final p in (a['participants'] as List<dynamic>).cast<Map<String, dynamic>>())
                        Card(
                          child: ListTile(
                            title: Text(p['name'] as String? ?? '-'),
                            subtitle: Text(participationLabels[p['participation_status']] ?? p['participation_status'] as String),
                            trailing: p['checked_in_at'] != null
                                ? const Icon(Icons.check_circle, color: Colors.green)
                                : a['can_edit'] == true
                                    ? TextButton(onPressed: () => _checkIn(p), child: const Text('Hadir'))
                                    : null,
                          ),
                        ),
                    ],
                  ],
                ),
              ),
          },
        );
      },
    );
  }
}
