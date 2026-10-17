<?php

namespace App\Http\Controllers\Community;

use App\Models\AdministrativeUnit;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * W6: pengumuman per RT/RW. Draf dan jadwal terbit hanya terlihat oleh pengurus unit;
 * warga melihat yang sedang tayang di unitnya dan RW induknya.
 */
class AnnouncementController extends CommunityController
{
    /** Linimasa pengumuman yang sedang tayang untuk pengguna ini. */
    public function feed(Request $request): JsonResponse
    {
        $unitIds = $this->access->visibleUnitIds($request->user());
        if ($unitId = $request->query('unit_id')) {
            $this->ensureVisible($request, $unitId);
            $unitIds = [$unitId];
        }

        $page = Announcement::query()
            ->live()
            ->whereIn('unit_id', $unitIds)
            ->with(['unit:id,name,unit_type,parent_unit_id', 'unit.parent:id,name', 'author:id,display_name'])
            ->orderByRaw('coalesce(publish_at, created_at) DESC')
            ->paginate(20);

        return $this->page($request, $page);
    }

    /** Daftar kelola (termasuk draf/terjadwal/arsip) untuk pengurus unit. */
    public function index(Request $request, AdministrativeUnit $unit): JsonResponse
    {
        $this->authorizeWrite($request, $unit);

        $page = Announcement::query()
            ->whereIn('unit_id', $unit->selfAndDescendantIds())
            ->when(in_array($request->query('status'), Announcement::STATUSES, true), fn ($q) => $q->where('status', $request->query('status')))
            ->with(['unit:id,name,unit_type,parent_unit_id', 'unit.parent:id,name', 'author:id,display_name'])
            ->latest('updated_at')
            ->paginate(20);

        return $this->page($request, $page);
    }

    public function show(Request $request, Announcement $announcement): JsonResponse
    {
        $announcement->load(['unit', 'author:id,display_name']);
        if ($announcement->isLive()) {
            $this->ensureVisible($request, $announcement->unit_id);
        } else {
            $this->authorizeWrite($request, $announcement->unit);
        }

        return response()->json(['data' => $this->present($request, $announcement)]);
    }

    public function store(Request $request, AdministrativeUnit $unit): JsonResponse
    {
        $this->authorizeWrite($request, $unit);
        $data = $request->validate([
            ...$this->rules($request),
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
            'status' => ['sometimes', Rule::in([Announcement::STATUS_DRAFT, Announcement::STATUS_PUBLISHED])],
        ]);

        $announcement = DB::transaction(function () use ($request, $unit, $data) {
            $announcement = Announcement::query()->create([
                ...$data,
                'unit_id' => $unit->id,
                'status' => $data['status'] ?? Announcement::STATUS_DRAFT,
                'author_user_id' => $request->user()->id,
            ]);
            $this->audit->log('announcement.created', 'announcement', $announcement->id, $unit->id, [
                'status' => $announcement->status,
                'publish_at' => $announcement->publish_at?->toIso8601String(),
            ]);
            $this->notifyIfLive($request, $announcement, $unit);

            return $announcement;
        });

        return response()->json(['data' => $this->present($request, $announcement->load(['unit', 'author:id,display_name']))], 201);
    }

    public function update(Request $request, Announcement $announcement): JsonResponse
    {
        $unit = $announcement->unit;
        $this->authorizeWrite($request, $unit);
        $data = $request->validate([
            ...$this->rules($request),
            'title' => ['sometimes', 'string', 'max:200'],
            'body' => ['sometimes', 'string', 'max:10000'],
            'status' => ['sometimes', Rule::in(Announcement::STATUSES)],
        ]);

        DB::transaction(function () use ($request, $announcement, $unit, $data) {
            $wasLive = $announcement->isLive();
            $announcement->fill($data);

            $changes = [];
            foreach (array_keys($announcement->getDirty()) as $key) {
                // Isi panjang tidak disalin ke audit; cukup tanda bahwa isi berubah.
                $changes[$key] = $key === 'body' ? true : [$announcement->getOriginal($key), $announcement->getAttribute($key)];
            }
            $announcement->save();

            if ($changes !== []) {
                $this->audit->log('announcement.updated', 'announcement', $announcement->id, $unit->id, ['changes' => $changes]);
            }
            if (! $wasLive) {
                $this->notifyIfLive($request, $announcement, $unit);
            }
        });

        return response()->json(['data' => $this->present($request, $announcement->refresh()->load(['unit', 'author:id,display_name']))]);
    }

    /** @return array<string, list<mixed>> */
    private function rules(Request $request): array
    {
        return [
            'publish_at' => ['sometimes', 'nullable', 'date'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now', ...($request->filled('publish_at') ? ['after:publish_at'] : [])],
        ];
    }

    /**
     * Pengumuman terjadwal (publish_at di masa depan) belum dinotifikasi; penjadwal notifikasi menyusul.
     */
    private function notifyIfLive(Request $request, Announcement $announcement, AdministrativeUnit $unit): void
    {
        if ($announcement->isLive()) {
            $this->notifyMembers($unit, $request->user(), 'announcement.published', "Pengumuman {$unit->label()}", $announcement->title, [
                'announcement_id' => $announcement->id,
            ]);
        }
    }

    private function page(Request $request, $page): JsonResponse
    {
        $page->getCollection()->transform(fn (Announcement $a) => $this->present($request, $a));

        return response()->json($page);
    }

    /** @return array<string, mixed> */
    private function present(Request $request, Announcement $a): array
    {
        return [
            'id' => $a->id,
            'unit' => self::unitSummary($a->unit),
            'title' => $a->title,
            'body' => $a->body,
            'status' => $a->status,
            'is_live' => $a->isLive(),
            'publish_at' => $a->publish_at?->toIso8601String(),
            'expires_at' => $a->expires_at?->toIso8601String(),
            'author_name' => $a->author?->display_name,
            'can_edit' => $this->canWrite($request->user(), $a->unit),
            'created_at' => $a->created_at?->toIso8601String(),
            'updated_at' => $a->updated_at?->toIso8601String(),
        ];
    }
}
