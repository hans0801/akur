import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'api/api_client.dart';
import 'auth/auth_controller.dart';
import 'screens/home_screen.dart';
import 'screens/login_screen.dart';
import 'screens/pending_screen.dart';
import 'theme.dart';
import 'widgets/async_view.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  final auth = AuthController(ApiClient())..init();
  runApp(AkurApp(auth: auth));
}

class AkurApp extends StatelessWidget {
  const AkurApp({super.key, required this.auth});

  final AuthController auth;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'AKUR',
      debugShowCheckedModeBanner: false,
      theme: AkurTheme.light(),
      darkTheme: AkurTheme.dark(),
      // Seluruh UI berbahasa Indonesia, termasuk pemilih tanggal/jam bawaan Material.
      locale: const Locale('id', 'ID'),
      supportedLocales: const [Locale('id', 'ID'), Locale('en')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      // Web/tablet: konten dibatasi lebarnya agar formulir dan daftar tetap nyaman dibaca.
      builder: (context, child) => ResponsiveFrame(child: child ?? const SizedBox.shrink()),
      home: ListenableBuilder(
        listenable: auth,
        builder: (context, _) => switch (auth.status) {
          AuthStatus.loading => const Scaffold(body: Center(child: CircularProgressIndicator(semanticsLabel: 'Memuat'))),
          AuthStatus.signedOut => LoginScreen(auth: auth),
          AuthStatus.signedIn when auth.user!.isActive => HomeScreen(auth: auth),
          AuthStatus.signedIn => PendingScreen(auth: auth),
        },
      ),
    );
  }
}
