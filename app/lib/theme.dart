import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

/// Sistem desain "Harmoni Warga": terracotta hangat + teal tenang, huruf Plus Jakarta Sans,
/// kartu lembut bersudut 18 px, target sentuh besar (min. 48–56 px) agar nyaman untuk semua usia.
class AkurColors {
  static const primary = Color(0xFF9D3E0E);
  static const primaryContainer = Color(0xFFBD5625);
  static const primaryFixed = Color(0xFFFFDBCD);
  static const secondary = Color(0xFF1B6870);
  static const secondaryContainer = Color(0xFFA6EBF5);
  static const onSecondaryContainer = Color(0xFF216C75);
  static const tertiary = Color(0xFF884E00);
  static const tertiaryFixed = Color(0xFFFFDCBF);
  static const surface = Color(0xFFF4FAFF);
  static const surfaceLow = Color(0xFFEAF5FD);
  static const surfaceContainer = Color(0xFFE4F0F8);
  static const surfaceHigh = Color(0xFFDEEAF2);
  static const ink = Color(0xFF121D23);
  static const inkVariant = Color(0xFF56423A);
  static const outline = Color(0xFF8A7269);
  static const outlineVariant = Color(0xFFDDC0B6);

  /// Pita pengumuman penting (madu lembut) dan aksen garis kirinya.
  static const notice = Color(0xFFFDF3E7);
}

class AkurTheme {
  static const radiusCard = 18.0;
  static const radiusButton = 14.0;

  static ThemeData light() => _build(
    const ColorScheme(
      brightness: Brightness.light,
      primary: AkurColors.primary,
      onPrimary: Colors.white,
      primaryContainer: AkurColors.primaryFixed,
      onPrimaryContainer: Color(0xFF7E2C00),
      primaryFixed: AkurColors.primaryFixed,
      secondary: AkurColors.secondary,
      onSecondary: Colors.white,
      secondaryContainer: AkurColors.secondaryContainer,
      onSecondaryContainer: AkurColors.onSecondaryContainer,
      tertiary: AkurColors.tertiary,
      onTertiary: Colors.white,
      tertiaryContainer: AkurColors.tertiaryFixed,
      onTertiaryContainer: Color(0xFF6A3B00),
      error: Color(0xFFBA1A1A),
      onError: Colors.white,
      errorContainer: Color(0xFFFFDAD6),
      onErrorContainer: Color(0xFF93000A),
      surface: AkurColors.surface,
      onSurface: AkurColors.ink,
      onSurfaceVariant: AkurColors.inkVariant,
      surfaceContainerLowest: Colors.white,
      surfaceContainerLow: AkurColors.surfaceLow,
      surfaceContainer: AkurColors.surfaceContainer,
      surfaceContainerHigh: AkurColors.surfaceHigh,
      surfaceContainerHighest: Color(0xFFD8E4EC),
      outline: AkurColors.outline,
      outlineVariant: AkurColors.outlineVariant,
      inverseSurface: Color(0xFF273238),
      onInverseSurface: Color(0xFFE7F2FA),
      inversePrimary: Color(0xFFFFB597),
    ),
  );

  static ThemeData dark() => _build(
    ColorScheme.fromSeed(
      seedColor: AkurColors.primaryContainer,
      brightness: Brightness.dark,
      primary: const Color(0xFFFFB597),
      secondary: const Color(0xFF8DD2DB),
    ),
  );

  static ThemeData _build(ColorScheme scheme) {
    final light = scheme.brightness == Brightness.light;
    final base = ThemeData(colorScheme: scheme, useMaterial3: true, brightness: scheme.brightness);
    // Ukuran huruf minimum 16 px untuk teks isi (aksesibilitas lansia).
    final text = GoogleFonts.plusJakartaSansTextTheme(base.textTheme)
        .copyWith(
          headlineMedium: GoogleFonts.plusJakartaSans(fontSize: 26, fontWeight: FontWeight.w700, height: 34 / 26),
          headlineSmall: GoogleFonts.plusJakartaSans(fontSize: 22, fontWeight: FontWeight.w700, height: 30 / 22),
          titleLarge: GoogleFonts.plusJakartaSans(fontSize: 19, fontWeight: FontWeight.w600, height: 26 / 19),
          titleMedium: GoogleFonts.plusJakartaSans(fontSize: 17, fontWeight: FontWeight.w600, height: 24 / 17),
          titleSmall: GoogleFonts.plusJakartaSans(fontSize: 15, fontWeight: FontWeight.w600, height: 20 / 15),
          bodyLarge: GoogleFonts.plusJakartaSans(fontSize: 17, height: 26 / 17),
          bodyMedium: GoogleFonts.plusJakartaSans(fontSize: 15, height: 22 / 15),
          bodySmall: GoogleFonts.plusJakartaSans(fontSize: 13.5, fontWeight: FontWeight.w500, height: 19 / 13.5),
          labelLarge: GoogleFonts.plusJakartaSans(fontSize: 16, fontWeight: FontWeight.w700),
          labelMedium: GoogleFonts.plusJakartaSans(fontSize: 13, fontWeight: FontWeight.w600, letterSpacing: 0.2),
        )
        .apply(bodyColor: scheme.onSurface, displayColor: scheme.onSurface);
    final buttonShape = RoundedRectangleBorder(borderRadius: BorderRadius.circular(radiusButton));
    final cardBorder = BorderSide(color: scheme.onSurface.withValues(alpha: 0.08));

    return base.copyWith(
      textTheme: text,
      scaffoldBackgroundColor: scheme.surface,
      appBarTheme: AppBarTheme(
        backgroundColor: scheme.surface,
        foregroundColor: scheme.onSurface,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        scrolledUnderElevation: 1,
        centerTitle: false,
        titleTextStyle: text.titleLarge?.copyWith(fontWeight: FontWeight.w700, color: scheme.onSurface),
      ),
      cardTheme: CardThemeData(
        elevation: 0,
        color: light ? Colors.white : scheme.surfaceContainer,
        surfaceTintColor: Colors.transparent,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(radiusCard), side: cardBorder),
        margin: EdgeInsets.zero,
      ),
      listTileTheme: ListTileThemeData(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(radiusCard)),
        minVerticalPadding: 12,
        titleTextStyle: text.titleSmall?.copyWith(fontSize: 16),
        subtitleTextStyle: text.bodyMedium?.copyWith(color: scheme.onSurfaceVariant),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size(64, 52),
          shape: buttonShape,
          textStyle: text.labelLarge,
          backgroundColor: light ? AkurColors.primaryContainer : null,
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size(64, 52),
          shape: buttonShape,
          textStyle: text.labelLarge,
          side: BorderSide(color: scheme.secondary, width: 1.5),
          foregroundColor: scheme.secondary,
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(minimumSize: const Size(48, 48), textStyle: text.titleSmall, foregroundColor: scheme.secondary),
      ),
      floatingActionButtonTheme: FloatingActionButtonThemeData(
        backgroundColor: light ? AkurColors.primaryContainer : scheme.primary,
        foregroundColor: light ? Colors.white : scheme.onPrimary,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(radiusButton)),
        extendedTextStyle: text.labelLarge,
      ),
      chipTheme: ChipThemeData(
        shape: const StadiumBorder(),
        side: BorderSide(color: scheme.outlineVariant),
        labelStyle: text.bodySmall?.copyWith(fontWeight: FontWeight.w600),
        backgroundColor: light ? Colors.white : scheme.surfaceContainer,
        selectedColor: scheme.secondary,
        secondaryLabelStyle: text.bodySmall?.copyWith(fontWeight: FontWeight.w700, color: scheme.onSecondary),
        checkmarkColor: scheme.onSecondary,
      ),
      segmentedButtonTheme: SegmentedButtonThemeData(
        style: SegmentedButton.styleFrom(
          minimumSize: const Size(48, 48),
          selectedBackgroundColor: scheme.secondary,
          selectedForegroundColor: scheme.onSecondary,
          textStyle: text.titleSmall,
        ),
      ),
      navigationBarTheme: NavigationBarThemeData(
        height: 72,
        backgroundColor: light ? Colors.white : scheme.surfaceContainer,
        surfaceTintColor: Colors.transparent,
        indicatorColor: scheme.primaryContainer,
        labelTextStyle: WidgetStateProperty.resolveWith(
          (states) => text.labelMedium?.copyWith(
            color: states.contains(WidgetState.selected) ? scheme.primary : scheme.onSurfaceVariant,
            fontWeight: states.contains(WidgetState.selected) ? FontWeight.w700 : FontWeight.w600,
          ),
        ),
        iconTheme: WidgetStateProperty.resolveWith(
          (states) => IconThemeData(color: states.contains(WidgetState.selected) ? scheme.primary : scheme.onSurfaceVariant),
        ),
      ),
      tabBarTheme: TabBarThemeData(
        labelColor: scheme.primary,
        unselectedLabelColor: scheme.onSurfaceVariant,
        indicatorColor: scheme.primary,
        labelStyle: text.titleSmall,
        unselectedLabelStyle: text.titleSmall,
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: light ? const Color(0xFFF4F0E8) : scheme.surfaceContainerHigh,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
        labelStyle: text.bodyLarge?.copyWith(color: scheme.onSurfaceVariant),
        floatingLabelStyle: text.titleSmall?.copyWith(color: scheme.onSurface),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(radiusButton),
          borderSide: const BorderSide(color: Color(0xFFD4CCC0), width: 1.5),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(radiusButton),
          borderSide: BorderSide(color: light ? const Color(0xFFD4CCC0) : scheme.outline, width: 1.5),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(radiusButton),
          borderSide: BorderSide(color: scheme.primary, width: 2),
        ),
      ),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(radiusButton)),
        contentTextStyle: text.bodyMedium?.copyWith(color: scheme.onInverseSurface),
      ),
      dividerTheme: DividerThemeData(color: scheme.onSurface.withValues(alpha: 0.08), space: 1),
    );
  }
}
