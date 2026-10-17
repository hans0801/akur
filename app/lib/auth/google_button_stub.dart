import 'package:flutter/material.dart';

/// Android: tombol biasa yang memanggil GoogleSignIn.authenticate().
Widget buildGoogleSignInButton({required VoidCallback onPressed}) {
  return FilledButton.icon(
    onPressed: onPressed,
    icon: const Icon(Icons.login),
    label: const Text('Masuk dengan Google'),
  );
}
