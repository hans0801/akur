import 'package:flutter/material.dart';
import 'package:google_sign_in_web/web_only.dart' as gsi;

/// Web: Google Identity Services mewajibkan tombol resmi yang dirender GIS.
/// Hasil login dikirim lewat GoogleSignIn.authenticationEvents.
/// Dibuat lebar dengan logo di tengah agar mirip tombol putih di Android/iOS.
Widget buildGoogleSignInButton({required VoidCallback onPressed}) {
  return gsi.renderButton(
    configuration: gsi.GSIButtonConfiguration(
      theme: gsi.GSIButtonTheme.outline,
      text: gsi.GSIButtonText.signinWith,
      shape: gsi.GSIButtonShape.rectangular,
      size: gsi.GSIButtonSize.large,
      logoAlignment: gsi.GSIButtonLogoAlignment.center,
      minimumWidth: 340,
      locale: 'id',
    ),
  );
}
