import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../community/community_common.dart';
import '../population/population_common.dart';
import 'finance_common.dart';
import '../../widgets/async_view.dart';

/// Tagihan iuran dan riwayat pembayaran keluarga pengguna; lapor pembayaran untuk diverifikasi bendahara.
class MyDuesScreen extends StatefulWidget {
  const MyDuesScreen({super.key, required this.api});

  final ApiClient api;

  @override
  State<MyDuesScreen> createState() => _MyDuesScreenState();
}

class _MyDuesScreenState extends State<MyDuesScreen> {
  late Future<(Map<String, dynamic>, List<Map<String, dynamic>>)> _data = _load();

  Future<(Map<String, dynamic>, List<Map<String, dynamic>>)> _load() async {
    final dues = (await widget.api.get('/my/dues'))['data'] as Map<String, dynamic>;
    final payments = dataList(await widget.api.get('/my/payments'));
    return (dues, payments);
  }

  void _reload() => setState(() => _data = _load());

  Future<void> _pay(int outstanding) async {
    final saved = await Navigator.of(context).push<bool>(MaterialPageRoute(
      builder: (_) => PaymentFormScreen(api: widget.api, suggestedAmount: outstanding),
    ));
    if (saved == true) _reload();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Iuran keluarga'),
          bottom: const TabBar(tabs: [Tab(text: 'Tagihan'), Tab(text: 'Pembayaran')]),
        ),
        body: FutureBuilder<(Map<String, dynamic>, List<Map<String, dynamic>>)>(
          future: _data,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
            if (snapshot.hasError) return ErrorView(error: snapshot.error, onRetry: _reload);
            final (dues, payments) = snapshot.data!;
            final assessments = (dues['assessments'] as List<dynamic>).cast<Map<String, dynamic>>();
            final outstanding = (dues['outstanding_total'] as num).toInt();

            return TabBarView(children: [
              RefreshIndicator(
                onRefresh: () async => _reload(),
                child: ListView(
                  padding: const EdgeInsets.all(16),
                  children: [
                    Card(
                      child: Padding(
                        padding: const EdgeInsets.all(16),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text('Total belum dibayar', style: theme.textTheme.labelLarge),
                            Text(formatRupiah(outstanding), style: theme.textTheme.headlineMedium),
                            Text(dues['household']['address_text'] as String, style: theme.textTheme.bodySmall),
                            if (outstanding > 0) ...[
                              const SizedBox(height: 12),
                              FilledButton.icon(onPressed: () => _pay(outstanding), icon: const Icon(Icons.payments_outlined), label: const Text('Lapor pembayaran')),
                            ],
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(height: 8),
                    if (assessments.isEmpty) const Padding(padding: EdgeInsets.all(24), child: Center(child: Text('Belum ada tagihan.'))),
                    for (final a in assessments)
                      Card(
                        child: ListTile(
                          title: Text('${a['dues_type_name']} · ${a['period_code']}'),
                          subtitle: Text('Jatuh tempo ${a['due_on']} · dibayar ${formatRupiah(a['amount_paid'] as num)} dari ${formatRupiah(a['amount_due'] as num)}'),
                          trailing: assessmentChip(a),
                        ),
                      ),
                  ],
                ),
              ),
              RefreshIndicator(
                onRefresh: () async => _reload(),
                child: payments.isEmpty
                    ? const EmptyState('Belum ada pembayaran.')
                    : ListView(
                        padding: const EdgeInsets.all(16),
                        children: [
                          for (final p in payments)
                            Card(
                              child: ListTile(
                                title: Text('${formatRupiah(p['amount'] as num)} · ${paymentMethodLabels[p['payment_method']] ?? p['payment_method']}'),
                                subtitle: Text([
                                  formatDateTime(p['paid_at'] as String?),
                                  for (final a in (p['allocations'] as List<dynamic>).cast<Map<String, dynamic>>()) '${a['label']}: ${formatRupiah(a['amount'] as num)}',
                                  if (p['rejection_reason'] != null) 'Alasan ditolak: ${p['rejection_reason']}',
                                ].join('\n')),
                                isThreeLine: true,
                                trailing: paymentChip(p['status'] as String),
                              ),
                            ),
                        ],
                      ),
              ),
            ]);
          },
        ),
      ),
    );
  }
}

/// Lapor pembayaran (dialokasikan otomatis ke tagihan terlama). Idempotency-Key mencegah pembayaran ganda saat kirim ulang.
class PaymentFormScreen extends StatefulWidget {
  const PaymentFormScreen({super.key, required this.api, required this.suggestedAmount});

  final ApiClient api;
  final int suggestedAmount;

  @override
  State<PaymentFormScreen> createState() => _PaymentFormScreenState();
}

class _PaymentFormScreenState extends State<PaymentFormScreen> {
  final _form = GlobalKey<FormState>();
  late final _amount = TextEditingController(text: '${widget.suggestedAmount}');
  final _reference = TextEditingController();
  final _idempotencyKey = newIdempotencyKey();
  String _method = 'transfer';
  bool _saving = false;

  Future<void> _save() async {
    if (!_form.currentState!.validate()) return;
    setState(() => _saving = true);
    final ok = await runApi(
      context,
      () => widget.api.post('/payments', {
        'amount': int.parse(_amount.text.trim()),
        'payment_method': _method,
        if (_reference.text.trim().isNotEmpty) 'reference_code': _reference.text.trim(),
      }, {'Idempotency-Key': _idempotencyKey}),
      'Pembayaran dilaporkan. Menunggu verifikasi bendahara.',
    );
    if (!mounted) return;
    setState(() => _saving = false);
    if (ok) Navigator.pop(context, true);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Lapor pembayaran')),
      body: Form(
        key: _form,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            TextFormField(
              controller: _amount,
              keyboardType: TextInputType.number,
              decoration: InputDecoration(labelText: 'Nominal (Rp) *', helperText: 'Maksimal ${formatRupiah(widget.suggestedAmount)}'),
              validator: (v) {
                final n = int.tryParse(v?.trim() ?? '');
                if (n == null || n < 1) return 'Masukkan nominal';
                if (n > widget.suggestedAmount) return 'Melebihi total tagihan';
                return null;
              },
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              initialValue: _method,
              decoration: const InputDecoration(labelText: 'Metode'),
              items: [for (final e in paymentMethodLabels.entries) DropdownMenuItem(value: e.key, child: Text(e.value))],
              onChanged: (v) => setState(() => _method = v ?? _method),
            ),
            const SizedBox(height: 12),
            TextFormField(controller: _reference, decoration: const InputDecoration(labelText: 'No. referensi / catatan transfer')),
            const SizedBox(height: 8),
            Text('Pembayaran dialokasikan ke tagihan yang paling lama jatuh tempo.', style: Theme.of(context).textTheme.bodySmall),
            const SizedBox(height: 24),
            FilledButton(onPressed: _saving ? null : _save, child: Text(_saving ? 'Mengirim…' : 'Kirim')),
          ],
        ),
      ),
    );
  }
}
