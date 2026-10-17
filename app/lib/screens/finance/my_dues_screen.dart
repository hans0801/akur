import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../models/current_user.dart';
import '../../widgets/akur_ui.dart';
import '../home_screen.dart';
import '../population/population_common.dart';
import 'attachments.dart';
import 'finance_common.dart';
import 'finance_report_screen.dart';
import '../../widgets/async_view.dart';

/// Iuran keluarga pengguna: tagihan berjalan dengan rincian, transparansi kas RT (saldo, partisipasi,
/// pemanfaatan dana), dan riwayat pembayaran. Warga melapor pembayaran untuk diverifikasi bendahara.
class MyDuesScreen extends StatefulWidget {
  const MyDuesScreen({super.key, required this.api, required this.user, this.embedded = false});

  final ApiClient api;
  final CurrentUser user;

  /// true bila tampil sebagai tab di kerangka utama (tanpa AppBar sendiri).
  final bool embedded;

  @override
  State<MyDuesScreen> createState() => _MyDuesScreenState();
}

typedef _DuesData = ({Map<String, dynamic>? dues, List<Map<String, dynamic>> payments, Map<String, dynamic>? report});

class _MyDuesScreenState extends State<MyDuesScreen> {
  late Future<_DuesData> _data = _load();

  Future<_DuesData> _load() async {
    final unit = primaryUnitOf(widget.user);
    final linked = widget.user.residentName != null;
    final results = await Future.wait<Object?>([
      linked ? widget.api.get('/my/dues') : Future.value(null),
      linked ? widget.api.get('/my/payments') : Future.value(null),
      // Laporan kas bersifat pelengkap: kegagalannya tidak menggagalkan halaman.
      unit == null
          ? Future.value(null)
          : widget.api.get('/units/${unit.id}/finance-reports/monthly').then<Object?>((v) => v, onError: (_) => null),
    ]);
    return (
      dues: (results[0] as Map<String, dynamic>?)?['data'] as Map<String, dynamic>?,
      payments: results[1] == null ? <Map<String, dynamic>>[] : dataList(results[1] as Map<String, dynamic>),
      report: (results[2] as Map<String, dynamic>?)?['data'] as Map<String, dynamic>?,
    );
  }

  void _reload() => setState(() { _data = _load(); });

  Future<void> _pay(int outstanding) async {
    final saved = await Navigator.of(context).push<bool>(
      MaterialPageRoute(
        builder: (_) => PaymentFormScreen(api: widget.api, suggestedAmount: outstanding),
      ),
    );
    if (saved == true) _reload();
  }

  void _openReport() => Navigator.of(context).push(
    MaterialPageRoute(
      builder: (_) => FinanceReportScreen(
        api: widget.api,
        units: reportUnits(widget.user),
        exportUnitIds: {for (final u in widget.user.financeUnits) u.id},
      ),
    ),
  );

  @override
  Widget build(BuildContext context) {
    final body = FutureBuilder<_DuesData>(
      future: _data,
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
        if (snapshot.hasError) return ErrorView(error: snapshot.error, onRetry: _reload);
        final data = snapshot.data!;
        return RefreshIndicator(
          onRefresh: () async => _reload(),
          child: ListView(
            padding: const EdgeInsets.fromLTRB(20, 8, 20, 32),
            children: [
              if (data.dues == null)
                const AkurCard(
                  child: Row(
                    children: [
                      IconDisc(Icons.link_off_rounded),
                      SizedBox(width: 14),
                      Expanded(
                        child: Text(
                          'Akun Anda belum tertaut ke data keluarga, jadi belum ada tagihan iuran. '
                          'Hubungi pengurus RT untuk verifikasi.',
                        ),
                      ),
                    ],
                  ),
                )
              else ...[
                _householdHeader(data.dues!),
                const SizedBox(height: 16),
                _billCard(data.dues!),
              ],
              if (data.report != null) ...[
                const SizedBox(height: 20),
                _transparencyCard(data.report!),
                if ((data.report!['expense'] as List<dynamic>).isNotEmpty) ...[const SizedBox(height: 24), _usage(data.report!)],
              ],
              if (data.dues != null) ...[
                const SizedBox(height: 24),
                const SectionHeader('Riwayat Pembayaran Anda'),
                const SizedBox(height: 8),
                if (data.payments.isEmpty)
                  const AkurCard(child: Text('Belum ada pembayaran.'))
                else
                  Column(spacing: 12, children: [for (final p in data.payments) _paymentRow(p)]),
              ],
              const SizedBox(height: 24),
              const HelpCard(
                icon: Icons.support_agent_rounded,
                title: 'Perlu bantuan pembayaran?',
                message: 'Bayar tunai ke bendahara juga bisa; bendahara akan mencatatnya untuk keluarga Anda.',
              ),
            ],
          ),
        );
      },
    );
    if (widget.embedded) return body;
    return Scaffold(
      appBar: AppBar(title: const Text('Iuran keluarga')),
      body: body,
    );
  }

  Widget _householdHeader(Map<String, dynamic> dues) {
    final theme = Theme.of(context);
    final unit = primaryUnitOf(widget.user);
    return Row(
      children: [
        const IconDisc(Icons.home_outlined, size: 48),
        const SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(dues['household']['address_text'] as String, style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
              Text('Keluarga ${widget.user.residentName}', style: theme.textTheme.bodySmall),
            ],
          ),
        ),
        if (unit != null) Pill(unit.name, tone: PillTone.secondary),
      ],
    );
  }

  Widget _billCard(Map<String, dynamic> dues) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final assessments = (dues['assessments'] as List<dynamic>).cast<Map<String, dynamic>>();
    final open = assessments.where((a) => a['status'] == 'unpaid' || a['status'] == 'partial').toList();
    final outstanding = (dues['outstanding_total'] as num).toInt();
    final nearestDue = open
        .map((a) => a['due_on'] as String?)
        .whereType<String>()
        .fold<String?>(null, (min, d) => min == null || d.compareTo(min) < 0 ? d : min);
    final overdue = open.any((a) => a['is_overdue'] == true);
    return AkurCard(
      padding: EdgeInsets.zero,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Container(
            height: 6,
            decoration: BoxDecoration(gradient: LinearGradient(colors: [scheme.primary, scheme.secondary])),
          ),
          Padding(
            padding: const EdgeInsets.all(20),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('TAGIHAN IURAN', style: theme.textTheme.labelMedium?.copyWith(color: scheme.onSurfaceVariant)),
                          Text(open.isEmpty ? 'Semua lunas' : '${open.length} tagihan terbuka', style: theme.textTheme.headlineSmall),
                        ],
                      ),
                    ),
                    Pill(
                      open.isEmpty ? 'Lunas' : (overdue ? 'Terlambat' : 'Menunggu Pembayaran'),
                      tone: open.isEmpty ? PillTone.secondary : (overdue ? PillTone.danger : PillTone.tertiary),
                      icon: open.isEmpty ? Icons.verified_outlined : Icons.error_outline_rounded,
                    ),
                  ],
                ),
                const SizedBox(height: 14),
                Text.rich(
                  TextSpan(
                    children: [
                      TextSpan(
                        text: formatRupiah(outstanding),
                        style: theme.textTheme.headlineMedium?.copyWith(color: scheme.primary, fontWeight: FontWeight.w800),
                      ),
                      TextSpan(text: '  belum dibayar', style: theme.textTheme.bodyMedium),
                    ],
                  ),
                ),
                if (nearestDue != null) ...[
                  const SizedBox(height: 6),
                  Text(
                    'Jatuh tempo terdekat ${formatShortDate(nearestDue)}. Gotong royong kita menjaga kenyamanan lingkungan.',
                    style: theme.textTheme.bodyMedium,
                  ),
                ],
                if (open.isNotEmpty) ...[
                  const SizedBox(height: 16),
                  InfoPanel(
                    children: [
                      Text('Rincian tagihan:', style: theme.textTheme.bodySmall?.copyWith(fontWeight: FontWeight.w700)),
                      for (final a in open)
                        Row(
                          children: [
                            IconDisc(Icons.receipt_long_outlined, size: 34, background: Colors.white, color: scheme.secondary),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Text(
                                '${a['dues_type_name']} · ${formatMonthCode(a['period_code'] as String?)}',
                                style: theme.textTheme.bodyMedium,
                              ),
                            ),
                            Text(
                              formatRupiah((a['amount_due'] as num) - (a['amount_paid'] as num)),
                              style: theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w700),
                            ),
                          ],
                        ),
                    ],
                  ),
                  const SizedBox(height: 16),
                  FilledButton.icon(
                    onPressed: () => _pay(outstanding),
                    icon: const Icon(Icons.qr_code_2_rounded),
                    label: const Text('Lapor Pembayaran'),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    'Bayar via QRIS/transfer/tunai, lalu laporkan di sini dengan bukti bayar untuk diverifikasi bendahara.',
                    textAlign: TextAlign.center,
                    style: theme.textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _transparencyCard(Map<String, dynamic> report) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final dues = (report['dues'] as List<dynamic>).cast<Map<String, dynamic>>().where((d) => (d['households'] as num) > 0).toList();
    final unitName = (report['unit'] as Map<String, dynamic>)['name'] as String;
    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(color: scheme.secondaryContainer, borderRadius: BorderRadius.circular(18)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              const Pill('Transparansi Keuangan Warga', tone: PillTone.neutral, icon: Icons.account_balance_outlined),
              const Spacer(),
              Icon(Icons.verified_user_outlined, color: scheme.secondary),
            ],
          ),
          const SizedBox(height: 12),
          Text('Saldo Kas $unitName', style: theme.textTheme.titleMedium?.copyWith(color: scheme.onSecondaryContainer)),
          const SizedBox(height: 4),
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Expanded(
                child: Text(
                  formatRupiah(report['closing_balance'] as num),
                  style: theme.textTheme.headlineMedium?.copyWith(fontWeight: FontWeight.w800, color: scheme.onSurface),
                ),
              ),
              Text('per ${formatMonthCode(report['month'] as String?)}', style: theme.textTheme.bodySmall),
            ],
          ),
          if (dues.isNotEmpty) ...[
            const SizedBox(height: 14),
            Text('Partisipasi iuran warga bulan ini', style: theme.textTheme.bodySmall?.copyWith(fontWeight: FontWeight.w700)),
            for (final d in dues) ...[
              const SizedBox(height: 8),
              Row(
                children: [
                  Expanded(child: Text('${d['dues_type']}', style: theme.textTheme.bodySmall)),
                  Text(
                    '${d['paid_households']} dari ${d['households']} keluarga '
                    '(${((d['paid_households'] as num) * 100 / (d['households'] as num)).round()}%)',
                    style: theme.textTheme.bodySmall?.copyWith(fontWeight: FontWeight.w700),
                  ),
                ],
              ),
              const SizedBox(height: 4),
              ProgressBar(value: (d['paid_households'] as num) / (d['households'] as num), background: Colors.white.withValues(alpha: 0.7)),
            ],
          ],
          const SizedBox(height: 16),
          Material(
            color: Colors.white,
            borderRadius: BorderRadius.circular(14),
            child: InkWell(
              borderRadius: BorderRadius.circular(14),
              onTap: _openReport,
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
                child: Row(
                  children: [
                    Icon(Icons.menu_book_outlined, color: scheme.secondary),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        'Lihat Pembukuan & Laporan Lengkap',
                        style: theme.textTheme.titleSmall?.copyWith(color: scheme.secondary),
                      ),
                    ),
                    Icon(Icons.chevron_right_rounded, color: scheme.secondary),
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// Pemanfaatan dana: pengeluaran kas bulan ini per kategori (pengganti foto kegiatan).
  Widget _usage(Map<String, dynamic> report) {
    final theme = Theme.of(context);
    final expense = (report['expense'] as List<dynamic>).cast<Map<String, dynamic>>();
    final total = expense.fold<num>(0, (s, e) => s + (e['amount'] as num));
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SectionHeader('Pemanfaatan Dana Iuran'),
        Padding(
          padding: const EdgeInsets.only(left: 4, bottom: 10),
          child: Text('Pengeluaran kas ${formatMonthCode(report['month'] as String?)}', style: theme.textTheme.bodySmall),
        ),
        AkurCard(
          child: Column(
            spacing: 14,
            children: [
              for (final e in expense)
                Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Row(
                      children: [
                        IconDisc(expenseIcon(e['category'] as String), size: 40),
                        const SizedBox(width: 12),
                        Expanded(child: Text(e['category'] as String, style: theme.textTheme.titleSmall)),
                        Text(formatRupiah(e['amount'] as num), style: theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w700)),
                      ],
                    ),
                    const SizedBox(height: 6),
                    ProgressBar(value: total == 0 ? 0 : (e['amount'] as num) / total, color: theme.colorScheme.primary),
                  ],
                ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _paymentRow(Map<String, dynamic> p) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final status = p['status'] as String;
    final allocations = (p['allocations'] as List<dynamic>).cast<Map<String, dynamic>>();
    return AkurCard(
      padding: const EdgeInsets.all(16),
      onTap: p['proof_file_id'] == null ? null : () => openAttachment(context, widget.api, p['proof_file_id'] as String),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          IconDisc(
            status == 'verified'
                ? Icons.check_circle_outline_rounded
                : (status == 'submitted' ? Icons.hourglass_top_rounded : Icons.cancel_outlined),
            background: status == 'verified'
                ? scheme.secondaryContainer
                : (status == 'submitted' ? scheme.tertiaryContainer : scheme.errorContainer),
            color: status == 'verified' ? scheme.secondary : (status == 'submitted' ? scheme.tertiary : scheme.error),
            size: 44,
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  allocations.isEmpty ? 'Pembayaran iuran' : allocations.map((a) => a['label']).join(', '),
                  style: theme.textTheme.titleSmall,
                ),
                Text(
                  '${formatShortDate(p['paid_at'] as String?)} • ${paymentMethodLabels[p['payment_method']] ?? p['payment_method']}',
                  style: theme.textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant),
                ),
                if (p['rejection_reason'] != null)
                  Text('Alasan ditolak: ${p['rejection_reason']}', style: theme.textTheme.bodySmall?.copyWith(color: scheme.error)),
              ],
            ),
          ),
          const SizedBox(width: 8),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            spacing: 4,
            children: [
              Text(formatRupiah(p['amount'] as num), style: theme.textTheme.titleSmall),
              Pill(
                (paymentStatusLabels[status] ?? status).toUpperCase(),
                tone: switch (status) {
                  'verified' => PillTone.secondary,
                  'submitted' => PillTone.tertiary,
                  _ => PillTone.danger,
                },
              ),
            ],
          ),
        ],
      ),
    );
  }
}

IconData expenseIcon(String category) {
  final c = category.toLowerCase();
  if (c.contains('aman')) return Icons.shield_outlined;
  if (c.contains('bersih') || c.contains('sampah')) return Icons.delete_outline_rounded;
  if (c.contains('sosial') || c.contains('takziah')) return Icons.volunteer_activism_outlined;
  if (c.contains('kegiatan')) return Icons.celebration_outlined;
  if (c.contains('operasional')) return Icons.build_outlined;
  return Icons.payments_outlined;
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
  String? _proofFileId;
  bool _saving = false;

  Future<void> _save() async {
    if (!_form.currentState!.validate()) return;
    setState(() => _saving = true);
    final ok = await runApi(
      context,
      () => widget.api.post(
        '/payments',
        {
          'amount': int.parse(_amount.text.trim()),
          'payment_method': _method,
          if (_reference.text.trim().isNotEmpty) 'reference_code': _reference.text.trim(),
          'proof_file_id': ?_proofFileId,
        },
        {'Idempotency-Key': _idempotencyKey},
      ),
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
            TextFormField(
              controller: _reference,
              decoration: const InputDecoration(labelText: 'No. referensi / catatan transfer'),
            ),
            const SizedBox(height: 12),
            AttachmentPicker(api: widget.api, purpose: 'payment_proof', label: 'Bukti bayar:', onChanged: (id) => _proofFileId = id),
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
