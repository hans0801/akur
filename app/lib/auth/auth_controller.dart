import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:google_sign_in/google_sign_in.dart';

import '../api/api_client.dart';
import '../config.dart';
import '../models/current_user.dart';

/// `maintenance`: mode pemeliharaan aktif dan pengguna bukan super_admin.
/// `unavailable`: server tidak terjangkau / galat 5xx (aplikasi menampilkan halaman pemeliharaan).
enum AuthStatus { loading, signedOut, signedIn, maintenance, unavailable }

/// Alur login (PRD §6): Google memberi ID token → backend memverifikasi dan menerbitkan sesi AKUR.
/// Token Google tidak disimpan dan tidak dipakai untuk memanggil API.
class AuthController extends ChangeNotifier {
  AuthController(this.api) {
    api.onUnauthorized = _sessionExpired;
    api.onServerTrouble = _serverTrouble;
  }

  final ApiClient api;
  StreamSubscription<GoogleSignInAuthenticationEvent>? _googleEvents;

  AuthStatus status = AuthStatus.loading;
  CurrentUser? user;
  String? error;
  bool busy = false;

  /// Mode pemeliharaan sedang aktif di server (super_admin tetap bisa memakai aplikasi).
  bool maintenanceActive = false;

  /// Pesan dari server untuk halaman pemeliharaan (opsional).
  String? maintenanceMessage;

  bool _probing = false;

  Future<void> init() async {
    if (AppConfig.googleWebClientId.isEmpty) {
      error = 'GOOGLE_WEB_CLIENT_ID belum diatur (--dart-define).';
    } else {
      // iOS membaca client ID-nya sendiri dari Info.plist (GIDClientID); Android/iOS meminta ID token
      // ber-aud Web client ID lewat serverClientId agar backend cukup memverifikasi satu aud.
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

  /// Android/iOS: memunculkan pemilih akun. Web memakai tombol GIS (lihat google_button_web.dart).
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
        'device_label': AppConfig.deviceLabel,
      });
      final token = json['token'] as String?;
      if (token != null) await api.saveToken(token);
      user = CurrentUser.fromJson(json['user'] as Map<String, dynamic>);
      _applyMaintenance(json['maintenance'] == true, json['maintenance_message'] as String?);
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

  /// Pemeriksaan sesi (juga tombol "Coba lagi" di halaman pemeliharaan).
  Future<void> refreshMe() async {
    try {
      final json = await api.get('/me');
      user = CurrentUser.fromJson(json['data'] as Map<String, dynamic>);
      final meta = json['meta'] as Map<String, dynamic>?;
      _applyMaintenance(meta?['maintenance'] == true, meta?['maintenance_message'] as String?);
    } on ApiException catch (e) {
      if (e.code == 'maintenance') {
        maintenanceMessage = e.message;
        status = AuthStatus.maintenance;
      } else if (ApiClient.isServerTrouble(e) || e.statusCode >= 500) {
        // Server mati/tak terjangkau: jangan keluarkan pengguna, tampilkan halaman pemeliharaan.
        status = AuthStatus.unavailable;
      } else {
        if (e.statusCode == 401) await api.clearToken();
        user = null;
        status = AuthStatus.signedOut;
      }
    } catch (_) {
      status = AuthStatus.unavailable;
    }
    notifyListeners();
  }

  /// Dipanggil super_admin setelah mengubah mode pemeliharaan dari Pengaturan aplikasi.
  void setMaintenanceActive(bool active) {
    maintenanceActive = active;
    notifyListeners();
  }

  /// Avatar/profil diperbarui dari Pengaturan aplikasi.
  void updateUser(CurrentUser updated) {
    user = updated;
    notifyListeners();
  }

  void _applyMaintenance(bool active, String? message) {
    maintenanceActive = active;
    maintenanceMessage = active ? message : null;
    status = active && !user!.isSuperAdmin ? AuthStatus.maintenance : AuthStatus.signedIn;
  }

  /// Request di dalam aplikasi gagal karena server: mode pemeliharaan langsung ditampilkan;
  /// galat jaringan/5xx dipastikan dulu lewat /me agar satu request yang gagal tidak menutup aplikasi.
  Future<void> _serverTrouble(ApiException e) async {
    if (status != AuthStatus.signedIn || _probing) return;
    if (e.code == 'maintenance') {
      maintenanceActive = true;
      maintenanceMessage = e.message;
      status = AuthStatus.maintenance;
      notifyListeners();
      return;
    }
    _probing = true;
    try {
      await refreshMe();
    } finally {
      _probing = false;
    }
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
    maintenanceActive = false;
    status = AuthStatus.signedOut;
    notifyListeners();
  }

  Future<void> _sessionExpired() async {
    if (status != AuthStatus.signedIn) return;
    await api.clearToken();
    user = null;
    status = AuthStatus.signedOut;
    error = 'Sesi Anda berakhir. Silakan masuk lagi.';
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
