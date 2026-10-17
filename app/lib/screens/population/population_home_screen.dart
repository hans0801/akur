import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../models/current_user.dart';
import 'households_screen.dart';
import 'population_common.dart';
import 'residents_screen.dart';
import 'streets_screen.dart';

/// Menu data kependudukan (FR-04) untuk pengurus: keluarga, warga, serta jalan & rumah.
class PopulationHomeScreen extends StatefulWidget {
  const PopulationHomeScreen({super.key, required this.api, required this.user});

  final ApiClient api;
  final CurrentUser user;

  @override
  State<PopulationHomeScreen> createState() => _PopulationHomeScreenState();
}

class _PopulationHomeScreenState extends State<PopulationHomeScreen> {
  late final List<UnitRef> _units = {
    for (final m in widget.user.populationMemberships) m.unit.id: m.unit,
  }.values.toList();
  late UnitRef _unit = _units.first;

  PopulationScope get _scope =>
      PopulationScope(api: widget.api, unit: _unit, canWrite: widget.user.canWritePopulation(_unit.id));

  void _open(Widget Function(PopulationScope scope) builder) {
    final scope = _scope;
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => builder(scope)));
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('Data warga')),
      body: ListView(
        children: [
          UnitDropdown(units: _units, value: _unit, onChanged: (u) => setState(() => _unit = u)),
          Padding(
            padding: const EdgeInsets.all(16),
            child: Text(
              _scope.canWrite
                  ? 'NIK dan No. KK selalu disamarkan. Perubahan alamat/status warga dicatat sebagai mutasi.'
                  : 'Anda memiliki akses baca saja untuk ${_unit.name}.',
              style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant),
            ),
          ),
          for (final item in [
            (Icons.family_restroom_outlined, 'Keluarga (KK)', 'Daftar KK, anggota, dan kepala keluarga', (PopulationScope s) => HouseholdsScreen(scope: s)),
            (Icons.people_outline, 'Warga', 'Cari warga, biodata, dan riwayat mutasi', (PopulationScope s) => ResidentsScreen(scope: s)),
            (Icons.signpost_outlined, 'Jalan & rumah', 'Master jalan dan nomor rumah', (PopulationScope s) => StreetsScreen(scope: s)),
          ])
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
              child: Card(
                child: ListTile(
                  leading: Icon(item.$1),
                  title: Text(item.$2),
                  subtitle: Text(item.$3),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => _open(item.$4),
                ),
              ),
            ),
        ],
      ),
    );
  }
}
