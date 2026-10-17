import 'package:flutter/material.dart';

import 'population_common.dart';
import '../../widgets/async_view.dart';

/// Master jalan per unit. Jalan dinonaktifkan, bukan dihapus.
class StreetsScreen extends StatefulWidget {
  const StreetsScreen({super.key, required this.scope});

  final PopulationScope scope;

  @override
  State<StreetsScreen> createState() => _StreetsScreenState();
}

class _StreetsScreenState extends State<StreetsScreen> {
  late Future<List<Map<String, dynamic>>> _streets = _load();

  Future<List<Map<String, dynamic>>> _load() async =>
      dataList(await widget.scope.api.get('/units/${widget.scope.unit.id}/streets?include_inactive=1'));

  void _reload() => setState(() { _streets = _load(); });

  Future<void> _edit([Map<String, dynamic>? street]) async {
    final controller = TextEditingController(text: street?['name'] as String?);
    final name = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(street == null ? 'Tambah jalan' : 'Ubah jalan'),
        content: TextField(controller: controller, autofocus: true, decoration: const InputDecoration(labelText: 'Nama jalan')),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Batal')),
          FilledButton(onPressed: () => Navigator.pop(ctx, controller.text.trim()), child: const Text('Simpan')),
        ],
      ),
    );
    if (name == null || name.isEmpty || !mounted) return;

    final ok = await runApi(
      context,
      () => street == null
          ? widget.scope.api.post('/units/${widget.scope.unit.id}/streets', {'name': name})
          : widget.scope.api.patch('/streets/${street['id']}', {'name': name}),
      'Jalan disimpan.',
    );
    if (ok) _reload();
  }

  Future<void> _toggle(Map<String, dynamic> street) async {
    final ok = await runApi(
      context,
      () => widget.scope.api.patch('/streets/${street['id']}', {'is_active': street['is_active'] != true}),
      street['is_active'] == true ? 'Jalan dinonaktifkan.' : 'Jalan diaktifkan.',
    );
    if (ok) _reload();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text('Jalan · ${widget.scope.unit.name}')),
      floatingActionButton: widget.scope.canWrite
          ? FloatingActionButton.extended(onPressed: _edit, icon: const Icon(Icons.add), label: const Text('Tambah jalan'))
          : null,
      body: FutureBuilder<List<Map<String, dynamic>>>(
        future: _streets,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
          if (snapshot.hasError) return ErrorView(error: snapshot.error, onRetry: _reload);
          final items = snapshot.data!;
          return RefreshIndicator(
            onRefresh: () async => _reload(),
            child: items.isEmpty
                ? const EmptyState('Belum ada data jalan.')
                : ListView.separated(
                    padding: const EdgeInsets.all(16),
                    itemCount: items.length,
                    separatorBuilder: (_, _) => const SizedBox(height: 8),
                    itemBuilder: (_, i) {
                      final s = items[i];
                      final active = s['is_active'] == true;
                      return Card(
                        child: ListTile(
                          leading: Icon(active ? Icons.signpost_outlined : Icons.do_not_disturb_alt),
                          title: Text(s['name'] as String),
                          subtitle: Text('${s['houses_count'] ?? 0} rumah${active ? '' : ' · nonaktif'}'),
                          trailing: widget.scope.canWrite
                              ? PopupMenuButton<String>(
                                  onSelected: (action) => action == 'edit' ? _edit(s) : _toggle(s),
                                  itemBuilder: (_) => [
                                    const PopupMenuItem(value: 'edit', child: Text('Ubah nama')),
                                    PopupMenuItem(value: 'toggle', child: Text(active ? 'Nonaktifkan' : 'Aktifkan')),
                                  ],
                                )
                              : null,
                          onTap: () => Navigator.of(context).push(
                            MaterialPageRoute(builder: (_) => HousesScreen(scope: widget.scope, street: s)),
                          ),
                        ),
                      );
                    },
                  ),
          );
        },
      ),
    );
  }
}

/// Rumah di satu jalan.
class HousesScreen extends StatefulWidget {
  const HousesScreen({super.key, required this.scope, required this.street});

  final PopulationScope scope;
  final Map<String, dynamic> street;

  @override
  State<HousesScreen> createState() => _HousesScreenState();
}

class _HousesScreenState extends State<HousesScreen> {
  late Future<List<Map<String, dynamic>>> _houses = _load();

  String get _unitId => widget.street['unit_id'] as String;

  Future<List<Map<String, dynamic>>> _load() async =>
      dataList(await widget.scope.api.get('/units/$_unitId/houses?street_id=${widget.street['id']}'));

  void _reload() => setState(() { _houses = _load(); });

  Future<void> _edit([Map<String, dynamic>? house]) async {
    final body = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (_) => _HouseDialog(house: house),
    );
    if (body == null || !mounted) return;

    final ok = await runApi(
      context,
      () => house == null
          ? widget.scope.api.post('/units/$_unitId/houses', {...body, 'street_id': widget.street['id']})
          : widget.scope.api.patch('/houses/${house['id']}', body),
      'Rumah disimpan.',
    );
    if (ok) _reload();
  }

  Future<void> _delete(Map<String, dynamic> house) async {
    final ok = await runApi(context, () => widget.scope.api.delete('/houses/${house['id']}'), 'Rumah dihapus.');
    if (ok) _reload();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(widget.street['name'] as String)),
      floatingActionButton: widget.scope.canWrite
          ? FloatingActionButton.extended(onPressed: _edit, icon: const Icon(Icons.add_home_outlined), label: const Text('Tambah rumah'))
          : null,
      body: FutureBuilder<List<Map<String, dynamic>>>(
        future: _houses,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
          if (snapshot.hasError) return ErrorView(error: snapshot.error, onRetry: _reload);
          final items = snapshot.data!;
          return RefreshIndicator(
            onRefresh: () async => _reload(),
            child: items.isEmpty
                ? const EmptyState('Belum ada rumah di jalan ini.')
                : ListView.separated(
                    padding: const EdgeInsets.all(16),
                    itemCount: items.length,
                    separatorBuilder: (_, _) => const SizedBox(height: 8),
                    itemBuilder: (_, i) {
                      final h = items[i];
                      return Card(
                        child: ListTile(
                          leading: const Icon(Icons.home_outlined),
                          title: Text('No. ${h['house_number']}${h['block_label'] != null ? ' Blok ${h['block_label']}' : ''}'),
                          subtitle: Text(occupancyStatusLabels[h['occupancy_status']] ?? h['occupancy_status'] as String),
                          trailing: widget.scope.canWrite
                              ? PopupMenuButton<String>(
                                  onSelected: (action) => action == 'edit' ? _edit(h) : _delete(h),
                                  itemBuilder: (_) => const [
                                    PopupMenuItem(value: 'edit', child: Text('Ubah')),
                                    PopupMenuItem(value: 'delete', child: Text('Hapus')),
                                  ],
                                )
                              : null,
                        ),
                      );
                    },
                  ),
          );
        },
      ),
    );
  }
}

class _HouseDialog extends StatefulWidget {
  const _HouseDialog({this.house});

  final Map<String, dynamic>? house;

  @override
  State<_HouseDialog> createState() => _HouseDialogState();
}

class _HouseDialogState extends State<_HouseDialog> {
  late final _number = TextEditingController(text: widget.house?['house_number'] as String?);
  late final _block = TextEditingController(text: widget.house?['block_label'] as String?);
  late String _occupancy = widget.house?['occupancy_status'] as String? ?? 'occupied';

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text(widget.house == null ? 'Tambah rumah' : 'Ubah rumah'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          TextField(controller: _number, autofocus: true, decoration: const InputDecoration(labelText: 'Nomor rumah *')),
          TextField(controller: _block, decoration: const InputDecoration(labelText: 'Blok')),
          const SizedBox(height: 8),
          DropdownButtonFormField<String>(
            initialValue: _occupancy,
            decoration: const InputDecoration(labelText: 'Status hunian'),
            items: [for (final e in occupancyStatusLabels.entries) DropdownMenuItem(value: e.key, child: Text(e.value))],
            onChanged: (v) => setState(() => _occupancy = v ?? _occupancy),
          ),
        ],
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: const Text('Batal')),
        FilledButton(
          onPressed: () {
            if (_number.text.trim().isEmpty) return;
            Navigator.pop(context, {
              'house_number': _number.text.trim(),
              'block_label': _block.text.trim().isEmpty ? null : _block.text.trim(),
              'occupancy_status': _occupancy,
            });
          },
          child: const Text('Simpan'),
        ),
      ],
    );
  }
}
