import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../models/current_user.dart';
import '../population/population_common.dart';
import 'announcement_form_screen.dart';
import 'community_common.dart';
import '../../widgets/async_view.dart';

/// Pengumuman yang sedang tayang untuk unit pengguna (RT-nya dan RW induknya).
/// Pengurus juga bisa membuka daftar kelola (draf, terjadwal, arsip).
class AnnouncementsScreen extends StatefulWidget {
  const AnnouncementsScreen({super.key, required this.api, required this.user});

  final ApiClient api;
  final CurrentUser user;

  @override
  State<AnnouncementsScreen> createState() => _AnnouncementsScreenState();
}

class _AnnouncementsScreenState extends State<AnnouncementsScreen> {
  late final List<UnitRef> _manageUnits = widget.user.communityManageUnits;
  late Future<List<Map<String, dynamic>>> _items = _load();

  Future<List<Map<String, dynamic>>> _load() async => dataList(await widget.api.get('/announcements'));

  void _reload() => setState(() => _items = _load());

  Future<void> _create() async {
    final saved = await Navigator.of(context).push<bool>(MaterialPageRoute(
      builder: (_) => AnnouncementFormScreen(api: widget.api, units: _manageUnits),
    ));
    if (saved == true) _reload();
  }

  Future<void> _open(Map<String, dynamic> a) async {
    await Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => AnnouncementDetailScreen(api: widget.api, announcementId: a['id'] as String, units: _manageUnits),
    ));
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Pengumuman'),
        actions: [
          if (_manageUnits.isNotEmpty)
            IconButton(
              tooltip: 'Kelola (draf & arsip)',
              icon: const Icon(Icons.edit_note),
              onPressed: () => Navigator.of(context).push(MaterialPageRoute(
                builder: (_) => _ManageAnnouncementsScreen(api: widget.api, units: _manageUnits),
              )),
            ),
        ],
      ),
      floatingActionButton: _manageUnits.isNotEmpty
          ? FloatingActionButton.extended(onPressed: _create, icon: const Icon(Icons.add), label: const Text('Buat'))
          : null,
      body: _AnnouncementList(future: _items, onRefresh: _reload, onTap: _open, empty: 'Belum ada pengumuman.'),
    );
  }
}

class _ManageAnnouncementsScreen extends StatefulWidget {
  const _ManageAnnouncementsScreen({required this.api, required this.units});

  final ApiClient api;
  final List<UnitRef> units;

  @override
  State<_ManageAnnouncementsScreen> createState() => _ManageAnnouncementsScreenState();
}

class _ManageAnnouncementsScreenState extends State<_ManageAnnouncementsScreen> {
  late UnitRef _unit = widget.units.first;
  String _status = 'draft';
  late Future<List<Map<String, dynamic>>> _items = _load();

  Future<List<Map<String, dynamic>>> _load() async =>
      dataList(await widget.api.get('/units/${_unit.id}/announcements?status=$_status'));

  void _reload() => setState(() => _items = _load());

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Kelola pengumuman')),
      body: Column(
        children: [
          UnitDropdown(units: widget.units, value: _unit, onChanged: (u) {
            _unit = u;
            _reload();
          }),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: SegmentedButton<String>(
              segments: [
                for (final e in announcementStatusLabels.entries) ButtonSegment(value: e.key, label: Text(e.value)),
              ],
              selected: {_status},
              onSelectionChanged: (s) {
                _status = s.first;
                _reload();
              },
            ),
          ),
          Expanded(
            child: _AnnouncementList(
              future: _items,
              onRefresh: _reload,
              empty: 'Tidak ada pengumuman berstatus ${announcementStatusLabels[_status]!.toLowerCase()}.',
              onTap: (a) async {
                await Navigator.of(context).push(MaterialPageRoute(
                  builder: (_) => AnnouncementDetailScreen(api: widget.api, announcementId: a['id'] as String, units: widget.units),
                ));
                _reload();
              },
            ),
          ),
        ],
      ),
    );
  }
}

class _AnnouncementList extends StatelessWidget {
  const _AnnouncementList({required this.future, required this.onRefresh, required this.onTap, required this.empty});

  final Future<List<Map<String, dynamic>>> future;
  final VoidCallback onRefresh;
  final ValueChanged<Map<String, dynamic>> onTap;
  final String empty;

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<List<Map<String, dynamic>>>(
      future: future,
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
        if (snapshot.hasError) return ErrorView(error: snapshot.error, onRetry: onRefresh);
        final items = snapshot.data!;
        return RefreshIndicator(
          onRefresh: () async => onRefresh(),
          child: items.isEmpty
              ? EmptyState(empty)
              : ListView.separated(
                  padding: const EdgeInsets.fromLTRB(16, 16, 16, 96),
                  itemCount: items.length,
                  separatorBuilder: (_, _) => const SizedBox(height: 8),
                  itemBuilder: (_, i) {
                    final a = items[i];
                    final unit = a['unit'] as Map<String, dynamic>;
                    return Card(
                      child: ListTile(
                        title: Text(a['title'] as String),
                        subtitle: Text(
                          '${unit['name']} · ${formatDateTime((a['publish_at'] ?? a['created_at']) as String?)}\n${a['body']}',
                          maxLines: 3,
                          overflow: TextOverflow.ellipsis,
                        ),
                        isThreeLine: true,
                        trailing: a['is_live'] == true ? null : Chip(label: Text(_badge(a))),
                        onTap: () => onTap(a),
                      ),
                    );
                  },
                ),
        );
      },
    );
  }

  static String _badge(Map<String, dynamic> a) =>
      a['status'] == 'published' ? 'Terjadwal/kedaluwarsa' : announcementStatusLabels[a['status']] ?? a['status'] as String;
}

class AnnouncementDetailScreen extends StatefulWidget {
  const AnnouncementDetailScreen({super.key, required this.api, required this.announcementId, required this.units});

  final ApiClient api;
  final String announcementId;
  final List<UnitRef> units;

  @override
  State<AnnouncementDetailScreen> createState() => _AnnouncementDetailScreenState();
}

class _AnnouncementDetailScreenState extends State<AnnouncementDetailScreen> {
  late Future<Map<String, dynamic>> _item = _load();

  Future<Map<String, dynamic>> _load() async =>
      (await widget.api.get('/announcements/${widget.announcementId}'))['data'] as Map<String, dynamic>;

  void _reload() => setState(() => _item = _load());

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return FutureBuilder<Map<String, dynamic>>(
      future: _item,
      builder: (context, snapshot) {
        final a = snapshot.data;
        return Scaffold(
          appBar: AppBar(
            title: const Text('Pengumuman'),
            actions: [
              if (a != null && a['can_edit'] == true)
                IconButton(
                  tooltip: 'Ubah',
                  icon: const Icon(Icons.edit_outlined),
                  onPressed: () async {
                    final saved = await Navigator.of(context).push<bool>(MaterialPageRoute(
                      builder: (_) => AnnouncementFormScreen(api: widget.api, units: widget.units, announcement: a),
                    ));
                    if (saved == true) setState(() => _item = _load());
                  },
                ),
            ],
          ),
          body: switch (snapshot) {
            _ when snapshot.connectionState != ConnectionState.done => const Center(child: CircularProgressIndicator()),
            _ when snapshot.hasError => ErrorView(error: snapshot.error, onRetry: _reload),
            _ => ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  Text(a!['title'] as String, style: theme.textTheme.headlineSmall),
                  const SizedBox(height: 8),
                  Text(
                    '${(a['unit'] as Map<String, dynamic>)['name']} · ${a['author_name'] ?? '-'} · '
                    '${formatDateTime((a['publish_at'] ?? a['created_at']) as String?)}',
                    style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                  ),
                  if (a['is_live'] != true) ...[
                    const SizedBox(height: 8),
                    Wrap(children: [Chip(label: Text(announcementStatusLabels[a['status']] ?? a['status'] as String))]),
                  ],
                  const SizedBox(height: 16),
                  SelectableText(a['body'] as String, style: theme.textTheme.bodyLarge),
                  if (a['expires_at'] != null) ...[
                    const SizedBox(height: 16),
                    Text('Berlaku sampai ${formatDateTime(a['expires_at'] as String)}', style: theme.textTheme.bodySmall),
                  ],
                ],
              ),
          },
        );
      },
    );
  }
}
