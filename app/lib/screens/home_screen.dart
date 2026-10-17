import 'package:flutter/material.dart';

import '../auth/auth_controller.dart';
import 'admin/pending_users_screen.dart';

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
            if (linking.isNotEmpty) ...[
              const SizedBox(height: 24),
              Text('Pengurus', style: theme.textTheme.titleMedium),
              const SizedBox(height: 8),
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
            Text('Segera hadir', style: theme.textTheme.titleMedium),
            const SizedBox(height: 8),
            for (final f in const [
              (Icons.campaign_outlined, 'Pengumuman & kegiatan'),
              (Icons.description_outlined, 'Surat pengantar'),
              (Icons.payments_outlined, 'Iuran & pembayaran'),
              (Icons.account_balance_wallet_outlined, 'Kas & laporan'),
            ])
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Card(child: ListTile(leading: Icon(f.$1), title: Text(f.$2), enabled: false)),
              ),
          ],
        ),
      ),
    );
  }
}
