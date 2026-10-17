import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../population/population_common.dart';
import 'documents_common.dart';

/// Ajukan surat baru, atau perbaiki pengajuan yang diminta revisi (`request` diisi).
/// Isian tambahan mengikuti `required_fields` jenis surat dari server.
class DocumentRequestFormScreen extends StatefulWidget {
  const DocumentRequestFormScreen({super.key, required this.api, this.request});

  final ApiClient api;
  final Map<String, dynamic>? request;

  @override
  State<DocumentRequestFormScreen> createState() => _DocumentRequestFormScreenState();
}

class _DocumentRequestFormScreenState extends State<DocumentRequestFormScreen> {
  final _form = GlobalKey<FormState>();
  late final _purpose = TextEditingController(text: widget.request?['purpose'] as String?);
  final Map<String, TextEditingController> _fieldControllers = {};
  final Map<String, String?> _dateValues = {};

  List<Map<String, dynamic>> _types = [];
  List<Map<String, dynamic>> _residents = [];
  String? _typeId;
  String? _residentId;
  String? _loadError;
  bool _saving = false;

  bool get _isRevision => widget.request != null;

  List<Map<String, dynamic>> get _fields {
    if (_isRevision) return (widget.request!['fields'] as List<dynamic>).cast<Map<String, dynamic>>();
    final type = _types.where((t) => t['id'] == _typeId).firstOrNull;
    return ((type?['required_fields'] as List<dynamic>?) ?? const []).cast<Map<String, dynamic>>();
  }

  @override
  void initState() {
    super.initState();
    final existing = formDataOf(widget.request);
    for (final e in existing.entries) {
      _dateValues[e.key] = e.value?.toString();
    }
    if (!_isRevision) _loadChoices();
  }

  Future<void> _loadChoices() async {
    try {
      final types = dataList(await widget.api.get('/document-types'));
      final residents = dataList(await widget.api.get('/document-requests/eligible-residents'));
      setState(() {
        _types = types;
        _residents = residents;
        _residentId = residents.isEmpty ? null : residents.first['id'] as String;
      });
    } on ApiException catch (e) {
      setState(() => _loadError = e.message);
    }
  }

  TextEditingController _controllerFor(String key) =>
      _fieldControllers.putIfAbsent(key, () => TextEditingController(text: _dateValues[key]));

  Future<void> _save() async {
    if (!_form.currentState!.validate()) return;
    final missingDate = _fields.where((f) => f['type'] == 'date' && f['required'] == true && _dateValues[f['key']] == null);
    if (missingDate.isNotEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('${missingDate.first['label']} wajib diisi.')));
      return;
    }
    setState(() => _saving = true);

    final formData = <String, dynamic>{
      for (final f in _fields)
        f['key'] as String: f['type'] == 'date'
            ? _dateValues[f['key']]
            : (_controllerFor(f['key'] as String).text.trim().isEmpty ? null : _controllerFor(f['key'] as String).text.trim()),
    };
    final body = {'purpose': _purpose.text.trim(), 'form_data': formData};

    final ok = await runApi(
      context,
      () => _isRevision
          ? widget.api.put('/document-requests/${widget.request!['id']}', body)
          : widget.api.post('/document-requests', {...body, 'document_type_id': _typeId, 'resident_id': _residentId}),
      _isRevision ? 'Perbaikan dikirim.' : 'Pengajuan surat dikirim.',
    );
    if (!mounted) return;
    setState(() => _saving = false);
    if (ok) Navigator.pop(context, true);
  }

  Widget _fieldInput(Map<String, dynamic> f) {
    final key = f['key'] as String;
    final required = f['required'] == true;
    final label = required ? '${f['label']} *' : f['label'] as String;

    if (f['type'] == 'date') {
      return DateField(label: f['label'] as String, required: required, value: _dateValues[key], onChanged: (v) => setState(() => _dateValues[key] = v));
    }
    return TextFormField(
      controller: _controllerFor(key),
      keyboardType: f['type'] == 'number' ? TextInputType.number : TextInputType.text,
      maxLines: f['type'] == 'textarea' ? 3 : 1,
      decoration: InputDecoration(labelText: label),
      validator: (v) {
        if (required && (v == null || v.trim().isEmpty)) return 'Wajib diisi';
        if (f['type'] == 'number' && v != null && v.trim().isNotEmpty && num.tryParse(v.trim()) == null) return 'Harus angka';
        return null;
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    final loading = !_isRevision && _types.isEmpty && _loadError == null;
    return Scaffold(
      appBar: AppBar(title: Text(_isRevision ? 'Perbaiki pengajuan' : 'Ajukan surat')),
      body: _loadError != null
          ? Center(child: Padding(padding: const EdgeInsets.all(24), child: Text(_loadError!, textAlign: TextAlign.center)))
          : loading
              ? const Center(child: CircularProgressIndicator())
              : Form(
                  key: _form,
                  child: ListView(
                    padding: const EdgeInsets.all(16),
                    children: [
                      if (_isRevision) ...[
                        Text((widget.request!['document_type'] as Map<String, dynamic>)['name'] as String,
                            style: Theme.of(context).textTheme.titleLarge),
                        if (widget.request!['admin_notes'] != null)
                          Card(
                            child: ListTile(
                              leading: const Icon(Icons.feedback_outlined),
                              title: const Text('Catatan pengurus'),
                              subtitle: Text(widget.request!['admin_notes'] as String),
                            ),
                          ),
                      ] else ...[
                        DropdownButtonFormField<String>(
                          initialValue: _typeId,
                          isExpanded: true,
                          decoration: const InputDecoration(labelText: 'Jenis surat *'),
                          items: [for (final t in _types) DropdownMenuItem(value: t['id'] as String, child: Text(t['name'] as String))],
                          onChanged: (v) => setState(() => _typeId = v),
                          validator: (v) => v == null ? 'Pilih jenis surat' : null,
                        ),
                        if (_typeId != null)
                          Padding(
                            padding: const EdgeInsets.only(top: 4),
                            child: Text(
                              _types.firstWhere((t) => t['id'] == _typeId)['description'] as String? ?? '',
                              style: Theme.of(context).textTheme.bodySmall,
                            ),
                          ),
                        const SizedBox(height: 12),
                        DropdownButtonFormField<String>(
                          initialValue: _residentId,
                          decoration: const InputDecoration(labelText: 'Untuk *'),
                          items: [
                            for (final r in _residents) DropdownMenuItem(value: r['id'] as String, child: Text(r['full_name'] as String)),
                          ],
                          onChanged: (v) => setState(() => _residentId = v),
                          validator: (v) => v == null ? 'Pilih warga' : null,
                        ),
                      ],
                      const SizedBox(height: 12),
                      TextFormField(
                        controller: _purpose,
                        maxLines: 2,
                        decoration: const InputDecoration(labelText: 'Keperluan *', hintText: 'mis. Melamar pekerjaan'),
                        validator: (v) => (v == null || v.trim().isEmpty) ? 'Keperluan wajib diisi' : null,
                      ),
                      for (final f in _fields) ...[const SizedBox(height: 12), _fieldInput(f)],
                      const SizedBox(height: 24),
                      FilledButton(onPressed: _saving ? null : _save, child: Text(_saving ? 'Mengirim…' : 'Kirim')),
                    ],
                  ),
                ),
    );
  }
}
