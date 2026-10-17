import 'package:http/browser_client.dart';
import 'package:http/http.dart' as http;

/// Di web sesi berupa cookie HttpOnly; browser yang menyimpannya, bukan kode Dart.
http.Client createHttpClient() => BrowserClient()..withCredentials = true;
