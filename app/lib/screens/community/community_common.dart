import 'package:flutter/material.dart';

/// Tanggal-waktu lokal ringkas, mis. "Min, 19/10/2026 07.00". Tanpa paket intl.
String formatDateTime(String? iso) {
  final d = iso == null ? null : DateTime.tryParse(iso)?.toLocal();
  if (d == null) return '-';
  const days = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
  String two(int n) => n.toString().padLeft(2, '0');
  return '${days[d.weekday - 1]}, ${two(d.day)}/${two(d.month)}/${d.year} ${two(d.hour)}.${two(d.minute)}';
}

const activityCategoryLabels = {
  'kerja_bakti': 'Kerja bakti',
  'rapat': 'Rapat',
  'keagamaan': 'Keagamaan',
  'olahraga': 'Olahraga',
  'sosial': 'Sosial',
  'perayaan': 'Perayaan',
  'lainnya': 'Lainnya',
};

const activityStatusLabels = {'planned': 'Terjadwal', 'cancelled': 'Dibatalkan', 'completed': 'Selesai'};

const announcementStatusLabels = {'draft': 'Draf', 'published': 'Terbit', 'archived': 'Arsip'};

const participationLabels = {'going': 'Hadir', 'maybe': 'Mungkin', 'not_going': 'Tidak hadir'};

/// Bidang tanggal + jam (zona waktu perangkat); dikirim ke API sebagai ISO 8601 UTC.
class DateTimeField extends StatelessWidget {
  const DateTimeField({
    super.key,
    required this.label,
    required this.value,
    required this.onChanged,
    this.required = false,
    this.allowPast = false,
  });

  final String label;
  final DateTime? value;
  final ValueChanged<DateTime?> onChanged;
  final bool required;
  final bool allowPast;

  Future<void> _pick(BuildContext context) async {
    final now = DateTime.now();
    final initial = value ?? now.add(const Duration(days: 1));
    final date = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: allowPast ? DateTime(now.year - 1) : DateTime(now.year, now.month, now.day),
      lastDate: DateTime(now.year + 2),
    );
    if (date == null || !context.mounted) return;
    final time = await showTimePicker(context: context, initialTime: TimeOfDay.fromDateTime(initial));
    if (time == null) return;
    onChanged(DateTime(date.year, date.month, date.day, time.hour, time.minute));
  }

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: () => _pick(context),
      child: InputDecorator(
        decoration: InputDecoration(
          labelText: required ? '$label *' : label,
          suffixIcon: value != null && !required
              ? IconButton(icon: const Icon(Icons.clear), onPressed: () => onChanged(null))
              : const Icon(Icons.schedule),
        ),
        child: Text(value == null ? '-' : formatDateTime(value!.toUtc().toIso8601String())),
      ),
    );
  }
}
