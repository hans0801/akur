import 'package:flutter/material.dart';

import '../theme.dart';

/// Komponen bersama gaya "Harmoni Warga" (kartu, ikon bulat pastel, pil status, judul bagian).

/// Bayangan lembut kartu yang beristirahat di atas kanvas.
const akurCardShadow = [
  BoxShadow(color: Color(0x0F253036), blurRadius: 16, offset: Offset(0, 4), spreadRadius: -2),
  BoxShadow(color: Color(0x0A253036), blurRadius: 3, offset: Offset(0, 1)),
];

/// Kartu putih bersudut 18 px dengan bayangan lembut dan garis tipis; bisa diketuk.
class AkurCard extends StatelessWidget {
  const AkurCard({super.key, required this.child, this.padding = const EdgeInsets.all(20), this.onTap, this.color, this.border});

  final Widget child;
  final EdgeInsetsGeometry padding;
  final VoidCallback? onTap;
  final Color? color;
  final BoxBorder? border;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final light = scheme.brightness == Brightness.light;
    final radius = BorderRadius.circular(AkurTheme.radiusCard);
    return DecoratedBox(
      decoration: BoxDecoration(borderRadius: radius, boxShadow: light ? akurCardShadow : null),
      child: Material(
        color: color ?? (light ? Colors.white : scheme.surfaceContainer),
        shape: RoundedRectangleBorder(borderRadius: radius),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          child: Container(
            decoration: BoxDecoration(
              borderRadius: radius,
              border: border ?? Border.all(color: scheme.onSurface.withValues(alpha: 0.08)),
            ),
            padding: padding,
            child: child,
          ),
        ),
      ),
    );
  }
}

/// Ikon di dalam cakram pastel (layanan, riwayat, kategori).
class IconDisc extends StatelessWidget {
  const IconDisc(this.icon, {super.key, this.color, this.background, this.size = 48});

  final IconData icon;
  final Color? color;
  final Color? background;
  final double size;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(color: background ?? scheme.primaryContainer, borderRadius: BorderRadius.circular(size * 0.3)),
      child: Icon(icon, color: color ?? scheme.primary, size: size * 0.52),
    );
  }
}

enum PillTone { primary, secondary, tertiary, neutral, danger, solid }

/// Pil status/kategori bersudut penuh.
class Pill extends StatelessWidget {
  const Pill(this.label, {super.key, this.tone = PillTone.secondary, this.icon});

  final String label;
  final PillTone tone;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final (bg, fg) = switch (tone) {
      PillTone.primary => (scheme.primaryContainer, scheme.primary),
      PillTone.secondary => (scheme.secondaryContainer, scheme.onSecondaryContainer),
      PillTone.tertiary => (scheme.tertiaryContainer, scheme.onTertiaryContainer),
      PillTone.danger => (scheme.errorContainer, scheme.onErrorContainer),
      PillTone.solid => (scheme.primary, scheme.onPrimary),
      PillTone.neutral => (scheme.surfaceContainerHigh, scheme.onSurfaceVariant),
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(999)),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (icon != null) ...[Icon(icon, size: 16, color: fg), const SizedBox(width: 4)],
          Flexible(
            child: Text(
              label,
              overflow: TextOverflow.ellipsis,
              style: Theme.of(context).textTheme.labelMedium?.copyWith(color: fg, fontWeight: FontWeight.w700),
            ),
          ),
        ],
      ),
    );
  }
}

/// Judul bagian dengan aksi kecil di kanan (mis. "Lihat semua").
class SectionHeader extends StatelessWidget {
  const SectionHeader(this.title, {super.key, this.action, this.onAction, this.dot = false});

  final String title;
  final String? action;
  final VoidCallback? onAction;
  final bool dot;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.fromLTRB(4, 8, 0, 4),
      child: Row(
        children: [
          if (dot) ...[
            Container(
              width: 10,
              height: 10,
              decoration: BoxDecoration(color: theme.colorScheme.primary, shape: BoxShape.circle),
            ),
            const SizedBox(width: 8),
          ],
          Expanded(child: Text(title, style: theme.textTheme.titleLarge)),
          if (action != null) TextButton(onPressed: onAction, child: Text(action!)),
        ],
      ),
    );
  }
}

/// Baris info berikon di dalam kotak bernuansa biru muda (jadwal, lokasi, pemohon).
class InfoPanel extends StatelessWidget {
  const InfoPanel({super.key, required this.children, this.color});

  final List<Widget> children;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: color ?? Theme.of(context).colorScheme.surfaceContainerLow, borderRadius: BorderRadius.circular(14)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, spacing: 8, children: children),
    );
  }
}

class InfoRow extends StatelessWidget {
  const InfoRow({super.key, required this.icon, required this.text, this.sub, this.color});

  final IconData icon;
  final String text;
  final String? sub;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, size: 20, color: color ?? theme.colorScheme.onSurfaceVariant),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(text, style: theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w600)),
              if (sub != null) Text(sub!, style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
            ],
          ),
        ),
      ],
    );
  }
}

/// Baris label–nilai (Pemohon: …, Tgl. pengajuan: …).
class KeyValueRow extends StatelessWidget {
  const KeyValueRow(this.label, this.value, {super.key, this.valueStyle});

  final String label;
  final String value;
  final TextStyle? valueStyle;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          flex: 2,
          child: Text(label, style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
        ),
        const SizedBox(width: 12),
        Expanded(
          flex: 3,
          child: Text(
            value,
            textAlign: TextAlign.end,
            style: valueStyle ?? theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w600),
          ),
        ),
      ],
    );
  }
}

/// Bilah kemajuan bulat (partisipasi iuran, konfirmasi hadir, tahap surat).
class ProgressBar extends StatelessWidget {
  const ProgressBar({super.key, required this.value, this.color, this.background});

  final double value;
  final Color? color;
  final Color? background;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return ClipRRect(
      borderRadius: BorderRadius.circular(999),
      child: LinearProgressIndicator(
        value: value.clamp(0, 1),
        minHeight: 8,
        color: color ?? scheme.secondary,
        backgroundColor: background ?? scheme.surfaceContainerHigh,
      ),
    );
  }
}

/// Kotak bantuan bernuansa teal di bagian bawah layar.
class HelpCard extends StatelessWidget {
  const HelpCard({super.key, required this.icon, required this.title, required this.message, this.action, this.onAction});

  final IconData icon;
  final String title;
  final String message;
  final String? action;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(color: scheme.surfaceContainer, borderRadius: BorderRadius.circular(AkurTheme.radiusCard)),
      child: Row(
        children: [
          IconDisc(icon, background: scheme.secondaryContainer, color: scheme.secondary, size: 44),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: theme.textTheme.titleSmall?.copyWith(color: scheme.secondary)),
                const SizedBox(height: 2),
                Text(message, style: theme.textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant)),
              ],
            ),
          ),
          if (action != null) ...[
            const SizedBox(width: 8),
            OutlinedButton(
              onPressed: onAction,
              style: OutlinedButton.styleFrom(minimumSize: const Size(48, 44), padding: const EdgeInsets.symmetric(horizontal: 14)),
              child: Text(action!),
            ),
          ],
        ],
      ),
    );
  }
}

/// Inisial nama untuk avatar ("Bambang Hartono" → "BH").
String initialsOf(String? name) {
  final parts = (name ?? '').trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty).toList();
  if (parts.isEmpty) return '?';
  return (parts.first[0] + (parts.length > 1 ? parts[1][0] : '')).toUpperCase();
}

/// Sapaan menurut jam perangkat.
String greetingNow([DateTime? now]) {
  final h = (now ?? DateTime.now()).hour;
  if (h < 11) return 'Selamat Pagi';
  if (h < 15) return 'Selamat Siang';
  if (h < 18) return 'Selamat Sore';
  return 'Selamat Malam';
}

const _months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
const _days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];

/// "Minggu, 18 Mei 2025".
String formatLongDate(String? iso) {
  final d = iso == null ? null : DateTime.tryParse(iso)?.toLocal();
  if (d == null) return '-';
  return '${_days[d.weekday - 1]}, ${d.day} ${_months[d.month - 1]} ${d.year}';
}

/// "18 Mei 2025".
String formatShortDate(String? iso) {
  final d = iso == null ? null : DateTime.tryParse(iso)?.toLocal();
  if (d == null) return '-';
  return '${d.day} ${_months[d.month - 1]} ${d.year}';
}

/// "06.30 WIB" (jam perangkat).
String formatClock(String? iso) {
  final d = iso == null ? null : DateTime.tryParse(iso)?.toLocal();
  if (d == null) return '-';
  String two(int n) => n.toString().padLeft(2, '0');
  return '${two(d.hour)}.${two(d.minute)}';
}

/// "Oktober 2026" dari "2026-10".
String formatMonthCode(String? code) {
  final parts = (code ?? '').split('-');
  if (parts.length < 2) return code ?? '-';
  final m = int.tryParse(parts[1]);
  return m == null || m < 1 || m > 12 ? code! : '${_months[m - 1]} ${parts[0]}';
}

/// Teks relatif singkat untuk notifikasi ("5 menit lalu").
String formatRelative(String? iso) {
  final d = iso == null ? null : DateTime.tryParse(iso)?.toLocal();
  if (d == null) return '';
  final diff = DateTime.now().difference(d);
  if (diff.inMinutes < 1) return 'Baru saja';
  if (diff.inHours < 1) return '${diff.inMinutes} menit lalu';
  if (diff.inDays < 1) return '${diff.inHours} jam lalu';
  if (diff.inDays < 7) return '${diff.inDays} hari lalu';
  return formatShortDate(iso);
}
