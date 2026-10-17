import 'package:flutter/material.dart';

/// Sistem desain terracotta AKUR — identitas baru, bukan turunan brand Rukunin.
class AkurColors {
  static const terracotta = Color(0xFFB85C38);
  static const terracottaDeep = Color(0xFF8E4127);
  static const clay = Color(0xFFE9C9B4);
  static const sand = Color(0xFFFBF5EF);
  static const olive = Color(0xFF5C6B3C);
  static const ink = Color(0xFF2B211C);
}

class AkurTheme {
  static ThemeData light() => _build(
        ColorScheme.fromSeed(
          seedColor: AkurColors.terracotta,
          primary: AkurColors.terracotta,
          secondary: AkurColors.olive,
          surface: AkurColors.sand,
          onSurface: AkurColors.ink,
        ),
      );

  static ThemeData dark() => _build(
        ColorScheme.fromSeed(
          seedColor: AkurColors.terracotta,
          brightness: Brightness.dark,
          primary: const Color(0xFFE8A07F),
          secondary: const Color(0xFFB9C78F),
        ),
      );

  static ThemeData _build(ColorScheme scheme) {
    final shape = RoundedRectangleBorder(borderRadius: BorderRadius.circular(14));
    return ThemeData(
      colorScheme: scheme,
      useMaterial3: true,
      scaffoldBackgroundColor: scheme.surface,
      appBarTheme: AppBarTheme(
        backgroundColor: scheme.surface,
        foregroundColor: scheme.onSurface,
        elevation: 0,
        centerTitle: false,
      ),
      cardTheme: CardThemeData(
        elevation: 0,
        shape: shape.copyWith(side: BorderSide(color: scheme.outlineVariant)),
        margin: EdgeInsets.zero,
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size(64, 48),
          shape: shape,
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(minimumSize: const Size(64, 48), shape: shape),
      ),
      inputDecorationTheme: InputDecorationTheme(
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
        filled: true,
        fillColor: scheme.surfaceContainerHighest.withValues(alpha: 0.4),
      ),
    );
  }
}
