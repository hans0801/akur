import 'package:flutter/material.dart';

import 'population_common.dart';
import 'resident_detail_screen.dart';
import '../../widgets/async_view.dart';

/// Daftar warga per unit dengan pencarian nama, filter status, dan pencarian NIK exact-match (POST, diaudit).
class ResidentsScreen extends StatefulWidget {
  const ResidentsScreen({super.key, required this.scope});

  final PopulationScope scope;

  @override
  State<ResidentsScreen> createState() => _ResidentsScreenState();
}

class _ResidentsScreenState extends State<ResidentsScreen> {
  static const _statusFilters = {'active': 'Aktif', 'moved_out': 'Pindah keluar', 'deceased': 'Meninggal', 'all': 'Semua'};

  final _query = TextEditingController();
  String _status = 'active';
  late Future<List<Map<String, dynamic>>> _residents = _load();

  Future<List<Map<String, dynamic>>> _load() async {
    final q = Uri.encodeQueryComponent(_query.text.trim());
    return dataList(await widget.scope.api.get('/units/${widget.scope.unit.id}/residents?status=$_status&q=$q'));
  }

  void _reload() => setState(() => _residents = _load());

  Future<void> _lookupNik() async {
    final controller = TextEditingController();
    final nik = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Cari berdasarkan NIK'),
        content: TextField(
          controller: controller,
          autofocus: true,
          obscureText: true,
          keyboardType: TextInputType.number,
          decoration: const InputDecoration(labelText: 'NIK (16 digit)', helperText: 'Pencarian dicatat di log audit.'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Batal')),
          FilledButton(onPressed: () => Navigator.pop(ctx, controller.text.trim()), child: const Text('Cari')),
        ],
      ),
    );
    if (nik == null || nik.isEmpty || !mounted) return;

    setState(() {
      _residents = widget.scope.api
          .post('/units/${widget.scope.unit.id}/residents/lookup-nik', {'nik': nik})
          .then(dataList);
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text('Warga · ${widget.scope.unit.name}'),
        actions: [
          if (widget.scope.canWrite)
            IconButton(onPressed: _lookupNik, icon: const Icon(Icons.badge_outlined), tooltip: 'Cari NIK'),
        ],
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _query,
                    decoration: const InputDecoration(prefixIcon: Icon(Icons.search), labelText: 'Cari nama'),
                    onSubmitted: (_) => _reload(),
                  ),
                ),
                const SizedBox(width: 8),
                DropdownButton<String>(
                  value: _status,
                  items: [for (final e in _statusFilters.entries) DropdownMenuItem(value: e.key, child: Text(e.value))],
                  onChanged: (v) {
                    if (v == null) return;
                    _status = v;
                    _reload();
                  },
                ),
              ],
            ),
          ),
          Expanded(
            child: FutureBuilder<List<Map<String, dynamic>>>(
              future: _residents,
              builder: (context, snapshot) {
                if (snapshot.connectionState != ConnectionState.done) {
                  return const Center(child: CircularProgressIndicator());
                }
                if (snapshot.hasError) return ErrorView(error: snapshot.error, onRetry: _reload);
                final items = snapshot.data!;
                return RefreshIndicator(
                  onRefresh: () async => _reload(),
                  child: items.isEmpty
                      ? const EmptyState('Tidak ada data warga.')
                      : ListView.separated(
                          padding: const EdgeInsets.all(16),
                          itemCount: items.length,
                          separatorBuilder: (_, _) => const SizedBox(height: 8),
                          itemBuilder: (_, i) {
                            final r = items[i];
                            return Card(
                              child: ListTile(
                                leading: Icon(r['is_alive'] == true ? Icons.person_outline : Icons.person_off_outlined),
                                title: Text(r['full_name'] as String),
                                subtitle: Text(
                                  '${r['address_text'] ?? '-'}\n'
                                  '${residencyStatusLabels[r['residency_status']] ?? r['residency_status']}'
                                  '${r['nik_masked'] != null ? ' · NIK ${r['nik_masked']}' : ''}',
                                ),
                                isThreeLine: true,
                                trailing: r['is_household_head'] == true ? const Text('Kepala KK') : null,
                                onTap: () async {
                                  await Navigator.of(context).push(MaterialPageRoute(
                                    builder: (_) => ResidentDetailScreen(scope: widget.scope, residentId: r['id'] as String),
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
