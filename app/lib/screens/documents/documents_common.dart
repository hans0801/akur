import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../api/api_client.dart';

const documentStatusLabels = {
  'submitted': 'Diajukan',
  'under_review': 'Ditinjau',
  'revision_requested': 'Perlu perbaikan',
  'approved': 'Disetujui',
  'rejected': 'Ditolak',
  'issued': 'Terbit',
  'cancelled': 'Dibatalkan',
};

/// Label tombol aksi pengurus untuk status tujuan.
const documentActionLabels = {
  'under_review': 'Mulai tinjau',
  'revision_requested': 'Minta perbaikan',
  'approved': 'Setujui & terbitkan',
  'rejected': 'Tolak',
  'issued': 'Terbitkan surat',
};

class DocumentStatusChip extends StatelessWidget {
  const DocumentStatusChip(this.status, {super.key});

  final String status;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final color = switch (status) {
      'issued' || 'approved' => scheme.primaryContainer,
      'rejected' || 'cancelled' => scheme.errorContainer,
      'revision_requested' => scheme.tertiaryContainer,
      _ => scheme.surfaceContainerHighest,
    };
    return Chip(label: Text(documentStatusLabels[status] ?? status), backgroundColor: color);
  }
}

/// Meminta tautan PDF berumur pendek lalu membukanya di browser untuk diunduh (PDF memuat NIK; unduhan diaudit server).
Future<void> openLetter(BuildContext context, ApiClient api, String requestId) async {
  final messenger = ScaffoldMessenger.of(context);
  try {
    final json = await api.post('/document-requests/$requestId/letter-link');
    final url = Uri.parse((json['data'] as Map<String, dynamic>)['url'] as String);
    final opened = await launchUrl(url, mode: LaunchMode.externalApplication);
    if (!opened) messenger.showSnackBar(const SnackBar(content: Text('Tidak dapat membuka browser.')));
  } on ApiException catch (e) {
    messenger.showSnackBar(SnackBar(content: Text(e.message)));
  }
}

/// Isian surat dari API sebagai map. Toleran terhadap `[]` (array kosong dari PHP) atau null.
Map<String, dynamic> formDataOf(Map<String, dynamic>? request) {
  final raw = request?['form_data'];
  return raw is Map ? Map<String, dynamic>.from(raw) : <String, dynamic>{};
}
