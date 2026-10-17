import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../models/current_user.dart';
import '../population/population_common.dart';
import 'document_request_detail_screen.dart';
import 'document_requests_screen.dart';
import '../../widgets/async_view.dart';

/// Antrean surat pengantar untuk pengurus: yang masih terbuka, atau difilter per status.
class DocumentQueueScreen extends StatefulWidget {
  const DocumentQueueScreen({super.key, required this.api, required this.units});

  final ApiClient api;
  final List<UnitRef> units;

  @override
  State<DocumentQueueScreen> createState() => _DocumentQueueScreenState();
}

class _DocumentQueueScreenState extends State<DocumentQueueScreen> {
  static const _filters = {
    'open': 'Perlu diproses',
    'revision_requested': 'Menunggu perbaikan',
    'issued': 'Terbit',
    'rejected': 'Ditolak',
    'all': 'Semua',
  };

  late UnitRef _unit = widget.units.first;
  String _status = 'open';
  late Future<List<Map<String, dynamic>>> _items = _load();

  Future<List<Map<String, dynamic>>> _load() async =>
      dataList(await widget.api.get('/units/${_unit.id}/document-requests?status=$_status'));

  void _reload() => setState(() => _items = _load());

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Antrean surat')),
      body: Column(
        children: [
          UnitDropdown(units: widget.units, value: _unit, onChanged: (u) {
            _unit = u;
            _reload();
          }),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: DropdownButtonFormField<String>(
              initialValue: _status,
              decoration: const InputDecoration(labelText: 'Status'),
              items: [for (final e in _filters.entries) DropdownMenuItem(value: e.key, child: Text(e.value))],
              onChanged: (v) {
                if (v == null) return;
                _status = v;
                _reload();
              },
            ),
          ),
          Expanded(
            child: FutureBuilder<List<Map<String, dynamic>>>(
              future: _items,
              builder: (context, snapshot) {
                if (snapshot.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
                if (snapshot.hasError) return ErrorView(error: snapshot.error, onRetry: _reload);
                return RefreshIndicator(
                  onRefresh: () async => _reload(),
                  child: DocumentRequestList(
                    items: snapshot.data!,
                    empty: 'Tidak ada pengajuan ${_filters[_status]!.toLowerCase()}.',
                    onTap: (d) async {
                      await Navigator.of(context).push(MaterialPageRoute(
                        builder: (_) => DocumentRequestDetailScreen(api: widget.api, requestId: d['id'] as String),
                      ));
                      _reload();
                    },
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}
