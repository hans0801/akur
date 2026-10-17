import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../community/community_common.dart';
import '../population/population_common.dart';
import 'document_request_detail_screen.dart';
import 'document_request_form_screen.dart';
import 'documents_common.dart';
import '../../widgets/async_view.dart';

/// Surat pengantar milik pengguna: riwayat pengajuan dan tombol ajukan baru.
class DocumentRequestsScreen extends StatefulWidget {
  const DocumentRequestsScreen({super.key, required this.api});

  final ApiClient api;

  @override
  State<DocumentRequestsScreen> createState() => _DocumentRequestsScreenState();
}

class _DocumentRequestsScreenState extends State<DocumentRequestsScreen> {
  late Future<List<Map<String, dynamic>>> _items = _load();

  Future<List<Map<String, dynamic>>> _load() async => dataList(await widget.api.get('/document-requests'));

  void _reload() => setState(() => _items = _load());

  Future<void> _push(Widget screen) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Surat pengantar')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _push(DocumentRequestFormScreen(api: widget.api)),
        icon: const Icon(Icons.add),
        label: const Text('Ajukan surat'),
      ),
      body: FutureBuilder<List<Map<String, dynamic>>>(
        future: _items,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
          if (snapshot.hasError) return ErrorView(error: snapshot.error, onRetry: _reload);
          return RefreshIndicator(
            onRefresh: () async => _reload(),
            child: DocumentRequestList(
              items: snapshot.data!,
              empty: 'Belum ada pengajuan surat.',
              onTap: (d) => _push(DocumentRequestDetailScreen(api: widget.api, requestId: d['id'] as String)),
            ),
          );
        },
      ),
    );
  }
}

class DocumentRequestList extends StatelessWidget {
  const DocumentRequestList({super.key, required this.items, required this.empty, required this.onTap});

  final List<Map<String, dynamic>> items;
  final String empty;
  final ValueChanged<Map<String, dynamic>> onTap;

  @override
  Widget build(BuildContext context) {
    if (items.isEmpty) return EmptyState(empty);
    return ListView.separated(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 96),
      itemCount: items.length,
      separatorBuilder: (_, _) => const SizedBox(height: 8),
      itemBuilder: (_, i) {
        final d = items[i];
        return Card(
          child: ListTile(
            leading: const Icon(Icons.description_outlined),
            title: Text((d['document_type'] as Map<String, dynamic>)['name'] as String),
            subtitle: Text(
              '${d['resident_name'] ?? '-'} · ${(d['unit'] as Map<String, dynamic>)['name']}\n'
              '${d['letter_number'] ?? formatDateTime(d['submitted_at'] as String?)}',
            ),
            isThreeLine: true,
            trailing: DocumentStatusChip(d['status'] as String),
            onTap: () => onTap(d),
          ),
        );
      },
    );
  }
}
