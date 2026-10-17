import 'package:flutter/material.dart';

import 'api/api_client.dart';
import 'auth/auth_controller.dart';
import 'screens/home_screen.dart';
import 'screens/login_screen.dart';
import 'screens/pending_screen.dart';
import 'theme.dart';

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
      home: ListenableBuilder(
        listenable: auth,
        builder: (context, _) => switch (auth.status) {
          AuthStatus.loading => const Scaffold(body: Center(child: CircularProgressIndicator())),
          AuthStatus.signedOut => LoginScreen(auth: auth),
          AuthStatus.signedIn when auth.user!.isActive => HomeScreen(auth: auth),
          AuthStatus.signedIn => PendingScreen(auth: auth),
        },
      ),
    );
  }
}
