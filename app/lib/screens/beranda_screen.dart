import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../auth/auth_controller.dart';
import '../models/current_user.dart';
import '../theme.dart';
import '../widgets/akur_ui.dart';
import 'admin/pending_users_screen.dart';
import 'community/announcements_screen.dart';
import 'documents/document_queue_screen.dart';
import 'documents/letter_settings_screen.dart';
import 'finance/cashbook_screen.dart';
import 'finance/dues_admin_screen.dart';
import 'finance/finance_common.dart';
import 'finance/finance_report_screen.dart';
import 'finance/operational_reports_screen.dart';
import 'finance/payment_queue_screen.dart';
import 'home_screen.dart';
import 'population/population_common.dart';
import 'population/population_home_screen.dart';

/// Beranda warga: sapaan, pengumuman terbaru, layanan warga, status iuran + saldo kas,
/// agenda terdekat, dan (untuk pengurus) menu pengurus. Tiap bagian dimuat terpisah agar
/// satu kegagalan tidak mengosongkan seluruh beranda.
class BerandaScreen extends StatefulWidget {
  const BerandaScreen({super.key, required this.auth, required this.onNavigate});

  final AuthController auth;

  /// Pindah tab bawah: 1 Iuran, 2 Surat, 3 Kegiatan.
  final ValueChanged<int> onNavigate;

  @override
  State<BerandaScreen> createState() => _BerandaScreenState();
}

class _BerandaScreenState extends State<BerandaScreen> {
  Map<String, dynamic>? _announcement;
  Map<String, dynamic>? _dues;
  Map<String, dynamic>? _activity;
  Map<String, dynamic>? _report;
  bool _loading = true;

  ApiClient get _api => widget.auth.api;
  CurrentUser get _user => widget.auth.user!;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<T?> _try<T>(Future<T> Function() f) async {
    try {
      return await f();
    } on ApiException {
      return null;
    }
  }

  Future<void> _load() async {
    final unit = primaryUnitOf(_user);
    final results = await Future.wait([
      _try(() async => dataList(await _api.get('/announcements')).firstOrNull),
      _user.residentName == null ? Future.value(null) : _try(() async => (await _api.get('/my/dues'))['data'] as Map<String, dynamic>),
      _try(() async => dataList(await _api.get('/activities?when=upcoming')).where((a) => a['status'] == 'planned').firstOrNull),
      unit == null
          ? Future.value(null)
          : _try(() async => (await _api.get('/units/${unit.id}/finance-reports/monthly'))['data'] as Map<String, dynamic>),
    ]);
    if (!mounted) return;
    setState(() {
      _announcement = results[0];
      _dues = results[1];
      _activity = results[2];
      _report = results[3];
      _loading = false;
    });
  }

  Future<void> _refresh() async {
    await widget.auth.refreshMe();
    await _load();
  }

  void _push(Widget screen) => Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen)).then((_) => _load());

  Future<void> _attend() async {
    final ok = await runApi(
      context,
      () => _api.put('/activities/${_activity!['id']}/participation', {'status': 'going'}),
      'Terima kasih! Kehadiran Anda tercatat.',
    );
    if (ok) _load();
  }

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: _refresh,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 32),
        children: [
          _greeting(),
          if (_announcement != null) ...[const SizedBox(height: 20), _announcementBanner(_announcement!)],
          const SizedBox(height: 24),
          SectionHeader(
            'Layanan Warga',
            action: 'Pengumuman',
            onAction: () => _push(AnnouncementsScreen(api: _api, user: _user)),
          ),
          const SizedBox(height: 8),
          TwoColumnGrid(
            children: [
              ServiceTile(
                icon: Icons.account_balance_wallet_outlined,
                title: 'Iuran Warga',
                subtitle: 'Tagihan & riwayat bayar',
                onTap: () => widget.onNavigate(1),
              ),
              ServiceTile(
                icon: Icons.description_outlined,
                title: 'Surat Pengantar',
                subtitle: 'Urus KTP, domisili, dll.',
                tone: PillTone.secondary,
                onTap: () => widget.onNavigate(2),
              ),
              ServiceTile(
                icon: Icons.campaign_outlined,
                title: 'Pengumuman',
                subtitle: 'Info terbaru RT/RW',
                tone: PillTone.tertiary,
                onTap: () => _push(AnnouncementsScreen(api: _api, user: _user)),
              ),
              if (_user.memberships.isNotEmpty)
                ServiceTile(icon: Icons.bar_chart_rounded, title: 'Laporan Kas', subtitle: 'Transparansi keuangan', onTap: _openReport),
            ],
          ),
          const SizedBox(height: 24),
          if (_loading)
            const Padding(
              padding: EdgeInsets.all(24),
              child: Center(child: CircularProgressIndicator()),
            )
          else ...[
            if (_dues != null || _report != null) _duesCard(),
            if (_activity != null) ...[const SizedBox(height: 24), _agendaCard(_activity!)],
          ],
          if (_adminTiles().isNotEmpty) ...[
            const SizedBox(height: 24),
            const SectionHeader('Menu Pengurus'),
            const SizedBox(height: 8),
            TwoColumnGrid(children: _adminTiles()),
          ],
          const SizedBox(height: 24),
          const HelpCard(
            icon: Icons.diversity_3_rounded,
            title: 'Pesan Guyub Rukun',
            message: '"Tetangga dekat adalah saudara pertama saat kita butuh bantuan."',
          ),
        ],
      ),
    );
  }

  void _openReport() =>
      _push(FinanceReportScreen(api: _api, units: reportUnits(_user), exportUnitIds: {for (final u in _user.financeUnits) u.id}));

  Widget _greeting() {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final unit = primaryUnitOf(_user);
    final address = (_dues?['household'] as Map<String, dynamic>?)?['address_text'] as String?;
    final role = _user.memberships.where((m) => !m.inherited).firstOrNull?.roleName;
    return AkurCard(
      padding: const EdgeInsets.all(16),
      child: Row(
        children: [
          UserAvatar(user: _user, size: 60),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Icon(Icons.wb_sunny_outlined, size: 16, color: scheme.secondary),
                    const SizedBox(width: 4),
                    Flexible(
                      child: Text(
                        '${greetingNow()} Warga',
                        style: theme.textTheme.bodySmall?.copyWith(color: scheme.secondary, fontWeight: FontWeight.w700),
                      ),
                    ),
                  ],
                ),
                Text(
                  _user.residentName ?? _user.displayName,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700),
                ),
                Text(
                  [address, unit?.name].whereType<String>().join(' • '),
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant),
                ),
                if (role != null) ...[const SizedBox(height: 6), Pill(role, tone: PillTone.secondary, icon: Icons.verified_user_outlined)],
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _announcementBanner(Map<String, dynamic> a) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return Material(
      color: AkurColors.notice,
      borderRadius: BorderRadius.circular(AkurTheme.radiusCard),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => _push(AnnouncementsScreen(api: _api, user: _user)),
        child: Container(
          decoration: const BoxDecoration(
            border: Border(left: BorderSide(color: AkurColors.primaryContainer, width: 5)),
          ),
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 18),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  const Pill('PENGUMUMAN', tone: PillTone.solid),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      formatShortDate((a['publish_at'] ?? a['created_at']) as String?),
                      style: theme.textTheme.bodySmall?.copyWith(fontWeight: FontWeight.w600),
                    ),
                  ),
                  Icon(Icons.chevron_right_rounded, color: scheme.primary),
                ],
              ),
              const SizedBox(height: 12),
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  IconDisc(Icons.campaign_rounded, size: 40, background: AkurColors.primaryFixed),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(a['title'] as String, style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
                        const SizedBox(height: 4),
                        Text(
                          a['body'] as String,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.bodyMedium?.copyWith(color: scheme.onSurfaceVariant),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _duesCard() {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final assessments = ((_dues?['assessments'] as List<dynamic>?) ?? const []).cast<Map<String, dynamic>>();
    final outstanding = (_dues?['outstanding_total'] as num?)?.toInt() ?? 0;
    // Tagihan terdekat yang belum lunas, atau tagihan terakhir bila semua sudah lunas.
    final current = assessments.where((a) => a['status'] == 'unpaid' || a['status'] == 'partial').firstOrNull ?? assessments.firstOrNull;
    final paid = outstanding == 0;
    return AkurCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(
                paid ? Icons.check_circle_outline_rounded : Icons.error_outline_rounded,
                color: paid ? scheme.secondary : scheme.primary,
              ),
              const SizedBox(width: 8),
              Expanded(child: Text('Status Iuran Lingkungan', style: theme.textTheme.titleMedium)),
              if (_dues != null) TextButton(onPressed: () => widget.onNavigate(1), child: const Text('Lihat Riwayat')),
            ],
          ),
          if (_dues != null) ...[
            const SizedBox(height: 12),
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(color: scheme.surfaceContainer, borderRadius: BorderRadius.circular(14)),
              child: current == null
                  ? Text('Belum ada tagihan iuran.', style: theme.textTheme.bodyLarge)
                  : Row(
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                paid
                                    ? '${current['dues_type_name']} · ${formatMonthCode(current['period_code'] as String?)}'
                                    : '${assessments.where((a) => a['status'] == 'unpaid' || a['status'] == 'partial').length} tagihan terbuka',
                                style: theme.textTheme.bodySmall?.copyWith(fontWeight: FontWeight.w600),
                              ),
                              const SizedBox(height: 2),
                              Text(
                                formatRupiah(paid ? current['amount_due'] as num : outstanding),
                                style: theme.textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800),
                              ),
                              Text(paid ? 'tagihan terakhir' : 'total belum dibayar', style: theme.textTheme.bodySmall),
                            ],
                          ),
                        ),
                        Pill(
                          paid ? 'LUNAS' : 'BELUM LUNAS',
                          tone: paid ? PillTone.secondary : PillTone.primary,
                          icon: paid ? Icons.verified_outlined : Icons.schedule_rounded,
                        ),
                      ],
                    ),
            ),
          ],
          if (_report != null) ...[
            const SizedBox(height: 14),
            InkWell(
              onTap: _openReport,
              borderRadius: BorderRadius.circular(10),
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 6),
                child: Row(
                  children: [
                    Icon(Icons.savings_outlined, color: scheme.secondary),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        'Saldo kas ${(_report!['unit'] as Map<String, dynamic>)['name']}: ${formatRupiah(_report!['closing_balance'] as num)}',
                        style: theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w600),
                      ),
                    ),
                    Text(
                      'Transparan •\nAkuntabel',
                      textAlign: TextAlign.end,
                      style: theme.textTheme.bodySmall?.copyWith(color: scheme.secondary, fontWeight: FontWeight.w700),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _agendaCard(Map<String, dynamic> a) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final going = a['my_status'] == 'going';
    return AkurCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Container(
                width: 10,
                height: 10,
                decoration: BoxDecoration(color: scheme.primary, shape: BoxShape.circle),
              ),
              const SizedBox(width: 8),
              Expanded(child: Text('Agenda Warga Terdekat', style: theme.textTheme.titleMedium)),
              TextButton(onPressed: () => widget.onNavigate(3), child: const Text('Semua')),
            ],
          ),
          const SizedBox(height: 8),
          ActivityBanner(activity: a, height: 150),
          const SizedBox(height: 12),
          Row(
            children: [
              Icon(Icons.calendar_month_outlined, size: 20, color: scheme.onSurfaceVariant),
              const SizedBox(width: 6),
              Expanded(child: Text(formatLongDate(a['starts_at'] as String?), style: theme.textTheme.bodyMedium)),
              Icon(Icons.groups_outlined, size: 20, color: scheme.secondary),
              const SizedBox(width: 4),
              Text('${a['going_count']} hadir', style: theme.textTheme.bodySmall?.copyWith(fontWeight: FontWeight.w700)),
            ],
          ),
          const SizedBox(height: 14),
          going
              ? OutlinedButton.icon(
                  onPressed: () => widget.onNavigate(3),
                  icon: const Icon(Icons.check_circle_outline),
                  label: const Text('Anda sudah konfirmasi hadir'),
                )
              : FilledButton.icon(onPressed: _attend, icon: const Icon(Icons.thumb_up_alt_outlined), label: const Text('Saya Hadir')),
        ],
      ),
    );
  }

  /// Menu pengurus sesuai peran (hanya untuk menampilkan menu; otorisasi tetap di server).
  List<Widget> _adminTiles() {
    final user = _user;
    final linking = user.linkingMemberships;
    return [
      if (user.populationMemberships.isNotEmpty)
        ServiceTile(
          icon: Icons.people_alt_outlined,
          title: 'Data Warga',
          subtitle: 'Keluarga, warga, mutasi',
          onTap: () => _push(PopulationHomeScreen(api: _api, user: user)),
        ),
      if (linking.isNotEmpty)
        ServiceTile(
          icon: Icons.how_to_reg_outlined,
          title: 'Verifikasi Akun',
          subtitle: 'Tautkan akun warga',
          tone: PillTone.secondary,
          onTap: () => _push(PendingUsersScreen(api: _api, units: linking.map((m) => m.unit).toList())),
        ),
      if (user.documentQueueUnits.isNotEmpty) ...[
        ServiceTile(
          icon: Icons.inbox_outlined,
          title: 'Antrean Surat',
          subtitle: 'Tinjau & terbitkan',
          tone: PillTone.secondary,
          onTap: () => _push(DocumentQueueScreen(api: _api, units: user.documentQueueUnits)),
        ),
        ServiceTile(
          icon: Icons.draw_outlined,
          title: 'Pengaturan Surat',
          subtitle: 'Kop, ketua, ttd + cap',
          tone: PillTone.tertiary,
          onTap: () => _push(LetterSettingsScreen(api: _api)),
        ),
      ],
      if (user.financeUnits.isNotEmpty) ...[
        ServiceTile(
          icon: Icons.receipt_long_outlined,
          title: 'Kelola Iuran',
          subtitle: 'Jenis, periode, tagihan',
          onTap: () => _push(DuesAdminScreen(api: _api, user: user)),
        ),
        ServiceTile(
          icon: Icons.fact_check_outlined,
          title: 'Verifikasi Bayar',
          subtitle: 'Pembayaran dari warga',
          tone: PillTone.secondary,
          onTap: () => _push(PaymentQueueScreen(api: _api, user: user)),
        ),
        ServiceTile(
          icon: Icons.account_balance_wallet_outlined,
          title: 'Buku Kas',
          subtitle: 'Pemasukan & pengeluaran',
          tone: PillTone.tertiary,
          onTap: () => _push(CashbookScreen(api: _api, user: user)),
        ),
      ],
      if (OperationalReportsScreen.unitsFor(user).isNotEmpty)
        ServiceTile(
          icon: Icons.table_chart_outlined,
          title: 'Laporan Pengurus',
          subtitle: 'Tunggakan, surat, kegiatan',
          onTap: () => _push(OperationalReportsScreen(api: _api, user: user)),
        ),
      if (user.communityManageUnits.isNotEmpty)
        ServiceTile(
          icon: Icons.event_available_outlined,
          title: 'Buat Kegiatan',
          subtitle: 'Agenda & kehadiran',
          tone: PillTone.secondary,
          onTap: () => widget.onNavigate(3),
        ),
    ];
  }
}

/// Grid dua kolom yang tinggi barisnya mengikuti isi terpanjang (teks besar tidak terpotong).
class TwoColumnGrid extends StatelessWidget {
  const TwoColumnGrid({super.key, required this.children, this.spacing = 14});

  final List<Widget> children;
  final double spacing;

  @override
  Widget build(BuildContext context) {
    final rows = <Widget>[];
    for (var i = 0; i < children.length; i += 2) {
      rows.add(
        IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(child: children[i]),
              SizedBox(width: spacing),
              Expanded(child: i + 1 < children.length ? children[i + 1] : const SizedBox.shrink()),
            ],
          ),
        ),
      );
    }
    return Column(spacing: spacing, children: rows);
  }
}

/// Ubin layanan: ikon dalam cakram pastel + judul + keterangan dua baris.
class ServiceTile extends StatelessWidget {
  const ServiceTile({
    super.key,
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.onTap,
    this.tone = PillTone.primary,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;
  final PillTone tone;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final (bg, fg) = switch (tone) {
      PillTone.secondary => (scheme.secondaryContainer, scheme.secondary),
      PillTone.tertiary => (scheme.tertiaryContainer, scheme.tertiary),
      _ => (scheme.primaryContainer, scheme.primary),
    };
    return Semantics(
      button: true,
      label: '$title. $subtitle',
      excludeSemantics: true,
      child: AkurCard(
        padding: const EdgeInsets.all(16),
        onTap: onTap,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            IconDisc(icon, background: bg, color: fg, size: 52),
            const SizedBox(height: 14),
            Text(title, style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
            const SizedBox(height: 2),
            Text(subtitle, style: theme.textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant)),
          ],
        ),
      ),
    );
  }
}

/// Spanduk kegiatan (pengganti foto): gradien terracotta–teal dengan ikon kategori, lokasi, judul, dan jam.
class ActivityBanner extends StatelessWidget {
  const ActivityBanner({super.key, required this.activity, this.height = 160, this.showTitle = true});

  final Map<String, dynamic> activity;
  final double height;
  final bool showTitle;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final category = activity['category'] as String?;
    return Container(
      height: height,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(14),
        gradient: const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [AkurColors.primaryContainer, AkurColors.primary, Color(0xFF1B6870)],
          stops: [0, 0.55, 1],
        ),
      ),
      padding: const EdgeInsets.all(14),
      child: Stack(
        children: [
          Positioned(
            right: -6,
            top: -6,
            child: Icon(activityCategoryIcon(category), size: 96, color: Colors.white.withValues(alpha: 0.18)),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  const Icon(Icons.place_outlined, color: Colors.white, size: 18),
                  const SizedBox(width: 4),
                  Expanded(
                    child: Text(
                      activity['location_text'] as String? ?? '-',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodySmall?.copyWith(color: Colors.white, fontWeight: FontWeight.w600),
                    ),
                  ),
                ],
              ),
              const Spacer(),
              Row(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Expanded(
                    child: !showTitle
                        ? const SizedBox.shrink()
                        : Text(
                            activity['title'] as String,
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.titleLarge?.copyWith(color: Colors.white, fontWeight: FontWeight.w800),
                          ),
                  ),
                  const SizedBox(width: 8),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                    decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(999)),
                    child: Text(
                      'Pkl ${formatClock(activity['starts_at'] as String?)}',
                      style: theme.textTheme.labelMedium?.copyWith(color: AkurColors.ink, fontWeight: FontWeight.w700),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ],
      ),
    );
  }
}

IconData activityCategoryIcon(String? category) => switch (category) {
  'kerja_bakti' => Icons.cleaning_services_outlined,
  'rapat' => Icons.forum_outlined,
  'keagamaan' => Icons.mosque_outlined,
  'olahraga' => Icons.sports_soccer_outlined,
  'sosial' => Icons.volunteer_activism_outlined,
  'perayaan' => Icons.celebration_outlined,
  _ => Icons.event_outlined,
};
