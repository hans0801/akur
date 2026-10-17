import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../models/current_user.dart';
import '../community/community_common.dart';
import '../population/population_common.dart';
import 'attachments.dart';
import 'finance_common.dart';
import '../../widgets/async_view.dart';

/// Pembayaran yang dilaporkan warga; bendahara memverifikasi (masuk kas) atau menolak dengan alasan.
class PaymentQueueScreen extends StatefulWidget {
  const PaymentQueueScreen({super.key, required this.api, required this.user});

  final ApiClient api;
  final CurrentUser user;

  @override
  State<PaymentQueueScreen> createState() => _PaymentQueueScreenState();
}

class _PaymentQueueScreenState extends State<PaymentQueueScreen> {
  late final List<UnitRef> _units = widget.user.financeUnits;
  late UnitRef _unit = _units.first;
  String _status = 'submitted';
  late Future<List<Map<String, dynamic>>> _items = _load();

  bool get _canWrite => widget.user.canWriteFinance(_unit.id);

  Future<List<Map<String, dynamic>>> _load() async => dataList(await widget.api.get('/units/${_unit.id}/payments?status=$_status'));

  void _reload() => setState(() { _items = _load(); });

  Future<void> _verify(Map<String, dynamic> p) async {
    if (await runApi(context, () => widget.api.post('/payments/${p['id']}/verify'), 'Pembayaran diverifikasi dan dicatat di kas.')) _reload();
  }

  Future<void> _reject(Map<String, dynamic> p) async {
    final reason = await askReason(context, title: 'Tolak pembayaran?', message: 'Alasan dikirim ke warga.', action: 'Tolak');
    if (reason == null || !mounted) return;
    if (await runApi(context, () => widget.api.post('/payments/${p['id']}/reject', {'reason': reason}), 'Pembayaran ditolak.')) _reload();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Verifikasi pembayaran')),
      body: Column(children: [
        UnitDropdown(units: _units, value: _unit, onChanged: (u) {
          _unit = u;
          _reload();
        }),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
          child: SegmentedButton<String>(
            segments: const [
              ButtonSegment(value: 'submitted', label: Text('Menunggu')),
              ButtonSegment(value: 'verified', label: Text('Terverifikasi')),
              ButtonSegment(value: 'rejected', label: Text('Ditolak')),
            ],
            selected: {_status},
            onSelectionChanged: (s) {
              _status = s.first;
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
                    ? const EmptyState('Tidak ada pembayaran.')
                    : ListView(
                        padding: const EdgeInsets.all(16),
                        children: [
                          for (final p in items)
                            Card(
                              child: Padding(
                                padding: const EdgeInsets.all(12),
                                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                  Row(children: [
                                    Expanded(
                                      child: Text('${formatRupiah(p['amount'] as num)} · ${paymentMethodLabels[p['payment_method']] ?? p['payment_method']}',
                                          style: Theme.of(context).textTheme.titleMedium),
                                    ),
                                    paymentChip(p['status'] as String),
                                  ]),
                                  Text('${p['household']['head_name'] ?? '-'} · ${p['household']['address_text']}'),
                                  Text('Dibayar ${formatDateTime(p['paid_at'] as String?)}${p['reference_code'] != null ? ' · Ref ${p['reference_code']}' : ''}'),
                                  for (final a in (p['allocations'] as List<dynamic>).cast<Map<String, dynamic>>())
                                    Text('• ${a['label']}: ${formatRupiah(a['amount'] as num)}'),
                                  if (p['proof_file_id'] != null)
                                    Align(
                                      alignment: Alignment.centerLeft,
                                      child: TextButton.icon(
                                        onPressed: () => openAttachment(context, widget.api, p['proof_file_id'] as String),
                                        icon: const Icon(Icons.receipt_outlined),
                                        label: const Text('Lihat bukti bayar'),
                                      ),
                                    ),
                                  if (_canWrite && p['status'] == 'submitted')
                                    Row(mainAxisAlignment: MainAxisAlignment.end, children: [
                                      TextButton(onPressed: () => _reject(p), child: const Text('Tolak')),
                                      const SizedBox(width: 8),
                                      FilledButton(onPressed: () => _verify(p), child: const Text('Verifikasi')),
                                    ]),
                                ]),
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
