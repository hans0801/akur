import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../auth/auth_controller.dart';
import '../models/current_user.dart';
import '../theme.dart';
import '../widgets/akur_ui.dart';
import '../widgets/async_view.dart';
import '../widgets/avatars.dart';

/// Pengaturan aplikasi: avatar akun (semua pengguna) dan mode pemeliharaan (hanya super_admin).
class SettingsScreen extends StatelessWidget {
  const SettingsScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Pengaturan aplikasi')),
      body: ListenableBuilder(
        listenable: auth,
        builder: (context, _) {
          final user = auth.user;
          if (user == null) return const SizedBox.shrink();
          return ListView(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
            children: [
              _ProfileHeader(user: user),
              const SizedBox(height: 12),
              const SectionHeader('Avatar akun', dot: true),
              AvatarPicker(auth: auth),
              if (user.isSuperAdmin) ...[
                const SizedBox(height: 12),
                const SectionHeader('Pemeliharaan', dot: true),
                MaintenanceToggleCard(auth: auth),
              ],
              const SizedBox(height: 12),
              const SectionHeader('Akun', dot: true),
              AkurCard(
                padding: EdgeInsets.zero,
                child: ListTile(
                  leading: Icon(Icons.logout, color: Theme.of(context).colorScheme.primary),
                  title: const Text('Keluar'),
                  subtitle: const Text('Akhiri sesi di perangkat ini'),
                  onTap: () {
                    Navigator.of(context).popUntil((r) => r.isFirst);
                    auth.signOut();
                  },
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _ProfileHeader extends StatelessWidget {
  const _ProfileHeader({required this.user});

  final CurrentUser user;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final name = user.residentName ?? user.displayName;
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(AkurTheme.radiusCard),
        gradient: const LinearGradient(colors: [AkurColors.primaryFixed, AkurColors.secondaryContainer]),
      ),
      child: Row(
        children: [
          AccountAvatar(choice: user.avatarChoice, seed: user.id, name: name, photoUrl: user.avatarUrl, size: 72),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(name, style: theme.textTheme.titleLarge, maxLines: 2, overflow: TextOverflow.ellipsis),
                if (user.email != null)
                  Text(user.email!, style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
                if (user.isSuperAdmin) ...[
                  const SizedBox(height: 6),
                  const Pill('Super Admin', tone: PillTone.primary, icon: Icons.verified_user_outlined),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Pilihan avatar: 8 ilustrasi warga, foto Google (bila ada), atau inisial. Tersimpan di server.
class AvatarPicker extends StatefulWidget {
  const AvatarPicker({super.key, required this.auth});

  final AuthController auth;

  @override
  State<AvatarPicker> createState() => _AvatarPickerState();
}

class _AvatarPickerState extends State<AvatarPicker> {
  String? _saving;

  Future<void> _choose(String choice) async {
    final messenger = ScaffoldMessenger.of(context);
    setState(() => _saving = choice);
    try {
      final json = await widget.auth.api.put('/me/avatar', {'avatar_choice': choice});
      widget.auth.updateUser(CurrentUser.fromJson(json['data'] as Map<String, dynamic>));
      messenger.showSnackBar(const SnackBar(content: Text('Avatar diperbarui.')));
    } catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(friendlyError(e))));
    } finally {
      if (mounted) setState(() => _saving = null);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final user = widget.auth.user!;
    final name = user.residentName ?? user.displayName;
    // Belum memilih: avatar bawaan (ilustrasi tetap per akun) ditandai terpilih.
    final current = user.avatarChoice ?? defaultAvatarFor(user.id).id;
    final options = <(String, String, Widget)>[
      for (final p in avatarPresets) (p.id, p.label, AvatarArt(preset: p, size: 64)),
      if (user.avatarUrl != null)
        ('google', 'Foto Google', AccountAvatar(choice: 'google', seed: user.id, name: name, photoUrl: user.avatarUrl, size: 64, ring: false)),
      ('initials', 'Inisial nama', AccountAvatar(choice: 'initials', seed: user.id, name: name, size: 64, ring: false)),
    ];
    return AkurCard(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Pilih avatar yang tampil di pojok kanan atas dan di beranda.',
            style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant),
          ),
          const SizedBox(height: 14),
          Wrap(
            spacing: 12,
            runSpacing: 14,
            children: [
              for (final (id, label, art) in options)
                _AvatarOption(
                  label: label,
                  selected: current == id,
                  busy: _saving == id,
                  onTap: _saving == null && current != id ? () => _choose(id) : null,
                  child: art,
                ),
            ],
          ),
        ],
      ),
    );
  }
}

class _AvatarOption extends StatelessWidget {
  const _AvatarOption({required this.label, required this.selected, required this.busy, required this.onTap, required this.child});

  final String label;
  final bool selected;
  final bool busy;
  final VoidCallback? onTap;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return Semantics(
      button: true,
      selected: selected,
      label: 'Avatar $label',
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(16),
        child: SizedBox(
          width: 84,
          child: Column(
            children: [
              Stack(
                clipBehavior: Clip.none,
                children: [
                  AnimatedContainer(
                    duration: const Duration(milliseconds: 180),
                    padding: const EdgeInsets.all(3),
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      border: Border.all(color: selected ? scheme.secondary : scheme.outlineVariant.withValues(alpha: 0.6), width: selected ? 3 : 1.5),
                    ),
                    child: ClipOval(
                      child: SizedBox.square(
                        dimension: 64,
                        child: ColoredBox(color: scheme.primaryContainer, child: Center(child: child)),
                      ),
                    ),
                  ),
                  if (selected)
                    Positioned(
                      right: -2,
                      bottom: -2,
                      child: Container(
                        decoration: BoxDecoration(color: scheme.secondary, shape: BoxShape.circle, border: Border.all(color: Colors.white, width: 2)),
                        padding: const EdgeInsets.all(2),
                        child: const Icon(Icons.check_rounded, size: 16, color: Colors.white),
                      ),
                    ),
                  if (busy)
                    const Positioned.fill(child: Center(child: CircularProgressIndicator(strokeWidth: 3))),
                ],
              ),
              const SizedBox(height: 6),
              Text(
                label,
                textAlign: TextAlign.center,
                maxLines: 2,
                style: theme.textTheme.bodySmall?.copyWith(
                  fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
                  color: selected ? scheme.secondary : scheme.onSurfaceVariant,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Saklar mode pemeliharaan (super_admin). Saat aktif, pengguna lain melihat halaman pemeliharaan.
class MaintenanceToggleCard extends StatefulWidget {
  const MaintenanceToggleCard({super.key, required this.auth});

  final AuthController auth;

  @override
  State<MaintenanceToggleCard> createState() => _MaintenanceToggleCardState();
}

class _MaintenanceToggleCardState extends State<MaintenanceToggleCard> {
  final _message = TextEditingController();
  bool? _enabled;
  bool _saving = false;
  Object? _error;

  ApiClient get _api => widget.auth.api;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _message.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final json = await _api.get('/admin/settings');
      _apply((json['data'] as Map<String, dynamic>)['maintenance'] as Map<String, dynamic>);
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  void _apply(Map<String, dynamic> maintenance) {
    if (!mounted) return;
    setState(() {
      _enabled = maintenance['enabled'] == true;
      _message.text = (maintenance['message'] as String?) ?? '';
    });
    widget.auth.setMaintenanceActive(_enabled!);
  }

  Future<void> _save(bool enabled) async {
    final messenger = ScaffoldMessenger.of(context);
    // Konfirmasi hanya saat menyalakan; "Simpan pesan" saat sudah aktif langsung disimpan.
    if (enabled && _enabled != true) {
      final ok = await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          icon: const Icon(Icons.construction_rounded),
          title: const Text('Aktifkan mode pemeliharaan?'),
          content: const Text(
            'Semua pengguna selain super admin akan melihat halaman pemeliharaan dan tidak dapat memakai AKUR sampai mode ini dimatikan.',
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Batal')),
            FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Aktifkan')),
          ],
        ),
      );
      if (ok != true) return;
    }
    setState(() => _saving = true);
    try {
      final json = await _api.put('/admin/settings/maintenance', {
        'enabled': enabled,
        'message': _message.text.trim().isEmpty ? null : _message.text.trim(),
      });
      _apply((json['data'] as Map<String, dynamic>)['maintenance'] as Map<String, dynamic>);
      messenger.showSnackBar(SnackBar(content: Text(enabled ? 'Mode pemeliharaan aktif.' : 'Mode pemeliharaan dimatikan.')));
    } catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(friendlyError(e))));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    if (_error != null) {
      return AkurCard(
        child: Column(
          children: [
            Text(friendlyError(_error), textAlign: TextAlign.center),
            const SizedBox(height: 8),
            TextButton.icon(onPressed: _load, icon: const Icon(Icons.refresh), label: const Text('Coba lagi')),
          ],
        ),
      );
    }
    if (_enabled == null) {
      return const AkurCard(child: Center(child: CircularProgressIndicator(semanticsLabel: 'Memuat')));
    }
    final on = _enabled!;
    return AkurCard(
      padding: const EdgeInsets.all(16),
      border: on ? Border.all(color: AkurColors.tertiary.withValues(alpha: 0.5), width: 1.5) : null,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              IconDisc(
                Icons.construction_rounded,
                background: on ? AkurColors.tertiaryFixed : scheme.surfaceContainer,
                color: on ? AkurColors.tertiary : scheme.secondary,
                size: 44,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Mode pemeliharaan', style: theme.textTheme.titleSmall),
                    Text(
                      on ? 'Aktif — hanya super admin yang bisa memakai AKUR.' : 'Nonaktif — AKUR terbuka untuk semua pengguna.',
                      style: theme.textTheme.bodySmall?.copyWith(color: on ? AkurColors.tertiary : scheme.onSurfaceVariant),
                    ),
                  ],
                ),
              ),
              Semantics(
                label: 'Mode pemeliharaan',
                child: Switch(value: on, onChanged: _saving ? null : _save),
              ),
            ],
          ),
          const SizedBox(height: 14),
          TextField(
            controller: _message,
            enabled: !_saving,
            maxLength: 300,
            maxLines: 2,
            decoration: const InputDecoration(
              labelText: 'Pesan untuk warga (opsional)',
              hintText: 'Mis. Pembaruan sistem, selesai pukul 22.00 WIB.',
            ),
          ),
          if (on)
            Align(
              alignment: Alignment.centerRight,
              child: TextButton.icon(
                onPressed: _saving ? null : () => _save(true),
                icon: const Icon(Icons.save_outlined),
                label: const Text('Simpan pesan'),
              ),
            ),
        ],
      ),
    );
  }
}
