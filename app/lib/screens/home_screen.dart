import 'package:flutter/material.dart';

import '../auth/auth_controller.dart';
import 'admin/pending_users_screen.dart';
import 'community/activities_screen.dart';
import 'community/announcements_screen.dart';
import 'documents/document_queue_screen.dart';
import 'documents/document_requests_screen.dart';
import 'documents/letter_settings_screen.dart';
import 'finance/cashbook_screen.dart';
import 'finance/dues_admin_screen.dart';
import 'finance/finance_report_screen.dart';
import 'finance/my_dues_screen.dart';
import 'finance/operational_reports_screen.dart';
import 'finance/payment_queue_screen.dart';
import 'population/population_home_screen.dart';

class HomeScreen extends StatelessWidget {
  const HomeScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final user = auth.user!;
    final linking = user.linkingMemberships;

    return Scaffold(
      appBar: AppBar(
        title: const Text('AKUR'),
        actions: [IconButton(onPressed: auth.signOut, icon: const Icon(Icons.logout), tooltip: 'Keluar')],
      ),
      body: RefreshIndicator(
        onRefresh: auth.refreshMe,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text('Halo, ${user.residentName ?? user.displayName}', style: theme.textTheme.headlineSmall),
            const SizedBox(height: 4),
            Text(user.email ?? '', style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
            const SizedBox(height: 20),
            Text('Peran Anda', style: theme.textTheme.titleMedium),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final m in user.memberships) Chip(label: Text('${m.roleName} · ${m.unit.name}')),
              ],
            ),
            if (linking.isNotEmpty || user.populationMemberships.isNotEmpty || user.documentQueueUnits.isNotEmpty || user.financeUnits.isNotEmpty) ...[
              const SizedBox(height: 24),
              Text('Pengurus', style: theme.textTheme.titleMedium),
              const SizedBox(height: 8),
            ],
            if (user.populationMemberships.isNotEmpty)
              Card(
                child: ListTile(
                  leading: const Icon(Icons.people_alt_outlined),
                  title: const Text('Data warga'),
                  subtitle: const Text('Keluarga, warga, mutasi, jalan & rumah'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).push(
                    MaterialPageRoute(builder: (_) => PopulationHomeScreen(api: auth.api, user: user)),
                  ),
                ),
              ),
            if (OperationalReportsScreen.unitsFor(user).isNotEmpty)
              Card(
                child: ListTile(
                  leading: const Icon(Icons.table_chart_outlined),
                  title: const Text('Laporan pengurus'),
                  subtitle: const Text('Tunggakan, pembayaran, surat, kegiatan'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).push(
                    MaterialPageRoute(builder: (_) => OperationalReportsScreen(api: auth.api, user: user)),
                  ),
                ),
              ),
            if (user.financeUnits.isNotEmpty) ...[
              Card(
                child: ListTile(
                  leading: const Icon(Icons.receipt_long_outlined),
                  title: const Text('Kelola iuran'),
                  subtitle: const Text('Jenis iuran, periode, dan tagihan keluarga'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).push(
                    MaterialPageRoute(builder: (_) => DuesAdminScreen(api: auth.api, user: user)),
                  ),
                ),
              ),
              Card(
                child: ListTile(
                  leading: const Icon(Icons.account_balance_wallet_outlined),
                  title: const Text('Buku kas'),
                  subtitle: const Text('Saldo, pemasukan, dan pengeluaran'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).push(
                    MaterialPageRoute(builder: (_) => CashbookScreen(api: auth.api, user: user)),
                  ),
                ),
              ),
              Card(
                child: ListTile(
                  leading: const Icon(Icons.fact_check_outlined),
                  title: const Text('Verifikasi pembayaran'),
                  subtitle: const Text('Pembayaran iuran yang dilaporkan warga'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).push(
                    MaterialPageRoute(builder: (_) => PaymentQueueScreen(api: auth.api, user: user)),
                  ),
                ),
              ),
            ],
            if (user.documentQueueUnits.isNotEmpty)
              Card(
                child: ListTile(
                  leading: const Icon(Icons.draw_outlined),
                  title: const Text('Pengaturan surat'),
                  subtitle: const Text('Nama ketua, kop, tanda tangan + cap'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).push(
                    MaterialPageRoute(builder: (_) => LetterSettingsScreen(api: auth.api, units: user.documentQueueUnits)),
                  ),
                ),
              ),
            if (user.documentQueueUnits.isNotEmpty)
              Card(
                child: ListTile(
                  leading: const Icon(Icons.inbox_outlined),
                  title: const Text('Antrean surat'),
                  subtitle: const Text('Tinjau dan terbitkan surat pengantar'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).push(
                    MaterialPageRoute(builder: (_) => DocumentQueueScreen(api: auth.api, units: user.documentQueueUnits)),
                  ),
                ),
              ),
            if (linking.isNotEmpty) ...[
              Card(
                child: ListTile(
                  leading: const Icon(Icons.how_to_reg_outlined),
                  title: const Text('Verifikasi akun warga'),
                  subtitle: const Text('Tautkan akun pending ke data warga'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).push(
                    MaterialPageRoute(
                      builder: (_) => PendingUsersScreen(api: auth.api, units: linking.map((m) => m.unit).toList()),
                    ),
                  ),
                ),
              ),
            ],
            const SizedBox(height: 24),
            Text('Warga', style: theme.textTheme.titleMedium),
            const SizedBox(height: 8),
            Card(
              child: ListTile(
                leading: const Icon(Icons.campaign_outlined),
                title: const Text('Pengumuman'),
                subtitle: const Text('Info terbaru dari RT/RW'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => AnnouncementsScreen(api: auth.api, user: user)),
                ),
              ),
            ),
            Card(
              child: ListTile(
                leading: const Icon(Icons.event_outlined),
                title: const Text('Kegiatan'),
                subtitle: const Text('Agenda warga dan konfirmasi kehadiran'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => ActivitiesScreen(api: auth.api, user: user)),
                ),
              ),
            ),
            Card(
              child: ListTile(
                leading: const Icon(Icons.description_outlined),
                title: const Text('Surat pengantar'),
                subtitle: const Text('Ajukan dan cetak surat dari RT'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => DocumentRequestsScreen(api: auth.api)),
                ),
              ),
            ),
            if (user.residentName != null)
              Card(
                child: ListTile(
                  leading: const Icon(Icons.payments_outlined),
                  title: const Text('Iuran keluarga'),
                  subtitle: const Text('Tagihan dan lapor pembayaran'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).push(
                    MaterialPageRoute(builder: (_) => MyDuesScreen(api: auth.api)),
                  ),
                ),
              ),
            if (user.memberships.isNotEmpty)
              Card(
                child: ListTile(
                  leading: const Icon(Icons.bar_chart_outlined),
                  title: const Text('Laporan kas'),
                  subtitle: const Text('Transparansi keuangan RT/RW'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).push(
                    MaterialPageRoute(builder: (_) => FinanceReportScreen(
                      api: auth.api,
                      units: reportUnits(user),
                      exportUnitIds: {for (final u in user.financeUnits) u.id},
                    )),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
