import 'package:akur/api/api_client.dart';
import 'package:akur/screens/community/community_common.dart';
import 'package:akur/screens/documents/documents_common.dart';
import 'package:akur/screens/finance/finance_common.dart';
import 'package:akur/widgets/async_view.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('formatRupiah', () {
    test('memakai titik sebagai pemisah ribuan', () {
      expect(formatRupiah(0), 'Rp 0');
      expect(formatRupiah(25000), 'Rp 25.000');
      expect(formatRupiah(1250000), 'Rp 1.250.000');
      expect(formatRupiah(-150000), '-Rp 150.000');
    });
  });

  test('formDataOf menerima objek, array kosong dari PHP, dan null', () {
    expect(formDataOf({'form_data': {'nama_usaha': 'Warung'}}), {'nama_usaha': 'Warung'});
    expect(formDataOf({'form_data': <dynamic>[]}), isEmpty);
    expect(formDataOf({'form_data': null}), isEmpty);
    expect(formDataOf(null), isEmpty);
  });

  test('formatDateTime menampilkan waktu lokal dan "-" untuk null', () {
    expect(formatDateTime(null), '-');
    final local = DateTime(2026, 10, 18, 7, 5);
    expect(formatDateTime(local.toUtc().toIso8601String()), 'Min, 18/10/2026 07.05');
  });

  group('friendlyError', () {
    test('menyembunyikan detail galat server', () {
      expect(friendlyError(ApiException(500, 'SQLSTATE[42P01] relation missing')), isNot(contains('SQLSTATE')));
    });

    test('meneruskan pesan validasi dan akses dari server', () {
      expect(friendlyError(ApiException(422, 'NIK harus 16 digit angka.')), 'NIK harus 16 digit angka.');
      expect(friendlyError(ApiException(403, 'Anda tidak memiliki akses ke unit ini.')), 'Anda tidak memiliki akses ke unit ini.');
    });

    test('galat tak dikenal tidak bocor ke pengguna', () {
      expect(friendlyError(StateError('null check')), isNot(contains('null check')));
    });
  });

  testWidgets('ErrorView menampilkan pesan dan tombol coba lagi', (tester) async {
    var retried = 0;
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(body: ErrorView(error: ApiException(0, 'Tidak dapat terhubung ke server.'), onRetry: () => retried++)),
    ));

    expect(find.text('Tidak dapat terhubung ke server.'), findsOneWidget);
    expect(find.byIcon(Icons.wifi_off), findsOneWidget);
    await tester.tap(find.text('Coba lagi'));
    expect(retried, 1);
  });

  testWidgets('ResponsiveFrame membatasi lebar hanya di layar lebar', (tester) async {
    Future<double> widthAt(double screen) async {
      tester.view.physicalSize = Size(screen, 800);
      tester.view.devicePixelRatio = 1;
      await tester.pumpWidget(const MaterialApp(home: ResponsiveFrame(child: SizedBox.expand(key: Key('content')))));
      return tester.getSize(find.byKey(const Key('content'))).width;
    }

    addTearDown(tester.view.reset);
    expect(await widthAt(400), 400);
    expect(await widthAt(1400), 840);
  });
}
