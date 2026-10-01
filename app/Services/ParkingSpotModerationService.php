<?php

namespace App\Services;

use App\Models\ParkingSpot;
use App\Models\ParkingSpotBusinessHour;
use App\Models\ParkingSpotModerationAction;
use App\Models\ParkingSpotRates;
use App\Models\ParkingSpotReport;
use App\Models\ParkingSpotUpdateHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ParkingSpotModerationService
{
    public function hide(ParkingSpot $parkingSpot, User $actor, string $reason, bool $resolveRelatedReports = false): void
    {
        DB::transaction(function () use ($parkingSpot, $actor, $reason, $resolveRelatedReports): void {
            $parkingSpot = ParkingSpot::query()->lockForUpdate()->findOrFail($parkingSpot->id);
            $pendingReportIds = $resolveRelatedReports ? $this->pendingReportIds($parkingSpot) : [];
            if (! $parkingSpot->is_published) {
                $this->resolveReports($pendingReportIds, $actor);

                return;
            }

            $parkingSpot->is_published = false;
            $parkingSpot->lock_version++;
            $parkingSpot->save();
            $this->recordAction($parkingSpot, $actor, 'hidden', reason: $reason);
            $this->resolveReports($pendingReportIds, $actor);
        });
    }

    public function publish(ParkingSpot $parkingSpot, User $actor, string $reason, bool $resolveRelatedReports = false): void
    {
        DB::transaction(function () use ($parkingSpot, $actor, $reason, $resolveRelatedReports): void {
            $parkingSpot = ParkingSpot::query()->lockForUpdate()->findOrFail($parkingSpot->id);
            $pendingReportIds = $resolveRelatedReports ? $this->pendingReportIds($parkingSpot) : [];
            if ($parkingSpot->is_published) {
                $this->resolveReports($pendingReportIds, $actor);

                return;
            }

            $parkingSpot->is_published = true;
            $parkingSpot->lock_version++;
            $parkingSpot->save();
            $this->recordAction($parkingSpot, $actor, 'published', reason: $reason);
            $this->resolveReports($pendingReportIds, $actor);
        });
    }

    public function restoreToHistory(ParkingSpot $parkingSpot, ParkingSpotUpdateHistory $target, User $actor, string $reason, bool $resolveRelatedReports = false): void
    {
        DB::transaction(function () use ($parkingSpot, $target, $actor, $reason, $resolveRelatedReports): void {
            $parkingSpot = ParkingSpot::query()->lockForUpdate()->findOrFail($parkingSpot->id);
            $pendingReportIds = $resolveRelatedReports ? $this->pendingReportIds($parkingSpot) : [];
            $parkingSpot->load(['rates', 'businessHours']);
            $originalRates = $this->normalizeRates($parkingSpot->rates);
            $originalBusinessHours = $this->normalizeBusinessHours($parkingSpot->businessHours);
            $histories = $parkingSpot->updateHistories()
                // 履歴は差分なので、指定時点より後の変更を新しい順に打ち消して復元する。
                ->where(function ($query) use ($target): void {
                    $query->where('created_at', '>', $target->created_at)
                        ->orWhere(function ($query) use ($target): void {
                            $query->where('created_at', $target->created_at)
                                ->where('id', '>', $target->id);
                        });
                })
                ->get();
            foreach ($histories as $history) {
                foreach ($history->getAttribute('changes') ?? [] as $field => $change) {
                    if (! in_array($field, ['rates', 'images', 'business_hours'], true) && array_key_exists('before', $change)) {
                        $parkingSpot->setAttribute($field, $change['before']);
                    }
                    if ($field === 'rates' && array_key_exists('before', $change)) {
                        $this->replaceRates($parkingSpot, $change['before']);
                    }
                    if ($field === 'business_hours' && array_key_exists('before', $change)) {
                        $this->replaceBusinessHours($parkingSpot, $change['before']);
                    }
                }
            }
            $changes = collect($parkingSpot->getDirty())
                ->except('updated_at')
                ->mapWithKeys(fn ($after, string $field) => [
                    $field => ['before' => $parkingSpot->getOriginal($field), 'after' => $after],
                ])
                ->all();
            $parkingSpot->lock_version++;
            $parkingSpot->save();

            $updatedRates = $this->normalizeRates($parkingSpot->rates()->get());
            if ($originalRates !== $updatedRates) {
                $changes['rates'] = ['before' => $originalRates, 'after' => $updatedRates];
            }

            $updatedBusinessHours = $this->normalizeBusinessHours($parkingSpot->businessHours()->get());
            if ($originalBusinessHours !== $updatedBusinessHours) {
                $changes['business_hours'] = ['before' => $originalBusinessHours, 'after' => $updatedBusinessHours];
            }

            ParkingSpotUpdateHistory::create([
                'parking_spot_id' => $parkingSpot->id,
                'user_id' => $actor->id,
                'changes' => $changes,
            ]);
            $this->recordAction($parkingSpot, $actor, 'restored', $target, $reason);
            $this->resolveReports($pendingReportIds, $actor);
        });
    }

    /**
     * @return list<int>
     */
    private function pendingReportIds(ParkingSpot $parkingSpot): array
    {
        // The parent row lock establishes this operation's report scope; later reports belong to a subsequent review.
        return ParkingSpotReport::query()
            ->where('parking_spot_id', $parkingSpot->id)
            ->where('status', 'pending')
            ->lockForUpdate()
            ->pluck('id')
            ->all();
    }

    /**
     * @param  list<int>  $reportIds
     */
    protected function resolveReports(array $reportIds, User $actor): void
    {
        if ($reportIds === []) {
            return;
        }

        ParkingSpotReport::query()
            ->whereKey($reportIds)
            ->update([
                'status' => 'resolved',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ]);
    }

    private function replaceRates(ParkingSpot $parkingSpot, array $rates): void
    {
        $parkingSpot->rates()->delete();
        foreach ($rates as $rate) {
            ParkingSpotRates::create([
                'parking_spot_id' => $parkingSpot->id,
                ...$rate,
            ]);
        }
    }

    /** @param list<array<string, mixed>> $businessHours */
    private function replaceBusinessHours(ParkingSpot $parkingSpot, array $businessHours): void
    {
        $parkingSpot->businessHours()->delete();
        foreach ($businessHours as $businessHour) {
            $parkingSpot->businessHours()->create([
                'day_type' => $businessHour['day_type'],
                'is_closed' => (bool) ($businessHour['is_closed'] ?? false),
                'opening_time' => $this->databaseTime($businessHour['opening_time']),
                'closing_time' => $this->databaseTime($businessHour['closing_time']),
            ]);
        }
    }

    private function normalizeRates(iterable $rates): array
    {
        return collect($rates)->map(fn (ParkingSpotRates $rate) => [
            'day_type' => $rate->day_type,
            'start_time' => substr((string) $rate->start_time, 0, 5),
            'end_time' => substr((string) $rate->end_time, 0, 5),
            'unit_minutes' => $rate->unit_minutes,
            'rate' => $rate->rate,
            'free_minutes' => $rate->free_minutes,
            'max_rate' => $rate->max_rate,
            'max_rate_period' => $rate->max_rate_period,
            'max_rate_period_minutes' => $rate->max_rate_period_minutes,
            'max_rate_repeats' => $rate->max_rate_repeats,
            'post_max_rate_unit_minutes' => $rate->post_max_rate_unit_minutes,
            'post_max_rate' => $rate->post_max_rate,
        ])->values()->all();
    }

    private function normalizeBusinessHours(iterable $businessHours): array
    {
        return collect($businessHours)->map(fn (ParkingSpotBusinessHour $businessHour) => [
            'day_type' => $businessHour->day_type,
            'is_closed' => $businessHour->is_closed,
            'opening_time' => substr((string) $businessHour->opening_time, 0, 5),
            'closing_time' => substr((string) $businessHour->closing_time, 0, 5),
        ])->values()->all();
    }

    private function databaseTime(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : $time;
    }

    private function recordAction(ParkingSpot $parkingSpot, User $actor, string $action, ?ParkingSpotUpdateHistory $history = null, ?string $reason = null): void
    {
        ParkingSpotModerationAction::create([
            'parking_spot_id' => $parkingSpot->id,
            'parking_spot_update_history_id' => $history?->id,
            'user_id' => $actor->id,
            'action' => $action,
            'details' => array_filter([
                'reason' => $reason,
                'restored_to' => $history?->created_at?->toIso8601String(),
            ]),
        ]);
    }
}
