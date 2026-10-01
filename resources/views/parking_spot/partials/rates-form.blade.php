<div class="border-t border-slate-200 pt-6" id="parking-spot-rates" data-parking-spot-rates data-max-rates="4">
    <div class="mb-4 flex items-center justify-between gap-4">
        <div>
            <h2 class="bp-section-title">料金</h2>
            <p class="bp-muted mt-1">時間帯ごとの料金を最大4件まで設定できます。</p>
        </div>
        <button
            class="inline-flex items-center justify-center rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700 transition hover:bg-emerald-100"
            id="add-rate-button" data-add-rate type="button">
            料金帯を追加
        </button>
    </div>

    @error('rates')
        <div class="mb-4 text-sm text-red-600">{{ $message }}</div>
    @enderror

    <div id="rate-list" data-rate-list>
        @foreach ($ratesInput as $index => $rate)
            @php
                $itemErrors = collect($errors->getMessages())->filter(
                    fn($messages, $field) => str_starts_with($field, "rates.{$index}."),
                );
            @endphp
            <x-parking-spot.rate-row :index="$index" :messages="$itemErrors" :rate="$rate"
                :rate-day-types="$rateDayTypes" :rate-unit-minutes="$rateUnitMinutes" :max-rate-periods="$maxRatePeriods" />
        @endforeach
    </div>

    <template id="rate-template" data-rate-template>
        <x-parking-spot.rate-row :index="0" :rate="[
            'day_type' => '全日',
            'start_time' => '00:00',
            'end_time' => '00:00',
            'unit_minutes' => 30,
            'rate' => '',
            'is_free' => false,
            'free_minutes' => 0,
            'no_free_minutes' => true,
            'max_rate' => '',
            'no_max_rate' => false,
            'max_rate_period' => 'entry_24_hours',
            'max_rate_period_minutes' => '',
            'max_rate_repeats' => false,
            'post_max_rate_unit_minutes' => '',
            'post_max_rate' => '',
        ]" :rate-day-types="$rateDayTypes" :rate-unit-minutes="$rateUnitMinutes" :max-rate-periods="$maxRatePeriods" template />
    </template>
</div>
