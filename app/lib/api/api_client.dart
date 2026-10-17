import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

import '../config.dart';
import 'http_client_stub.dart' if (dart.library.js_interop) 'http_client_web.dart';

class ApiException implements Exception {
  ApiException(this.statusCode, this.message, {this.code});

  final int statusCode;
  final String message;
  final String? code;

  @override
  String toString() => message;
}

/// Klien API AKUR.
/// - Android/iOS: token sesi disimpan di secure storage (Keystore/Keychain) dan dikirim sebagai Bearer.
/// - Web: cookie HttpOnly + header CSRF; token tidak pernah terlihat oleh Dart.
class ApiClient {
  ApiClient({http.Client? client, FlutterSecureStorage? storage})
      : _client = client ?? createHttpClient(),
        _storage = storage ??
            const FlutterSecureStorage(
              // Keychain: token tidak ikut backup/sinkron iCloud ke perangkat lain dan tetap terbaca
              // untuk request latar setelah perangkat dibuka sekali sejak boot.
              iOptions: IOSOptions(accessibility: KeychainAccessibility.first_unlock_this_device),
            );

  static const _tokenKey = 'akur_session_token';

  /// Batas waktu per request agar layar tidak memutar indikator tanpa akhir di jaringan lambat.
  static const timeout = Duration(seconds: 20);

  /// Dipanggil saat API membalas 401 di luar alur login (diatur oleh AuthController).
  VoidCallback? onUnauthorized;

  final http.Client _client;
  final FlutterSecureStorage _storage;

  Future<void> saveToken(String token) async {
    if (!kIsWeb) await _storage.write(key: _tokenKey, value: token);
  }

  Future<void> clearToken() async {
    if (!kIsWeb) await _storage.delete(key: _tokenKey);
  }

  Future<Map<String, dynamic>> get(String path) => _send('GET', path);

  /// `headers` untuk header tambahan, mis. Idempotency-Key pada pembayaran.
  Future<Map<String, dynamic>> post(String path, [Map<String, dynamic>? body, Map<String, String>? headers]) =>
      _send('POST', path, body, headers);

  Future<Map<String, dynamic>> put(String path, Map<String, dynamic> body) => _send('PUT', path, body);

  Future<Map<String, dynamic>> patch(String path, Map<String, dynamic> body) => _send('PATCH', path, body);

  Future<Map<String, dynamic>> delete(String path, [Map<String, dynamic>? body]) => _send('DELETE', path, body);

  /// Unggah satu berkas (multipart) — dipakai untuk bukti bayar dan nota kas.
  Future<Map<String, dynamic>> upload(String path, {required List<int> bytes, required String filename, Map<String, String> fields = const {}}) async {
    final request = http.MultipartRequest('POST', Uri.parse('${AppConfig.apiBaseUrl}/api/v1$path'))
      ..headers['Accept'] = 'application/json'
      ..fields.addAll(fields)
      ..files.add(http.MultipartFile.fromBytes('file', bytes, filename: filename));
    if (kIsWeb) {
      request.headers['X-AKUR-CSRF'] = '1';
    } else {
      final token = await _storage.read(key: _tokenKey);
      if (token != null) request.headers['Authorization'] = 'Bearer $token';
    }

    final http.Response response;
    try {
      response = await http.Response.fromStream(await _client.send(request).timeout(const Duration(seconds: 60)));
    } on TimeoutException {
      throw ApiException(0, 'Unggahan terlalu lama. Periksa koneksi internet Anda lalu coba lagi.');
    } catch (_) {
      throw ApiException(0, 'Tidak dapat terhubung ke server. Periksa koneksi internet Anda.');
    }
    final decoded = response.body.isEmpty ? <String, dynamic>{} : jsonDecode(response.body);
    final json = decoded is Map<String, dynamic> ? decoded : <String, dynamic>{'data': decoded};
    if (response.statusCode == 401) onUnauthorized?.call();
    if (response.statusCode >= 400) {
      final errors = json['errors'] as Map<String, dynamic>?;
      final first = errors?.values.whereType<List<dynamic>>().expand((e) => e).firstOrNull;
      throw ApiException(response.statusCode, (first ?? json['message'] ?? 'Unggahan gagal (${response.statusCode}).') as String);
    }
    return json;
  }

  Future<Map<String, dynamic>> _send(String method, String path, [Map<String, dynamic>? body, Map<String, String>? headers]) async {
    final request = http.Request(method, Uri.parse('${AppConfig.apiBaseUrl}/api/v1$path'))
      ..headers.addAll({'Accept': 'application/json', 'Content-Type': 'application/json', ...?headers});

    if (kIsWeb) {
      if (method != 'GET') request.headers['X-AKUR-CSRF'] = '1';
    } else {
      final token = await _storage.read(key: _tokenKey);
      if (token != null) request.headers['Authorization'] = 'Bearer $token';
    }

    if (body != null) request.body = jsonEncode(body);

    final http.Response response;
    try {
      response = await http.Response.fromStream(await _client.send(request).timeout(timeout)).timeout(timeout);
    } on TimeoutException {
      throw ApiException(0, 'Server tidak merespons. Periksa koneksi internet Anda lalu coba lagi.');
    } catch (_) {
      throw ApiException(0, 'Tidak dapat terhubung ke server. Periksa koneksi internet Anda.');
    }

    final Object? decoded;
    try {
      decoded = response.body.isEmpty ? <String, dynamic>{} : jsonDecode(response.body);
    } on FormatException {
      throw ApiException(response.statusCode, 'Respons server tidak valid (${response.statusCode}). Coba lagi nanti.');
    }
    final json = decoded is Map<String, dynamic> ? decoded : <String, dynamic>{'data': decoded};

    // Sesi kedaluwarsa/dicabut saat aplikasi terbuka: kembalikan pengguna ke layar login.
    if (response.statusCode == 401 && path != '/me' && !path.startsWith('/auth/')) onUnauthorized?.call();

    if (response.statusCode >= 400) {
      throw ApiException(
        response.statusCode,
        (json['message'] as String?) ?? 'Terjadi kesalahan (${response.statusCode}).',
        code: json['code'] as String?,
      );
    }
    return json;
  }
}
