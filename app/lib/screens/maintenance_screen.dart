import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../auth/auth_controller.dart';
import '../theme.dart';
import '../widgets/akur_logo.dart';
import '../widgets/akur_ui.dart';

/// Halaman saat AKUR tidak bisa dipakai: mode pemeliharaan dari server, atau server mati/tak terjangkau.
/// Memeriksa ulang otomatis tiap 30 detik; tombol "Coba lagi" memeriksa saat itu juga.
class MaintenanceScreen extends StatefulWidget {
  const MaintenanceScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<MaintenanceScreen> createState() => _MaintenanceScreenState();
}

class _MaintenanceScreenState extends State<MaintenanceScreen> {
  static const _autoRetry = Duration(seconds: 30);

  Timer? _timer;
  bool _checking = false;

  @override
  void initState() {
    super.initState();
    _timer = Timer.periodic(_autoRetry, (_) => _retry());
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _retry() async {
    if (_checking) return;
    setState(() => _checking = true);
    await widget.auth.refreshMe();
    if (mounted) setState(() => _checking = false);
  }

  @override
  Widget build(BuildContext context) {
    final auth = widget.auth;
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final maintenance = auth.status == AuthStatus.maintenance;
    final title = maintenance ? 'Sedang dalam pemeliharaan' : 'Layanan sedang tidak tersedia';
    final message = maintenance
        ? (auth.maintenanceMessage?.trim().isNotEmpty ?? false
              ? auth.maintenanceMessage!
              : 'Pengurus sedang merapikan dan meningkatkan AKUR agar makin nyaman dipakai. Silakan kembali sebentar lagi.')
        : 'AKUR belum dapat terhubung ke server. Mungkin koneksi internet Anda terputus atau server sedang dibenahi.';

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(20, 24, 20, 24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 480),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      const AkurLogo(size: 36),
                      const SizedBox(width: 10),
                      Text('AKUR', style: theme.textTheme.titleLarge?.copyWith(color: scheme.primary, fontWeight: FontWeight.w800)),
                    ],
                  ),
                  const SizedBox(height: 16),
                  AspectRatio(
                    aspectRatio: 4 / 3,
                    child: Semantics(
                      image: true,
                      label: maintenance ? 'Ilustrasi kampung yang sedang diperbaiki' : 'Ilustrasi kampung tanpa sambungan',
                      child: Stack(
                        children: [
                          const Positioned.fill(child: MaintenanceIllustration()),
                          Positioned(
                            left: 0,
                            top: 8,
                            child: _Badge(
                              icon: maintenance ? Icons.construction_rounded : Icons.cloud_off_rounded,
                              color: maintenance ? AkurColors.tertiary : scheme.secondary,
                              background: maintenance ? AkurColors.tertiaryFixed : AkurColors.secondaryContainer,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 8),
                  Center(
                    child: Pill(
                      maintenance ? 'Pemeliharaan terjadwal' : 'Gangguan koneksi',
                      tone: maintenance ? PillTone.tertiary : PillTone.secondary,
                      icon: maintenance ? Icons.schedule_rounded : Icons.wifi_off_rounded,
                    ),
                  ),
                  const SizedBox(height: 12),
                  Text(title, textAlign: TextAlign.center, style: theme.textTheme.headlineSmall),
                  const SizedBox(height: 8),
                  Text(
                    message,
                    textAlign: TextAlign.center,
                    style: theme.textTheme.bodyLarge?.copyWith(color: scheme.onSurfaceVariant),
                  ),
                  const SizedBox(height: 20),
                  InfoPanel(
                    children: [
                      _Hint(
                        icon: Icons.favorite_border_rounded,
                        text: maintenance
                            ? 'Data Anda aman. Iuran, surat, dan kegiatan akan kembali seperti semula.'
                            : 'Pastikan internet aktif. Data Anda tetap aman di server.',
                      ),
                      _Hint(icon: Icons.autorenew_rounded, text: 'Kami memeriksa ulang otomatis setiap 30 detik.'),
                    ],
                  ),
                  const SizedBox(height: 20),
                  FilledButton.icon(
                    onPressed: _checking ? null : _retry,
                    icon: _checking
                        ? const SizedBox.square(dimension: 20, child: CircularProgressIndicator(strokeWidth: 2.4))
                        : const Icon(Icons.refresh_rounded),
                    label: Text(_checking ? 'Memeriksa…' : 'Coba lagi'),
                  ),
                  if (auth.user != null) ...[
                    const SizedBox(height: 8),
                    TextButton.icon(onPressed: auth.signOut, icon: const Icon(Icons.logout), label: const Text('Keluar dari akun')),
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _Badge extends StatelessWidget {
  const _Badge({required this.icon, required this.color, required this.background});

  final IconData icon;
  final Color color;
  final Color background;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(10),
    decoration: BoxDecoration(
      color: background,
      shape: BoxShape.circle,
      border: Border.all(color: Colors.white, width: 3),
      boxShadow: akurCardShadow,
    ),
    child: Icon(icon, color: color, size: 28),
  );
}

class _Hint extends StatelessWidget {
  const _Hint({required this.icon, required this.text});

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, size: 20, color: theme.colorScheme.secondary),
        const SizedBox(width: 10),
        Expanded(child: Text(text, style: theme.textTheme.bodyMedium)),
      ],
    );
  }
}

/// Ilustrasi "kampung sedang dibenahi": rumah warga, palang jalan, kerucut, dan roda gigi.
class MaintenanceIllustration extends StatelessWidget {
  const MaintenanceIllustration({super.key});

  @override
  Widget build(BuildContext context) => ExcludeSemantics(child: CustomPaint(painter: _MaintenancePainter()));
}

class _MaintenancePainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    // Ruang gambar 240×180, dipusatkan dan diskalakan proporsional.
    final scale = math.min(size.width / 240, size.height / 180);
    canvas.save();
    canvas.translate((size.width - 240 * scale) / 2, (size.height - 180 * scale) / 2);
    canvas.scale(scale);

    Paint fill(Color c) => Paint()..color = c;
    final stroke = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2
      ..strokeJoin = StrokeJoin.round
      ..color = const Color(0xFF3B2A22).withValues(alpha: 0.85);

    // Latar: cakram biru muda, matahari, awan.
    canvas.drawCircle(const Offset(120, 92), 80, fill(AkurColors.secondaryContainer.withValues(alpha: 0.55)));
    canvas.drawCircle(const Offset(70, 46), 14, fill(const Color(0xFFFFD27A)));
    _cloud(canvas, const Offset(150, 34), 1.0);
    _cloud(canvas, const Offset(52, 78), 0.7);

    // Tanah.
    canvas.drawOval(Rect.fromCenter(center: const Offset(120, 162), width: 228, height: 26), fill(AkurColors.surfaceHigh));

    // Rumah kecil (kiri).
    _house(canvas, stroke, left: 42, top: 112, width: 50, roof: AkurColors.primaryContainer, door: AkurColors.secondary);
    // Rumah besar (kanan) dengan perancah.
    _house(canvas, stroke, left: 112, top: 96, width: 64, roof: AkurColors.primary, door: AkurColors.tertiary);
    final pole = Paint()
      ..color = const Color(0xFF8A7269)
      ..strokeWidth = 3
      ..strokeCap = StrokeCap.round;
    canvas.drawLine(const Offset(106, 158), const Offset(106, 92), pole);
    canvas.drawLine(const Offset(182, 158), const Offset(182, 92), pole);
    canvas.drawLine(const Offset(104, 112), const Offset(184, 112), pole);
    canvas.drawLine(const Offset(106, 112), const Offset(182, 158), pole..strokeWidth = 2);

    // Palang jalan bergaris.
    final board = RRect.fromLTRBR(30, 136, 112, 150, const Radius.circular(4));
    canvas.drawLine(const Offset(40, 150), const Offset(40, 166), pole..strokeWidth = 3);
    canvas.drawLine(const Offset(102, 150), const Offset(102, 166), pole);
    canvas.save();
    canvas.clipRRect(board);
    canvas.drawRRect(board, fill(Colors.white));
    final stripe = fill(AkurColors.primaryContainer);
    for (var x = 20.0; x < 120; x += 16) {
      canvas.drawPath(Path()..moveTo(x, 150)..lineTo(x + 8, 150)..lineTo(x + 20, 136)..lineTo(x + 12, 136)..close(), stripe);
    }
    canvas.restore();
    canvas.drawRRect(board, stroke);

    // Kerucut lalu lintas.
    canvas.drawRRect(RRect.fromLTRBR(186, 158, 218, 164, const Radius.circular(2)), fill(const Color(0xFFB4501F)));
    canvas.drawPath(Path()..moveTo(190, 158)..lineTo(200, 124)..lineTo(204, 124)..lineTo(214, 158)..close(), fill(const Color(0xFFE8792F)));
    canvas.drawPath(Path()..moveTo(194.5, 144)..lineTo(197, 136)..lineTo(207, 136)..lineTo(209.5, 144)..close(), fill(Colors.white));

    // Roda gigi melayang.
    _gear(canvas, const Offset(196, 62), 17, AkurColors.secondary);
    _gear(canvas, const Offset(220, 86), 10, AkurColors.primaryContainer);
    // Kilau kecil.
    for (final o in const [Offset(30, 110), Offset(214, 120), Offset(98, 30)]) {
      _sparkle(canvas, o, 5, const Color(0xFFFFB347));
    }
    canvas.restore();
  }

  void _house(Canvas canvas, Paint stroke, {required double left, required double top, required double width, required Color roof, required Color door}) {
    final right = left + width;
    final wall = Rect.fromLTRB(left, top, right, 160);
    canvas.drawRect(wall, Paint()..color = Colors.white);
    canvas.drawRect(wall, stroke);
    final roofPath = Path()
      ..moveTo(left - 7, top + 2)
      ..lineTo(left + width / 2, top - width * 0.48)
      ..lineTo(right + 7, top + 2)
      ..close();
    canvas.drawPath(roofPath, Paint()..color = roof);
    canvas.drawPath(roofPath, stroke);
    final doorW = width * 0.26;
    final doorRect = Rect.fromLTWH(left + width / 2 - doorW / 2, 160 - width * 0.46, doorW, width * 0.46);
    canvas.drawRRect(RRect.fromRectAndCorners(doorRect, topLeft: const Radius.circular(4), topRight: const Radius.circular(4)), Paint()..color = door);
    final win = Paint()..color = AkurColors.secondaryContainer;
    final w = width * 0.18;
    canvas.drawRRect(RRect.fromLTRBR(left + 6, top + 10, left + 6 + w, top + 10 + w, const Radius.circular(3)), win);
    canvas.drawRRect(RRect.fromLTRBR(right - 6 - w, top + 10, right - 6, top + 10 + w, const Radius.circular(3)), win);
  }

  void _cloud(Canvas canvas, Offset c, double s) {
    final p = Paint()..color = Colors.white.withValues(alpha: 0.95);
    canvas.drawCircle(c + Offset(-14 * s, 4 * s), 9 * s, p);
    canvas.drawCircle(c, 13 * s, p);
    canvas.drawCircle(c + Offset(14 * s, 4 * s), 9 * s, p);
    canvas.drawRRect(RRect.fromLTRBR(c.dx - 22 * s, c.dy + 2 * s, c.dx + 22 * s, c.dy + 13 * s, Radius.circular(6 * s)), p);
  }

  void _gear(Canvas canvas, Offset c, double r, Color color) {
    final p = Paint()..color = color;
    canvas.save();
    canvas.translate(c.dx, c.dy);
    for (var i = 0; i < 8; i++) {
      canvas.drawRRect(RRect.fromLTRBR(-r * 0.2, -r * 1.25, r * 0.2, -r * 0.6, Radius.circular(r * 0.08)), p);
      canvas.rotate(math.pi / 4);
    }
    canvas.restore();
    canvas.drawCircle(c, r, p);
    canvas.drawCircle(c, r * 0.4, Paint()..color = Colors.white);
  }

  void _sparkle(Canvas canvas, Offset c, double r, Color color) {
    canvas.drawPath(
      Path()
        ..moveTo(c.dx, c.dy - r)
        ..quadraticBezierTo(c.dx, c.dy, c.dx + r, c.dy)
        ..quadraticBezierTo(c.dx, c.dy, c.dx, c.dy + r)
        ..quadraticBezierTo(c.dx, c.dy, c.dx - r, c.dy)
        ..quadraticBezierTo(c.dx, c.dy, c.dx, c.dy - r)
        ..close(),
      Paint()..color = color,
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
