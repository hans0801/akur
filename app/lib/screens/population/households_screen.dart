import 'package:flutter/material.dart';

import 'household_form_screen.dart';
import 'population_common.dart';
import 'resident_detail_screen.dart';
import 'resident_form_screen.dart';

/// Daftar keluarga (KK) per unit. No. KK hanya tampil tersamar.
class HouseholdsScreen extends StatefulWidget {
  const HouseholdsScreen({super.key, required this.scope});

  final PopulationScope scope;

  @override
  State<HouseholdsScreen> createState() => _HouseholdsScreenState();
}

class _HouseholdsScreenState extends State<HouseholdsScreen> {
  final _query = TextEditingController();
  String _status = 'active';
  late Future<List<Map<String, dynamic>>> _households = _load();

  Future<List<Map<String, dynamic>>> _load() async {
    final q = Uri.encodeQueryComponent(_query.text.trim());
    return dataList(await widget.scope.api.get('/units/${widget.scope.unit.id}/households?status=$_status&q=$q'));
  }

  void _reload() => setState(() => _households = _load());

  Future<void> _create() async {
    final created = await Navigator.of(context).push<bool>(
      MaterialPageRoute(builder: (_) => HouseholdFormScreen(scope: widget.scope)),
    );
    if (created == true) _reload();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text('Keluarga · ${widget.scope.unit.name}')),
      floatingActionButton: widget.scope.canWrite
          ? FloatingActionButton.extended(onPressed: _create, icon: const Icon(Icons.add), label: const Text('Tambah KK'))
          : null,
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _query,
                    decoration: const InputDecoration(prefixIcon: Icon(Icons.search), labelText: 'Cari alamat / kepala keluarga'),
                    onSubmitted: (_) => _reload(),
                  ),
                ),
                const SizedBox(width: 8),
                DropdownButton<String>(
                  value: _status,
                  items: [
                    for (final e in householdStatusLabels.entries) DropdownMenuItem(value: e.key, child: Text(e.value)),
                  ],
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
              future: _households,
              builder: (context, snapshot) {
                if (snapshot.connectionState != ConnectionState.done) {
                  return const Center(child: CircularProgressIndicator());
                }
                if (snapshot.hasError) return Center(child: Text(snapshot.error.toString()));
                final items = snapshot.data!;
                return RefreshIndicator(
                  onRefresh: () async => _reload(),
                  child: items.isEmpty
                      ? const EmptyState('Belum ada data keluarga.')
                      : ListView.separated(
                          padding: const EdgeInsets.all(16),
                          itemCount: items.length,
                          separatorBuilder: (_, _) => const SizedBox(height: 8),
                          itemBuilder: (_, i) {
                            final h = items[i];
                            return Card(
                              child: ListTile(
                                leading: const Icon(Icons.family_restroom_outlined),
                                title: Text(h['head_name'] as String? ?? 'Kepala keluarga belum ditetapkan'),
                                subtitle: Text('${h['address_text']}\nKK: ${h['kk_masked'] ?? '-'}'),
                                isThreeLine: true,
                                trailing: Text('${h['members_count'] ?? 0} jiwa'),
                                onTap: () async {
                                  await Navigator.of(context).push(MaterialPageRoute(
                                    builder: (_) => HouseholdDetailScreen(scope: widget.scope, householdId: h['id'] as String),
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

/// Detail keluarga: anggota, kepala keluarga, dan No. KK (tersamar; tampilkan utuh lewat alasan + audit).
class HouseholdDetailScreen extends StatefulWidget {
  const HouseholdDetailScreen({super.key, required this.scope, required this.householdId});

  final PopulationScope scope;
  final String householdId;

  @override
  State<HouseholdDetailScreen> createState() => _HouseholdDetailScreenState();
}

class _HouseholdDetailScreenState extends State<HouseholdDetailScreen> {
  late Future<Map<String, dynamic>> _household = _load();

  Future<Map<String, dynamic>> _load() async =>
      (await widget.scope.api.get('/households/${widget.householdId}'))['data'] as Map<String, dynamic>;

  void _reload() => setState(() => _household = _load());

  Future<void> _edit(Map<String, dynamic> household) async {
    final saved = await Navigator.of(context).push<bool>(
      MaterialPageRoute(builder: (_) => HouseholdFormScreen(scope: widget.scope, household: household)),
    );
    if (saved == true) _reload();
  }

  Future<void> _addMember() async {
    final saved = await Navigator.of(context).push<bool>(
      MaterialPageRoute(builder: (_) => ResidentFormScreen(scope: widget.scope, householdId: widget.householdId)),
    );
    if (saved == true) _reload();
  }

  Future<void> _setHead(Map<String, dynamic> resident) async {
    final ok = await runApi(
      context,
      () => widget.scope.api.patch('/households/${widget.householdId}', {'head_resident_id': resident['id']}),
      '${resident['full_name']} ditetapkan sebagai kepala keluarga.',
    );
    if (ok) _reload();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return FutureBuilder<Map<String, dynamic>>(
      future: _household,
      builder: (context, snapshot) {
        final h = snapshot.data;
        return Scaffold(
          appBar: AppBar(
            title: const Text('Detail keluarga'),
            actions: [
              if (h != null && widget.scope.canWrite)
                IconButton(onPressed: () => _edit(h), icon: const Icon(Icons.edit_outlined), tooltip: 'Ubah'),
            ],
          ),
          floatingActionButton: h != null && widget.scope.canWrite && h['status'] == 'active'
              ? FloatingActionButton.extended(
                  onPressed: _addMember,
                  icon: const Icon(Icons.person_add_alt),
                  label: const Text('Tambah anggota'),
                )
              : null,
          body: switch (snapshot) {
            _ when snapshot.connectionState != ConnectionState.done => const Center(child: CircularProgressIndicator()),
            _ when snapshot.hasError => Center(child: Text(snapshot.error.toString())),
            _ => RefreshIndicator(
                onRefresh: () async => _reload(),
                child: ListView(
                  padding: const EdgeInsets.all(16),
                  children: [
                    Text(h!['address_text'] as String, style: theme.textTheme.titleLarge),
                    const SizedBox(height: 8),
                    Wrap(spacing: 8, runSpacing: 8, children: [
                      Chip(label: Text(householdStatusLabels[h['status']] ?? h['status'] as String)),
                      if (h['street_name'] != null) Chip(label: Text(h['street_name'] as String)),
                      if (h['house_number'] != null) Chip(label: Text('No. ${h['house_number']}')),
                      if (h['household_number'] != null) Chip(label: Text('Nomor: ${h['household_number']}')),
                    ]),
                    const SizedBox(height: 8),
                    Card(
                      child: ListTile(
                        leading: const Icon(Icons.badge_outlined),
                        title: Text(h['kk_masked'] as String? ?? 'Belum diisi'),
                        subtitle: const Text('No. KK (tersamar)'),
                        trailing: h['has_kk'] == true && widget.scope.canWrite
                            ? TextButton(
                                onPressed: () => revealSensitive(context, widget.scope.api,
                                    path: '/households/${widget.householdId}/kk/reveal', field: 'kk_number', label: 'No. KK'),
                                child: const Text('Tampilkan'),
                              )
                            : null,
                      ),
                    ),
                    const SizedBox(height: 16),
                    Text('Anggota', style: theme.textTheme.titleMedium),
                    const SizedBox(height: 8),
                    for (final r in (h['members'] as List<dynamic>).cast<Map<String, dynamic>>())
                      Card(
                        child: ListTile(
                          leading: Icon(r['is_household_head'] == true ? Icons.star : Icons.person_outline),
                          title: Text(r['full_name'] as String),
                          subtitle: Text(
                            '${residencyStatusLabels[r['residency_status']] ?? r['residency_status']}'
                            '${r['nik_masked'] != null ? ' · NIK ${r['nik_masked']}' : ''}',
                          ),
                          trailing: widget.scope.canWrite &&
                                  r['is_household_head'] != true &&
                                  r['is_alive'] == true &&
                                  r['residency_status'] != 'moved_out'
                              ? PopupMenuButton<String>(
                                  onSelected: (_) => _setHead(r),
                                  itemBuilder: (_) => const [
                                    PopupMenuItem(value: 'head', child: Text('Jadikan kepala keluarga')),
                                  ],
                                )
                              : null,
                          onTap: () async {
                            await Navigator.of(context).push(MaterialPageRoute(
                              builder: (_) => ResidentDetailScreen(scope: widget.scope, residentId: r['id'] as String),
                            ));
                            _reload();
                          },
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
