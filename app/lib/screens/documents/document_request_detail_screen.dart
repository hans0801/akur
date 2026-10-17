import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../community/community_common.dart';
import '../population/population_common.dart';
import 'document_request_form_screen.dart';
import 'documents_common.dart';
import '../../widgets/async_view.dart';

/// Detail pengajuan surat. Pemohon: perbaiki/batalkan/buka surat. Pengurus: tinjau, setujui, tolak, terbitkan.
class DocumentRequestDetailScreen extends StatefulWidget {
  const DocumentRequestDetailScreen({super.key, required this.api, required this.requestId});

  final ApiClient api;
  final String requestId;

  @override
  State<DocumentRequestDetailScreen> createState() => _DocumentRequestDetailScreenState();
}

class _DocumentRequestDetailScreenState extends State<DocumentRequestDetailScreen> {
  late Future<Map<String, dynamic>> _item = _load();

  Future<Map<String, dynamic>> _load() async =>
      (await widget.api.get('/document-requests/${widget.requestId}'))['data'] as Map<String, dynamic>;

  void _reload() => setState(() { _item = _load(); });

  Future<void> _transition(String status) async {
    String? notes;
    if (status == 'revision_requested' || status == 'rejected') {
      notes = await askReason(
        context,
        title: documentActionLabels[status]!,
        message: 'Catatan ini dikirim ke pemohon.',
        action: documentActionLabels[status]!,
      );
      if (notes == null) return;
    } else if (status == 'approved' || status == 'issued') {
      final confirmed = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('Setujui dan terbitkan surat?'),
          content: const Text('Nomor surat diberikan, PDF dibuat dengan tanda tangan yang tersimpan, dan warga bisa langsung '
              'mengunduhnya. Tindakan ini tidak dapat dibatalkan.'),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Batal')),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Setujui & terbitkan')),
          ],
        ),
      );
      if (confirmed != true) return;
    }
    if (!mounted) return;
    final ok = await runApi(
      context,
      () => widget.api.post('/document-requests/${widget.requestId}/transition', {'status': status, 'notes': ?notes}),
      status == 'approved' ? 'Surat disetujui dan terbit. PDF siap diunduh.' : 'Status: ${documentStatusLabels[status]}.',
    );
    if (ok) _reload();
  }

  Future<void> _cancel() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Batalkan pengajuan?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Tidak')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Batalkan')),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;
    final ok = await runApi(context, () => widget.api.post('/document-requests/${widget.requestId}/cancel'), 'Pengajuan dibatalkan.');
    if (ok) _reload();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return FutureBuilder<Map<String, dynamic>>(
      future: _item,
      builder: (context, snapshot) {
        final d = snapshot.data;
        return Scaffold(
          appBar: AppBar(title: const Text('Pengajuan surat')),
          body: switch (snapshot) {
            _ when snapshot.connectionState != ConnectionState.done => const Center(child: CircularProgressIndicator()),
            _ when snapshot.hasError => ErrorView(error: snapshot.error, onRetry: _reload),
            _ => RefreshIndicator(
                onRefresh: () async => _reload(),
                child: ListView(
                  padding: const EdgeInsets.all(16),
                  children: [
                    Text((d!['document_type'] as Map<String, dynamic>)['name'] as String, style: theme.textTheme.headlineSmall),
                    const SizedBox(height: 8),
                    Wrap(spacing: 8, runSpacing: 8, children: [
                      DocumentStatusChip(d['status'] as String),
                      Chip(label: Text((d['unit'] as Map<String, dynamic>)['name'] as String)),
                    ]),
                    if (d['letter_number'] != null)
                      ListTile(
                        contentPadding: EdgeInsets.zero,
                        leading: const Icon(Icons.tag),
                        title: Text(d['letter_number'] as String),
                        subtitle: Text('Terbit ${formatDateTime(d['issued_at'] as String?)}'),
                      ),
                    ListTile(contentPadding: EdgeInsets.zero, leading: const Icon(Icons.person_outline), title: Text(d['resident_name'] as String? ?? '-')),
                    ListTile(
                      contentPadding: EdgeInsets.zero,
                      leading: const Icon(Icons.flag_outlined),
                      title: Text(d['purpose'] as String),
                      subtitle: Text('Diajukan ${formatDateTime(d['submitted_at'] as String?)}'),
                    ),
                    for (final f in (d['fields'] as List<dynamic>).cast<Map<String, dynamic>>())
                      ListTile(
                        dense: true,
                        contentPadding: EdgeInsets.zero,
                        title: Text(f['label'] as String),
                        trailing: Text('${formDataOf(d)[f['key']] ?? '-'}'),
                      ),
                    if (d['admin_notes'] != null)
                      Card(
                        child: ListTile(
                          leading: const Icon(Icons.feedback_outlined),
                          title: const Text('Catatan pengurus'),
                          subtitle: Text('${d['admin_notes']}${d['processed_by'] != null ? '\n— ${d['processed_by']}' : ''}'),
                        ),
                      ),
                    const SizedBox(height: 16),
                    if (d['status'] == 'issued')
                      FilledButton.icon(
                        onPressed: () => openLetter(context, widget.api, widget.requestId),
                        icon: const Icon(Icons.picture_as_pdf_outlined),
                        label: const Text('Unduh surat (PDF)'),
                      ),
                    if (d['is_mine'] == true && d['status'] == 'revision_requested')
                      FilledButton.icon(
                        onPressed: () async {
                          final saved = await Navigator.of(context).push<bool>(MaterialPageRoute(
                            builder: (_) => DocumentRequestFormScreen(api: widget.api, request: d),
                          ));
                          if (saved == true) _reload();
                        },
                        icon: const Icon(Icons.edit_outlined),
                        label: const Text('Perbaiki pengajuan'),
                      ),
                    if (d['is_mine'] == true && (d['status'] == 'submitted' || d['status'] == 'revision_requested'))
                      TextButton(onPressed: _cancel, child: const Text('Batalkan pengajuan')),
                    if (d['can_process'] == true && (d['next_statuses'] as List<dynamic>).isNotEmpty) ...[
                      const Divider(height: 32),
                      Text('Tindakan pengurus', style: theme.textTheme.titleMedium),
                      const SizedBox(height: 8),
                      Wrap(spacing: 8, runSpacing: 8, children: [
                        for (final s in (d['next_statuses'] as List<dynamic>).cast<String>())
                          s == 'rejected' || s == 'revision_requested'
                              ? OutlinedButton(onPressed: () => _transition(s), child: Text(documentActionLabels[s]!))
                              : FilledButton(onPressed: () => _transition(s), child: Text(documentActionLabels[s]!)),
                      ]),
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
