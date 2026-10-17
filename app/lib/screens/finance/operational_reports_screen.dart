import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../api/api_client.dart';
import '../../models/current_user.dart';
import '../../widgets/async_view.dart';
import '../community/community_common.dart';
import '../population/population_common.dart';
import 'finance_common.dart';

/// FR-11: laporan pengurus per unit dan bulan (tunggakan, pembayaran, surat, kegiatan) + ekspor CSV/cetak.
/// Jenis laporan yang tampil mengikuti peran; server tetap memeriksa hak akses.
class OperationalReportsScreen extends StatefulWidget {
  const OperationalReportsScreen({super.key, required this.api, required this.user});

  final ApiClient api;
  final CurrentUser user;

  static const _types = {
    'arrears': ('Tunggakan', CurrentUser.financeReadRoles),
    'payments': ('Pembayaran', CurrentUser.financeReadRoles),
    'documents': ('Surat', CurrentUser.documentReadRoles),
    'activities': ('Kegiatan', CurrentUser.communityWriteRoles),
  };

  /// Unit yang punya setidaknya satu laporan untuk pengguna ini.
  static List<UnitRef> unitsFor(CurrentUser user) => {
        for (final m in user.memberships)
          if (_types.values.any((t) => t.$2.contains(m.role))) m.unit.id: m.unit,
      }.values.toList();

  @override
  State<OperationalReportsScreen> createState() => _OperationalReportsScreenState();
}

class _OperationalReportsScreenState extends State<OperationalReportsScreen> {
  late final List<UnitRef> _units = OperationalReportsScreen.unitsFor(widget.user);
  late UnitRef _unit = _units.first;
  late String _type = _allowedTypes.first;
  DateTime _month = DateTime(DateTime.now().year, DateTime.now().month);
  late Future<Map<String, dynamic>> _data = _load();

  List<String> get _allowedTypes {
    final roles = widget.user.memberships.where((m) => m.unit.id == _unit.id).map((m) => m.role).toSet();
    return [
      for (final e in OperationalReportsScreen._types.entries)
        if (e.value.$2.any(roles.contains)) e.key,
    ];
  }

  String get _monthCode => '${_month.year}-${_month.month.toString().padLeft(2, '0')}';

  Future<Map<String, dynamic>> _load() async =>
      (await widget.api.get('/units/${_unit.id}/reports/$_type?month=$_monthCode'))['data'] as Map<String, dynamic>;

  void _reload() => setState(() { _data = _load(); });

  Future<void> _export(String format) async {
    final messenger = ScaffoldMessenger.of(context);
    try {
      final json = await widget.api.post('/units/${_unit.id}/finance-reports/export-link', {'format': format, 'month': _monthCode, 'report': _type});
      final url = Uri.parse((json['data'] as Map<String, dynamic>)['url'] as String);
      if (!await launchUrl(url, mode: LaunchMode.externalApplication)) {
        messenger.showSnackBar(const SnackBar(content: Text('Tidak dapat membuka browser.')));
      }
    } on ApiException catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(friendlyError(e))));
    }
  }

  String _cell(Map<String, dynamic> column, Object? value) {
    if (value == null || value == '') return '-';
    return switch (column['type']) {
      'money' => formatRupiah(value as num),
      'datetime' => formatDateTime(value as String),
      _ => '$value',
    };
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isCurrent = _month.year == DateTime.now().year && _month.month == DateTime.now().month;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Laporan pengurus'),
        actions: [
          PopupMenuButton<String>(
            tooltip: 'Ekspor',
            icon: const Icon(Icons.download_outlined),
            onSelected: _export,
            itemBuilder: (_) => const [
              PopupMenuItem(value: 'html', child: Text('Cetak / simpan PDF')),
              PopupMenuItem(value: 'csv', child: Text('Unduh CSV')),
            ],
          ),
        ],
      ),
      body: Column(children: [
        UnitDropdown(units: _units, value: _unit, onChanged: (u) {
          _unit = u;
          if (!_allowedTypes.contains(_type)) _type = _allowedTypes.first;
          _reload();
        }),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
          child: Wrap(spacing: 8, children: [
            for (final t in _allowedTypes)
              ChoiceChip(
                label: Text(OperationalReportsScreen._types[t]!.$1),
                selected: _type == t,
                onSelected: (_) {
                  _type = t;
                  _reload();
                },
              ),
          ]),
        ),
        Row(mainAxisAlignment: MainAxisAlignment.center, children: [
          IconButton(tooltip: 'Bulan sebelumnya', onPressed: () => setState(() {
                _month = DateTime(_month.year, _month.month - 1);
                _data = _load();
              }), icon: const Icon(Icons.chevron_left)),
          Text(_monthCode, style: theme.textTheme.titleMedium),
          IconButton(tooltip: 'Bulan berikutnya', onPressed: isCurrent ? null : () => setState(() {
                _month = DateTime(_month.year, _month.month + 1);
                _data = _load();
              }), icon: const Icon(Icons.chevron_right)),
        ]),
        Expanded(
          child: FutureBuilder<Map<String, dynamic>>(
            future: _data,
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
              if (snapshot.hasError) return ErrorView(error: snapshot.error, onRetry: _reload);
              final r = snapshot.data!;
              final columns = (r['columns'] as List<dynamic>).cast<Map<String, dynamic>>();
              final rows = (r['rows'] as List<dynamic>).cast<Map<String, dynamic>>();
              return RefreshIndicator(
                onRefresh: () async => _reload(),
                child: ListView(
                  padding: const EdgeInsets.all(16),
                  children: [
                    Text(r['title'] as String, style: theme.textTheme.titleLarge),
                    Text(r['subtitle'] as String, style: theme.textTheme.bodySmall),
                    const SizedBox(height: 8),
                    Wrap(spacing: 8, runSpacing: 8, children: [
                      for (final t in (r['totals'] as Map<String, dynamic>).values.cast<Map<String, dynamic>>())
                        Chip(label: Text('${t['label']}: ${t['type'] == 'money' ? formatRupiah(t['value'] as num) : t['value']}')),
                    ]),
                    const SizedBox(height: 8),
                    if (rows.isEmpty)
                      const Padding(padding: EdgeInsets.all(24), child: Center(child: Text('Tidak ada data untuk periode ini.')))
                    else
                      SingleChildScrollView(
                        scrollDirection: Axis.horizontal,
                        child: DataTable(
                          columns: [
                            for (final c in columns)
                              DataColumn(label: Text(c['label'] as String), numeric: c['type'] == 'money' || c['type'] == 'number'),
                          ],
                          rows: [
                            for (final row in rows)
                              DataRow(cells: [for (final c in columns) DataCell(Text(_cell(c, row[c['key']])))]),
                          ],
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
