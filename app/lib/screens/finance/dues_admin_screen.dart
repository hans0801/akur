import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../models/current_user.dart';
import '../population/population_common.dart';
import 'finance_common.dart';
import '../../widgets/async_view.dart';

/// Pengelolaan iuran oleh bendahara (ketua RT/RW: lihat saja): jenis iuran → periode → tagihan per keluarga.
class DuesAdminScreen extends StatefulWidget {
  const DuesAdminScreen({super.key, required this.api, required this.user});

  final ApiClient api;
  final CurrentUser user;

  @override
  State<DuesAdminScreen> createState() => _DuesAdminScreenState();
}

class _DuesAdminScreenState extends State<DuesAdminScreen> {
  late final List<UnitRef> _units = widget.user.financeUnits;
  late UnitRef _unit = _units.first;
  late Future<List<Map<String, dynamic>>> _types = _load();

  bool get _canWrite => widget.user.canWriteFinance(_unit.id);

  Future<List<Map<String, dynamic>>> _load() async => dataList(await widget.api.get('/units/${_unit.id}/dues-types'));

  void _reload() => setState(() { _types = _load(); });

  Future<void> _createType() async {
    final name = TextEditingController();
    final amount = TextEditingController();
    var frequency = 'monthly';
    final body = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => AlertDialog(
          title: const Text('Jenis iuran baru'),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            TextField(controller: name, autofocus: true, decoration: const InputDecoration(labelText: 'Nama')),
            TextField(controller: amount, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Nominal bawaan (Rp)')),
            DropdownButtonFormField<String>(
              initialValue: frequency,
              decoration: const InputDecoration(labelText: 'Frekuensi'),
              items: [for (final e in duesFrequencyLabels.entries) DropdownMenuItem(value: e.key, child: Text(e.value))],
              onChanged: (v) => setLocal(() => frequency = v ?? frequency),
            ),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Batal')),
            FilledButton(
              onPressed: () {
                final n = int.tryParse(amount.text.trim());
                if (name.text.trim().isEmpty || n == null) return;
                Navigator.pop(ctx, {'name': name.text.trim(), 'default_amount': n, 'frequency': frequency});
              },
              child: const Text('Simpan'),
            ),
          ],
        ),
      ),
    );
    if (body == null || !mounted) return;
    if (await runApi(context, () => widget.api.post('/units/${_unit.id}/dues-types', body), 'Jenis iuran dibuat.')) _reload();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Kelola iuran')),
      floatingActionButton: _canWrite
          ? FloatingActionButton.extended(onPressed: _createType, icon: const Icon(Icons.add), label: const Text('Jenis iuran'))
          : null,
      body: Column(children: [
        UnitDropdown(units: _units, value: _unit, onChanged: (u) {
          _unit = u;
          _reload();
        }),
        Expanded(
          child: FutureBuilder<List<Map<String, dynamic>>>(
            future: _types,
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
              if (snapshot.hasError) return ErrorView(error: snapshot.error, onRetry: _reload);
              final types = snapshot.data!;
              return RefreshIndicator(
                onRefresh: () async => _reload(),
                child: types.isEmpty
                    ? const EmptyState('Belum ada jenis iuran.')
                    : ListView(
                        padding: const EdgeInsets.fromLTRB(16, 16, 16, 96),
                        children: [
                          for (final t in types)
                            Card(
                              child: ListTile(
                                leading: Icon(t['is_active'] == true ? Icons.receipt_long_outlined : Icons.block),
                                title: Text(t['name'] as String),
                                subtitle: Text('${formatRupiah(t['default_amount'] as num)} · ${duesFrequencyLabels[t['frequency']] ?? t['frequency']}'
                                    ' · ${(t['unit'] as Map<String, dynamic>)['name']}'),
                                trailing: Text('${t['periods_count']} periode'),
                                onTap: () => Navigator.of(context).push(MaterialPageRoute(
                                  builder: (_) => _PeriodsScreen(
                                    api: widget.api,
                                    type: t,
                                    canWrite: widget.user.canWriteFinance(t['unit_id'] as String) || _canWrite,
                                  ),
                                )),
                              ),
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

class _PeriodsScreen extends StatefulWidget {
  const _PeriodsScreen({required this.api, required this.type, required this.canWrite});

  final ApiClient api;
  final Map<String, dynamic> type;
  final bool canWrite;

  @override
  State<_PeriodsScreen> createState() => _PeriodsScreenState();
}

class _PeriodsScreenState extends State<_PeriodsScreen> {
  late Future<List<Map<String, dynamic>>> _periods = _load();

  Future<List<Map<String, dynamic>>> _load() async => dataList(await widget.api.get('/dues-types/${widget.type['id']}/periods'));

  void _reload() => setState(() { _periods = _load(); });

  Future<void> _create() async {
    final now = DateTime.now();
    final code = TextEditingController(text: '${now.year}-${now.month.toString().padLeft(2, '0')}');
    final amount = TextEditingController(text: '${widget.type['default_amount']}');
    String? startsOn = formatDate(DateTime(now.year, now.month));
    String? dueOn = formatDate(DateTime(now.year, now.month, 10));
    final body = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => AlertDialog(
          title: const Text('Terbitkan tagihan periode'),
          content: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              TextField(controller: code, decoration: const InputDecoration(labelText: 'Kode periode (mis. 2026-10)')),
              TextField(controller: amount, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Nominal per keluarga (Rp)')),
              _DatePick(label: 'Mulai', value: startsOn, onChanged: (v) => setLocal(() => startsOn = v)),
              _DatePick(label: 'Jatuh tempo', value: dueOn, onChanged: (v) => setLocal(() => dueOn = v)),
              const SizedBox(height: 8),
              const Text('Tagihan dibuat untuk semua keluarga aktif dan warga menerima notifikasi.'),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Batal')),
            FilledButton(
              onPressed: () => Navigator.pop(ctx, {
                'period_code': code.text.trim(),
                'amount': int.tryParse(amount.text.trim()),
                'starts_on': startsOn,
                'due_on': dueOn,
              }),
              child: const Text('Terbitkan'),
            ),
          ],
        ),
      ),
    );
    if (body == null || !mounted) return;
    if (await runApi(context, () => widget.api.post('/dues-types/${widget.type['id']}/periods', body), 'Tagihan diterbitkan.')) _reload();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(widget.type['name'] as String)),
      floatingActionButton: widget.canWrite
          ? FloatingActionButton.extended(onPressed: _create, icon: const Icon(Icons.add), label: const Text('Periode'))
          : null,
      body: FutureBuilder<List<Map<String, dynamic>>>(
        future: _periods,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
          if (snapshot.hasError) return ErrorView(error: snapshot.error, onRetry: _reload);
          final periods = snapshot.data!;
          return RefreshIndicator(
            onRefresh: () async => _reload(),
            child: periods.isEmpty
                ? const EmptyState('Belum ada periode tagihan.')
                : ListView(
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 96),
                    children: [
                      for (final p in periods)
                        Card(
                          child: ListTile(
                            title: Text('${p['period_code']}${p['status'] == 'closed' ? ' (ditutup)' : ''}'),
                            subtitle: Text('${p['assessments_count']} keluarga · terkumpul ${formatRupiah(num.tryParse('${p['total_paid'] ?? 0}'))}'
                                ' dari ${formatRupiah(num.tryParse('${p['total_due'] ?? 0}'))}'),
                            trailing: const Icon(Icons.chevron_right),
                            onTap: () => Navigator.of(context).push(MaterialPageRoute(
                              builder: (_) => PeriodDetailScreen(api: widget.api, periodId: p['id'] as String, canWrite: widget.canWrite),
                            )),
                          ),
                        ),
                    ],
                  ),
          );
        },
      ),
    );
  }
}

class _DatePick extends StatelessWidget {
  const _DatePick({required this.label, required this.value, required this.onChanged});

  final String label;
  final String? value;
  final ValueChanged<String?> onChanged;

  @override
  Widget build(BuildContext context) => ListTile(
        contentPadding: EdgeInsets.zero,
        title: Text(label),
        trailing: Text(value ?? '-'),
        onTap: () async {
          final picked = await showDatePicker(
            context: context,
            initialDate: DateTime.tryParse(value ?? '') ?? DateTime.now(),
            firstDate: DateTime(2020),
            lastDate: DateTime(DateTime.now().year + 2),
          );
          if (picked != null) onChanged(formatDate(picked));
        },
      );
}

/// Tagihan per keluarga dalam satu periode; bendahara bisa mencatat bayar tunai atau membebaskan tagihan.
class PeriodDetailScreen extends StatefulWidget {
  const PeriodDetailScreen({super.key, required this.api, required this.periodId, required this.canWrite});

  final ApiClient api;
  final String periodId;
  final bool canWrite;

  @override
  State<PeriodDetailScreen> createState() => _PeriodDetailScreenState();
}

class _PeriodDetailScreenState extends State<PeriodDetailScreen> {
  late Future<Map<String, dynamic>> _data = _load();

  Future<Map<String, dynamic>> _load() async => (await widget.api.get('/dues-periods/${widget.periodId}'))['data'] as Map<String, dynamic>;

  void _reload() => setState(() { _data = _load(); });

  Future<void> _recordCash(Map<String, dynamic> a, String unitId) async {
    final amount = TextEditingController(text: '${a['outstanding']}');
    final value = await showDialog<int>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('Terima tunai · ${a['household']['head_name'] ?? a['household']['address_text']}'),
        content: TextField(controller: amount, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Nominal (Rp)')),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Batal')),
          FilledButton(onPressed: () => Navigator.pop(ctx, int.tryParse(amount.text.trim())), child: const Text('Catat')),
        ],
      ),
    );
    if (value == null || !mounted) return;
    final ok = await runApi(
      context,
      () => widget.api.post('/units/$unitId/payments', {
        'household_id': a['household_id'],
        'amount': value,
        'payment_method': 'cash',
        'allocations': [
          {'dues_assessment_id': a['id'], 'amount': value},
        ],
      }, {'Idempotency-Key': newIdempotencyKey()}),
      'Pembayaran tunai dicatat.',
    );
    if (ok) _reload();
  }

  Future<void> _waive(Map<String, dynamic> a) async {
    final reason = await askReason(context, title: 'Bebaskan tagihan?', message: 'Sisa ${formatRupiah(a['outstanding'] as num)} tidak lagi ditagih.', action: 'Bebaskan');
    if (reason == null || !mounted) return;
    if (await runApi(context, () => widget.api.post('/dues-assessments/${a['id']}/waive', {'reason': reason}), 'Tagihan dibebaskan.')) _reload();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return FutureBuilder<Map<String, dynamic>>(
      future: _data,
      builder: (context, snapshot) {
        final d = snapshot.data;
        return Scaffold(
          appBar: AppBar(title: Text(d == null ? 'Periode' : 'Periode ${(d['period'] as Map<String, dynamic>)['period_code']}')),
          body: switch (snapshot) {
            _ when snapshot.connectionState != ConnectionState.done => const Center(child: CircularProgressIndicator()),
            _ when snapshot.hasError => ErrorView(error: snapshot.error, onRetry: _reload),
            _ => RefreshIndicator(
                onRefresh: () async => _reload(),
                child: ListView(
                  padding: const EdgeInsets.all(16),
                  children: [
                    Card(
                      child: Padding(
                        padding: const EdgeInsets.all(16),
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text('Terkumpul', style: theme.textTheme.labelLarge),
                          Text('${formatRupiah(d!['summary']['total_paid'] as num)} / ${formatRupiah(d['summary']['total_due'] as num)}',
                              style: theme.textTheme.titleLarge),
                          Text('${d['summary']['paid_count']} dari ${d['summary']['households']} keluarga lunas'),
                        ]),
                      ),
                    ),
                    for (final a in (d['assessments'] as List<dynamic>).cast<Map<String, dynamic>>())
                      Card(
                        child: ListTile(
                          title: Text(a['household']['head_name'] as String? ?? a['household']['address_text'] as String),
                          subtitle: Text('${a['household']['address_text']}\n${formatRupiah(a['amount_paid'] as num)} / ${formatRupiah(a['amount_due'] as num)}'),
                          isThreeLine: true,
                          trailing: widget.canWrite && (a['status'] == 'unpaid' || a['status'] == 'partial')
                              ? PopupMenuButton<String>(
                                  onSelected: (v) => v == 'cash' ? _recordCash(a, _unitOf(d)) : _waive(a),
                                  itemBuilder: (_) => const [
                                    PopupMenuItem(value: 'cash', child: Text('Terima tunai')),
                                    PopupMenuItem(value: 'waive', child: Text('Bebaskan')),
                                  ],
                                  child: assessmentChip(a),
                                )
                              : assessmentChip(a),
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

  String _unitOf(Map<String, dynamic> d) => ((d['period'] as Map<String, dynamic>)['dues_type'] as Map<String, dynamic>)['unit_id'] as String;
}
