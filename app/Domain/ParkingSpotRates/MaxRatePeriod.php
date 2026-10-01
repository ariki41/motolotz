<?php

namespace App\Domain\ParkingSpotRates;

enum MaxRatePeriod: string
{
    case Entry24Hours = 'entry_24_hours';
    case EntryCustomHours = 'entry_custom_hours';
    case UntilMidnight = 'until_midnight';
    case UntilRatePeriodEnds = 'until_rate_period_ends';

    public function label(): string
    {
        return match ($this) {
            self::Entry24Hours => '入庫から24時間',
            self::EntryCustomHours => '入庫から指定時間',
            self::UntilMidnight => '当日24時まで',
            self::UntilRatePeriodEnds => '料金時間帯の終了まで',
        };
    }
}
