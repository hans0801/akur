import 'package:flutter/material.dart';

import '../api/api_client.dart';

/// Pesan galat yang aman ditampilkan ke pengguna (tanpa stack trace atau detail teknis).
String friendlyError(Object? error) {
  if (error is ApiException) {
    return switch (error.statusCode) {
      0 => error.message,
      403 => error.message.isNotEmpty ? error.message : 'Anda tidak memiliki akses.',
      >= 500 => 'Server sedang bermasalah. Coba lagi beberapa saat lagi.',
      _ => error.message,
    };
  }
  return 'Terjadi kesalahan tak terduga. Coba lagi.';
}

/// Tampilan galat seragam dengan tombol coba lagi. Bisa digulir agar tetap bekerja di dalam RefreshIndicator.
class ErrorView extends StatelessWidget {
  const ErrorView({super.key, required this.error, this.onRetry});

  final Object? error;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final offline = error is ApiException && (error as ApiException).statusCode == 0;
    return LayoutBuilder(
      builder: (context, constraints) => SingleChildScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
        child: ConstrainedBox(
          constraints: BoxConstraints(minHeight: constraints.maxHeight),
          child: Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(offline ? Icons.wifi_off : Icons.error_outline, size: 48, color: theme.colorScheme.error, semanticLabel: 'Galat'),
                  const SizedBox(height: 12),
                  Text(friendlyError(error), textAlign: TextAlign.center, style: theme.textTheme.bodyLarge),
                  if (onRetry != null) ...[
                    const SizedBox(height: 16),
                    FilledButton.tonalIcon(onPressed: onRetry, icon: const Icon(Icons.refresh), label: const Text('Coba lagi')),
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

/// Membatasi lebar konten di layar lebar (web/tablet) agar tetap nyaman dibaca; di ponsel tidak berpengaruh.
class ResponsiveFrame extends StatelessWidget {
  const ResponsiveFrame({super.key, required this.child, this.maxWidth = 840});

  final Widget child;
  final double maxWidth;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(builder: (context, constraints) {
      if (constraints.maxWidth <= maxWidth) return child;
      return ColoredBox(
        color: Theme.of(context).colorScheme.surfaceContainerLow,
        child: Center(
          child: ConstrainedBox(
            constraints: BoxConstraints(maxWidth: maxWidth),
            child: Material(elevation: 1, child: child),
          ),
        ),
      );
    });
  }
}
