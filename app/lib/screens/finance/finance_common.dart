import 'dart:math';

import 'package:flutter/material.dart';

/// "Rp 25.000" — nominal rupiah bulat dari API (bigint).
String formatRupiah(num? amount) {
  final value = (amount ?? 0).round();
  final digits = value.abs().toString();
  final buffer = StringBuffer();
  for (var i = 0; i < digits.length; i++) {
    if (i > 0 && (digits.length - i) % 3 == 0) buffer.write('.');
    buffer.write(digits[i]);
  }
  return '${value < 0 ? '-' : ''}Rp $buffer';
}

/// Kunci idempotensi acak per percobaan kirim; dipakai ulang bila pengguna menekan kirim lagi setelah gagal jaringan.
String newIdempotencyKey() {
  final random = Random.secure();
  return List.generate(16, (_) => random.nextInt(256).toRadixString(16).padLeft(2, '0')).join();
}

const assessmentStatusLabels = {
  'unpaid': 'Belum bayar',
  'partial': 'Sebagian',
  'paid': 'Lunas',
  'waived': 'Dibebaskan',
};

const paymentStatusLabels = {
  'submitted': 'Menunggu verifikasi',
  'verified': 'Terverifikasi',
  'rejected': 'Ditolak',
  'refunded': 'Dikembalikan',
};

const paymentMethodLabels = {'cash': 'Tunai', 'transfer': 'Transfer bank', 'qris': 'QRIS', 'ewallet': 'E-wallet'};

const duesFrequencyLabels = {'monthly': 'Bulanan', 'yearly': 'Tahunan', 'once': 'Sekali'};

class StatusChip extends StatelessWidget {
  const StatusChip({super.key, required this.label, required this.tone});

  final String label;

  /// good | bad | warn | neutral
  final String tone;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final color = switch (tone) {
      'good' => scheme.primaryContainer,
      'bad' => scheme.errorContainer,
      'warn' => scheme.tertiaryContainer,
      _ => scheme.surfaceContainerHighest,
    };
    return Chip(label: Text(label), backgroundColor: color, visualDensity: VisualDensity.compact);
  }
}

StatusChip assessmentChip(Map<String, dynamic> a) {
  final status = a['status'] as String;
  if (a['is_overdue'] == true) return const StatusChip(label: 'Terlambat', tone: 'bad');
  return StatusChip(
    label: assessmentStatusLabels[status] ?? status,
    tone: switch (status) { 'paid' || 'waived' => 'good', 'partial' => 'warn', _ => 'neutral' },
  );
}

StatusChip paymentChip(String status) => StatusChip(
      label: paymentStatusLabels[status] ?? status,
      tone: switch (status) { 'verified' => 'good', 'rejected' || 'refunded' => 'bad', _ => 'warn' },
    );
