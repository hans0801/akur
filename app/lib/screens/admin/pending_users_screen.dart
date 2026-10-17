import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../models/current_user.dart';

/// Daftar akun pending dan penautan ke data warga (FR-02). Server tetap memvalidasi peran dan cakupan unit.
class PendingUsersScreen extends StatefulWidget {
  const PendingUsersScreen({super.key, required this.api, required this.units});

  final ApiClient api;
  final List<UnitRef> units;

  @override
  State<PendingUsersScreen> createState() => _PendingUsersScreenState();
}

class _PendingUsersScreenState extends State<PendingUsersScreen> {
  late Future<List<Map<String, dynamic>>> _pending = _load();

  Future<List<Map<String, dynamic>>> _load() async {
    final json = await widget.api.get('/admin/pending-users');
    return (json['data'] as List<dynamic>).cast<Map<String, dynamic>>();
  }

  void _reload() => setState(() => _pending = _load());

  Future<void> _link(Map<String, dynamic> user) async {
    final resident = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (_) => _ResidentPickerDialog(api: widget.api, units: widget.units),
    );
    if (resident == null || !mounted) return;

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Tautkan akun?'),
        content: Text('Akun ${user['display_name']} (${user['email'] ?? '-'}) akan ditautkan ke data warga '
            '${resident['full_name']}, ${resident['address_text']}. Pastikan identitasnya sudah Anda periksa.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Batal')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Tautkan')),
        ],
      ),
    );
    if (confirmed != true) return;

    await _run(() => widget.api.post('/admin/pending-users/${user['id']}/link', {'resident_id': resident['id']}),
        'Akun berhasil diverifikasi.');
  }

  Future<void> _reject(Map<String, dynamic> user) async {
    final controller = TextEditingController();
    final reason = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Tolak akun?'),
        content: TextField(controller: controller, decoration: const InputDecoration(labelText: 'Alasan (wajib)')),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Batal')),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, controller.text.trim()),
            child: const Text('Tolak'),
          ),
        ],
      ),
    );
    if (reason == null || reason.isEmpty) return;

    await _run(() => widget.api.post('/admin/pending-users/${user['id']}/reject', {'reason': reason}), 'Akun ditolak.');
  }

  Future<void> _run(Future<void> Function() action, String success) async {
    final messenger = ScaffoldMessenger.of(context);
    try {
      await action();
      messenger.showSnackBar(SnackBar(content: Text(success)));
      _reload();
    } on ApiException catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Verifikasi akun warga')),
      body: FutureBuilder<List<Map<String, dynamic>>>(
        future: _pending,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            return Center(child: Text(snapshot.error.toString()));
          }
          final users = snapshot.data!;
          if (users.isEmpty) {
            return const Center(child: Text('Tidak ada akun yang menunggu verifikasi.'));
          }
          return RefreshIndicator(
            onRefresh: () async => _reload(),
            child: ListView.separated(
              padding: const EdgeInsets.all(16),
              itemCount: users.length,
              separatorBuilder: (_, _) => const SizedBox(height: 8),
              itemBuilder: (_, i) {
                final u = users[i];
                return Card(
                  child: ListTile(
                    title: Text(u['display_name'] as String),
                    subtitle: Text(u['email'] as String? ?? '-'),
                    trailing: Wrap(
                      spacing: 4,
                      children: [
                        IconButton(
                          tooltip: 'Tolak',
                          onPressed: () => _reject(u),
                          icon: const Icon(Icons.block),
                        ),
                        FilledButton(onPressed: () => _link(u), child: const Text('Tautkan')),
                      ],
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

class _ResidentPickerDialog extends StatefulWidget {
  const _ResidentPickerDialog({required this.api, required this.units});

  final ApiClient api;
  final List<UnitRef> units;

  @override
  State<_ResidentPickerDialog> createState() => _ResidentPickerDialogState();
}

class _ResidentPickerDialogState extends State<_ResidentPickerDialog> {
  late UnitRef _unit = widget.units.first;
  final _query = TextEditingController();
  List<Map<String, dynamic>> _results = [];
  bool _loading = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _search();
  }

  Future<void> _search() async {
    setState(() => _loading = true);
    try {
      final q = Uri.encodeQueryComponent(_query.text.trim());
      final json = await widget.api.get('/units/${_unit.id}/residents?unlinked=1&q=$q');
      _results = (json['data'] as List<dynamic>).cast<Map<String, dynamic>>();
      _error = null;
    } on ApiException catch (e) {
      _error = e.message;
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('Pilih data warga'),
      content: SizedBox(
        width: 420,
        height: 420,
        child: Column(
          children: [
            if (widget.units.length > 1)
              DropdownButton<UnitRef>(
                value: _unit,
                isExpanded: true,
                items: [for (final u in widget.units) DropdownMenuItem(value: u, child: Text(u.name))],
                onChanged: (u) {
                  if (u == null) return;
                  setState(() => _unit = u);
                  _search();
                },
              ),
            TextField(
              controller: _query,
              decoration: const InputDecoration(prefixIcon: Icon(Icons.search), labelText: 'Cari nama'),
              onSubmitted: (_) => _search(),
            ),
            const SizedBox(height: 8),
            Expanded(
              child: _loading
                  ? const Center(child: CircularProgressIndicator())
                  : _error != null
                      ? Center(child: Text(_error!))
                      : ListView(
                          children: [
                            for (final r in _results)
                              ListTile(
                                title: Text(r['full_name'] as String),
                                subtitle: Text(r['address_text'] as String),
                                trailing: r['is_household_head'] == true ? const Text('Kepala KK') : null,
                                onTap: () => Navigator.pop(context, r),
                              ),
                          ],
                        ),
            ),
          ],
        ),
      ),
      actions: [TextButton(onPressed: () => Navigator.pop(context), child: const Text('Batal'))],
    );
  }
}
