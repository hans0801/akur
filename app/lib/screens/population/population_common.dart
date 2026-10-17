import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../widgets/async_view.dart';
import '../../models/current_user.dart';

/// Konteks bersama layar data kependudukan (FR-04): klien API, unit terpilih, dan apakah tombol ubah ditampilkan.
/// Otorisasi sebenarnya selalu di server (peran + cakupan RT/RW).
class PopulationScope {
  const PopulationScope({required this.api, required this.unit, required this.canWrite});

  final ApiClient api;
  final UnitRef unit;
  final bool canWrite;
}

const residencyStatusLabels = {
  'permanent': 'Tetap',
  'contract': 'Kontrak',
  'temporary': 'Sementara',
  'moved_out': 'Pindah keluar',
  'deceased': 'Meninggal',
};

const movementTypeLabels = {
  'move_in': 'Pindah masuk',
  'move_out': 'Pindah keluar',
  'move_house': 'Pindah rumah/KK',
  'death': 'Meninggal',
  'correction': 'Koreksi status',
};

const genderLabels = {'male': 'Laki-laki', 'female': 'Perempuan'};

const householdStatusLabels = {'active': 'Aktif', 'moved_out': 'Pindah', 'inactive': 'Tidak aktif'};

const occupancyStatusLabels = {
  'occupied': 'Dihuni',
  'vacant': 'Kosong',
  'rented': 'Disewakan',
  'under_construction': 'Dibangun',
};

String formatDate(DateTime d) =>
    '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

List<Map<String, dynamic>> dataList(Map<String, dynamic> json) =>
    (json['data'] as List<dynamic>).cast<Map<String, dynamic>>();

/// Menjalankan aksi API dengan SnackBar hasil. Mengembalikan true jika berhasil.
Future<bool> runApi(BuildContext context, Future<void> Function() action, String success) async {
  final messenger = ScaffoldMessenger.of(context);
  try {
    await action();
    messenger.showSnackBar(SnackBar(content: Text(success)));
    return true;
  } on ApiException catch (e) {
    messenger.showSnackBar(SnackBar(content: Text(friendlyError(e))));
    return false;
  }
}

/// Dialog alasan wajib (mis. sebelum menampilkan NIK/KK utuh atau menghapus data).
Future<String?> askReason(BuildContext context, {required String title, String? message, String action = 'Lanjut'}) {
  final controller = TextEditingController();
  return showDialog<String>(
    context: context,
    builder: (ctx) => AlertDialog(
      title: Text(title),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (message != null) ...[Text(message), const SizedBox(height: 12)],
          TextField(controller: controller, autofocus: true, decoration: const InputDecoration(labelText: 'Alasan (wajib)')),
        ],
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Batal')),
        FilledButton(
          onPressed: () {
            final reason = controller.text.trim();
            if (reason.isNotEmpty) Navigator.pop(ctx, reason);
          },
          child: Text(action),
        ),
      ],
    ),
  );
}

/// Menampilkan NIK/KK utuh sesaat setelah pengguna memberi alasan; server mencatatnya di audit log.
Future<void> revealSensitive(BuildContext context, ApiClient api, {required String path, required String field, required String label}) async {
  final reason = await askReason(
    context,
    title: 'Tampilkan $label?',
    message: 'Akses ini dicatat di log audit. Jangan menyalin atau membagikan $label tanpa keperluan.',
    action: 'Tampilkan',
  );
  if (reason == null || !context.mounted) return;

  try {
    final json = await api.post(path, {'reason': reason});
    final value = (json['data'] as Map<String, dynamic>)[field] as String;
    if (!context.mounted) return;
    await showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(label),
        content: Text(value, style: Theme.of(ctx).textTheme.headlineSmall),
        actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Tutup'))],
      ),
    );
  } on ApiException catch (e) {
    if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(friendlyError(e))));
  }
}

/// Pemilih unit di AppBar bila pengguna mengelola lebih dari satu unit.
class UnitDropdown extends StatelessWidget {
  const UnitDropdown({super.key, required this.units, required this.value, required this.onChanged});

  final List<UnitRef> units;
  final UnitRef value;
  final ValueChanged<UnitRef> onChanged;

  @override
  Widget build(BuildContext context) {
    if (units.length < 2) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
      child: DropdownButtonFormField<String>(
        initialValue: value.id,
        decoration: const InputDecoration(labelText: 'Unit'),
        items: [for (final u in units) DropdownMenuItem(value: u.id, child: Text(u.name))],
        onChanged: (id) {
          if (id != null) onChanged(units.firstWhere((u) => u.id == id));
        },
      ),
    );
  }
}

class EmptyState extends StatelessWidget {
  const EmptyState(this.message, {super.key});

  final String message;

  @override
  Widget build(BuildContext context) => ListView(
        children: [
          Padding(padding: const EdgeInsets.all(32), child: Center(child: Text(message, textAlign: TextAlign.center))),
        ],
      );
}

/// Bidang tanggal (yyyy-mm-dd) dengan date picker; tanggal masa depan tidak diizinkan.
class DateField extends StatelessWidget {
  const DateField({super.key, required this.label, required this.value, required this.onChanged, this.required = false});

  final String label;
  final String? value;
  final ValueChanged<String?> onChanged;
  final bool required;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: () async {
        final now = DateTime.now();
        final picked = await showDatePicker(
          context: context,
          initialDate: DateTime.tryParse(value ?? '') ?? now,
          firstDate: DateTime(1900),
          lastDate: now,
        );
        if (picked != null) onChanged(formatDate(picked));
      },
      child: InputDecorator(
        decoration: InputDecoration(
          labelText: required ? '$label *' : label,
          suffixIcon: value != null && !required
              ? IconButton(tooltip: 'Kosongkan $label', icon: const Icon(Icons.clear), onPressed: () => onChanged(null))
              : const Icon(Icons.calendar_today_outlined),
        ),
        child: Text(value ?? '-'),
      ),
    );
  }
}
