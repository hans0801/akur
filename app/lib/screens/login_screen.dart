import 'package:flutter/material.dart';

import '../auth/auth_controller.dart';
import '../widgets/akur_logo.dart';
import '../auth/google_button_stub.dart' if (dart.library.js_interop) '../auth/google_button_web.dart';

class LoginScreen extends StatelessWidget {
  const LoginScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Center(child: AkurLogo(size: 112)),
                  const SizedBox(height: 24),
                  Text(
                    'AKUR',
                    textAlign: TextAlign.center,
                    style: theme.textTheme.headlineMedium?.copyWith(fontWeight: FontWeight.w800),
                  ),
                  Text('Aplikasi Kerukunan Warga', textAlign: TextAlign.center, style: theme.textTheme.titleMedium),
                  const SizedBox(height: 12),
                  Text(
                    'Administrasi RT/RW dalam satu tempat: data warga, surat pengantar, iuran, kas, dan pengumuman.',
                    textAlign: TextAlign.center,
                    style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                  ),
                  const SizedBox(height: 32),
                  if (auth.busy)
                    const Center(child: CircularProgressIndicator())
                  else
                    Center(child: buildGoogleSignInButton(onPressed: auth.signInWithGoogle)),
                  if (auth.error != null) ...[
                    const SizedBox(height: 16),
                    Text(auth.error!, style: TextStyle(color: theme.colorScheme.error), textAlign: TextAlign.center),
                  ],
                  const SizedBox(height: 32),
                  Text(
                    'Login Google hanya membuktikan identitas akun Anda. Pengurus RT akan memverifikasi '
                    'akun sebelum Anda dapat mengakses data warga.',
                    style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                    textAlign: TextAlign.center,
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
