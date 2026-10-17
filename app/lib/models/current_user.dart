class UnitRef {
  UnitRef({required this.id, required this.unitType, required this.code, required this.name});

  factory UnitRef.fromJson(Map<String, dynamic> json) => UnitRef(
        id: json['id'] as String,
        unitType: json['unit_type'] as String,
        code: json['code'] as String,
        name: json['name'] as String,
      );

  final String id;
  final String unitType;
  final String code;
  final String name;
}

class Membership {
  Membership({required this.role, required this.roleName, required this.unit});

  factory Membership.fromJson(Map<String, dynamic> json) => Membership(
        role: json['role'] as String,
        roleName: json['role_name'] as String,
        unit: UnitRef.fromJson(json['unit'] as Map<String, dynamic>),
      );

  final String role;
  final String roleName;
  final UnitRef unit;
}

class CurrentUser {
  CurrentUser({
    required this.id,
    required this.displayName,
    required this.email,
    required this.avatarUrl,
    required this.accountStatus,
    required this.memberships,
    required this.residentName,
  });

  factory CurrentUser.fromJson(Map<String, dynamic> json) => CurrentUser(
        id: json['id'] as String,
        displayName: json['display_name'] as String,
        email: json['email'] as String?,
        avatarUrl: json['avatar_url'] as String?,
        accountStatus: json['account_status'] as String,
        memberships: (json['memberships'] as List<dynamic>)
            .map((m) => Membership.fromJson(m as Map<String, dynamic>))
            .toList(),
        residentName: (json['resident'] as Map<String, dynamic>?)?['full_name'] as String?,
      );

  static const linkingRoles = {'super_admin', 'ketua_rw', 'ketua_rt', 'sekretaris'};

  final String id;
  final String displayName;
  final String? email;
  final String? avatarUrl;
  final String accountStatus;
  final List<Membership> memberships;
  final String? residentName;

  bool get isActive => accountStatus == 'active';

  /// Hanya untuk menampilkan menu. Otorisasi sebenarnya selalu di server.
  List<Membership> get linkingMemberships =>
      memberships.where((m) => linkingRoles.contains(m.role)).toList();
}
