<?php

namespace Tests\Feature;

use App\Models\ParkingSpotRates;
use App\Services\ParkingSpotConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\InteractsWithParkingSpotRateFixtures;
use Tests\TestCase;

class ParkingSpotRateDetailDisplayTest extends TestCase
{
    use InteractsWithParkingSpotRateFixtures;
    use RefreshDatabase;

    public function test_parking_spot_detail_displays_rates(): void
    {
        [$parkingSpot, $user] = $this->createParkingSpot();

        ParkingSpotRates::create([
            'parking_spot_id' => $parkingSpot->id,
            'day_type' => '平日',
            'start_time' => '08:00:00',
            'end_time' => '20:00:00',
            'unit_minutes' => 30,
            'rate' => 100,
            'free_minutes' => 30,
            'max_rate' => 1200,
            'max_rate_period' => 'entry_24_hours',
            'max_rate_repeats' => true,
        ]);

        $response = $this->actingAs($user)
            ->get(route('parking_spot.show', $parkingSpot->id));

        $response->assertOk();
        $response->assertSee('〒100-0001');
        $response->assertSee('東京都千代田区千代田1-1');
        $response->assertSee('適用曜日');
        $response->assertSee('時間帯');
        $response->assertSee('料金');
        $response->assertSee('最大料金');
        $response->assertSee('平日');
        $response->assertSee('08:00');
        $response->assertSee('20:00');
        $response->assertSee('最初の30分無料 / 以降30分 100円');
        $response->assertSee('1,200円');
        $response->assertSee('入庫から24時間・繰り返し適用');
    }

    public function test_parking_spot_detail_displays_no_max_rate_label(): void
    {
        [$parkingSpot, $user] = $this->createParkingSpot();

        ParkingSpotRates::create([
            'parking_spot_id' => $parkingSpot->id,
            'day_type' => '全日',
            'start_time' => '00:00:00',
            'end_time' => '00:00:00',
            'unit_minutes' => 30,
            'rate' => 100,
            'free_minutes' => 0,
            'max_rate' => null,
        ]);

        $response = $this->actingAs($user)
            ->get(route('parking_spot.show', $parkingSpot->id));

        $response->assertOk();
        $response->assertSee('30分 100円');
        $response->assertSee('最大料金なし');
        $response->assertSee('00:00 ～ 24:00');
        $response->assertDontSee('最初の0分無料');
        $response->assertDontSee('以降30分 100円');
    }

    public function test_parking_spot_detail_displays_a_rate_after_a_custom_max_rate_period(): void
    {
        [$parkingSpot, $user] = $this->createParkingSpot();

        ParkingSpotRates::create([
            'parking_spot_id' => $parkingSpot->id,
            'day_type' => '全日', 'start_time' => '00:00:00', 'end_time' => '00:00:00',
            'unit_minutes' => 30, 'rate' => 100, 'free_minutes' => 0, 'max_rate' => 1200,
            'max_rate_period' => 'entry_custom_hours', 'max_rate_period_minutes' => 720,
            'post_max_rate_unit_minutes' => 30, 'post_max_rate' => 100,
        ]);

        $this->actingAs($user)
            ->get(route('parking_spot.show', $parkingSpot->id))
            ->assertOk()
            ->assertSee('入庫から12時間・1回限り・適用後 30分ごとに100円加算');
    }

    public function test_parking_spot_create_form_can_select_max_rate_conditions(): void
    {
        [, $user] = $this->createParkingSpot();

        $response = $this->actingAs($user)->get(route('parking_spot.create'));

        $response->assertOk();
        $response->assertSee('name="rates[0][max_rate_period]"', false);
        $response->assertSeeText('入庫から24時間');
        $response->assertSeeText('入庫から指定時間');
        $response->assertSeeText('当日24時まで');
        $response->assertSeeText('料金時間帯の終了まで');
        $response->assertSee('name="rates[0][max_rate_repeats]"', false);
        $response->assertSeeText('繰り返し適用する');
        $response->assertSeeText('最大料金の適用後に加算する料金（任意）');
    }

    public function test_parking_spot_detail_displays_free_rate_label(): void
    {
        [$parkingSpot, $user] = $this->createParkingSpot();

        ParkingSpotRates::create([
            'parking_spot_id' => $parkingSpot->id,
            'day_type' => '全日',
            'start_time' => '00:00:00',
            'end_time' => '00:00:00',
            'unit_minutes' => 30,
            'rate' => 0,
            'free_minutes' => 0,
            'max_rate' => null,
        ]);

        $response = $this->actingAs($user)
            ->get(route('parking_spot.show', $parkingSpot->id));

        $response->assertOk();
        $response->assertSee('無料');
        $response->assertDontSee('30分 0円');
    }

    public function test_parking_spot_detail_displays_overnight_rate_end_time_as_next_day(): void
    {
        [$parkingSpot, $user] = $this->createParkingSpot();

        ParkingSpotRates::create([
            'parking_spot_id' => $parkingSpot->id,
            'day_type' => '全日',
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'unit_minutes' => 60,
            'rate' => 200,
            'free_minutes' => 0,
            'max_rate' => 800,
        ]);

        $response = $this->actingAs($user)
            ->get(route('parking_spot.show', $parkingSpot->id));

        $response->assertOk();
        $response->assertSee('22:00 ～ 翌06:00');
    }

    public function test_parking_spot_detail_displays_placeholder_without_rates(): void
    {
        [$parkingSpot, $user] = $this->createParkingSpot();

        $response = $this->actingAs($user)
            ->get(route('parking_spot.show', $parkingSpot->id));

        $response->assertOk();
        $response->assertSee('料金未登録');
        $response->assertSee('/images/noimage.jpg');
    }

    public function test_parking_spot_create_form_displays_rate_unit_select(): void
    {
        [, $user] = $this->createParkingSpot();

        $response = $this->actingAs($user)
            ->get(route('parking_spot.create'));

        $response->assertOk();
        $response->assertSee('name="rates[0][unit_minutes]"', false);
        $response->assertSee('<option value="12"', false);
        $response->assertSee('<option value="15"', false);
        $response->assertSee('<option value="30" selected', false);
        $response->assertDontSee('<option value="昼間"', false);
        $response->assertDontSee('<option value="夜間"', false);
        $response->assertSee('<option value="60"', false);
        $response->assertSee('<option value="120"', false);
        $response->assertSeeText('2時間');
        $response->assertSee('<option value="180"', false);
        $response->assertSeeText('3時間');
        $response->assertSee('<option value="240"', false);
        $response->assertSeeText('4時間');
        $response->assertSee('<option value="300"', false);
        $response->assertSeeText('5時間');
        $response->assertSee('<option value="720"', false);
        $response->assertSeeText('12時間');
        $response->assertSee('<option value="1440"', false);
        $response->assertSeeText('24時間');
    }

    public function test_parking_spot_create_form_can_select_no_free_minutes(): void
    {
        [, $user] = $this->createParkingSpot();

        $response = $this->actingAs($user)
            ->get(route('parking_spot.create'));

        $response->assertOk();
        $response->assertSeeText('無料時間なし');
        $response->assertSee('name="rates[0][no_free_minutes]"', false);
        $response->assertSee('data-rate-field="no_free_minutes"', false);
    }

    public function test_parking_spot_create_form_can_select_free_rate(): void
    {
        [, $user] = $this->createParkingSpot();

        $response = $this->actingAs($user)
            ->get(route('parking_spot.create'));

        $response->assertOk();
        $response->assertSeeText('無料');
        $response->assertSee('name="rates[0][is_free]"', false);
        $response->assertSee('data-rate-field="is_free"', false);
    }

    public function test_edit_form_disables_rate_related_fields_for_an_existing_free_rate(): void
    {
        [$parkingSpot, $user] = $this->createParkingSpot();

        ParkingSpotRates::create([
            'parking_spot_id' => $parkingSpot->id,
            'day_type' => '全日',
            'start_time' => '00:00:00',
            'end_time' => '00:00:00',
            'unit_minutes' => 30,
            'rate' => 0,
            'free_minutes' => 0,
            'max_rate' => null,
        ]);

        $response = $this->actingAs($user)->get(route('parking_spot.edit', $parkingSpot));

        $response->assertOk();
        $response->assertSee('data-rate-field="unit_minutes" required disabled', false);
        $response->assertSee('class="free-minutes-input bp-input cursor-not-allowed bg-slate-100 text-slate-500"', false);
        $response->assertSee('class="max-rate-input bp-input cursor-not-allowed bg-slate-100 text-slate-500"', false);
        $response->assertSee('data-rate-field="max_rate_period" disabled', false);
        $response->assertSeeText('無料をチェックすると料金単位・無料時間・最大料金・適用期間を無効化します。');
    }

    public function test_no_free_minutes_input_is_normalized_on_confirm(): void
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
                        'free_minutes' => 30,
                        'no_free_minutes' => '1',
                    ]),
                ],
            ]));

        $response->assertOk();
        $response->assertSessionMissing('errors');
        $response->assertSessionHas(ParkingSpotConfirmationService::SESSION_KEY.'.input.rates.0.free_minutes', 0);
        $response->assertDontSee('最初の30分無料');
        $response->assertDontSee('最初の0分無料');
        $response->assertSee('30分 100円');
    }

    public function test_overnight_rate_end_time_is_displayed_as_next_day_on_confirm(): void
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
                        'start_time' => '22:00',
                        'end_time' => '06:00',
                    ]),
                ],
            ]));

        $response->assertOk();
        $response->assertSessionMissing('errors');
        $response->assertSee('22:00 ～ 翌06:00');
    }

    public function test_full_day_rate_is_displayed_as_midnight_to_24_on_confirm(): void
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
                        'start_time' => '00:00',
                        'end_time' => '00:00',
                    ]),
                ],
            ]));

        $response->assertOk();
        $response->assertSessionMissing('errors');
        $response->assertSee('00:00 ～ 24:00');
    }
}
