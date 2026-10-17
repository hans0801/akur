import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:google_sign_in/google_sign_in.dart';

import '../api/api_client.dart';
import '../config.dart';
import '../models/current_user.dart';

enum AuthStatus { loading, signedOut, signedIn }

/// Alur login (PRD §6): Google memberi ID token → backend memverifikasi dan menerbitkan sesi AKUR.
/// Token Google tidak disimpan dan tidak dipakai untuk memanggil API.
class AuthController extends ChangeNotifier {
  AuthController(this.api);

  final ApiClient api;
  StreamSubscription<GoogleSignInAuthenticationEvent>? _googleEvents;

  AuthStatus status = AuthStatus.loading;
  CurrentUser? user;
  String? error;
  bool busy = false;

  Future<void> init() async {
    if (AppConfig.googleWebClientId.isEmpty) {
      error = 'GOOGLE_WEB_CLIENT_ID belum diatur (--dart-define).';
    } else {
      await GoogleSignIn.instance.initialize(
        clientId: kIsWeb ? AppConfig.googleWebClientId : null,
        serverClientId: kIsWeb ? null : AppConfig.googleWebClientId,
      );
      _googleEvents = GoogleSignIn.instance.authenticationEvents.listen(
        _onGoogleEvent,
        onError: (Object e) => _fail('Login Google dibatalkan atau gagal.'),
      );
    }
    await refreshMe();
  }

  /// Android: memunculkan pemilih akun. Web memakai tombol GIS (lihat google_button_web.dart).
  Future<void> signInWithGoogle() async {
    error = null;
    try {
      await GoogleSignIn.instance.authenticate();
    } on GoogleSignInException catch (e) {
      if (e.code != GoogleSignInExceptionCode.canceled) _fail('Login Google gagal.');
    }
  }

  Future<void> _onGoogleEvent(GoogleSignInAuthenticationEvent event) async {
    if (event is! GoogleSignInAuthenticationEventSignIn) return;
    final idToken = event.user.authentication.idToken;
    if (idToken == null) return _fail('Google tidak mengembalikan ID token.');

    busy = true;
    notifyListeners();
    try {
      final json = await api.post('/auth/google', {
        'id_token': idToken,
        'platform': AppConfig.platform,
        'device_label': kIsWeb ? 'Browser' : 'Android',
      });
      final token = json['token'] as String?;
      if (token != null) await api.saveToken(token);
      user = CurrentUser.fromJson(json['user'] as Map<String, dynamic>);
      status = AuthStatus.signedIn;
      error = null;
    } on ApiException catch (e) {
      _fail(e.message);
    } catch (_) {
      _fail('Tidak dapat terhubung ke server.');
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  Future<void> refreshMe() async {
    try {
      final json = await api.get('/me');
      user = CurrentUser.fromJson(json['data'] as Map<String, dynamic>);
      status = AuthStatus.signedIn;
    } on ApiException catch (e) {
      if (e.statusCode == 401) await api.clearToken();
      user = null;
      status = AuthStatus.signedOut;
    } catch (_) {
      user = null;
      status = AuthStatus.signedOut;
    }
    notifyListeners();
  }

  Future<void> signOut() async {
    try {
      await api.post('/auth/logout');
    } catch (_) {
      // Tetap keluar secara lokal walau server tidak terjangkau.
    }
    await api.clearToken();
    if (AppConfig.googleWebClientId.isNotEmpty) await GoogleSignIn.instance.signOut();
    user = null;
    status = AuthStatus.signedOut;
    notifyListeners();
  }

  void _fail(String message) {
    error = message;
    status = user == null ? AuthStatus.signedOut : status;
    notifyListeners();
  }

  @override
  void dispose() {
    _googleEvents?.cancel();
    super.dispose();
  }
}
