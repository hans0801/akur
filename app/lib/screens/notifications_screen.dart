import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../auth/auth_controller.dart';
import '../theme.dart';
import '../widgets/akur_ui.dart';
import '../widgets/async_view.dart';
import 'population/population_common.dart';

/// Lonceng di header: titik merah bila ada notifikasi belum dibaca; membuka daftar notifikasi.
class NotificationBell extends StatefulWidget {
  const NotificationBell({super.key, required this.auth});

  final AuthController auth;

  @override
  State<NotificationBell> createState() => NotificationBellState();
}

class NotificationBellState extends State<NotificationBell> {
  bool _unread = false;

  @override
  void initState() {
    super.initState();
    refresh();
  }

  Future<void> refresh() async {
    try {
      final items = dataList(await widget.auth.api.get('/notifications'));
      if (mounted) setState(() => _unread = items.any((n) => n['read_at'] == null));
    } on ApiException {
      // Lonceng tetap bisa dibuka; titik merah hanya penanda.
    }
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return IconButton(
      tooltip: _unread ? 'Notifikasi (ada yang belum dibaca)' : 'Notifikasi',
      onPressed: () async {
        await Navigator.of(context).push(MaterialPageRoute(builder: (_) => NotificationsScreen(api: widget.auth.api)));
        refresh();
      },
      icon: Badge(
        isLabelVisible: _unread,
        smallSize: 10,
        backgroundColor: scheme.brightness == Brightness.light ? AkurColors.primaryContainer : scheme.primary,
        child: const Icon(Icons.notifications_none_rounded, size: 28),
      ),
    );
  }
}

class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key, required this.api});

  final ApiClient api;

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  late Future<List<Map<String, dynamic>>> _items = _load();

  Future<List<Map<String, dynamic>>> _load() async => dataList(await widget.api.get('/notifications'));

  void _reload() => setState(() { _items = _load(); });

  Future<void> _markRead(Map<String, dynamic> n) async {
    if (n['read_at'] != null) return;
    try {
      await widget.api.post('/notifications/${n['id']}/read');
      setState(() => n['read_at'] = DateTime.now().toIso8601String());
    } on ApiException {
      // Diabaikan: status baca bukan hal kritis.
    }
  }

  Future<void> _markAll(List<Map<String, dynamic>> items) async {
    for (final n in items.where((n) => n['read_at'] == null)) {
      await _markRead(n);
    }
  }

  IconData _icon(String? type) => switch (type?.split('.').first) {
    'payment' => Icons.payments_outlined,
    'document_request' => Icons.description_outlined,
    'activity' => Icons.event_outlined,
    'announcement' => Icons.campaign_outlined,
    'membership' || 'account' => Icons.verified_user_outlined,
    _ => Icons.notifications_none_rounded,
  };

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return FutureBuilder<List<Map<String, dynamic>>>(
      future: _items,
      builder: (context, snapshot) {
        final items = snapshot.data ?? const [];
        return Scaffold(
          appBar: AppBar(
            title: const Text('Notifikasi'),
            actions: [
              if (items.any((n) => n['read_at'] == null)) TextButton(onPressed: () => _markAll(items), child: const Text('Tandai dibaca')),
            ],
          ),
          body: switch (snapshot) {
            _ when snapshot.connectionState != ConnectionState.done => const Center(child: CircularProgressIndicator()),
            _ when snapshot.hasError => ErrorView(error: snapshot.error, onRetry: _reload),
            _ when items.isEmpty => const EmptyState('Belum ada notifikasi.'),
            _ => RefreshIndicator(
              onRefresh: () async => _reload(),
              child: ListView.separated(
                padding: const EdgeInsets.all(16),
                itemCount: items.length,
                separatorBuilder: (_, _) => const SizedBox(height: 10),
                itemBuilder: (_, i) {
                  final n = items[i];
                  final unread = n['read_at'] == null;
                  return AkurCard(
                    padding: const EdgeInsets.all(16),
                    onTap: () => _markRead(n),
                    color: unread ? theme.colorScheme.surfaceContainerLow : null,
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        IconDisc(_icon(n['type'] as String?), size: 44),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(n['title'] as String? ?? '-', style: theme.textTheme.titleSmall),
                              const SizedBox(height: 2),
                              Text(n['body'] as String? ?? '', style: theme.textTheme.bodyMedium),
                              const SizedBox(height: 4),
                              Text(
                                formatRelative(n['created_at'] as String?),
                                style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                              ),
                            ],
                          ),
                        ),
                        if (unread)
                          Container(
                            width: 10,
                            height: 10,
                            margin: const EdgeInsets.only(top: 6, left: 8),
                            decoration: BoxDecoration(color: theme.colorScheme.primary, shape: BoxShape.circle),
                          ),
                      ],
                    ),
                  );
                },
              ),
            ),
          },
        );
      },
    );
  }
}
