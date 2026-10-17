import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../models/current_user.dart';
import '../community/community_common.dart';
import '../population/population_common.dart';
import 'finance_common.dart';
import '../../widgets/async_view.dart';

/// Buku kas unit: saldo, rincian transaksi, catat pemasukan/pengeluaran, dan pembalikan (bendahara).
class CashbookScreen extends StatefulWidget {
  const CashbookScreen({super.key, required this.api, required this.user});

  final ApiClient api;
  final CurrentUser user;

  @override
  State<CashbookScreen> createState() => _CashbookScreenState();
}

class _CashbookScreenState extends State<CashbookScreen> {
  late final List<UnitRef> _units = widget.user.financeUnits;
  late UnitRef _unit = _units.first;
  late Future<Map<String, dynamic>> _data = _load();

  bool get _canWrite => widget.user.canWriteFinance(_unit.id);

  Future<Map<String, dynamic>> _load() => widget.api.get('/units/${_unit.id}/finance-transactions');

  void _reload() => setState(() => _data = _load());

  Future<void> _add() async {
    final saved = await Navigator.of(context).push<bool>(MaterialPageRoute(
      builder: (_) => _TransactionFormScreen(api: widget.api, unit: _unit),
    ));
    if (saved == true) _reload();
  }

  Future<void> _reverse(Map<String, dynamic> t) async {
    final reason = await askReason(
      context,
      title: 'Balik transaksi?',
      message: 'Transaksi asal tetap tercatat; transaksi pembalik ${formatRupiah(t['amount'] as num)} ditambahkan.',
      action: 'Balik',
    );
    if (reason == null || !mounted) return;
    if (await runApi(context, () => widget.api.post('/finance-transactions/${t['id']}/reverse', {'reason': reason}), 'Transaksi dibalik.')) _reload();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('Buku kas')),
      floatingActionButton: _canWrite
          ? FloatingActionButton.extended(onPressed: _add, icon: const Icon(Icons.add), label: const Text('Transaksi'))
          : null,
      body: Column(children: [
        UnitDropdown(units: _units, value: _unit, onChanged: (u) {
          _unit = u;
          _reload();
        }),
        Expanded(
          child: FutureBuilder<Map<String, dynamic>>(
            future: _data,
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
              if (snapshot.hasError) return ErrorView(error: snapshot.error, onRetry: _reload);
              final items = dataList(snapshot.data!);
              return RefreshIndicator(
                onRefresh: () async => _reload(),
                child: ListView(
                  padding: const EdgeInsets.fromLTRB(16, 16, 16, 96),
                  children: [
                    Card(
                      child: ListTile(
                        title: const Text('Saldo kas'),
                        subtitle: Text(_unit.name),
                        trailing: Text(formatRupiah(snapshot.data!['balance'] as num), style: theme.textTheme.titleLarge),
                      ),
                    ),
                    if (items.isEmpty) const Padding(padding: EdgeInsets.all(24), child: Center(child: Text('Belum ada transaksi.'))),
                    for (final t in items)
                      Card(
                        child: ListTile(
                          leading: Icon(switch (t['kind']) {
                            'income' => Icons.south_west,
                            'expense' => Icons.north_east,
                            _ => Icons.undo,
                          }),
                          title: Text(t['description'] as String),
                          subtitle: Text('${t['category']?['name'] ?? '-'} · ${formatDateTime(t['occurred_at'] as String)}'
                              '${t['is_reversed'] == true ? ' · dibalik' : ''}'),
                          trailing: Text(
                            '${t['kind'] == 'expense' ? '−' : t['kind'] == 'reversal' ? '↺ ' : '+'}${formatRupiah(t['amount'] as num)}',
                            style: TextStyle(
                              decoration: t['is_reversed'] == true ? TextDecoration.lineThrough : null,
                              color: t['kind'] == 'expense' ? theme.colorScheme.error : null,
                            ),
                          ),
                          onLongPress: _canWrite && t['kind'] != 'reversal' && t['is_reversed'] != true && t['reference_type'] != 'payment'
                              ? () => _reverse(t)
                              : null,
                        ),
                      ),
                    if (_canWrite && items.isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.only(top: 8),
                        child: Text('Tekan lama transaksi manual untuk membaliknya.', style: theme.textTheme.bodySmall),
                      ),
                  ],
                ),
              );
            },
          ),
        ),
      ]),
    );
  }
}

class _TransactionFormScreen extends StatefulWidget {
  const _TransactionFormScreen({required this.api, required this.unit});

  final ApiClient api;
  final UnitRef unit;

  @override
  State<_TransactionFormScreen> createState() => _TransactionFormScreenState();
}

class _TransactionFormScreenState extends State<_TransactionFormScreen> {
  final _form = GlobalKey<FormState>();
  final _amount = TextEditingController();
  final _description = TextEditingController();
  List<Map<String, dynamic>> _categories = [];
  String _kind = 'expense';
  String? _categoryId;
  DateTime? _occurredAt = DateTime.now();
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    widget.api.get('/units/${widget.unit.id}/finance-categories').then((json) {
      if (mounted) setState(() => _categories = dataList(json).where((c) => c['is_active'] == true).toList());
    });
  }

  Future<void> _save() async {
    if (!_form.currentState!.validate() || _occurredAt == null) return;
    setState(() => _saving = true);
    final ok = await runApi(
      context,
      () => widget.api.post('/units/${widget.unit.id}/finance-transactions', {
        'category_id': _categoryId,
        'amount': int.parse(_amount.text.trim()),
        'occurred_at': _occurredAt!.toUtc().toIso8601String(),
        'description': _description.text.trim(),
      }),
      'Transaksi dicatat.',
    );
    if (!mounted) return;
    setState(() => _saving = false);
    if (ok) Navigator.pop(context, true);
  }

  @override
  Widget build(BuildContext context) {
    final options = _categories.where((c) => c['kind'] == _kind).toList();
    return Scaffold(
      appBar: AppBar(title: Text('Transaksi kas · ${widget.unit.name}')),
      body: Form(
        key: _form,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            SegmentedButton<String>(
              segments: const [
                ButtonSegment(value: 'income', label: Text('Pemasukan')),
                ButtonSegment(value: 'expense', label: Text('Pengeluaran')),
              ],
              selected: {_kind},
              onSelectionChanged: (s) => setState(() {
                _kind = s.first;
                _categoryId = null;
              }),
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              key: ValueKey('cat-$_kind-${_categories.length}'),
              initialValue: _categoryId,
              decoration: const InputDecoration(labelText: 'Kategori *'),
              items: [for (final c in options) DropdownMenuItem(value: c['id'] as String, child: Text(c['name'] as String))],
              onChanged: (v) => setState(() => _categoryId = v),
              validator: (v) => v == null ? 'Pilih kategori' : null,
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _amount,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(labelText: 'Nominal (Rp) *'),
              validator: (v) => (int.tryParse(v?.trim() ?? '') ?? 0) < 1 ? 'Masukkan nominal' : null,
            ),
            const SizedBox(height: 12),
            DateTimeField(label: 'Waktu', required: true, allowPast: true, value: _occurredAt, onChanged: (v) => setState(() => _occurredAt = v)),
            const SizedBox(height: 12),
            TextFormField(
              controller: _description,
              decoration: const InputDecoration(labelText: 'Keterangan *'),
              validator: (v) => (v == null || v.trim().isEmpty) ? 'Wajib diisi' : null,
            ),
            const SizedBox(height: 8),
            Text('Transaksi tidak bisa diubah atau dihapus; kesalahan dikoreksi dengan pembalikan.', style: Theme.of(context).textTheme.bodySmall),
            const SizedBox(height: 24),
            FilledButton(onPressed: _saving ? null : _save, child: Text(_saving ? 'Menyimpan…' : 'Simpan')),
          ],
        ),
      ),
    );
  }
}
