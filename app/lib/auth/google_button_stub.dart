import 'dart:math' as math;

import 'package:flutter/material.dart';

/// Android/iOS: tombol putih bergaya Google yang memanggil GoogleSignIn.authenticate().
Widget buildGoogleSignInButton({required VoidCallback onPressed}) {
  return Builder(
    builder: (context) {
      final theme = Theme.of(context);
      return DecoratedBox(
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(16),
          boxShadow: const [BoxShadow(color: Color(0x0F253036), blurRadius: 16, offset: Offset(0, 4), spreadRadius: -2)],
        ),
        child: SizedBox(
          width: double.infinity,
          child: OutlinedButton(
            onPressed: onPressed,
            style: OutlinedButton.styleFrom(
              minimumSize: const Size(64, 60),
              backgroundColor: Colors.white,
              foregroundColor: const Color(0xFF121D23),
              side: BorderSide(color: theme.colorScheme.onSurface.withValues(alpha: 0.10), width: 1.5),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
              textStyle: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700),
            ),
            child: const Row(
              mainAxisSize: MainAxisSize.min,
              children: [GoogleLogo(size: 24), SizedBox(width: 14), Text('Masuk dengan Google')],
            ),
          ),
        ),
      );
    },
  );
}

/// Logo "G" Google empat warna, digambar tanpa aset.
class GoogleLogo extends StatelessWidget {
  const GoogleLogo({super.key, this.size = 24});

  final double size;

  @override
  Widget build(BuildContext context) => Semantics(
    label: 'Google',
    child: CustomPaint(size: Size.square(size), painter: _GoogleLogoPainter()),
  );
}

class _GoogleLogoPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final s = size.width;
    final stroke = s * 0.2;
    final rect = Rect.fromCircle(center: Offset(s / 2, s / 2), radius: (s - stroke) / 2);
    Paint arc(Color c) => Paint()
      ..color = c
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke;
    double deg(double d) => d * math.pi / 180;
    // Sudut dihitung searah jarum jam dari arah jam 3.
    canvas.drawArc(rect, deg(-40), deg(-95), false, arc(const Color(0xFFEA4335))); // merah: atas
    canvas.drawArc(rect, deg(-135), deg(-90), false, arc(const Color(0xFFFBBC05))); // kuning: kiri
    canvas.drawArc(rect, deg(135), deg(-90), false, arc(const Color(0xFF34A853))); // hijau: bawah
    canvas.drawArc(rect, deg(45), deg(-45), false, arc(const Color(0xFF4285F4))); // biru: kanan bawah
    // Palang biru "G".
    canvas.drawRect(Rect.fromLTWH(s / 2, s / 2 - stroke / 2, s / 2 - stroke * 0.1, stroke), Paint()..color = const Color(0xFF4285F4));
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
