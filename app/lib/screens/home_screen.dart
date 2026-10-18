import 'package:flutter/material.dart';

import '../auth/auth_controller.dart';
import '../models/current_user.dart';
import '../theme.dart';
import '../widgets/akur_logo.dart';
import '../widgets/akur_ui.dart';
import '../widgets/avatars.dart';
import 'beranda_screen.dart';
import 'community/activities_screen.dart';
import 'documents/document_requests_screen.dart';
import 'finance/my_dues_screen.dart';
import 'notifications_screen.dart';
import 'settings_screen.dart';

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
      body: Column(
        children: [
          // Super admin tetap bisa memakai aplikasi saat pemeliharaan; ingatkan bahwa mode ini aktif.
          if (auth.maintenanceActive) _MaintenanceStrip(onTap: () => openSettings(context, auth)),
          Expanded(child: IndexedStack(index: _tab, children: tabs)),
        ],
      ),
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

class _MaintenanceStrip extends StatelessWidget {
  const _MaintenanceStrip({required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Material(
      color: AkurColors.tertiaryFixed,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
          child: Row(
            children: [
              const Icon(Icons.construction_rounded, color: AkurColors.tertiary, size: 20),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  'Mode pemeliharaan aktif — pengguna lain melihat halaman pemeliharaan.',
                  style: theme.textTheme.bodySmall?.copyWith(color: const Color(0xFF6A3B00), fontWeight: FontWeight.w700),
                ),
              ),
              const Icon(Icons.chevron_right_rounded, color: AkurColors.tertiary),
            ],
          ),
        ),
      ),
    );
  }
}

/// Membuka Pengaturan aplikasi (avatar, dan mode pemeliharaan untuk super admin).
Future<void> openSettings(BuildContext context, AuthController auth) =>
    Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => SettingsScreen(auth: auth)));

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
            label: 'Profil, pengaturan, dan keluar',
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

/// Avatar akun pengguna yang sedang login (ilustrasi pilihan, foto Google, atau inisial).
class UserAvatar extends StatelessWidget {
  const UserAvatar({super.key, required this.user, this.size = 40});

  final CurrentUser user;
  final double size;

  @override
  Widget build(BuildContext context) => AccountAvatar(
    choice: user.avatarChoice,
    seed: user.id,
    name: user.residentName ?? user.displayName,
    photoUrl: user.avatarUrl,
    size: size,
  );
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
              FilledButton.icon(
                onPressed: () {
                  Navigator.pop(context);
                  openSettings(context, auth);
                },
                icon: const Icon(Icons.settings_outlined),
                label: const Text('Pengaturan aplikasi'),
              ),
              const SizedBox(height: 10),
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
