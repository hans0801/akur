class UnitRef {
  UnitRef({required this.id, required this.unitType, required this.code, required this.name});

  factory UnitRef.fromJson(Map<String, dynamic> json) =>
      UnitRef(id: json['id'] as String, unitType: json['unit_type'] as String, code: json['code'] as String, name: json['name'] as String);

  final String id;
  final String unitType;
  final String code;
  final String name;
}

class Membership {
  Membership({required this.role, required this.roleName, required this.unit, this.inherited = false});

  factory Membership.fromJson(Map<String, dynamic> json) => Membership(
    role: json['role'] as String,
    roleName: json['role_name'] as String,
    unit: UnitRef.fromJson(json['unit'] as Map<String, dynamic>),
    inherited: json['inherited'] as bool? ?? false,
  );

  final String role;
  final String roleName;
  final UnitRef unit;

  /// Turunan dari peran di RW untuk RT di bawahnya (bukan keanggotaan tersendiri).
  final bool inherited;
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
    memberships: (json['memberships'] as List<dynamic>).map((m) => Membership.fromJson(m as Map<String, dynamic>)).toList(),
    residentName: (json['resident'] as Map<String, dynamic>?)?['full_name'] as String?,
  );

  static const linkingRoles = {'super_admin', 'ketua_rw', 'ketua_rt', 'sekretaris'};

  /// Sama dengan config akur.population_*_roles di backend (FR-04).
  static const populationReadRoles = {'super_admin', 'ketua_rw', 'ketua_rt', 'sekretaris'};
  static const populationWriteRoles = {'super_admin', 'ketua_rt', 'sekretaris'};

  /// Sama dengan config akur.community_write_roles (pengumuman & kegiatan).
  static const communityWriteRoles = {'super_admin', 'ketua_rw', 'ketua_rt', 'sekretaris'};

  /// Sama dengan config akur.document_read_roles (antrean surat pengantar).
  static const documentReadRoles = {'super_admin', 'ketua_rw', 'ketua_rt', 'sekretaris'};

  /// Sama dengan config akur.finance_read_roles / finance_write_roles (iuran & kas).
  static const financeReadRoles = {'super_admin', 'ketua_rw', 'ketua_rt', 'bendahara'};
  static const financeWriteRoles = {'super_admin', 'bendahara'};

  final String id;
  final String displayName;
  final String? email;
  final String? avatarUrl;
  final String accountStatus;
  final List<Membership> memberships;
  final String? residentName;

  bool get isActive => accountStatus == 'active';

  /// Hanya untuk menampilkan menu. Otorisasi sebenarnya selalu di server.
  List<Membership> get linkingMemberships => memberships.where((m) => linkingRoles.contains(m.role)).toList();

  List<Membership> get populationMemberships => memberships.where((m) => populationReadRoles.contains(m.role)).toList();

  /// Hanya untuk menampilkan tombol ubah. Server tetap memeriksa peran dan cakupan unit.
  bool canWritePopulation(String unitId) => memberships.any((m) => populationWriteRoles.contains(m.role) && m.unit.id == unitId);

  /// Unit tempat pengguna boleh membuat pengumuman/kegiatan (hanya untuk menampilkan tombol).
  List<UnitRef> get communityManageUnits =>
      {for (final m in memberships.where((m) => communityWriteRoles.contains(m.role))) m.unit.id: m.unit}.values.toList();

  /// Unit yang antrean suratnya bisa dibuka pengguna (hanya untuk menampilkan menu).
  List<UnitRef> get documentQueueUnits =>
      {for (final m in memberships.where((m) => documentReadRoles.contains(m.role))) m.unit.id: m.unit}.values.toList();

  /// Unit yang data keuangannya bisa dibuka pengguna (hanya untuk menampilkan menu).
  List<UnitRef> get financeUnits =>
      {for (final m in memberships.where((m) => financeReadRoles.contains(m.role))) m.unit.id: m.unit}.values.toList();

  bool canWriteFinance(String unitId) => memberships.any((m) => financeWriteRoles.contains(m.role) && m.unit.id == unitId);
}
