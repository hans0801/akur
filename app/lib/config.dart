import 'package:flutter/foundation.dart';

/// Konfigurasi build-time lewat --dart-define. Tidak ada rahasia di sini:
/// Client ID OAuth bersifat publik; secret hanya ada di server.
class AppConfig {
  /// Basis URL API. Kosong di web = origin yang sama (Caddy meneruskan /api ke Laravel).
  static const String _apiBaseUrl = String.fromEnvironment('API_BASE_URL');

  /// Web client ID Google. Di Android dipakai sebagai serverClientId sehingga
  /// `aud` ID token sama dengan yang diverifikasi backend.
  static const String googleWebClientId = String.fromEnvironment('GOOGLE_WEB_CLIENT_ID');

  static String get apiBaseUrl {
    if (_apiBaseUrl.isNotEmpty) return _apiBaseUrl;
    if (kIsWeb) return '';
    // Emulator Android mengakses host lewat 10.0.2.2; Simulator iOS memakai localhost milik Mac.
    // Perangkat fisik selalu butuh --dart-define=API_BASE_URL.
    return isIos ? 'http://localhost:8000' : 'http://10.0.2.2:8000';
  }

  static bool get isIos => !kIsWeb && defaultTargetPlatform == TargetPlatform.iOS;

  /// Nilai `platform` untuk POST /auth/google: web → cookie; android/ios → token Bearer.
  static String get platform => kIsWeb ? 'web' : (isIos ? 'ios' : 'android');

  static String get deviceLabel => kIsWeb ? 'Browser' : (isIos ? 'iOS' : 'Android');
}
