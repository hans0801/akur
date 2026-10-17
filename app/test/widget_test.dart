import 'package:akur/models/current_user.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('CurrentUser parses /me and exposes linking memberships only', () {
    final user = CurrentUser.fromJson({
      'id': 'u1',
      'display_name': 'Budi',
      'email': 'budi@example.com',
      'avatar_url': null,
      'account_status': 'active',
      'resident': {'id': 'r1', 'full_name': 'Budi Contoh'},
      'memberships': [
        {
          'role': 'warga',
          'role_name': 'Warga',
          'unit': {'id': 'rt', 'unit_type': 'rt', 'code': '05', 'name': 'RT 05'},
        },
        {
          'role': 'ketua_rt',
          'role_name': 'Ketua RT',
          'unit': {'id': 'rt', 'unit_type': 'rt', 'code': '05', 'name': 'RT 05'},
        },
      ],
    });

    expect(user.isActive, isTrue);
    expect(user.residentName, 'Budi Contoh');
    expect(user.linkingMemberships.map((m) => m.role), ['ketua_rt']);
  });
}
