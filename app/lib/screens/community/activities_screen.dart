import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../models/current_user.dart';
import '../../theme.dart';
import '../../widgets/akur_ui.dart';
import '../beranda_screen.dart';
import '../home_screen.dart';
import '../population/population_common.dart';
import 'activity_form_screen.dart';
import 'community_common.dart';
import '../../widgets/async_view.dart';

/// Agenda kegiatan warga: spanduk ringkas, filter kategori, agenda utama terdekat dengan konfirmasi
/// hadir/izin, dan jadwal agenda lainnya (akan datang / sudah lewat).
class ActivitiesScreen extends StatefulWidget {
  const ActivitiesScreen({super.key, required this.api, required this.user, this.embedded = false});

  final ApiClient api;
  final CurrentUser user;

  /// true bila tampil sebagai tab di kerangka utama (tanpa AppBar sendiri).
  final bool embedded;

  @override
  State<ActivitiesScreen> createState() => _ActivitiesScreenState();
}

class _ActivitiesScreenState extends State<ActivitiesScreen> {
  late final List<UnitRef> _manageUnits = widget.user.communityManageUnits;
  String _when = 'upcoming';
  String? _category;
  late Future<List<Map<String, dynamic>>> _items = _load();

  Future<List<Map<String, dynamic>>> _load() async => dataList(await widget.api.get('/activities?when=$_when'));

  void _reload() => setState(() { _items = _load(); });

  Future<void> _create() async {
    final saved = await Navigator.of(context).push<bool>(
      MaterialPageRoute(
        builder: (_) => ActivityFormScreen(api: widget.api, units: _manageUnits),
      ),
    );
    if (saved == true) _reload();
  }

  Future<void> _open(Map<String, dynamic> a) async {
    await Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => ActivityDetailScreen(api: widget.api, activityId: a['id'] as String, units: _manageUnits),
      ),
    );
    _reload();
  }

  Future<void> _rsvp(Map<String, dynamic> a, String status) async {
    final ok = await runApi(
      context,
      () => widget.api.put('/activities/${a['id']}/participation', {'status': status}),
      status == 'going' ? 'Terima kasih! Kehadiran Anda tercatat.' : 'Izin berhalangan tercatat.',
    );
    if (ok) _reload();
  }

  @override
  Widget build(BuildContext context) {
    final body = FutureBuilder<List<Map<String, dynamic>>>(
      future: _items,
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
        if (snapshot.hasError) return ErrorView(error: snapshot.error, onRetry: _reload);
        final all = snapshot.data!;
        final items = _category == null ? all : all.where((a) => a['category'] == _category).toList();
        final featured = _when == 'upcoming' ? items.where((a) => a['status'] == 'planned').firstOrNull : null;
        final rest = items.where((a) => a != featured).toList();
        return RefreshIndicator(
          onRefresh: () async => _reload(),
          child: ListView(
            padding: const EdgeInsets.fromLTRB(0, 8, 0, 96),
            children: [
              Padding(padding: const EdgeInsets.symmetric(horizontal: 20), child: _banner(all)),
              const SizedBox(height: 16),
              _categoryChips(),
              const SizedBox(height: 16),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 20),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    if (featured != null) ...[_featured(featured), const SizedBox(height: 24)],
                    SectionHeader(_when == 'upcoming' ? 'Jadwal Agenda Komunitas' : 'Agenda Sebelumnya', dot: true),
                    const SizedBox(height: 4),
                    SegmentedButton<String>(
                      showSelectedIcon: false,
                      segments: const [
                        ButtonSegment(value: 'upcoming', label: Text('Akan datang')),
                        ButtonSegment(value: 'past', label: Text('Sudah lewat')),
                      ],
                      selected: {_when},
                      onSelectionChanged: (s) {
                        _when = s.first;
                        _reload();
                      },
                    ),
                    const SizedBox(height: 12),
                    if (rest.isEmpty)
                      AkurCard(
                        child: Text(
                          featured != null
                              ? 'Belum ada agenda lain.'
                              : _when == 'upcoming'
                              ? 'Belum ada kegiatan terjadwal.'
                              : 'Belum ada kegiatan yang lewat.',
                        ),
                      )
                    else
                      Column(spacing: 14, children: [for (final a in rest) _scheduleCard(a)]),
                    const SizedBox(height: 24),
                    if (_manageUnits.isNotEmpty)
                      HelpCard(
                        icon: Icons.lightbulb_outline_rounded,
                        title: 'Punya agenda baru?',
                        message: 'Buat kegiatan dan warga akan mendapat notifikasi untuk konfirmasi hadir.',
                        action: 'Buat',
                        onAction: _create,
                      )
                    else
                      const HelpCard(
                        icon: Icons.lightbulb_outline_rounded,
                        title: 'Punya usulan kegiatan warga?',
                        message: 'Sampaikan ide positif Anda ke pengurus RT; agenda baru akan tampil di sini.',
                      ),
                  ],
                ),
              ),
            ],
          ),
        );
      },
    );
    return Scaffold(
      backgroundColor: Colors.transparent,
      appBar: widget.embedded ? null : AppBar(title: const Text('Kegiatan')),
      floatingActionButton: _manageUnits.isNotEmpty
          ? FloatingActionButton.extended(onPressed: _create, icon: const Icon(Icons.add), label: const Text('Buat kegiatan'))
          : null,
      body: body,
    );
  }

  Widget _banner(List<Map<String, dynamic>> all) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final unit = primaryUnitOf(widget.user);
    final planned = all.where((a) => a['status'] == 'planned').length;
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(AkurTheme.radiusCard),
        gradient: LinearGradient(colors: [AkurColors.primaryFixed, scheme.secondaryContainer]),
      ),
      child: Row(
        children: [
          IconDisc(Icons.volunteer_activism_rounded, background: Colors.white.withValues(alpha: 0.6)),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Guyub Rukun ${unit?.name.split(' / ').first ?? 'Warga'}',
                  style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800),
                ),
                Text(
                  _when == 'upcoming' ? '$planned agenda kebersamaan siap diikuti!' : 'Kenangan kebersamaan warga',
                  style: theme.textTheme.bodySmall,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _categoryChips() {
    Widget chip(String? value, String label, IconData icon) {
      final selected = _category == value;
      return Padding(
        padding: const EdgeInsets.only(right: 8),
        child: ChoiceChip(
          showCheckmark: false,
          selected: selected,
          onSelected: (_) => setState(() => _category = value),
          avatar: Icon(icon, size: 18, color: selected ? Colors.white : Theme.of(context).colorScheme.primary),
          label: Text(label),
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
        ),
      );
    }

    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.symmetric(horizontal: 20),
      child: Row(
        children: [
          chip(null, 'Semua Kegiatan', Icons.calendar_month_outlined),
          for (final e in activityCategoryLabels.entries) chip(e.key, e.value, activityCategoryIcon(e.key)),
        ],
      ),
    );
  }

  Widget _featured(Map<String, dynamic> a) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final my = a['my_status'] as String?;
    final description = (a['description'] as String? ?? '').trim();
    return AkurCard(
      padding: EdgeInsets.zero,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Container(
            color: AkurColors.primary,
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
            child: Row(
              children: [
                const Icon(Icons.campaign_outlined, color: Colors.white, size: 20),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    'AGENDA UTAMA TERDEKAT',
                    style: theme.textTheme.labelMedium?.copyWith(color: Colors.white, fontWeight: FontWeight.w800),
                  ),
                ),
                Pill((a['unit'] as Map<String, dynamic>)['name'] as String, tone: PillTone.tertiary),
              ],
            ),
          ),
          InkWell(
            onTap: () => _open(a),
            child: ActivityBanner(activity: a, height: 150, showTitle: false),
          ),
          Padding(
            padding: const EdgeInsets.all(18),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(a['title'] as String, style: theme.textTheme.headlineSmall),
                const SizedBox(height: 12),
                InfoPanel(
                  children: [
                    InfoRow(
                      icon: Icons.calendar_month_outlined,
                      color: scheme.primary,
                      text: formatLongDate(a['starts_at'] as String?),
                      sub:
                          '${formatClock(a['starts_at'] as String?)}${a['ends_at'] != null ? ' - ${formatClock(a['ends_at'] as String?)}' : ''} WIB',
                    ),
                    InfoRow(icon: Icons.place_outlined, color: scheme.secondary, text: a['location_text'] as String? ?? '-'),
                  ],
                ),
                if (description.isNotEmpty) ...[
                  const SizedBox(height: 12),
                  Container(
                    padding: const EdgeInsets.all(14),
                    decoration: BoxDecoration(
                      color: AkurColors.notice,
                      borderRadius: BorderRadius.circular(14),
                      border: Border.all(color: AkurColors.primaryFixed),
                    ),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Icon(Icons.info_outline_rounded, size: 20, color: scheme.primary),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(description, maxLines: 4, overflow: TextOverflow.ellipsis, style: theme.textTheme.bodyMedium),
                        ),
                      ],
                    ),
                  ),
                ],
                const SizedBox(height: 14),
                Row(
                  children: [
                    Icon(Icons.groups_outlined, color: scheme.secondary),
                    const SizedBox(width: 6),
                    Expanded(child: Text('${a['going_count']} warga sudah konfirmasi hadir', style: theme.textTheme.titleSmall)),
                  ],
                ),
                if (my != null) ...[
                  const SizedBox(height: 6),
                  Text(
                    'Status Anda: ${participationLabels[my]}',
                    style: theme.textTheme.bodySmall?.copyWith(color: scheme.secondary, fontWeight: FontWeight.w700),
                  ),
                ],
                const SizedBox(height: 14),
                Row(
                  children: [
                    Expanded(
                      flex: 3,
                      child: FilledButton.icon(
                        onPressed: my == 'going' ? null : () => _rsvp(a, 'going'),
                        icon: const Icon(Icons.how_to_reg_outlined),
                        label: Text(my == 'going' ? 'Tercatat Hadir' : 'Saya Hadir'),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      flex: 2,
                      child: FilledButton.tonalIcon(
                        style: FilledButton.styleFrom(backgroundColor: scheme.surfaceContainerHigh, foregroundColor: scheme.onSurface),
                        onPressed: my == 'not_going' ? null : () => _rsvp(a, 'not_going'),
                        icon: const Icon(Icons.event_busy_outlined),
                        label: const Text('Izin'),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _scheduleCard(Map<String, dynamic> a) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final cancelled = a['status'] == 'cancelled';
    final category = a['category'] as String?;
    final my = a['my_status'] as String?;
    return AkurCard(
      onTap: () => _open(a),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              IconDisc(activityCategoryIcon(category), background: scheme.secondaryContainer, color: scheme.secondary),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Wrap(
                      spacing: 6,
                      runSpacing: 4,
                      children: [
                        Pill((activityCategoryLabels[category] ?? category ?? '-').toUpperCase(), tone: PillTone.secondary),
                        if (cancelled) const Pill('DIBATALKAN', tone: PillTone.danger),
                        if (a['status'] == 'completed') const Pill('SELESAI', tone: PillTone.neutral),
                      ],
                    ),
                    const SizedBox(height: 4),
                    Text(
                      a['title'] as String,
                      style: theme.textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                        decoration: cancelled ? TextDecoration.lineThrough : null,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          InfoPanel(
            children: [
              InfoRow(
                icon: Icons.schedule_rounded,
                text: '${formatLongDate(a['starts_at'] as String?)} • ${formatClock(a['starts_at'] as String?)} WIB',
              ),
              InfoRow(icon: Icons.place_outlined, text: a['location_text'] as String? ?? '-'),
            ],
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              Icon(Icons.groups_outlined, size: 20, color: scheme.onSurfaceVariant),
              const SizedBox(width: 6),
              Expanded(
                child: Text('${a['going_count']} hadir • ${(a['unit'] as Map<String, dynamic>)['name']}', style: theme.textTheme.bodySmall),
              ),
              if (my != null)
                Pill(participationLabels[my]!, tone: my == 'going' ? PillTone.secondary : PillTone.neutral)
              else
                Text('Lihat detail', style: theme.textTheme.titleSmall?.copyWith(color: scheme.secondary)),
            ],
          ),
        ],
      ),
    );
  }
}

class ActivityDetailScreen extends StatefulWidget {
  const ActivityDetailScreen({super.key, required this.api, required this.activityId, required this.units});

  final ApiClient api;
  final String activityId;
  final List<UnitRef> units;

  @override
  State<ActivityDetailScreen> createState() => _ActivityDetailScreenState();
}

class _ActivityDetailScreenState extends State<ActivityDetailScreen> {
  late Future<Map<String, dynamic>> _item = _load();

  Future<Map<String, dynamic>> _load() async =>
      (await widget.api.get('/activities/${widget.activityId}'))['data'] as Map<String, dynamic>;

  void _reload() => setState(() { _item = _load(); });

  Future<void> _rsvp(String status) async {
    final ok = await runApi(
      context,
      () => widget.api.put('/activities/${widget.activityId}/participation', {'status': status}),
      'Konfirmasi tersimpan: ${participationLabels[status]}.',
    );
    if (ok) _reload();
  }

  Future<void> _cancel() async {
    final reason = await askReason(
      context,
      title: 'Batalkan kegiatan?',
      message: 'Semua anggota unit akan menerima notifikasi pembatalan.',
      action: 'Batalkan',
    );
    if (reason == null || !mounted) return;
    final ok = await runApi(
      context,
      () => widget.api.patch('/activities/${widget.activityId}', {'status': 'cancelled', 'reason': reason}),
      'Kegiatan dibatalkan.',
    );
    if (ok) _reload();
  }

  Future<void> _checkIn(Map<String, dynamic> p) async {
    final ok = await runApi(
      context,
      () => widget.api.post('/activities/${widget.activityId}/participants/${p['id']}/check-in'),
      '${p['name']} tercatat hadir.',
    );
    if (ok) _reload();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return FutureBuilder<Map<String, dynamic>>(
      future: _item,
      builder: (context, snapshot) {
        final a = snapshot.data;
        final canEdit = a != null && a['can_edit'] == true && a['status'] == 'planned';
        return Scaffold(
          appBar: AppBar(
            title: const Text('Detail kegiatan'),
            actions: [
              if (canEdit) ...[
                IconButton(
                  tooltip: 'Ubah',
                  icon: const Icon(Icons.edit_outlined),
                  onPressed: () async {
                    final saved = await Navigator.of(context).push<bool>(MaterialPageRoute(
                      builder: (_) => ActivityFormScreen(api: widget.api, units: widget.units, activity: a),
                    ));
                    if (saved == true) _reload();
                  },
                ),
                IconButton(tooltip: 'Batalkan', icon: const Icon(Icons.event_busy_outlined), onPressed: _cancel),
              ],
            ],
          ),
          body: switch (snapshot) {
            _ when snapshot.connectionState != ConnectionState.done => const Center(child: CircularProgressIndicator()),
            _ when snapshot.hasError => ErrorView(error: snapshot.error, onRetry: _reload),
            _ => RefreshIndicator(
                onRefresh: () async => _reload(),
                child: ListView(
                  padding: const EdgeInsets.all(16),
                  children: [
                    Text(a!['title'] as String, style: theme.textTheme.headlineSmall),
                    const SizedBox(height: 8),
                    Wrap(spacing: 8, runSpacing: 8, children: [
                      Chip(label: Text(activityStatusLabels[a['status']] ?? a['status'] as String)),
                      Chip(label: Text(activityCategoryLabels[a['category']] ?? a['category'] as String)),
                      Chip(label: Text((a['unit'] as Map<String, dynamic>)['name'] as String)),
                    ]),
                    const SizedBox(height: 8),
                    ListTile(
                      contentPadding: EdgeInsets.zero,
                      leading: const Icon(Icons.schedule),
                      title: Text(formatDateTime(a['starts_at'] as String)),
                      subtitle: a['ends_at'] != null ? Text('sampai ${formatDateTime(a['ends_at'] as String)}') : null,
                    ),
                    ListTile(
                      contentPadding: EdgeInsets.zero,
                      leading: const Icon(Icons.place_outlined),
                      title: Text(a['location_text'] as String),
                    ),
                    ListTile(
                      contentPadding: EdgeInsets.zero,
                      leading: const Icon(Icons.groups_outlined),
                      title: Text('${a['going_count']} orang akan hadir'),
                      subtitle: Text('Penyelenggara: ${a['organizer_name'] ?? '-'}'),
                    ),
                    const SizedBox(height: 8),
                    SelectableText(a['description'] as String, style: theme.textTheme.bodyLarge),
                    if (a['status'] == 'planned') ...[
                      const SizedBox(height: 24),
                      Text('Konfirmasi kehadiran Anda', style: theme.textTheme.titleMedium),
                      const SizedBox(height: 8),
                      SegmentedButton<String>(
                        emptySelectionAllowed: true,
                        segments: [
                          for (final e in participationLabels.entries) ButtonSegment(value: e.key, label: Text(e.value)),
                        ],
                        selected: {if (a['my_status'] != null) a['my_status'] as String},
                        onSelectionChanged: (s) {
                          if (s.isNotEmpty) _rsvp(s.first);
                        },
                      ),
                    ],
                    if (a['participants'] != null) ...[
                      const SizedBox(height: 24),
                      Text('Peserta', style: theme.textTheme.titleMedium),
                      const SizedBox(height: 8),
                      if ((a['participants'] as List<dynamic>).isEmpty) const Text('Belum ada konfirmasi.'),
                      for (final p in (a['participants'] as List<dynamic>).cast<Map<String, dynamic>>())
                        Card(
                          child: ListTile(
                            title: Text(p['name'] as String? ?? '-'),
                            subtitle: Text(participationLabels[p['participation_status']] ?? p['participation_status'] as String),
                            trailing: p['checked_in_at'] != null
                                ? const Icon(Icons.check_circle, color: Colors.green)
                                : a['can_edit'] == true
                                    ? TextButton(onPressed: () => _checkIn(p), child: const Text('Hadir'))
                                    : null,
                          ),
                        ),
                    ],
                  ],
                ),
              ),
          },
        );
      },
    );
  }
}
