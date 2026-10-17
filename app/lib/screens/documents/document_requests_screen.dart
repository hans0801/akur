import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../theme.dart';
import '../../widgets/akur_ui.dart';
import '../population/population_common.dart';
import 'document_request_detail_screen.dart';
import 'document_request_form_screen.dart';
import 'documents_common.dart';
import '../../widgets/async_view.dart';

/// Layanan surat untuk warga: ajukan surat baru, pantau tahap proses, dan unduh PDF yang sudah terbit.
class DocumentRequestsScreen extends StatefulWidget {
  const DocumentRequestsScreen({super.key, required this.api, this.embedded = false});

  final ApiClient api;

  /// true bila tampil sebagai tab di kerangka utama (tanpa AppBar sendiri).
  final bool embedded;

  @override
  State<DocumentRequestsScreen> createState() => _DocumentRequestsScreenState();
}

/// Kelompok filter riwayat: diproses = belum selesai, selesai = terbit/disetujui/ditolak/dibatalkan.
const _inProgress = {'submitted', 'under_review', 'revision_requested'};

class _DocumentRequestsScreenState extends State<DocumentRequestsScreen> {
  late Future<List<Map<String, dynamic>>> _items = _load();
  String _filter = 'all';

  Future<List<Map<String, dynamic>>> _load() async => dataList(await widget.api.get('/document-requests'));

  void _reload() => setState(() { _items = _load(); });

  Future<void> _push(Widget screen) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final body = FutureBuilder<List<Map<String, dynamic>>>(
      future: _items,
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
        if (snapshot.hasError) return ErrorView(error: snapshot.error, onRetry: _reload);
        final all = snapshot.data!;
        final active = all.where((d) => _inProgress.contains(d['status'])).toList();
        final done = all.where((d) => !_inProgress.contains(d['status'])).toList();
        final shown = switch (_filter) {
          'active' => active,
          'done' => done,
          _ => all,
        };
        return RefreshIndicator(
          onRefresh: () async => _reload(),
          child: ListView(
            padding: const EdgeInsets.fromLTRB(20, 8, 20, 32),
            children: [
              Text('Layanan Surat RT', style: theme.textTheme.headlineSmall),
              Text(
                'Urus surat pengantar resmi tanpa antre dan pantau prosesnya langsung.',
                style: theme.textTheme.bodyMedium?.copyWith(color: scheme.onSurfaceVariant),
              ),
              const SizedBox(height: 16),
              _NewRequestButton(onTap: () => _push(DocumentRequestFormScreen(api: widget.api))),
              const SizedBox(height: 16),
              Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(color: scheme.surfaceContainer, borderRadius: BorderRadius.circular(14)),
                child: Row(
                  children: [
                    IconDisc(Icons.verified_outlined, size: 40, background: scheme.secondary, color: Colors.white),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Text(
                        'Surat terbit sebagai PDF resmi bernomor, lengkap dengan tanda tangan + cap Ketua RT dan Ketua RW, '
                        'siap dibawa ke kelurahan.',
                        style: theme.textTheme.bodySmall,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 24),
              Row(
                children: [
                  Expanded(child: Text('Riwayat Permohonan', style: theme.textTheme.titleLarge)),
                  Pill('Total ${all.length} berkas', tone: PillTone.secondary),
                ],
              ),
              const SizedBox(height: 12),
              SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: Row(
                  spacing: 8,
                  children: [
                    _filterChip('all', 'Semua', null),
                    _filterChip('active', 'Diproses', active.length),
                    _filterChip('done', 'Selesai', done.length),
                  ],
                ),
              ),
              const SizedBox(height: 16),
              if (shown.isEmpty)
                AkurCard(
                  child: Text(
                    all.isEmpty
                        ? 'Belum ada pengajuan surat. Ketuk "Buat Pengajuan Surat Baru" untuk mulai.'
                        : 'Tidak ada surat di kelompok ini.',
                  ),
                )
              else
                Column(
                  spacing: 14,
                  children: [
                    for (final d in shown)
                      DocumentRequestCard(
                        request: d,
                        api: widget.api,
                        onOpen: () => _push(DocumentRequestDetailScreen(api: widget.api, requestId: d['id'] as String)),
                      ),
                  ],
                ),
            ],
          ),
        );
      },
    );
    if (widget.embedded) return body;
    return Scaffold(
      appBar: AppBar(title: const Text('Surat pengantar')),
      body: body,
    );
  }

  Widget _filterChip(String value, String label, int? count) {
    final selected = _filter == value;
    final scheme = Theme.of(context).colorScheme;
    return ChoiceChip(
      showCheckmark: false,
      selected: selected,
      onSelected: (_) => setState(() => _filter = value),
      label: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(label),
          if (count != null) ...[
            const SizedBox(width: 6),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 1),
              decoration: BoxDecoration(
                color: selected ? Colors.white : scheme.tertiaryContainer,
                borderRadius: BorderRadius.circular(999),
              ),
              child: Text(
                '$count',
                style: TextStyle(color: selected ? scheme.secondary : scheme.onTertiaryContainer, fontWeight: FontWeight.w700),
              ),
            ),
          ],
        ],
      ),
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
    );
  }
}

class _NewRequestButton extends StatelessWidget {
  const _NewRequestButton({required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Semantics(
      button: true,
      label: 'Buat pengajuan surat baru',
      excludeSemantics: true,
      child: Material(
        borderRadius: BorderRadius.circular(AkurTheme.radiusCard),
        clipBehavior: Clip.antiAlias,
        child: Ink(
          decoration: const BoxDecoration(gradient: LinearGradient(colors: [AkurColors.primaryContainer, AkurColors.primary])),
          child: InkWell(
            onTap: onTap,
            child: Padding(
              padding: const EdgeInsets.all(18),
              child: Row(
                children: [
                  IconDisc(Icons.note_add_outlined, background: Colors.white.withValues(alpha: 0.2), color: Colors.white, size: 52),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Buat Pengajuan Surat Baru',
                          style: theme.textTheme.titleMedium?.copyWith(color: Colors.white, fontWeight: FontWeight.w800),
                        ),
                        Text(
                          'Surat domisili, pengantar KTP/KK, dan lainnya',
                          style: theme.textTheme.bodySmall?.copyWith(color: Colors.white.withValues(alpha: 0.9)),
                        ),
                      ],
                    ),
                  ),
                  Container(
                    width: 40,
                    height: 40,
                    decoration: BoxDecoration(color: Colors.white.withValues(alpha: 0.22), shape: BoxShape.circle),
                    child: const Icon(Icons.arrow_forward_rounded, color: Colors.white),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Tahap proses surat untuk ditampilkan ke warga: (keterangan, langkah ke-, nada).
(String, int, PillTone) documentStage(String status) => switch (status) {
  'submitted' => ('Menunggu ditinjau pengurus RT', 1, PillTone.tertiary),
  'under_review' => ('Sedang ditinjau pengurus RT', 2, PillTone.tertiary),
  'revision_requested' => ('Perlu perbaikan dari Anda', 1, PillTone.danger),
  'approved' || 'issued' => ('Selesai / Siap diunduh', 3, PillTone.secondary),
  'rejected' => ('Permohonan ditolak', 3, PillTone.danger),
  _ => ('Dibatalkan', 3, PillTone.neutral),
};

/// Kartu permohonan surat: jenis, pemohon, tanggal, tahap proses, dan tombol unduh/rincian.
class DocumentRequestCard extends StatelessWidget {
  const DocumentRequestCard({super.key, required this.request, required this.api, required this.onOpen});

  final Map<String, dynamic> request;
  final ApiClient api;
  final VoidCallback onOpen;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final d = request;
    final status = d['status'] as String;
    final (stage, step, tone) = documentStage(status);
    final issued = status == 'issued' || status == 'approved';
    final active = _inProgress.contains(status);
    return AkurCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              IconDisc(
                issued ? Icons.task_outlined : Icons.description_outlined,
                background: issued ? scheme.secondaryContainer : scheme.primaryContainer,
                color: issued ? scheme.secondary : scheme.primary,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      d['letter_number'] != null ? 'No. ${d['letter_number']}' : (d['unit'] as Map<String, dynamic>)['name'] as String,
                      style: theme.textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant, fontWeight: FontWeight.w600),
                    ),
                    Text(
                      (d['document_type'] as Map<String, dynamic>)['name'] as String,
                      style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          InfoPanel(
            children: [
              KeyValueRow('Pemohon', d['resident_name'] as String? ?? '-'),
              KeyValueRow(
                issued ? 'Tgl. terbit' : 'Tgl. pengajuan',
                issued && d['issued_at'] != null
                    ? formatShortDate(d['issued_at'] as String?)
                    : '${formatShortDate(d['submitted_at'] as String?)}, ${formatClock(d['submitted_at'] as String?)}',
              ),
            ],
          ),
          const SizedBox(height: 12),
          if (active)
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: tone == PillTone.danger ? scheme.errorContainer.withValues(alpha: 0.5) : AkurColors.notice,
                borderRadius: BorderRadius.circular(14),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Row(
                    children: [
                      Icon(
                        tone == PillTone.danger ? Icons.edit_note_rounded : Icons.hourglass_top_rounded,
                        size: 20,
                        color: scheme.primary,
                      ),
                      const SizedBox(width: 8),
                      Expanded(child: Text(stage, style: theme.textTheme.titleSmall)),
                      Text(
                        'Langkah $step/3',
                        style: theme.textTheme.bodySmall?.copyWith(color: scheme.primary, fontWeight: FontWeight.w700),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  ProgressBar(value: step / 3, color: scheme.primary, background: Colors.white),
                ],
              ),
            )
          else
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
              decoration: BoxDecoration(
                color: issued ? scheme.secondaryContainer.withValues(alpha: 0.5) : scheme.surfaceContainerHigh,
                borderRadius: BorderRadius.circular(14),
              ),
              child: Row(
                children: [
                  Icon(
                    issued ? Icons.check_circle_outline_rounded : Icons.block_rounded,
                    color: issued ? scheme.secondary : scheme.onSurfaceVariant,
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(stage, style: theme.textTheme.titleSmall?.copyWith(color: issued ? scheme.secondary : null)),
                  ),
                  if (issued) const Pill('PDF Resmi', tone: PillTone.neutral),
                ],
              ),
            ),
          const SizedBox(height: 12),
          if (issued)
            Row(
              children: [
                Expanded(
                  flex: 3,
                  child: FilledButton.icon(
                    style: FilledButton.styleFrom(backgroundColor: scheme.secondary, padding: const EdgeInsets.symmetric(horizontal: 12)),
                    onPressed: () => openLetter(context, api, d['id'] as String),
                    icon: const Icon(Icons.download_rounded),
                    label: const Text('Unduh PDF'),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  flex: 2,
                  child: FilledButton.tonal(
                    style: FilledButton.styleFrom(
                      backgroundColor: scheme.surfaceContainerHigh,
                      foregroundColor: scheme.onSurface,
                      padding: const EdgeInsets.symmetric(horizontal: 12),
                    ),
                    onPressed: onOpen,
                    child: const Text('Detail'),
                  ),
                ),
              ],
            )
          else
            FilledButton.tonalIcon(
              style: FilledButton.styleFrom(backgroundColor: scheme.surfaceContainerHigh, foregroundColor: scheme.onSurface),
              onPressed: onOpen,
              icon: Icon(active ? Icons.visibility_outlined : Icons.history_rounded),
              label: Text(active ? 'Lacak & Rincian' : 'Lihat Riwayat'),
            ),
        ],
      ),
    );
  }
}

/// Daftar kartu permohonan (dipakai antrean pengurus).
class DocumentRequestList extends StatelessWidget {
  const DocumentRequestList({super.key, required this.items, required this.empty, required this.onTap, required this.api});

  final List<Map<String, dynamic>> items;
  final String empty;
  final ValueChanged<Map<String, dynamic>> onTap;
  final ApiClient api;

  @override
  Widget build(BuildContext context) {
    if (items.isEmpty) return EmptyState(empty);
    return ListView.separated(
      padding: const EdgeInsets.fromLTRB(20, 16, 20, 96),
      itemCount: items.length,
      separatorBuilder: (_, _) => const SizedBox(height: 14),
      itemBuilder: (_, i) => DocumentRequestCard(request: items[i], api: api, onOpen: () => onTap(items[i])),
    );
  }
}
