import 'package:flutter/material.dart';
import 'package:google_sign_in_web/web_only.dart' as gsi;

/// Web: Google Identity Services mewajibkan tombol resmi yang dirender GIS.
/// Hasil login dikirim lewat GoogleSignIn.authenticationEvents.
Widget buildGoogleSignInButton({required VoidCallback onPressed}) {
  return gsi.renderButton(
    configuration: gsi.GSIButtonConfiguration(
      text: gsi.GSIButtonText.signinWith,
      shape: gsi.GSIButtonShape.pill,
      size: gsi.GSIButtonSize.large,
      locale: 'id',
    ),
  );
}
