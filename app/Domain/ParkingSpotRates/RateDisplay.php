<?php

namespace App\Domain\ParkingSpotRates;

use App\Models\ParkingSpotRates;

/**
 * The canonical, view-facing representation of a parking rate.
 *
 * It accepts both persisted models and the validated arrays shown on the
 * confirmation page, so a rate is described identically before and after it
 * is saved.
 */
final readonly class RateDisplay
{
    private function __construct(
        public string $dayType,
        public string $timeRangeLabel,
        public string $baseRateLabel,
        public string $maxRateLabel,
        public string $maxRateConditionLabel,
        public string $rateLabel,
    ) {}

    public static function fromModel(ParkingSpotRates $rate): self
    {
        return self::fromValues(
            $rate->day_type,
            $rate->start_time,
            $rate->end_time,
            $rate->unit_minutes,
            $rate->rate,
            $rate->free_minutes,
            $rate->max_rate,
            $rate->max_rate_period,
            $rate->max_rate_period_minutes,
            (bool) ($rate->max_rate_repeats ?? false),
            $rate->post_max_rate_unit_minutes,
            $rate->post_max_rate,
        );
    }

    /**
     * @param  array{day_type?: mixed, start_time?: mixed, end_time?: mixed, unit_minutes?: mixed, rate?: mixed, free_minutes?: mixed, max_rate?: mixed, max_rate_period?: mixed, max_rate_period_minutes?: mixed, max_rate_repeats?: mixed, post_max_rate_unit_minutes?: mixed, post_max_rate?: mixed}  $rate
     */
    public static function fromArray(array $rate): self
    {
        return self::fromValues(
            (string) ($rate['day_type'] ?? ''),
            self::nullableString($rate['start_time'] ?? null),
            self::nullableString($rate['end_time'] ?? null),
            (int) ($rate['unit_minutes'] ?? 0),
            (int) ($rate['rate'] ?? 0),
            (int) ($rate['free_minutes'] ?? 0),
            self::nullableInteger($rate['max_rate'] ?? null),
            self::nullableString($rate['max_rate_period'] ?? null),
            self::nullableInteger($rate['max_rate_period_minutes'] ?? null),
            (bool) ($rate['max_rate_repeats'] ?? false),
            self::nullableInteger($rate['post_max_rate_unit_minutes'] ?? null),
            self::nullableInteger($rate['post_max_rate'] ?? null),
        );
    }

    public static function formatTimeRange(?string $startTime, ?string $endTime): string
    {
        // 料金設定では00:00から00:00を同時刻ではなく終日料金として扱う。
        if (self::isFullDayRange($startTime, $endTime)) {
            return '00:00 ～ 24:00';
        }

        $startLabel = self::formatTimeLabel($startTime);
        $endLabel = self::formatTimeLabel($endTime, self::isOvernight($startTime, $endTime));

        return "{$startLabel} ～ {$endLabel}";
    }

    private static function fromValues(
        string $dayType,
        ?string $startTime,
        ?string $endTime,
        int $unitMinutes,
        int $rate,
        int $freeMinutes,
        ?int $maxRate,
        ?string $maxRatePeriod,
        ?int $maxRatePeriodMinutes,
        bool $maxRateRepeats,
        ?int $postMaxRateUnitMinutes,
        ?int $postMaxRate,
    ): self {
        if ($rate === 0) {
            return new self(
                $dayType,
                self::formatTimeRange($startTime, $endTime),
                '無料',
                self::maxRateLabel($maxRate),
                self::maxRateConditionLabel($maxRate, $maxRatePeriod, $maxRatePeriodMinutes, $maxRateRepeats, $postMaxRateUnitMinutes, $postMaxRate),
                '無料',
            );
        }

        $baseRateLabel = self::formatMinutes($unitMinutes).' '.number_format($rate).'円';

        if ($freeMinutes > 0) {
            $baseRateLabel = '最初の'.self::formatMinutes($freeMinutes).'無料 / 以降'.$baseRateLabel;
        }

        $maxRateLabel = self::maxRateLabel($maxRate);
        $maxRateConditionLabel = self::maxRateConditionLabel($maxRate, $maxRatePeriod, $maxRatePeriodMinutes, $maxRateRepeats, $postMaxRateUnitMinutes, $postMaxRate);

        return new self(
            $dayType,
            self::formatTimeRange($startTime, $endTime),
            $baseRateLabel,
            $maxRateLabel,
            $maxRateConditionLabel,
            $baseRateLabel.($maxRate === null
                ? ' / 最大料金なし'
                : ' / 最大 '.$maxRateLabel.($maxRateConditionLabel === '適用条件未設定' ? '' : '（'.$maxRateConditionLabel.'）')),
        );
    }

    private static function maxRateLabel(?int $maxRate): string
    {
        if ($maxRate === null) {
            return '最大料金なし';
        }

        return number_format($maxRate).'円';
    }

    private static function maxRateConditionLabel(?int $maxRate, ?string $period, ?int $periodMinutes, bool $repeats, ?int $postUnitMinutes, ?int $postRate): string
    {
        if ($maxRate === null) {
            return '—';
        }

        $condition = MaxRatePeriod::tryFrom((string) $period);

        if ($condition === null) {
            return '適用条件未設定';
        }

        $periodLabel = $condition === MaxRatePeriod::EntryCustomHours && $periodMinutes !== null
            ? '入庫から'.self::formatMinutes($periodMinutes)
            : $condition->label();
        $postRateLabel = $postUnitMinutes !== null && $postRate !== null
            ? '・適用後 '.self::formatMinutes($postUnitMinutes).'ごとに'.number_format($postRate).'円加算'
            : '';

        return $periodLabel.'・'.($repeats ? '繰り返し適用' : '1回限り').$postRateLabel;
    }

    private static function isOvernight(?string $startTime, ?string $endTime): bool
    {
        return $startTime !== null && $endTime !== null
            && self::normalizeTime($startTime) > self::normalizeTime($endTime);
    }

    private static function isFullDayRange(?string $startTime, ?string $endTime): bool
    {
        return $startTime !== null && $endTime !== null
            && self::normalizeTime($startTime) === '00:00'
            && self::normalizeTime($endTime) === '00:00';
    }

    private static function formatTimeLabel(?string $time, bool $isNextDay = false): string
    {
        if ($time === null || $time === '') {
            return '';
        }

        $label = self::normalizeTime($time);

        if (! $isNextDay && $label === '00:00') {
            return '24:00';
        }

        return $isNextDay ? "翌{$label}" : $label;
    }

    private static function normalizeTime(string $time): string
    {
        return date('H:i', strtotime($time));
    }

    private static function formatMinutes(int $minutes): string
    {
        if ($minutes >= 60 && $minutes % 60 === 0) {
            return ($minutes / 60).'時間';
        }

        return "{$minutes}分";
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private static function nullableInteger(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
