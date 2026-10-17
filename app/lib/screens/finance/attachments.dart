import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../api/api_client.dart';
import '../../widgets/async_view.dart';

/// Pilih foto (kamera/galeri), unggah sebagai berkas privat, dan kembalikan id berkasnya.
/// Gambar diperkecil di perangkat agar hemat kuota dan di bawah batas unggahan server (5 MB).
class AttachmentPicker extends StatefulWidget {
  const AttachmentPicker({super.key, required this.api, required this.purpose, required this.label, required this.onChanged, this.resize = true});

  final ApiClient api;

  /// `payment_proof`, `finance_receipt`, `letter_signature`, atau `letter_logo`.
  final String purpose;
  final String label;

  /// false untuk tanda tangan/logo: berkas asli (PNG transparan) tidak diperkecil atau dikonversi ke JPEG.
  final bool resize;
  final ValueChanged<String?> onChanged;

  @override
  State<AttachmentPicker> createState() => _AttachmentPickerState();
}

class _AttachmentPickerState extends State<AttachmentPicker> {
  String? _fileName;
  bool _uploading = false;

  Future<void> _pick(ImageSource source) async {
    final messenger = ScaffoldMessenger.of(context);
    final picked = widget.resize
        ? await ImagePicker().pickImage(source: source, maxWidth: 1600, maxHeight: 1600, imageQuality: 80)
        : await ImagePicker().pickImage(source: source);
    if (picked == null) return;

    setState(() => _uploading = true);
    try {
      final json = await widget.api.upload('/files', bytes: await picked.readAsBytes(), filename: picked.name, fields: {'purpose': widget.purpose});
      final id = (json['data'] as Map<String, dynamic>)['id'] as String;
      setState(() => _fileName = picked.name);
      widget.onChanged(id);
    } on ApiException catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(friendlyError(e))));
    } finally {
      if (mounted) setState(() => _uploading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_uploading) return const ListTile(leading: CircularProgressIndicator(), title: Text('Mengunggah…'));
    if (_fileName != null) {
      return ListTile(
        contentPadding: EdgeInsets.zero,
        leading: const Icon(Icons.attach_file),
        title: Text(_fileName!, overflow: TextOverflow.ellipsis),
        trailing: IconButton(
          tooltip: 'Hapus lampiran',
          icon: const Icon(Icons.close),
          onPressed: () {
            setState(() => _fileName = null);
            widget.onChanged(null);
          },
        ),
      );
    }
    return Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
      Text(widget.label),
      OutlinedButton.icon(onPressed: () => _pick(ImageSource.camera), icon: const Icon(Icons.photo_camera_outlined), label: const Text('Kamera')),
      OutlinedButton.icon(onPressed: () => _pick(ImageSource.gallery), icon: const Icon(Icons.photo_library_outlined), label: const Text('Galeri')),
    ]);
  }
}

/// Buka lampiran (bukti bayar/nota) lewat tautan bertanda tangan berumur pendek.
Future<void> openAttachment(BuildContext context, ApiClient api, String fileId) async {
  final messenger = ScaffoldMessenger.of(context);
  try {
    final json = await api.post('/files/$fileId/link');
    final url = Uri.parse((json['data'] as Map<String, dynamic>)['url'] as String);
    if (!await launchUrl(url, mode: LaunchMode.externalApplication)) {
      messenger.showSnackBar(const SnackBar(content: Text('Tidak dapat membuka browser.')));
    }
  } on ApiException catch (e) {
    messenger.showSnackBar(SnackBar(content: Text(friendlyError(e))));
  }
}
