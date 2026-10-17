import 'package:flutter/material.dart';

import '../auth/auth_controller.dart';

/// FR-02: akun baru berstatus pending sampai pengurus menautkannya ke data warga.
class PendingScreen extends StatelessWidget {
  const PendingScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final user = auth.user!;
    return Scaffold(
      appBar: AppBar(
        title: const Text('AKUR'),
        actions: [IconButton(onPressed: auth.signOut, icon: const Icon(Icons.logout), tooltip: 'Keluar')],
      ),
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 480),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.hourglass_top_rounded, size: 56, color: theme.colorScheme.primary),
                const SizedBox(height: 16),
                Text('Menunggu verifikasi', style: theme.textTheme.headlineSmall),
                const SizedBox(height: 8),
                Text(
                  'Halo ${user.displayName}. Akun ${user.email ?? ''} sudah terdaftar, tetapi belum '
                  'terhubung ke data warga. Hubungi Ketua RT atau Sekretaris untuk verifikasi.',
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 24),
                OutlinedButton.icon(
                  onPressed: auth.refreshMe,
                  icon: const Icon(Icons.refresh),
                  label: const Text('Periksa status'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
