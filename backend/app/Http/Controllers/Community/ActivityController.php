<?php

namespace App\Http\Controllers\Community;

use App\Models\Activity;
use App\Models\ActivityParticipant;
use App\Models\AdministrativeUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * W6: kegiatan warga per RT/RW dan konfirmasi kehadiran (RSVP).
 * Daftar peserta hanya untuk pengurus; warga hanya melihat jumlah dan status miliknya sendiri.
 */
class ActivityController extends CommunityController
{
    /** Kegiatan di unit yang terlihat. `when=upcoming` (default) atau `past`; `unit_id` opsional. */
    public function index(Request $request): JsonResponse
    {
        $unitIds = $this->access->visibleUnitIds($request->user());
        if ($unitId = $request->query('unit_id')) {
            $this->ensureVisible($request, $unitId);
            $unitIds = [$unitId];
        }
        $past = $request->query('when') === 'past';

        $page = Activity::query()
            ->whereIn('unit_id', $unitIds)
            ->when(
                $past,
                fn ($q) => $q->whereRaw('coalesce(ends_at, starts_at) < now()')->orderByDesc('starts_at'),
                fn ($q) => $q->whereRaw('coalesce(ends_at, starts_at) >= now()')->orderBy('starts_at'),
            )
            ->with(['unit:id,name,unit_type,parent_unit_id', 'organizer:id,display_name'])
            ->withCount(['participants as going_count' => fn ($q) => $q->where('participation_status', 'going')])
            ->paginate(20);

        $mine = $this->myStatuses($request, $page->getCollection()->pluck('id')->all());
        $page->getCollection()->transform(fn (Activity $a) => $this->present($request, $a, $mine[$a->id] ?? null));

        return response()->json($page);
    }

    public function show(Request $request, Activity $activity): JsonResponse
    {
        $this->ensureVisible($request, $activity->unit_id);
        $activity->load(['unit', 'organizer:id,display_name'])
            ->loadCount(['participants as going_count' => fn ($q) => $q->where('participation_status', 'going')]);

        $data = $this->present($request, $activity, $this->myStatuses($request, [$activity->id])[$activity->id] ?? null);
        if ($data['can_edit']) {
            $data['participants'] = $activity->participants()
                ->with(['resident:id,full_name', 'user:id,display_name'])
                ->orderBy('created_at')
                ->get()
                ->map(fn (ActivityParticipant $p) => [
                    'id' => $p->id,
                    'name' => $p->resident?->full_name ?? $p->user?->display_name,
                    'participation_status' => $p->participation_status,
                    'checked_in_at' => $p->checked_in_at?->toIso8601String(),
                ]);
        }

        return response()->json(['data' => $data]);
    }

    public function store(Request $request, AdministrativeUnit $unit): JsonResponse
    {
        $this->authorizeWrite($request, $unit);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:10000'],
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'location_text' => ['required', 'string', 'max:500'],
            'category' => ['required', Rule::in(Activity::CATEGORIES)],
        ]);

        $activity = DB::transaction(function () use ($request, $unit, $data) {
            $activity = Activity::query()->create([
                ...$data,
                'unit_id' => $unit->id,
                'organizer_user_id' => $request->user()->id,
                'status' => Activity::STATUS_PLANNED,
            ]);
            $this->audit->log('activity.created', 'activity', $activity->id, $unit->id, [
                'category' => $activity->category,
                'starts_at' => $activity->starts_at->toIso8601String(),
            ]);
            $this->notifyMembers($unit, $request->user(), 'activity.created', "Kegiatan {$unit->name}", $activity->title, [
                'activity_id' => $activity->id,
            ]);

            return $activity;
        });

        return response()->json(['data' => $this->present($request, $activity->load(['unit', 'organizer:id,display_name']), null)], 201);
    }

    public function update(Request $request, Activity $activity): JsonResponse
    {
        $unit = $activity->unit;
        $this->authorizeWrite($request, $unit);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'string', 'max:10000'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'location_text' => ['sometimes', 'string', 'max:500'],
            'category' => ['sometimes', Rule::in(Activity::CATEGORIES)],
            'status' => ['sometimes', Rule::in(Activity::STATUSES)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        abort_if($activity->status === Activity::STATUS_CANCELLED, 409, 'Kegiatan sudah dibatalkan.');

        DB::transaction(function () use ($request, $activity, $unit, $data) {
            $activity->fill(collect($data)->except('reason')->all());
            abort_if($activity->ends_at !== null && $activity->ends_at->lt($activity->starts_at), 422, 'Waktu selesai harus setelah waktu mulai.');

            $changes = [];
            foreach (array_keys($activity->getDirty()) as $key) {
                $changes[$key] = $key === 'description' ? true : [$activity->getOriginal($key), $activity->getAttribute($key)];
            }
            $activity->save();
            if ($changes === []) {
                return;
            }

            $this->audit->log('activity.updated', 'activity', $activity->id, $unit->id, ['changes' => $changes, 'reason' => $data['reason'] ?? null]);

            if ($activity->status === Activity::STATUS_CANCELLED) {
                $this->notifyMembers($unit, $request->user(), 'activity.cancelled', "Kegiatan dibatalkan · {$unit->name}", $activity->title, [
                    'activity_id' => $activity->id,
                ]);
            } elseif (isset($changes['starts_at']) || isset($changes['location_text'])) {
                $this->notifyMembers($unit, $request->user(), 'activity.rescheduled', "Kegiatan berubah · {$unit->name}", $activity->title, [
                    'activity_id' => $activity->id,
                ]);
            }
        });

        return response()->json(['data' => $this->present($request, $activity->refresh()->load(['unit', 'organizer:id,display_name']), null)]);
    }

    /** RSVP milik pengguna sendiri, tercatat atas data warganya bila sudah ditautkan. */
    public function participate(Request $request, Activity $activity): JsonResponse
    {
        $this->ensureVisible($request, $activity->unit_id);
        $data = $request->validate(['status' => ['required', Rule::in(ActivityParticipant::STATUSES)]]);
        abort_if($activity->status !== Activity::STATUS_PLANNED, 409, 'Kegiatan ini tidak menerima konfirmasi kehadiran.');
        abort_if(($activity->ends_at ?? $activity->starts_at)->isPast(), 409, 'Kegiatan sudah berlalu.');

        $user = $request->user();
        $residentId = $user->resident?->id;

        $participant = DB::transaction(function () use ($activity, $user, $residentId, $data) {
            $participant = ActivityParticipant::query()
                ->where('activity_id', $activity->id)
                ->where(fn ($q) => $residentId ? $q->where('resident_id', $residentId) : $q->whereNull('resident_id')->where('user_id', $user->id))
                ->lockForUpdate()
                ->first();

            $participant ??= new ActivityParticipant(['activity_id' => $activity->id, 'resident_id' => $residentId]);
            $participant->fill(['user_id' => $user->id, 'participation_status' => $data['status']])->save();

            return $participant;
        });

        return response()->json(['data' => ['participation_status' => $participant->participation_status]]);
    }

    public function checkIn(Request $request, Activity $activity, ActivityParticipant $participant): JsonResponse
    {
        $this->authorizeWrite($request, $activity->unit);
        abort_if($participant->activity_id !== $activity->id, 404);

        $participant->checked_in_at ??= now();
        $participant->save();
        $this->audit->log('activity.checked_in', 'activity_participant', $participant->id, $activity->unit_id, ['activity_id' => $activity->id]);

        return response()->json(['data' => ['checked_in_at' => $participant->checked_in_at->toIso8601String()]]);
    }

    /**
     * @param  list<string>  $activityIds
     * @return array<string, string>
     */
    private function myStatuses(Request $request, array $activityIds): array
    {
        $user = $request->user();
        $residentId = $user->resident?->id;

        return ActivityParticipant::query()
            ->whereIn('activity_id', $activityIds)
            ->where(fn ($q) => $residentId ? $q->where('resident_id', $residentId) : $q->where('user_id', $user->id))
            ->pluck('participation_status', 'activity_id')
            ->all();
    }

    /** @return array<string, mixed> */
    private function present(Request $request, Activity $a, ?string $myStatus): array
    {
        return [
            'id' => $a->id,
            'unit' => self::unitSummary($a->unit),
            'title' => $a->title,
            'description' => $a->description,
            'starts_at' => $a->starts_at->toIso8601String(),
            'ends_at' => $a->ends_at?->toIso8601String(),
            'location_text' => $a->location_text,
            'category' => $a->category,
            'status' => $a->status,
            'organizer_name' => $a->organizer?->display_name,
            'going_count' => (int) ($a->going_count ?? 0),
            'my_status' => $myStatus,
            'can_edit' => $this->canWrite($request->user(), $a->unit),
            'updated_at' => $a->updated_at?->toIso8601String(),
        ];
    }
}
