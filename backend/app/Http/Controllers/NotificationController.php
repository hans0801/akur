<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20, ['id', 'type', 'title', 'body', 'payload', 'read_at', 'created_at']);

        return response()->json($notifications);
    }

    public function markRead(Request $request, string $notificationId): JsonResponse
    {
        $notification = AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($notificationId)
            ->firstOrFail();

        $notification->read_at ??= now();
        $notification->save();

        return response()->json(['message' => 'Ditandai sudah dibaca.']);
    }
}
