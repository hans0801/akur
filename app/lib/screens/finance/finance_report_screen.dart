import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../api/api_client.dart';
import '../../models/current_user.dart';
import '../population/population_common.dart';
import 'finance_common.dart';
import '../../widgets/async_view.dart';

/// Laporan kas bulanan (agregat) untuk semua anggota unit: saldo awal/akhir, total per kategori, rekap iuran.
class FinanceReportScreen extends StatefulWidget {
  const FinanceReportScreen({super.key, required this.api, required this.units, this.exportUnitIds = const {}});

  final ApiClient api;
  final List<UnitRef> units;

  /// Unit yang boleh diekspor pengguna (peran keuangan); server tetap memeriksa.
  final Set<String> exportUnitIds;

  @override
  State<FinanceReportScreen> createState() => _FinanceReportScreenState();
}

class _FinanceReportScreenState extends State<FinanceReportScreen> {
  late UnitRef _unit = widget.units.first;
  DateTime _month = DateTime(DateTime.now().year, DateTime.now().month);
  late Future<Map<String, dynamic>> _data = _load();

  String get _monthCode => '${_month.year}-${_month.month.toString().padLeft(2, '0')}';

  Future<Map<String, dynamic>> _load() async =>
      (await widget.api.get('/units/${_unit.id}/finance-reports/monthly?month=$_monthCode'))['data'] as Map<String, dynamic>;

  void _reload() => setState(() => _data = _load());

  Future<void> _export(String format) async {
    final messenger = ScaffoldMessenger.of(context);
    try {
      final json = await widget.api.post('/units/${_unit.id}/finance-reports/export-link', {'format': format, 'month': _monthCode});
      final url = Uri.parse((json['data'] as Map<String, dynamic>)['url'] as String);
      if (!await launchUrl(url, mode: LaunchMode.externalApplication)) {
        messenger.showSnackBar(const SnackBar(content: Text('Tidak dapat membuka browser.')));
      }
    } on ApiException catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  void _shift(int months) => setState(() {
        _month = DateTime(_month.year, _month.month + months);
        _data = _load();
      });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isCurrent = _month.year == DateTime.now().year && _month.month == DateTime.now().month;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Laporan kas'),
        actions: [
          if (widget.exportUnitIds.contains(_unit.id))
            PopupMenuButton<String>(
              tooltip: 'Ekspor',
              icon: const Icon(Icons.download_outlined),
              onSelected: _export,
              itemBuilder: (_) => const [
                PopupMenuItem(value: 'html', child: Text('Cetak / simpan PDF')),
                PopupMenuItem(value: 'csv', child: Text('Unduh CSV transaksi')),
              ],
            ),
        ],
      ),
      body: Column(children: [
        UnitDropdown(units: widget.units, value: _unit, onChanged: (u) => setState(() {
              _unit = u;
              _data = _load();
            })),
        Row(mainAxisAlignment: MainAxisAlignment.center, children: [
          IconButton(tooltip: 'Bulan sebelumnya', onPressed: () => _shift(-1), icon: const Icon(Icons.chevron_left)),
          Text(_monthCode, style: theme.textTheme.titleMedium),
          IconButton(tooltip: 'Bulan berikutnya', onPressed: isCurrent ? null : () => _shift(1), icon: const Icon(Icons.chevron_right)),
        ]),
        Expanded(
          child: FutureBuilder<Map<String, dynamic>>(
            future: _data,
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
              if (snapshot.hasError) return ErrorView(error: snapshot.error, onRetry: _reload);
              final r = snapshot.data!;
              Widget row(String label, num value, {TextStyle? style}) => ListTile(
                    dense: true,
                    title: Text(label, style: style),
                    trailing: Text(formatRupiah(value), style: style),
                  );
              return ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  Card(
                    child: Column(children: [
                      row('Saldo awal', r['opening_balance'] as num),
                      row('Total pemasukan', r['total_income'] as num),
                      row('Total pengeluaran', -(r['total_expense'] as num)),
                      const Divider(height: 1),
                      row('Saldo akhir', r['closing_balance'] as num, style: theme.textTheme.titleMedium),
                    ]),
                  ),
                  const SizedBox(height: 12),
                  Text('Pemasukan', style: theme.textTheme.titleMedium),
                  Card(
                    child: Column(children: [
                      if ((r['income'] as List<dynamic>).isEmpty) const ListTile(dense: true, title: Text('-')),
                      for (final i in (r['income'] as List<dynamic>).cast<Map<String, dynamic>>()) row(i['category'] as String, i['amount'] as num),
                    ]),
                  ),
                  const SizedBox(height: 12),
                  Text('Pengeluaran', style: theme.textTheme.titleMedium),
                  Card(
                    child: Column(children: [
                      if ((r['expense'] as List<dynamic>).isEmpty) const ListTile(dense: true, title: Text('-')),
                      for (final e in (r['expense'] as List<dynamic>).cast<Map<String, dynamic>>()) row(e['category'] as String, e['amount'] as num),
                    ]),
                  ),
                  if ((r['dues'] as List<dynamic>).isNotEmpty) ...[
                    const SizedBox(height: 12),
                    Text('Iuran jatuh tempo bulan ini', style: theme.textTheme.titleMedium),
                    for (final d in (r['dues'] as List<dynamic>).cast<Map<String, dynamic>>())
                      Card(
                        child: ListTile(
                          title: Text('${d['dues_type']} · ${d['period_code']}'),
                          subtitle: Text('${d['paid_households']} dari ${d['households']} keluarga lunas'),
                          trailing: Text('${formatRupiah(d['total_paid'] as num)}\n/ ${formatRupiah(d['total_due'] as num)}', textAlign: TextAlign.end),
                        ),
                      ),
                  ],
                  const SizedBox(height: 8),
                  Text('Laporan ini terbuka untuk semua warga ${r['unit']['name']}. Rincian transaksi hanya untuk pengurus keuangan.',
                      style: theme.textTheme.bodySmall),
                ],
              );
            },
          ),
        ),
      ]),
    );
  }
}

/// Unit yang laporannya bisa dibuka: unit keanggotaan dan RW induk tidak diketahui klien, jadi pakai unit keanggotaan.
List<UnitRef> reportUnits(CurrentUser user) => {for (final m in user.memberships) m.unit.id: m.unit}.values.toList();
