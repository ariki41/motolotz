<?php

namespace App\Models;

use App\Domain\ParkingSpotRates\RateDisplay;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParkingSpotRates extends Model
{
    protected $fillable = [
        'parking_spot_id',
        'day_type',
        'start_time',
        'end_time',
        'unit_minutes',
        'rate',
        'free_minutes',
        'max_rate',
        'max_rate_period',
        'max_rate_period_minutes',
        'max_rate_repeats',
        'post_max_rate_unit_minutes',
        'post_max_rate',
    ];

    protected $casts = [
        'unit_minutes' => 'integer',
        'rate' => 'integer',
        'free_minutes' => 'integer',
        'max_rate' => 'integer',
        'max_rate_period_minutes' => 'integer',
        'max_rate_repeats' => 'boolean',
        'post_max_rate_unit_minutes' => 'integer',
        'post_max_rate' => 'integer',
    ];

    public function getRateLabelAttribute(): string
    {
        return RateDisplay::fromModel($this)->rateLabel;
    }

    public function getBaseRateLabelAttribute(): string
    {
        return RateDisplay::fromModel($this)->baseRateLabel;
    }

    public function getMaxRateLabelAttribute(): string
    {
        return RateDisplay::fromModel($this)->maxRateLabel;
    }

    public function getTimeRangeLabelAttribute(): string
    {
        return RateDisplay::fromModel($this)->timeRangeLabel;
    }

    public static function formatTimeRange(?string $startTime, ?string $endTime): string
    {
        return RateDisplay::formatTimeRange($startTime, $endTime);
    }

    public function parkingSpot(): BelongsTo
    {
        return $this->belongsTo(ParkingSpot::class);
    }
}
