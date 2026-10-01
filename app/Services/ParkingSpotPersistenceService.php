<?php

namespace App\Services;

use App\Exceptions\ParkingSpotVersionConflictException;
use App\Models\ParkingSpot;
use App\Models\ParkingSpotBusinessHour;
use App\Models\ParkingSpotRates;
use App\Models\ParkingSpotUpdateHistory;
use App\Models\Postalcode;
use App\Models\User;
use App\ValueObjects\PersistedParkingSpotImages;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ParkingSpotPersistenceService
{
    public function __construct(
        private readonly ParkingSpotImageService $images,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, User $createdBy): ParkingSpot
    {
        $postalcode = $this->postalcode($input['postalcode']);
        $persistedImages = null;

        try {
            $parkingSpot = DB::transaction(function () use ($input, $postalcode, $createdBy, &$persistedImages): ParkingSpot {
                $parkingSpot = new ParkingSpot;
                $parkingSpot->user_id = $createdBy->id;
                $this->fillParkingSpot($parkingSpot, $input, $postalcode);
                $parkingSpot->save();

                $persistedImages = $this->images->persistConfirmedImages(
                    $parkingSpot,
                    $this->confirmedImagePaths($input),
                );
                $parkingSpot->image_path = $persistedImages->paths[0] ?? null;
                $parkingSpot->save();
                $this->images->replaceParkingSpotImages($parkingSpot, $persistedImages->paths, $createdBy);
                $this->saveParkingSpotRates($parkingSpot, $input['rates']);
                $this->saveBusinessHours($parkingSpot, $this->businessHoursInput($input));

                return $parkingSpot;
            });
        } catch (\Throwable $exception) {
            if ($persistedImages instanceof PersistedParkingSpotImages) {
                // ファイルストレージはDBトランザクションに含まれないため、ロールバック時は新規作成分を補償削除する。
                $this->images->deleteImagePaths($persistedImages->createdPaths);
            }

            throw $exception;
        }

        // DBコミット成功後にだけ一時画像を消し、失敗時には確認画面から再試行できるようにする。
        $this->images->deleteImagePaths($persistedImages->temporaryPaths);

        return $parkingSpot;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(array $input, User $updatedBy): ParkingSpot
    {
        $postalcode = $this->postalcode($input['postalcode']);
        $persistedImages = null;
        $removedImagePaths = [];

        try {
            $parkingSpot = DB::transaction(function () use ($input, $postalcode, $updatedBy, &$persistedImages, &$removedImagePaths): ParkingSpot {
                $parkingSpot = ParkingSpot::query()
                    // 画像・料金・更新履歴を一体で更新するため、同時編集で変更差分を取り違えないようにする。
                    ->lockForUpdate()
                    ->findOrFail($input['id']);
                $parkingSpot->load(['images', 'rates', 'businessHours']);

                if ((int) ($input['lock_version'] ?? 0) !== $parkingSpot->lock_version) {
                    throw new ParkingSpotVersionConflictException;
                }

                $originalImagePaths = $parkingSpot->image_paths;
                $originalRates = $this->normalizeStoredRates($parkingSpot);
                $originalBusinessHours = $this->normalizeStoredBusinessHours($parkingSpot);

                $this->fillParkingSpot($parkingSpot, $input, $postalcode);
                // 関連する料金・画像・営業時間だけが変わる場合も、確認内容を古くする。
                $parkingSpot->lock_version++;
                $persistedImages = $this->images->persistConfirmedImages(
                    $parkingSpot,
                    $this->confirmedImagePaths($input),
                );
                $parkingSpot->image_path = $persistedImages->paths[0] ?? null;

                $changes = collect($parkingSpot->getDirty())
                    // 先頭画像はimagesの変更としてまとめ、代表画像のミラー値を重複して履歴化しない。
                    ->except(['image_path', 'lock_version', 'updated_at'])
                    ->mapWithKeys(fn ($after, string $field) => [
                        $field => [
                            'before' => $parkingSpot->getOriginal($field),
                            'after' => $after,
                        ],
                    ])
                    ->all();

                $parkingSpot->save();
                $this->images->replaceParkingSpotImages($parkingSpot, $persistedImages->paths, $updatedBy);

                if ($originalImagePaths !== $persistedImages->paths) {
                    $changes['images'] = [
                        'before' => $originalImagePaths,
                        'after' => $persistedImages->paths,
                    ];
                }

                $parkingSpot->rates()->delete();
                $this->saveParkingSpotRates($parkingSpot, $input['rates']);

                $updatedRates = $this->normalizeInputRates($input['rates']);
                if ($originalRates !== $updatedRates) {
                    $changes['rates'] = [
                        'before' => $originalRates,
                        'after' => $updatedRates,
                    ];
                }

                $parkingSpot->businessHours()->delete();
                $businessHours = $this->businessHoursInput($input);
                $this->saveBusinessHours($parkingSpot, $businessHours);
                $updatedBusinessHours = $this->normalizeInputBusinessHours($businessHours);
                if ($originalBusinessHours !== $updatedBusinessHours) {
                    $changes['business_hours'] = ['before' => $originalBusinessHours, 'after' => $updatedBusinessHours];
                }

                ParkingSpotUpdateHistory::create([
                    'parking_spot_id' => $parkingSpot->id,
                    'user_id' => $updatedBy->id,
                    'changes' => $changes,
                ]);

                $removedImagePaths = array_values(array_diff($originalImagePaths, $persistedImages->paths));

                return $parkingSpot;
            });
        } catch (\Throwable $exception) {
            if ($persistedImages instanceof PersistedParkingSpotImages) {
                // ファイルストレージはDBトランザクションに含まれないため、ロールバック時は新規作成分を補償削除する。
                $this->images->deleteImagePaths($persistedImages->createdPaths);
            }

            throw $exception;
        }

        // DBコミット成功後にだけ一時画像と旧画像を消し、失敗時の表示と再試行用データを残す。
        $this->images->deleteImagePaths([
            ...$persistedImages->temporaryPaths,
            ...$removedImagePaths,
        ]);

        return $parkingSpot;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function fillParkingSpot(ParkingSpot $parkingSpot, array $input, Postalcode $postalcode): void
    {
        $parkingSpot->name = $input['name'];
        $parkingSpot->postalcode()->associate($postalcode);
        $parkingSpot->address = $input['address'];
        $parkingSpot->longitude = $input['longitude'];
        $parkingSpot->latitude = $input['latitude'];
        $representative = $this->representativeBusinessHour($this->businessHoursInput($input));
        $parkingSpot->opening_time = $this->normalizeDatabaseTime($representative['opening_time']);
        $parkingSpot->closing_time = $this->normalizeDatabaseTime($representative['closing_time']);
        $parkingSpot->capacity = $input['capacity'];
        $parkingSpot->max_displacement_class = $input['max_displacement_class'];
    }

    private function postalcode(string $postalcode): Postalcode
    {
        $postalcode = Postalcode::query()
            ->active()
            ->where('postalcode', str_replace('-', '', $postalcode))
            ->first();

        if ($postalcode === null) {
            throw ValidationException::withMessages([
                'postalcode' => '郵便番号に対応する住所が見つかりません。',
            ]);
        }

        return $postalcode;
    }

    private function normalizeStoredRates(ParkingSpot $parkingSpot): array
    {
        return $parkingSpot->rates
            ->map(fn (ParkingSpotRates $rate) => [
                'day_type' => $rate->day_type,
                'start_time' => substr((string) $rate->start_time, 0, 5),
                'end_time' => substr((string) $rate->end_time, 0, 5),
                'unit_minutes' => (int) $rate->unit_minutes,
                'rate' => (int) $rate->rate,
                'free_minutes' => (int) $rate->free_minutes,
                'max_rate' => $rate->max_rate === null ? null : (int) $rate->max_rate,
                'max_rate_period' => $rate->max_rate_period,
                'max_rate_period_minutes' => $rate->max_rate_period_minutes,
                'max_rate_repeats' => (bool) $rate->max_rate_repeats,
                'post_max_rate_unit_minutes' => $rate->post_max_rate_unit_minutes,
                'post_max_rate' => $rate->post_max_rate,
            ])
            ->values()
            ->all();
    }

    private function normalizeInputRates(array $rates): array
    {
        return collect($rates)
            ->map(fn (array $rate) => [
                'day_type' => $rate['day_type'],
                'start_time' => substr($rate['start_time'], 0, 5),
                'end_time' => substr($rate['end_time'], 0, 5),
                'unit_minutes' => (int) $rate['unit_minutes'],
                'rate' => (int) $rate['rate'],
                'free_minutes' => (int) ($rate['free_minutes'] ?? 0),
                'max_rate' => ($rate['no_max_rate'] ?? false)
                    ? null
                    : (isset($rate['max_rate']) ? (int) $rate['max_rate'] : null),
                'max_rate_period' => ($rate['no_max_rate'] ?? false) ? null : ($rate['max_rate_period'] ?? null),
                'max_rate_period_minutes' => ($rate['no_max_rate'] ?? false) ? null : ($rate['max_rate_period_minutes'] ?? null),
                'max_rate_repeats' => ($rate['no_max_rate'] ?? false) ? null : (bool) ($rate['max_rate_repeats'] ?? false),
                'post_max_rate_unit_minutes' => ($rate['no_max_rate'] ?? false) ? null : ($rate['post_max_rate_unit_minutes'] ?? null),
                'post_max_rate' => ($rate['no_max_rate'] ?? false) ? null : ($rate['post_max_rate'] ?? null),
            ])
            ->values()
            ->all();
    }

    private function normalizeStoredBusinessHours(ParkingSpot $parkingSpot): array
    {
        return $parkingSpot->businessHours->map(fn (ParkingSpotBusinessHour $hour) => [
            'day_type' => $hour->day_type,
            'is_closed' => $hour->is_closed,
            'opening_time' => substr((string) $hour->opening_time, 0, 5),
            'closing_time' => substr((string) $hour->closing_time, 0, 5),
        ])->values()->all();
    }

    private function normalizeInputBusinessHours(array $businessHours): array
    {
        return collect($businessHours)->map(fn (array $hour) => [
            'day_type' => $hour['day_type'],
            'is_closed' => (bool) ($hour['is_closed'] ?? false),
            'opening_time' => substr($hour['opening_time'], 0, 5),
            'closing_time' => substr($hour['closing_time'], 0, 5),
        ])->values()->all();
    }

    private function normalizeDatabaseTime(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : $time;
    }

    private function saveParkingSpotRates(ParkingSpot $parkingSpot, array $rates): void
    {
        foreach ($rates as $rate) {
            ParkingSpotRates::create([
                'parking_spot_id' => $parkingSpot->id,
                'day_type' => $rate['day_type'],
                'start_time' => $rate['start_time'],
                'end_time' => $rate['end_time'],
                'unit_minutes' => $rate['unit_minutes'],
                'rate' => $rate['rate'],
                'free_minutes' => $rate['free_minutes'] ?? 0,
                'max_rate' => ($rate['no_max_rate'] ?? false) ? null : ($rate['max_rate'] ?? null),
                'max_rate_period' => ($rate['no_max_rate'] ?? false) ? null : ($rate['max_rate_period'] ?? null),
                'max_rate_period_minutes' => ($rate['no_max_rate'] ?? false) ? null : ($rate['max_rate_period_minutes'] ?? null),
                'max_rate_repeats' => ($rate['no_max_rate'] ?? false) ? null : (bool) ($rate['max_rate_repeats'] ?? false),
                'post_max_rate_unit_minutes' => ($rate['no_max_rate'] ?? false) ? null : ($rate['post_max_rate_unit_minutes'] ?? null),
                'post_max_rate' => ($rate['no_max_rate'] ?? false) ? null : ($rate['post_max_rate'] ?? null),
            ]);
        }
    }

    private function saveBusinessHours(ParkingSpot $parkingSpot, array $businessHours): void
    {
        foreach ($this->normalizeInputBusinessHours($businessHours) as $hour) {
            $parkingSpot->businessHours()->create([
                ...$hour,
                'opening_time' => $this->normalizeDatabaseTime($hour['opening_time']),
                'closing_time' => $this->normalizeDatabaseTime($hour['closing_time']),
            ]);
        }
    }

    private function businessHoursInput(array $input): array
    {
        return isset($input['business_hours']) && is_array($input['business_hours']) && $input['business_hours'] !== []
            ? $input['business_hours']
            : [['day_type' => '全日', 'is_closed' => false, 'opening_time' => $input['opening_time'], 'closing_time' => $input['closing_time']]];
    }

    private function representativeBusinessHour(array $businessHours): array
    {
        $openHours = collect($businessHours)->filter(fn (array $hour) => ! ($hour['is_closed'] ?? false));
        $hour = $openHours->first(fn (array $hour) => ($hour['day_type'] ?? null) === '全日')
            ?? $openHours->first()
            ?? ['opening_time' => '00:00', 'closing_time' => '00:00'];

        return ['opening_time' => $hour['opening_time'] ?? '00:00', 'closing_time' => $hour['closing_time'] ?? '00:00'];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function confirmedImagePaths(array $input): array
    {
        if (isset($input['image_paths']) && is_array($input['image_paths'])) {
            return $input['image_paths'];
        }

        return array_filter([$input['image_path'] ?? null]);
    }
}
