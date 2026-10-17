import 'package:flutter/material.dart';

import '../auth/auth_controller.dart';
import '../models/current_user.dart';
import '../widgets/akur_logo.dart';
import '../widgets/akur_ui.dart';
import 'beranda_screen.dart';
import 'community/activities_screen.dart';
import 'documents/document_requests_screen.dart';
import 'finance/my_dues_screen.dart';
import 'notifications_screen.dart';

/// Kerangka utama setelah login: header AKUR (unit, notifikasi, profil) dan navigasi bawah
/// Beranda · Iuran · Surat · Kegiatan. Menu pengurus ada di Beranda.
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  int _tab = 0;
  final _notifications = GlobalKey<NotificationBellState>();

  void _go(int tab) => setState(() => _tab = tab);

  @override
  Widget build(BuildContext context) {
    final auth = widget.auth;
    final user = auth.user!;
    final tabs = [
      BerandaScreen(auth: auth, onNavigate: _go),
      MyDuesScreen(api: auth.api, user: user, embedded: true),
      DocumentRequestsScreen(api: auth.api, embedded: true),
      ActivitiesScreen(api: auth.api, user: user, embedded: true),
    ];
    return Scaffold(
      appBar: AkurTopBar(user: user, auth: auth, bellKey: _notifications),
      body: IndexedStack(index: _tab, children: tabs),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tab,
        onDestinationSelected: _go,
        destinations: const [
          NavigationDestination(icon: Icon(Icons.home_outlined), selectedIcon: Icon(Icons.home_rounded), label: 'Beranda'),
          NavigationDestination(icon: Icon(Icons.payments_outlined), selectedIcon: Icon(Icons.payments_rounded), label: 'Iuran'),
          NavigationDestination(icon: Icon(Icons.description_outlined), selectedIcon: Icon(Icons.description_rounded), label: 'Surat'),
          NavigationDestination(icon: Icon(Icons.event_outlined), selectedIcon: Icon(Icons.event_rounded), label: 'Kegiatan'),
        ],
      ),
    );
  }
}

/// Unit utama pengguna untuk header ("RT 005 / RW 006"): RT didahulukan oleh server.
UnitRef? primaryUnitOf(CurrentUser user) => user.memberships.isEmpty ? null : user.memberships.first.unit;

/// Header AKUR: logo, nama aplikasi + unit, lonceng notifikasi, dan avatar (menu profil).
class AkurTopBar extends StatelessWidget implements PreferredSizeWidget {
  const AkurTopBar({super.key, required this.user, required this.auth, this.bellKey});

  final CurrentUser user;
  final AuthController auth;
  final GlobalKey<NotificationBellState>? bellKey;

  @override
  Size get preferredSize => const Size.fromHeight(68);

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final unit = primaryUnitOf(user);
    final roles = {for (final m in user.memberships.where((m) => !m.inherited)) m.roleName}.join(' · ');
    return AppBar(
      toolbarHeight: 68,
      titleSpacing: 16,
      title: Row(
        children: [
          const AkurLogo(size: 44),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text.rich(
                  TextSpan(
                    children: [
                      TextSpan(
                        text: 'AKUR',
                        style: theme.textTheme.titleLarge?.copyWith(color: scheme.primary, fontWeight: FontWeight.w800),
                      ),
                      if (unit != null)
                        TextSpan(
                          text: '  •  ${unit.name}',
                          style: theme.textTheme.labelMedium?.copyWith(color: scheme.secondary, fontWeight: FontWeight.w700),
                        ),
                    ],
                  ),
                  overflow: TextOverflow.ellipsis,
                ),
                if (roles.isNotEmpty)
                  Text(
                    roles,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant),
                  ),
              ],
            ),
          ),
        ],
      ),
      actions: [
        NotificationBell(key: bellKey, auth: auth),
        Padding(
          padding: const EdgeInsets.only(right: 12, left: 4),
          child: Semantics(
            button: true,
            label: 'Profil dan keluar',
            child: InkWell(
              customBorder: const CircleBorder(),
              onTap: () => showProfileSheet(context, auth),
              child: UserAvatar(user: user, size: 40),
            ),
          ),
        ),
      ],
    );
  }
}

class UserAvatar extends StatelessWidget {
  const UserAvatar({super.key, required this.user, this.size = 40});

  final CurrentUser user;
  final double size;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final name = user.residentName ?? user.displayName;
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: scheme.primaryContainer,
        border: Border.all(color: Colors.white, width: 2),
        boxShadow: akurCardShadow,
      ),
      clipBehavior: Clip.antiAlias,
      alignment: Alignment.center,
      child: user.avatarUrl != null
          ? Image.network(
              user.avatarUrl!,
              width: size,
              height: size,
              fit: BoxFit.cover,
              errorBuilder: (_, _, _) => _initials(context, name),
            )
          : _initials(context, name),
    );
  }

  Widget _initials(BuildContext context, String name) =>
      Text(initialsOf(name), style: Theme.of(context).textTheme.titleSmall?.copyWith(color: Theme.of(context).colorScheme.primary));
}

/// Lembar profil: nama, email, peran per unit, dan tombol keluar.
Future<void> showProfileSheet(BuildContext context, AuthController auth) {
  final user = auth.user!;
  return showModalBottomSheet<void>(
    context: context,
    showDragHandle: true,
    isScrollControlled: true,
    builder: (context) {
      final theme = Theme.of(context);
      return SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  UserAvatar(user: user, size: 56),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(user.residentName ?? user.displayName, style: theme.textTheme.titleLarge),
                        if (user.email != null)
                          Text(user.email!, style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
                      ],
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 16),
              Text('Peran Anda', style: theme.textTheme.titleSmall),
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final m in user.memberships.where((m) => !m.inherited))
                    Pill('${m.roleName} · ${m.unit.name}', tone: PillTone.secondary),
                ],
              ),
              const SizedBox(height: 24),
              OutlinedButton.icon(
                onPressed: () {
                  Navigator.pop(context);
                  auth.signOut();
                },
                icon: const Icon(Icons.logout),
                label: const Text('Keluar'),
              ),
            ],
          ),
        ),
      );
    },
  );
}
