<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithParkingSpotRateFixtures;
use Tests\TestCase;

class ParkingSpotRateValidationTest extends TestCase
{
    use InteractsWithParkingSpotRateFixtures;
    use RefreshDatabase;

    #[DataProvider('invalidBusinessHoursProvider')]
    public function test_parking_spot_business_hour_validation_rejects_invalid_nested_input(mixed $businessHours, string $errorKey): void
    {
        [, $user, $postalcode] = $this->createParkingSpot();

        $response = $this->actingAs($user)
            ->from(route('parking_spot.create'))
            ->post(route('parking_spot.confirm'), $this->validParkingSpotInput($postalcode, [
                'business_hours' => $businessHours,
            ]));

        $response->assertRedirect(route('parking_spot.create'));
        $response->assertSessionHasErrors([$errorKey]);
    }

    public static function invalidBusinessHoursProvider(): array
    {
        return [
            'string instead of array' => ['invalid', 'business_hours'],
            'null instead of array' => [null, 'business_hours'],
            'scalar array element' => [['invalid'], 'business_hours.0'],
            'incomplete array element' => [[['day_type' => '全日']], 'business_hours.0.opening_time'],
        ];
    }

    public function test_parking_spot_rate_validation_rejects_zero_yen_max_rate(): void
    {
        [, $user, $postalcode] = $this->createParkingSpot();

        $response = $this->actingAs($user)
            ->from(route('parking_spot.create'))
            ->post(route('parking_spot.confirm'), $this->validParkingSpotInput($postalcode, [
                'rates' => [
                    $this->validRateInput([
                        'max_rate' => 0,
                    ]),
                ],
            ]));

        $response->assertRedirect(route('parking_spot.create'));
        $response->assertSessionHasErrors(['rates.0.max_rate']);
    }

    public function test_parking_spot_rate_validation_requires_custom_max_rate_period_and_complete_post_rate(): void
    {
        [, $user, $postalcode] = $this->createParkingSpot();

        $response = $this->actingAs($user)
            ->from(route('parking_spot.create'))
            ->post(route('parking_spot.confirm'), $this->validParkingSpotInput($postalcode, [
                'rates' => [$this->validRateInput([
                    'max_rate_period' => 'entry_custom_hours',
                    'max_rate_period_minutes' => '',
                    'post_max_rate_unit_minutes' => 30,
                    'post_max_rate' => '',
                ])],
            ]));

        $response->assertRedirect(route('parking_spot.create'));
        $response->assertSessionHasErrors([
            'rates.0.max_rate_period_minutes',
            'rates.0.post_max_rate',
        ]);
    }

    public function test_parking_spot_rate_validation_requires_at_least_one_rate(): void
    {
        [, $user, $postalcode] = $this->createParkingSpot();

        $response = $this->actingAs($user)
            ->from(route('parking_spot.create'))
            ->post(route('parking_spot.confirm'), $this->validParkingSpotInput($postalcode, [
                'rates' => [],
            ]));

        $response->assertRedirect(route('parking_spot.create'));
        $response->assertSessionHasErrors(['rates']);
    }

    public function test_parking_spot_rate_validation_limits_rates_to_four(): void
    {
        [, $user, $postalcode] = $this->createParkingSpot();

        $response = $this->actingAs($user)
            ->from(route('parking_spot.create'))
            ->post(route('parking_spot.confirm'), $this->validParkingSpotInput($postalcode, [
                'rates' => array_fill(0, 5, $this->validRateInput()),
            ]));

        $response->assertRedirect(route('parking_spot.create'));
        $response->assertSessionHasErrors(['rates']);
    }

    public function test_parking_spot_rate_validation_rejects_overlapping_ranges_with_same_day_type(): void
    {
        [, $user, $postalcode] = $this->createParkingSpot();

        $response = $this->actingAs($user)
            ->followingRedirects()
            ->from(route('parking_spot.create'))
            ->post(route('parking_spot.confirm'), $this->validParkingSpotInput($postalcode, [
                'rates' => [
                    $this->validRateInput([
                        'day_type' => '平日',
                        'start_time' => '08:00',
                        'end_time' => '12:00',
                    ]),
                    $this->validRateInput([
                        'day_type' => '平日',
                        'start_time' => '11:00',
                        'end_time' => '15:00',
                    ]),
                ],
            ]));

        $response->assertOk();
        $response->assertSee('料金帯1の「平日」と料金帯2の「平日」は適用条件が重複しています。');
    }

    public function test_parking_spot_rate_validation_rejects_full_day_and_weekday_full_day_combination(): void
    {
        [, $user, $postalcode] = $this->createParkingSpot();

        $response = $this->actingAs($user)
            ->followingRedirects()
            ->from(route('parking_spot.create'))
            ->post(route('parking_spot.confirm'), $this->validParkingSpotInput($postalcode, [
                'rates' => [
                    $this->validRateInput([
                        'day_type' => '全日',
                        'start_time' => '00:00',
                        'end_time' => '00:00',
                    ]),
                    $this->validRateInput([
                        'day_type' => '平日',
                        'start_time' => '00:00',
                        'end_time' => '00:00',
                    ]),
                ],
            ]));

        $response->assertOk();
        $response->assertSee('料金帯1の「全日」と料金帯2の「平日」は適用条件が重複しています。');
    }

    public function test_parking_spot_rate_validation_rejects_legacy_time_of_day_category(): void
    {
        [, $user, $postalcode] = $this->createParkingSpot();

        $response = $this->actingAs($user)
            ->followingRedirects()
            ->from(route('parking_spot.create'))
            ->post(route('parking_spot.confirm'), $this->validParkingSpotInput($postalcode, [
                'rates' => [$this->validRateInput(['day_type' => '昼間'])],
            ]));

        $response->assertOk();
        $response->assertSee('適用曜日を選択してください。');
    }

    public function test_parking_spot_rate_validation_allows_weekday_and_holiday_overlapping_time_ranges(): void
    {
        [, $user, $postalcode] = $this->createParkingSpot();
        Http::fake([
            '*' => Http::response([
                'Feature' => [
                    [
                        'Geometry' => ['Coordinates' => '139.753000,35.685000'],
                        'Property' => ['Address' => '東京都千代田区千代田1-2'],
                    ],
                ],
            ]),
        ]);

        $response = $this->actingAs($user)
            ->from(route('parking_spot.create'))
            ->post(route('parking_spot.confirm'), $this->validParkingSpotInput($postalcode, [
                'rates' => [
                    $this->validRateInput([
                        'day_type' => '土日祝',
                        'start_time' => '10:00',
                        'end_time' => '16:00',
                    ]),
                    $this->validRateInput([
                        'day_type' => '平日',
                        'start_time' => '12:00',
                        'end_time' => '18:00',
                    ]),
                ],
            ]));

        $response->assertOk();
        $response->assertSessionMissing('errors');
    }

    public function test_parking_spot_rate_validation_rejects_overnight_overlap_on_edit_flow(): void
    {
        [$parkingSpot, $user, $postalcode] = $this->createParkingSpot();

        $response = $this->actingAs($user)
            ->followingRedirects()
            ->from(route('parking_spot.edit', $parkingSpot->id))
            ->post(route('parking_spot.confirm'), $this->validParkingSpotInput($postalcode, [
                'id' => $parkingSpot->id,
                'rates' => [
                    $this->validRateInput([
                        'day_type' => '全日',
                        'start_time' => '22:00',
                        'end_time' => '06:00',
                    ]),
                    $this->validRateInput([
                        'day_type' => '全日',
                        'start_time' => '05:30',
                        'end_time' => '09:00',
                    ]),
                ],
            ]));

        $response->assertOk();
        $response->assertSee('料金帯1の「全日」と料金帯2の「全日」は適用条件が重複しています。');
    }

    public function test_parking_spot_rate_validation_allows_adjacent_ranges_with_same_day_type(): void
    {
        [, $user, $postalcode] = $this->createParkingSpot();
        Http::fake([
            '*' => Http::response([
                'Feature' => [
                    [
                        'Geometry' => ['Coordinates' => '139.753000,35.685000'],
                        'Property' => ['Address' => '東京都千代田区千代田1-2'],
                    ],
                ],
            ]),
        ]);

        $response = $this->actingAs($user)
            ->from(route('parking_spot.create'))
            ->post(route('parking_spot.confirm'), $this->validParkingSpotInput($postalcode, [
                'rates' => [
                    $this->validRateInput([
                        'day_type' => '平日',
                        'start_time' => '08:00',
                        'end_time' => '12:00',
                    ]),
                    $this->validRateInput([
                        'day_type' => '平日',
                        'start_time' => '12:00',
                        'end_time' => '18:00',
                    ]),
                ],
            ]));

        $response->assertOk();
        $response->assertSessionMissing('errors');
        $response->assertSee('08:00 ～ 12:00');
        $response->assertSee('12:00 ～ 18:00');
    }

    public function test_parking_spot_rate_validation_allows_adjacent_ranges_with_all_days(): void
    {
        [, $user, $postalcode] = $this->createParkingSpot();
        Http::fake([
            '*' => Http::response([
                'Feature' => [
                    [
                        'Geometry' => ['Coordinates' => '139.753000,35.685000'],
                        'Property' => ['Address' => '東京都千代田区千代田1-2'],
                    ],
                ],
            ]),
        ]);

        $response = $this->actingAs($user)
            ->from(route('parking_spot.create'))
            ->post(route('parking_spot.confirm'), $this->validParkingSpotInput($postalcode, [
                'rates' => [
                    $this->validRateInput([
                        'day_type' => '全日',
                        'start_time' => '08:00',
                        'end_time' => '18:00',
                    ]),
                    $this->validRateInput([
                        'day_type' => '全日',
                        'start_time' => '18:00',
                        'end_time' => '23:00',
                    ]),
                ],
            ]));

        $response->assertOk();
        $response->assertSessionMissing('errors');
        $response->assertSee('08:00 ～ 18:00');
        $response->assertSee('18:00 ～ 23:00');
    }

    public function test_parking_spot_rate_validation_allows_weekday_and_holiday_full_day_combination(): void
    {
        [, $user, $postalcode] = $this->createParkingSpot();
        Http::fake([
            '*' => Http::response([
                'Feature' => [
                    [
                        'Geometry' => ['Coordinates' => '139.753000,35.685000'],
                        'Property' => ['Address' => '東京都千代田区千代田1-2'],
                    ],
                ],
            ]),
        ]);

        $response = $this->actingAs($user)
            ->from(route('parking_spot.create'))
            ->post(route('parking_spot.confirm'), $this->validParkingSpotInput($postalcode, [
                'rates' => [
                    $this->validRateInput([
                        'day_type' => '平日',
                        'start_time' => '00:00',
                        'end_time' => '00:00',
                    ]),
                    $this->validRateInput([
                        'day_type' => '土日祝',
                        'start_time' => '00:00',
                        'end_time' => '00:00',
                    ]),
                ],
            ]));

        $response->assertOk();
        $response->assertSessionMissing('errors');
        $response->assertSee('00:00 ～ 24:00');
    }

    public function test_parking_spot_rate_validation_reports_only_format_error_when_time_fields_are_invalid(): void
    {
        [, $user, $postalcode] = $this->createParkingSpot();

        $response = $this->actingAs($user)
            ->followingRedirects()
            ->from(route('parking_spot.create'))
            ->post(route('parking_spot.confirm'), $this->validParkingSpotInput($postalcode, [
                'rates' => [
                    $this->validRateInput([
                        'day_type' => '全日',
                        'start_time' => '8時',
                        'end_time' => '20:00',
                    ]),
                    $this->validRateInput([
                        'day_type' => '平日',
                        'start_time' => '09:00',
                        'end_time' => '12:00',
                    ]),
                ],
            ]));

        $response->assertOk();
        $response->assertSee('料金開始時間の形式が正しくありません。例: 08:00');
        $response->assertDontSee('適用条件が重複しています。');
    }

    public function test_parking_spot_rate_validation_rejects_multiple_overlaps_from_one_full_day_rate(): void
    {
        [, $user, $postalcode] = $this->createParkingSpot();

        $response = $this->actingAs($user)
            ->followingRedirects()
            ->from(route('parking_spot.create'))
            ->post(route('parking_spot.confirm'), $this->validParkingSpotInput($postalcode, [
                'rates' => [
                    $this->validRateInput([
                        'day_type' => '全日',
                        'start_time' => '00:00',
                        'end_time' => '00:00',
                    ]),
                    $this->validRateInput([
                        'day_type' => '平日',
                        'start_time' => '09:00',
                        'end_time' => '18:00',
                    ]),
                    $this->validRateInput([
                        'day_type' => '土日祝',
                        'start_time' => '10:00',
                        'end_time' => '17:00',
                    ]),
                ],
            ]));

        $response->assertOk();
        $response->assertSee('料金帯1の「全日」と料金帯2の「平日」は適用条件が重複しています。');
        $response->assertSee('料金帯1の「全日」と料金帯3の「土日祝」は適用条件が重複しています。');
        $response->assertDontSee('料金帯2の「平日」と料金帯3の「土日祝」は適用条件が重複しています。');
    }

    public function test_parking_spot_rate_validation_requires_max_rate_when_no_max_rate_is_off(): void
    {
        [, $user, $postalcode] = $this->createParkingSpot();

        $response = $this->actingAs($user)
            ->from(route('parking_spot.create'))
            ->post(route('parking_spot.confirm'), $this->validParkingSpotInput($postalcode, [
                'rates' => [
                    $this->validRateInput([
                        'max_rate' => '',
                    ]),
                ],
            ]));

        $response->assertRedirect(route('parking_spot.create'));
        $response->assertSessionHasErrors(['rates.0.max_rate']);
    }

    public function test_parking_spot_rate_validation_allows_empty_max_rate_when_no_max_rate_is_on(): void
    {
        [, $user, $postalcode] = $this->createParkingSpot();
        Http::fake([
            '*' => Http::response([
                'Feature' => [
                    [
                        'Geometry' => ['Coordinates' => '139.753000,35.685000'],
                        'Property' => ['Address' => '東京都千代田区千代田1-2'],
                    ],
                ],
            ]),
        ]);

        $response = $this->actingAs($user)
            ->from(route('parking_spot.create'))
            ->post(route('parking_spot.confirm'), $this->validParkingSpotInput($postalcode, [
                'rates' => [
                    $this->validRateInput([
                        'max_rate' => '',
                        'no_max_rate' => '1',
                    ]),
                ],
            ]));

        $response->assertOk();
        $response->assertSessionMissing('errors');
    }

    public function test_parking_spot_rate_validation_rejects_invalid_rate_fields(): void
    {
        [, $user, $postalcode] = $this->createParkingSpot();

        $response = $this->actingAs($user)
            ->from(route('parking_spot.create'))
            ->post(route('parking_spot.confirm'), $this->validParkingSpotInput($postalcode, [
                'rates' => [
                    [
                        'day_type' => '祝前日',
                        'start_time' => '8時',
                        'end_time' => '20:00',
                        'unit_minutes' => 0,
                        'rate' => -1,
                        'free_minutes' => -1,
                        'max_rate' => -1,
                    ],
                ],
            ]));

        $response->assertRedirect(route('parking_spot.create'));
        $response->assertSessionHasErrors([
            'rates.0.day_type',
            'rates.0.start_time',
            'rates.0.unit_minutes',
            'rates.0.rate',
            'rates.0.free_minutes',
            'rates.0.max_rate',
        ]);
    }

    public function test_parking_spot_rate_validation_rejects_unconfigured_unit_minutes(): void
    {
        [, $user, $postalcode] = $this->createParkingSpot();

        $response = $this->actingAs($user)
            ->from(route('parking_spot.create'))
            ->post(route('parking_spot.confirm'), $this->validParkingSpotInput($postalcode, [
                'rates' => [
                    $this->validRateInput([
                        'unit_minutes' => 7,
                    ]),
                ],
            ]));

        $response->assertRedirect(route('parking_spot.create'));
        $response->assertSessionHasErrors(['rates.0.unit_minutes']);
    }
}
