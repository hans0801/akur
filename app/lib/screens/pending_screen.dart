import 'package:flutter/material.dart';

import '../auth/auth_controller.dart';
import '../theme.dart';
import '../widgets/akur_logo.dart';
import '../widgets/akur_ui.dart';

/// FR-02: akun baru berstatus pending sampai pengurus menautkannya ke data warga.
class PendingScreen extends StatelessWidget {
  const PendingScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final user = auth.user!;
    return Scaffold(
      appBar: AppBar(
        toolbarHeight: 68,
        title: Row(
          children: [
            const AkurLogo(size: 40),
            const SizedBox(width: 12),
            Text(
              'AKUR',
              style: theme.textTheme.titleLarge?.copyWith(color: scheme.primary, fontWeight: FontWeight.w800),
            ),
          ],
        ),
        actions: [IconButton(onPressed: auth.signOut, icon: const Icon(Icons.logout), tooltip: 'Keluar')],
      ),
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(20),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 480),
            child: AkurCard(
              padding: const EdgeInsets.all(24),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Center(
                    child: IconDisc(Icons.hourglass_top_rounded, background: AkurColors.tertiaryFixed, color: scheme.tertiary, size: 64),
                  ),
                  const SizedBox(height: 16),
                  Text('Menunggu verifikasi', textAlign: TextAlign.center, style: theme.textTheme.headlineSmall),
                  const SizedBox(height: 8),
                  Text(
                    'Halo ${user.displayName}. Akun ${user.email ?? ''} sudah terdaftar, tetapi belum '
                    'terhubung ke data warga. Hubungi Ketua RT atau Sekretaris untuk verifikasi.',
                    textAlign: TextAlign.center,
                    style: theme.textTheme.bodyLarge,
                  ),
                  const SizedBox(height: 24),
                  FilledButton.icon(onPressed: auth.refreshMe, icon: const Icon(Icons.refresh), label: const Text('Periksa status')),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
