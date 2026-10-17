import 'package:flutter/material.dart';

import 'population_common.dart';

/// Catat mutasi warga: pindah masuk/keluar, pindah rumah/KK, meninggal, atau koreksi status.
/// Riwayat tidak pernah ditimpa; server menambah baris mutasi lalu memperbarui keadaan terkini.
class MovementFormScreen extends StatefulWidget {
  const MovementFormScreen({super.key, required this.scope, required this.resident});

  final PopulationScope scope;
  final Map<String, dynamic> resident;

  @override
  State<MovementFormScreen> createState() => _MovementFormScreenState();
}

class _MovementFormScreenState extends State<MovementFormScreen> {
  final _reason = TextEditingController();
  late String _type = _allowedTypes.first;
  String? _date = formatDate(DateTime.now());
  String? _toHouseholdId;
  String? _residency;
  List<Map<String, dynamic>> _households = [];
  bool _saving = false;

  bool get _isAlive => widget.resident['is_alive'] == true;
  bool get _isMovedOut => widget.resident['residency_status'] == 'moved_out';

  /// Hanya jenis mutasi yang masuk akal untuk status warga saat ini (server tetap memvalidasi).
  List<String> get _allowedTypes => [
        if (_isAlive && _isMovedOut) 'move_in',
        if (_isAlive && !_isMovedOut) ...['move_house', 'move_out'],
        if (_isAlive) 'death',
        'correction',
      ];

  bool get _needsHousehold => _type == 'move_in' || _type == 'move_house';

  @override
  void initState() {
    super.initState();
    _loadHouseholds();
  }

  Future<void> _loadHouseholds() async {
    final households = dataList(await widget.scope.api.get('/units/${widget.scope.unit.id}/households?status=active'));
    if (mounted) setState(() => _households = households);
  }

  Future<void> _save() async {
    final messenger = ScaffoldMessenger.of(context);
    if (_date == null || (_needsHousehold && _toHouseholdId == null) || (_type == 'correction' && (_residency == null || _reason.text.trim().isEmpty))) {
      messenger.showSnackBar(const SnackBar(content: Text('Lengkapi isian yang wajib.')));
      return;
    }
    setState(() => _saving = true);

    final ok = await runApi(
      context,
      () => widget.scope.api.post('/residents/${widget.resident['id']}/movements', {
        'movement_type': _type,
        'effective_date': _date,
        if (_needsHousehold) 'to_household_id': _toHouseholdId,
        if (_type == 'move_in' || _type == 'correction') 'residency_status': _residency,
        if (_reason.text.trim().isNotEmpty) 'reason': _reason.text.trim(),
      }),
      'Mutasi dicatat.',
    );
    if (!mounted) return;
    setState(() => _saving = false);
    if (ok) Navigator.pop(context, true);
  }

  @override
  Widget build(BuildContext context) {
    final statusOptions = _type == 'correction'
        ? residencyStatusLabels.keys.toList()
        : const ['permanent', 'contract', 'temporary'];

    return Scaffold(
      appBar: AppBar(title: Text('Mutasi · ${widget.resident['full_name']}')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          DropdownButtonFormField<String>(
            initialValue: _type,
            decoration: const InputDecoration(labelText: 'Jenis mutasi *'),
            items: [for (final t in _allowedTypes) DropdownMenuItem(value: t, child: Text(movementTypeLabels[t]!))],
            onChanged: (v) => setState(() {
              _type = v ?? _type;
              _residency = null;
              _toHouseholdId = null;
            }),
          ),
          const SizedBox(height: 12),
          DateField(label: 'Tanggal berlaku', value: _date, required: true, onChanged: (v) => setState(() => _date = v)),
          if (_needsHousehold) ...[
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              key: ValueKey('to-${_households.length}-$_type'),
              initialValue: _toHouseholdId,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'Keluarga tujuan *'),
              items: [
                for (final h in _households.where((h) => h['id'] != widget.resident['household_id'] || _type == 'move_in'))
                  DropdownMenuItem(
                    value: h['id'] as String,
                    child: Text('${h['head_name'] ?? '-'} · ${h['address_text']}', overflow: TextOverflow.ellipsis),
                  ),
              ],
              onChanged: (v) => setState(() => _toHouseholdId = v),
            ),
          ],
          if (_type == 'move_in' || _type == 'correction') ...[
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              key: ValueKey('status-$_type'),
              initialValue: _residency,
              decoration: InputDecoration(labelText: _type == 'correction' ? 'Status yang benar *' : 'Status tinggal'),
              items: [for (final s in statusOptions) DropdownMenuItem(value: s, child: Text(residencyStatusLabels[s]!))],
              onChanged: (v) => setState(() => _residency = v),
            ),
          ],
          const SizedBox(height: 12),
          TextField(
            controller: _reason,
            decoration: InputDecoration(labelText: _type == 'correction' ? 'Alasan koreksi *' : 'Keterangan'),
            maxLines: 2,
          ),
          const SizedBox(height: 24),
          FilledButton(onPressed: _saving ? null : _save, child: Text(_saving ? 'Menyimpan…' : 'Catat mutasi')),
        ],
      ),
    );
  }
}
