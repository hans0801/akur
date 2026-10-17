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
    // Emulator Android mengakses host lewat 10.0.2.2.
    return kIsWeb ? '' : 'http://10.0.2.2:8000';
  }

  static String get platform => kIsWeb ? 'web' : 'android';
}
