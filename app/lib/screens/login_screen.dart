import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../auth/auth_controller.dart';
import '../widgets/akur_logo.dart';
import '../widgets/akur_ui.dart';
import '../auth/google_button_stub.dart' if (dart.library.js_interop) '../auth/google_button_web.dart';

/// Halaman masuk ringkas: logo di tengah, satu tombol Google, dan bantuan di bagian bawah.
/// RT/RW pengguna baru diketahui setelah login, jadi pil lokasi memakai teks umum.
class LoginScreen extends StatelessWidget {
  const LoginScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final light = scheme.brightness == Brightness.light;
    return Scaffold(
      body: DecoratedBox(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: light ? const [Color(0xFFF8FBFE), Color(0xFFEFF5F9), Color(0xFFF1EEEA)] : [scheme.surface, scheme.surface],
            stops: const [0, 0.6, 1],
          ),
        ),
        child: SafeArea(
          child: LayoutBuilder(
            builder: (context, constraints) => SingleChildScrollView(
              // Tinggi minimum = layar agar Spacer menyebar konten seperti desain; lebar dibatasi 420 px di layar lebar.
              child: ConstrainedBox(
                constraints: BoxConstraints(minHeight: constraints.maxHeight),
                child: IntrinsicHeight(
                  child: Padding(
                    padding: EdgeInsets.fromLTRB(
                      math.max(24, (constraints.maxWidth - 420) / 2),
                      32,
                      math.max(24, (constraints.maxWidth - 420) / 2),
                      20,
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        const Spacer(flex: 2),
                        _branding(theme),
                        const Spacer(flex: 3),
                        const SizedBox(height: 40),
                        if (auth.busy)
                          const Center(child: CircularProgressIndicator())
                        else
                          Center(child: buildGoogleSignInButton(onPressed: auth.signInWithGoogle)),
                        if (auth.error != null) ...[
                          const SizedBox(height: 12),
                          Text(
                            auth.error!,
                            style: TextStyle(color: scheme.error),
                            textAlign: TextAlign.center,
                          ),
                        ],
                        const SizedBox(height: 18),
                        Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(Icons.check_circle_outline_rounded, size: 20, color: scheme.secondary),
                            const SizedBox(width: 8),
                            Text(
                              'Cepat, aman, tanpa kata sandi',
                              style: theme.textTheme.bodyMedium?.copyWith(color: scheme.onSurfaceVariant),
                            ),
                          ],
                        ),
                        const Spacer(flex: 3),
                        const SizedBox(height: 40),
                        Center(
                          child: TextButton.icon(
                            onPressed: () => _showHelp(context),
                            icon: const Icon(Icons.chat_outlined),
                            label: const Text('Butuh bantuan? Hubungi Pengurus RT'),
                          ),
                        ),
                        const SizedBox(height: 8),
                        Text(
                          'Data warga terenkripsi & terkelola resmi RT/RW',
                          textAlign: TextAlign.center,
                          style: theme.textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant.withValues(alpha: 0.8)),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _branding(ThemeData theme) {
    final scheme = theme.colorScheme;
    return Column(
      children: [
        Container(
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(40),
            boxShadow: const [
              BoxShadow(color: Color(0x14253036), blurRadius: 32, offset: Offset(0, 12), spreadRadius: -4),
              BoxShadow(color: Color(0x0A253036), blurRadius: 6, offset: Offset(0, 2)),
            ],
          ),
          child: const AkurLogo(size: 92),
        ),
        const SizedBox(height: 28),
        Text(
          'AKUR',
          textAlign: TextAlign.center,
          style: theme.textTheme.headlineMedium?.copyWith(fontSize: 40, height: 1.1, fontWeight: FontWeight.w800, letterSpacing: -0.8),
        ),
        const SizedBox(height: 6),
        Text(
          'Aplikasi Kerukunan Warga',
          textAlign: TextAlign.center,
          style: theme.textTheme.titleMedium?.copyWith(color: scheme.primary, fontWeight: FontWeight.w600),
        ),
        const SizedBox(height: 16),
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 9),
          decoration: BoxDecoration(color: scheme.surfaceContainerHigh, borderRadius: BorderRadius.circular(999)),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.location_on_outlined, size: 20, color: scheme.primary),
              const SizedBox(width: 8),
              Flexible(
                child: Text('Layanan warga RT & RW', style: theme.textTheme.bodyMedium?.copyWith(color: scheme.onSurfaceVariant)),
              ),
            ],
          ),
        ),
      ],
    );
  }

  /// Penjelasan cara masuk dan verifikasi (kontak pengurus belum tersedia sebelum login).
  Future<void> _showHelp(BuildContext context) {
    return showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      builder: (context) {
        final theme = Theme.of(context);
        Widget step(IconData icon, String title, String body) => Padding(
          padding: const EdgeInsets.only(bottom: 14),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              IconDisc(icon, size: 40),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(title, style: theme.textTheme.titleSmall),
                    Text(body, style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
                  ],
                ),
              ),
            ],
          ),
        );
        return SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 16),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text('Cara masuk ke AKUR', style: theme.textTheme.titleLarge),
                const SizedBox(height: 16),
                step(
                  Icons.login_rounded,
                  'Masuk dengan Google',
                  'Gunakan akun Google yang aktif di ponsel Anda. Tidak perlu kata sandi baru.',
                ),
                step(
                  Icons.how_to_reg_outlined,
                  'Tunggu verifikasi',
                  'Login Google hanya membuktikan identitas akun. Ketua RT atau Sekretaris akan menautkan akun Anda ke data warga.',
                ),
                step(
                  Icons.support_agent_rounded,
                  'Hubungi pengurus RT',
                  'Bila belum terverifikasi atau lupa akun yang dipakai, sampaikan email Google Anda ke Ketua RT atau Sekretaris.',
                ),
                FilledButton(onPressed: () => Navigator.pop(context), child: const Text('Mengerti')),
              ],
            ),
          ),
        );
      },
    );
  }
}
