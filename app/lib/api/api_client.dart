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

  final http.Client _client;
  final FlutterSecureStorage _storage;

  Future<void> saveToken(String token) async {
    if (!kIsWeb) await _storage.write(key: _tokenKey, value: token);
  }

  Future<void> clearToken() async {
    if (!kIsWeb) await _storage.delete(key: _tokenKey);
  }

  Future<Map<String, dynamic>> get(String path) => _send('GET', path);

  Future<Map<String, dynamic>> post(String path, [Map<String, dynamic>? body]) => _send('POST', path, body);

  Future<Map<String, dynamic>> put(String path, Map<String, dynamic> body) => _send('PUT', path, body);

  Future<Map<String, dynamic>> patch(String path, Map<String, dynamic> body) => _send('PATCH', path, body);

  Future<Map<String, dynamic>> delete(String path, [Map<String, dynamic>? body]) => _send('DELETE', path, body);

  Future<Map<String, dynamic>> _send(String method, String path, [Map<String, dynamic>? body]) async {
    final request = http.Request(method, Uri.parse('${AppConfig.apiBaseUrl}/api/v1$path'))
      ..headers.addAll({'Accept': 'application/json', 'Content-Type': 'application/json'});

    if (kIsWeb) {
      if (method != 'GET') request.headers['X-AKUR-CSRF'] = '1';
    } else {
      final token = await _storage.read(key: _tokenKey);
      if (token != null) request.headers['Authorization'] = 'Bearer $token';
    }

    if (body != null) request.body = jsonEncode(body);

    final response = await http.Response.fromStream(await _client.send(request));
    final decoded = response.body.isEmpty ? <String, dynamic>{} : jsonDecode(response.body);
    final json = decoded is Map<String, dynamic> ? decoded : <String, dynamic>{'data': decoded};

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
