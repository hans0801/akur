import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../theme.dart';
import 'akur_ui.dart';

/// Gaya rambut/penutup kepala pada ilustrasi avatar warga.
enum AvatarHair { peci, hijab, short, bun, long, curly, elder }

/// Satu ilustrasi avatar bawaan "Harmoni Warga" (digambar vektor, tanpa berkas aset).
class AvatarPreset {
  const AvatarPreset({
    required this.id,
    required this.label,
    required this.background,
    required this.skin,
    required this.hairColor,
    required this.shirt,
    required this.hair,
    this.glasses = false,
    this.mustache = false,
    this.pattern = false,
  });

  final String id;
  final String label;
  final Color background;
  final Color skin;
  final Color hairColor;
  final Color shirt;
  final AvatarHair hair;
  final bool glasses;
  final bool mustache;

  /// Motif titik-titik ala batik pada baju.
  final bool pattern;
}

/// Sama dengan config akur.avatar_choices (warga-1..8) di backend.
const avatarPresets = <AvatarPreset>[
  AvatarPreset(
    id: 'warga-1',
    label: 'Bapak berpeci',
    background: AkurColors.secondaryContainer,
    skin: Color(0xFFC98D62),
    hairColor: Color(0xFF2B2522),
    shirt: AkurColors.primaryContainer,
    hair: AvatarHair.peci,
    pattern: true,
  ),
  AvatarPreset(
    id: 'warga-2',
    label: 'Ibu berhijab',
    background: AkurColors.primaryFixed,
    skin: Color(0xFFE2AE86),
    hairColor: AkurColors.primaryContainer,
    shirt: AkurColors.secondary,
    hair: AvatarHair.hijab,
  ),
  AvatarPreset(
    id: 'warga-3',
    label: 'Pemuda',
    background: Color(0xFFD9F1E4),
    skin: Color(0xFFB97B52),
    hairColor: Color(0xFF3A2A22),
    shirt: AkurColors.secondary,
    hair: AvatarHair.short,
  ),
  AvatarPreset(
    id: 'warga-4',
    label: 'Nenek bersanggul',
    background: AkurColors.tertiaryFixed,
    skin: Color(0xFFE6B48E),
    hairColor: Color(0xFFB9B4B0),
    shirt: Color(0xFF8E5A9B),
    hair: AvatarHair.bun,
    glasses: true,
    pattern: true,
  ),
  AvatarPreset(
    id: 'warga-5',
    label: 'Kakek berkacamata',
    background: AkurColors.surfaceHigh,
    skin: Color(0xFFC48A60),
    hairColor: Color(0xFFDAD6D2),
    shirt: AkurColors.tertiary,
    hair: AvatarHair.elder,
    glasses: true,
    mustache: true,
  ),
  AvatarPreset(
    id: 'warga-6',
    label: 'Remaja putri',
    background: Color(0xFFF6E1EA),
    skin: Color(0xFFEDBF9C),
    hairColor: Color(0xFF2E211C),
    shirt: AkurColors.primaryContainer,
    hair: AvatarHair.long,
  ),
  AvatarPreset(
    id: 'warga-7',
    label: 'Anak ceria',
    background: Color(0xFFFFF0C2),
    skin: Color(0xFFA86E47),
    hairColor: Color(0xFF241B17),
    shirt: Color(0xFF2E8A6E),
    hair: AvatarHair.curly,
  ),
  AvatarPreset(
    id: 'warga-8',
    label: 'Ibu berhijab teal',
    background: Color(0xFFE6F4F1),
    skin: Color(0xFFD9A07A),
    hairColor: AkurColors.secondary,
    shirt: AkurColors.tertiaryFixed,
    hair: AvatarHair.hijab,
    glasses: true,
  ),
];

AvatarPreset? avatarPresetById(String? id) => avatarPresets.where((p) => p.id == id).firstOrNull;

/// Avatar bawaan bila pengguna belum memilih: tetap sama untuk akun yang sama.
AvatarPreset defaultAvatarFor(String seed) => avatarPresets[seed.codeUnits.fold<int>(0, (a, c) => a + c) % avatarPresets.length];

/// Avatar akun: ilustrasi pilihan (atau bawaan), foto Google, atau inisial nama.
class AccountAvatar extends StatelessWidget {
  const AccountAvatar({
    super.key,
    required this.choice,
    required this.seed,
    required this.name,
    this.photoUrl,
    this.size = 40,
    this.ring = true,
  });

  /// Nilai `avatar_choice` dari server; null = avatar bawaan.
  final String? choice;
  final String seed;
  final String name;
  final String? photoUrl;
  final double size;
  final bool ring;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final preset = avatarPresetById(choice) ?? defaultAvatarFor(seed);
    final Widget art = switch (choice) {
      'initials' => _initials(context),
      'google' when photoUrl != null => Image.network(
        photoUrl!,
        width: size,
        height: size,
        fit: BoxFit.cover,
        // Web: foto Google tidak mengirim header CORS; tampilkan lewat elemen <img> bila perlu.
        webHtmlElementStrategy: WebHtmlElementStrategy.fallback,
        errorBuilder: (_, _, _) => AvatarArt(preset: defaultAvatarFor(seed), size: size),
      ),
      _ => AvatarArt(preset: preset, size: size),
    };
    return Semantics(
      image: true,
      label: 'Avatar $name',
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          color: scheme.primaryContainer,
          border: ring ? Border.all(color: Colors.white, width: size >= 48 ? 3 : 2) : null,
          boxShadow: ring ? akurCardShadow : null,
        ),
        clipBehavior: Clip.antiAlias,
        alignment: Alignment.center,
        child: art,
      ),
    );
  }

  Widget _initials(BuildContext context) => Text(
    initialsOf(name),
    style: Theme.of(context).textTheme.titleSmall?.copyWith(color: Theme.of(context).colorScheme.primary, fontSize: size * 0.36),
  );
}

/// Ilustrasi avatar dalam kotak persegi (dipotong bulat oleh pemanggil).
class AvatarArt extends StatelessWidget {
  const AvatarArt({super.key, required this.preset, this.size = 40});

  final AvatarPreset preset;
  final double size;

  @override
  Widget build(BuildContext context) =>
      ExcludeSemantics(child: CustomPaint(size: Size.square(size), painter: _AvatarPainter(preset)));
}

class _AvatarPainter extends CustomPainter {
  _AvatarPainter(this.p);

  final AvatarPreset p;

  static const _ink = Color(0xFF2A1F1A);

  @override
  void paint(Canvas canvas, Size size) {
    canvas.save();
    // Semua koordinat dalam ruang 100×100.
    canvas.scale(size.width / 100, size.height / 100);
    final fill = Paint()..isAntiAlias = true;

    canvas.drawRect(const Rect.fromLTWH(0, 0, 100, 100), fill..color = p.background);
    // Lingkaran lembut di belakang kepala.
    canvas.drawCircle(const Offset(50, 46), 36, fill..color = Colors.white.withValues(alpha: 0.35));

    final hair = Paint()..color = p.hairColor;
    final skin = Paint()..color = p.skin;
    final skinShade = Paint()..color = Color.lerp(p.skin, _ink, 0.18)!;
    final hijab = p.hair == AvatarHair.hijab;

    // Bagian belakang (di balik kepala).
    switch (p.hair) {
      case AvatarHair.long:
        canvas.drawRRect(RRect.fromLTRBR(29, 26, 71, 78, const Radius.circular(18)), hair);
      case AvatarHair.bun:
        canvas.drawCircle(const Offset(50, 22), 9, hair);
      case AvatarHair.hijab:
        // Kerudung menjuntai sampai bahu.
        canvas.drawPath(
          Path()
            ..moveTo(28, 50)
            ..quadraticBezierTo(18, 84, 14, 104)
            ..lineTo(86, 104)
            ..quadraticBezierTo(82, 84, 72, 50)
            ..close(),
          hair,
        );
      default:
        break;
    }

    // Badan dan baju.
    final shirt = Paint()..color = p.shirt;
    if (!hijab) {
      canvas.drawRRect(RRect.fromLTRBR(14, 74, 86, 130, const Radius.circular(30)), shirt);
      canvas.drawRect(const Rect.fromLTRB(44, 58, 56, 76), skinShade);
      canvas.drawPath(
        Path()
          ..moveTo(42, 74)
          ..lineTo(50, 86)
          ..lineTo(58, 74)
          ..close(),
        skinShade,
      );
      // Kerah.
      final collar = Paint()..color = Color.lerp(p.shirt, Colors.white, 0.35)!;
      canvas.drawPath(Path()..moveTo(40, 73)..lineTo(50, 86)..lineTo(44, 74)..close(), collar);
      canvas.drawPath(Path()..moveTo(60, 73)..lineTo(50, 86)..lineTo(56, 74)..close(), collar);
    } else {
      canvas.drawRRect(RRect.fromLTRBR(20, 86, 80, 130, const Radius.circular(24)), shirt);
    }
    if (p.pattern) {
      final dots = Paint()..color = Colors.white.withValues(alpha: 0.45);
      for (final o in const [Offset(26, 90), Offset(36, 96), Offset(64, 96), Offset(74, 90), Offset(30, 99), Offset(70, 99)]) {
        canvas.drawCircle(o, 2.2, dots);
      }
    }

    // Kepala.
    if (hijab) {
      canvas.drawOval(Rect.fromCenter(center: const Offset(50, 45), width: 44, height: 48), hair);
      canvas.drawOval(Rect.fromCenter(center: const Offset(50, 48), width: 26, height: 30), skin);
      // Lipatan kerudung di dahi.
      canvas.drawArc(
        Rect.fromCenter(center: const Offset(50, 48), width: 30, height: 34),
        math.pi * 1.1,
        math.pi * 0.8,
        false,
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = 2.4
          ..color = Color.lerp(p.hairColor, Colors.white, 0.3)!,
      );
    } else {
      canvas.drawCircle(const Offset(34.5, 47), 4.2, skin);
      canvas.drawCircle(const Offset(65.5, 47), 4.2, skin);
      canvas.drawOval(Rect.fromCenter(center: const Offset(50, 45), width: 31, height: 35), skin);
    }

    // Rambut / penutup kepala bagian depan.
    switch (p.hair) {
      case AvatarHair.short:
        canvas.drawPath(
          Path()
            ..moveTo(33.5, 46)
            ..quadraticBezierTo(31, 24, 50, 24)
            ..quadraticBezierTo(70, 24, 66.5, 46)
            ..quadraticBezierTo(64, 36, 56, 35)
            ..quadraticBezierTo(46, 39, 38, 34)
            ..quadraticBezierTo(35, 38, 33.5, 46)
            ..close(),
          hair,
        );
      case AvatarHair.peci:
        canvas.drawRect(const Rect.fromLTRB(34, 36, 38, 46), hair);
        canvas.drawRect(const Rect.fromLTRB(62, 36, 66, 46), hair);
        canvas.drawRRect(RRect.fromLTRBR(33, 21, 67, 37, const Radius.circular(5)), Paint()..color = const Color(0xFF1F1B1A));
        canvas.drawRect(const Rect.fromLTRB(33, 33, 67, 35), Paint()..color = const Color(0xFFD9A441));
      case AvatarHair.bun || AvatarHair.long:
        canvas.drawPath(
          Path()
            ..moveTo(33, 48)
            ..quadraticBezierTo(31, 25, 50, 25)
            ..quadraticBezierTo(69, 25, 67, 48)
            ..quadraticBezierTo(62, 34, 50, 33)
            ..quadraticBezierTo(38, 34, 33, 48)
            ..close(),
          hair,
        );
      case AvatarHair.curly:
        for (var i = 0; i <= 8; i++) {
          final a = math.pi + math.pi * i / 8;
          canvas.drawCircle(Offset(50 + 17 * math.cos(a), 40 + 15 * math.sin(a)), 6.5, hair);
        }
        canvas.drawOval(Rect.fromCenter(center: const Offset(50, 30), width: 30, height: 14), hair);
      case AvatarHair.elder:
        canvas.drawOval(Rect.fromCenter(center: const Offset(35, 39), width: 7, height: 12), hair);
        canvas.drawOval(Rect.fromCenter(center: const Offset(65, 39), width: 7, height: 12), hair);
        canvas.drawArc(Rect.fromCenter(center: const Offset(50, 31), width: 22, height: 8), math.pi, math.pi, true, hair);
      case AvatarHair.hijab:
        break;
    }

    // Wajah.
    final ink = Paint()..color = _ink;
    final eyeY = hijab ? 48.0 : 46.0;
    canvas.drawCircle(Offset(44, eyeY), 2, ink);
    canvas.drawCircle(Offset(56, eyeY), 2, ink);
    final blush = Paint()..color = const Color(0xFFF07C6B).withValues(alpha: 0.35);
    canvas.drawCircle(Offset(40, eyeY + 6), 3, blush);
    canvas.drawCircle(Offset(60, eyeY + 6), 3, blush);
    if (p.mustache) {
      canvas.drawPath(
        Path()
          ..moveTo(43, eyeY + 9)
          ..quadraticBezierTo(50, eyeY + 5, 57, eyeY + 9)
          ..quadraticBezierTo(50, eyeY + 8, 43, eyeY + 9)
          ..close(),
        Paint()..color = p.hairColor,
      );
    }
    canvas.drawArc(
      Rect.fromCenter(center: Offset(50, eyeY + 7), width: 11, height: 7),
      math.pi * 0.15,
      math.pi * 0.7,
      false,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1.8
        ..strokeCap = StrokeCap.round
        ..color = _ink,
    );
    if (p.glasses) {
      final frame = Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1.5
        ..color = const Color(0xFF3B3330);
      canvas.drawCircle(Offset(44, eyeY), 4.8, frame);
      canvas.drawCircle(Offset(56, eyeY), 4.8, frame);
      canvas.drawLine(Offset(48.8, eyeY), Offset(51.2, eyeY), frame);
    }
    canvas.restore();
  }

  @override
  bool shouldRepaint(_AvatarPainter oldDelegate) => oldDelegate.p != p;
}
