import 'package:flutter/material.dart';

/// Logo AKUR (rumah di tangan warga) dalam kartu putih bersudut bulat, sama seperti ikon aplikasi.
class AkurLogo extends StatelessWidget {
  const AkurLogo({super.key, this.size = 96});

  final double size;

  @override
  Widget build(BuildContext context) {
    final radius = BorderRadius.circular(size * 0.22);
    return Semantics(
      label: 'Logo AKUR',
      image: true,
      child: Material(
        elevation: 3,
        shadowColor: Colors.black26,
        borderRadius: radius,
        color: Colors.white,
        child: ClipRRect(
          borderRadius: radius,
          child: Image.asset(
            'assets/branding/akur_logo.png',
            width: size,
            height: size,
            filterQuality: FilterQuality.medium,
            excludeFromSemantics: true,
          ),
        ),
      ),
    );
  }
}
