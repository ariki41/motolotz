<?php

namespace Tests\Feature;

use App\Domain\ParkingSpots\EngineDisplacementClass;
use App\Models\ParkingSpot;
use App\Models\ParkingSpotRates;
use App\Services\ParkingSpotConfirmationService;
use App\Services\ParkingSpotPersistenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteractsWithParkingSpotRateFixtures;
use Tests\TestCase;

class ParkingSpotRatePersistenceTest extends TestCase
{
    use InteractsWithParkingSpotRateFixtures;
    use RefreshDatabase;

    public function test_parking_spot_can_save_multiple_rates(): void
    {
        [$parkingSpot, $user, $postalcode] = $this->createParkingSpot();

        $this->actingAs($user);

        app(ParkingSpotPersistenceService::class)->create([
            'name' => '複数料金テスト駐輪場',
            'postalcode' => $postalcode->postalcode,
            'address' => '東京都千代田区千代田1-2',
            'longitude' => 139.753000,
            'latitude' => 35.685000,
            'capacity' => 1,
            'max_displacement_class' => EngineDisplacementClass::UpTo400cc->value,
            'opening_time' => '00:00',
            'closing_time' => '00:00',
            'rates' => [
                [
                    'day_type' => '平日',
                    'start_time' => '08:00',
                    'end_time' => '20:00',
                    'unit_minutes' => 30,
                    'rate' => 100,
                    'free_minutes' => 0,
                    'max_rate' => 1200,
                ],
                [
                    'day_type' => '土日祝',
                    'start_time' => '08:00',
                    'end_time' => '20:00',
                    'unit_minutes' => 60,
                    'rate' => 300,
                    'free_minutes' => 30,
                    'max_rate' => 1800,
                ],
            ],
        ], $user);

        $this->assertDatabaseHas('parking_spot_rates', [
            'day_type' => '平日',
            'unit_minutes' => 30,
            'rate' => 100,
        ]);
        $this->assertDatabaseHas('parking_spot_rates', [
            'day_type' => '土日祝',
            'unit_minutes' => 60,
            'rate' => 300,
            'free_minutes' => 30,
        ]);
        $this->assertDatabaseCount('parking_spot_rates', 2);
    }

    public function test_parking_spot_store_creates_rates_from_confirmed_form(): void
    {
        [, $user, $postalcode] = $this->createParkingSpot();
        Storage::fake('public');
        $tempImagePath = UploadedFile::fake()->image('confirmed.jpg')->store('temp/parking-spots', 'public');

        $input = [
            'name' => '登録Featureテスト駐輪場',
            'postalcode' => $postalcode->postalcode,
            'address' => '東京都千代田区千代田1-2',
            'longitude' => 139.753000,
            'latitude' => 35.685000,
            'capacity' => 1,
            'max_displacement_class' => EngineDisplacementClass::UpTo400cc->value,
            'image_path' => $tempImagePath,
            'opening_time' => '00:00',
            'closing_time' => '00:00',
            'rates' => [
                $this->validRateInput([
                    'day_type' => '平日',
                    'start_time' => '08:00',
                    'end_time' => '20:00',
                    'unit_minutes' => 30,
                    'rate' => 100,
                    'free_minutes' => 0,
                    'max_rate' => 1200,
                ]),
            ],
        ];

        $response = $this->actingAs($user)
            ->withSession($this->confirmationState(ParkingSpotConfirmationService::MODE_CREATE, $input))
            ->post(route('parking_spot.store'));

        $response
            ->assertRedirect(route('home'))
            ->assertSessionHas('success', '駐輪場を登録しました。');
        $this->assertDatabaseHas('parking_spots', [
            'name' => '登録Featureテスト駐輪場',
            'user_id' => $user->id,
            'postalcode_id' => $postalcode->id,
        ]);
        $parkingSpot = ParkingSpot::where('name', '登録Featureテスト駐輪場')->firstOrFail();
        $this->assertNotNull($parkingSpot->image_path);
        $this->assertMatchesRegularExpression(
            '/^parking-spots\/'.$parkingSpot->id.'_\d{17}\.webp$/',
            $parkingSpot->image_path
        );
        Storage::disk('public')->assertExists($parkingSpot->image_path);
        Storage::disk('public')->assertMissing($tempImagePath);
        $this->assertDatabaseHas('parking_spot_rates', [
            'day_type' => '平日',
            'start_time' => '08:00',
            'end_time' => '20:00',
            'unit_minutes' => 30,
            'rate' => 100,
            'free_minutes' => 0,
            'max_rate' => 1200,
        ]);
    }

    public function test_parking_spot_can_save_rate_without_max_rate(): void
    {
        [, $user, $postalcode] = $this->createParkingSpot();

        $this->actingAs($user);

        app(ParkingSpotPersistenceService::class)->create([
            'name' => '最大料金なしテスト駐輪場',
            'postalcode' => $postalcode->postalcode,
            'address' => '東京都千代田区千代田1-2',
            'longitude' => 139.753000,
            'latitude' => 35.685000,
            'capacity' => 1,
            'max_displacement_class' => EngineDisplacementClass::UpTo400cc->value,
            'opening_time' => '00:00',
            'closing_time' => '00:00',
            'rates' => [
                [
                    'day_type' => '全日',
                    'start_time' => '00:00',
                    'end_time' => '00:00',
                    'unit_minutes' => 30,
                    'rate' => 100,
                    'free_minutes' => 0,
                    'max_rate' => '',
                    'no_max_rate' => '1',
                ],
            ],
        ], $user);

        $this->assertDatabaseHas('parking_spot_rates', [
            'day_type' => '全日',
            'rate' => 100,
            'max_rate' => null,
        ]);
    }

    public function test_parking_spot_persists_max_rate_conditions(): void
    {
        [, $user, $postalcode] = $this->createParkingSpot();

        app(ParkingSpotPersistenceService::class)->create([
            'name' => '最大料金条件テスト駐輪場',
            'postalcode' => $postalcode->postalcode,
            'address' => '東京都千代田区千代田1-2',
            'longitude' => 139.753000,
            'latitude' => 35.685000,
            'capacity' => 1,
            'max_displacement_class' => EngineDisplacementClass::UpTo400cc->value,
            'opening_time' => '00:00',
            'closing_time' => '00:00',
            'rates' => [[
                'day_type' => '全日', 'start_time' => '00:00', 'end_time' => '00:00',
                'unit_minutes' => 30, 'rate' => 100, 'free_minutes' => 0, 'max_rate' => 1200,
                'max_rate_period' => 'until_midnight', 'max_rate_repeats' => true,
            ]],
        ], $user);

        $this->assertDatabaseHas('parking_spot_rates', [
            'max_rate' => 1200,
            'max_rate_period' => 'until_midnight',
            'max_rate_repeats' => true,
        ]);
    }

    public function test_parking_spot_persists_a_rate_after_a_custom_max_rate_period(): void
    {
        [, $user, $postalcode] = $this->createParkingSpot();

        app(ParkingSpotPersistenceService::class)->create([
            'name' => '最大料金後加算テスト駐輪場',
            'postalcode' => $postalcode->postalcode,
            'address' => '東京都千代田区千代田1-2',
            'longitude' => 139.753000,
            'latitude' => 35.685000,
            'capacity' => 1,
            'max_displacement_class' => EngineDisplacementClass::UpTo400cc->value,
            'opening_time' => '00:00',
            'closing_time' => '00:00',
            'rates' => [[
                'day_type' => '全日', 'start_time' => '00:00', 'end_time' => '00:00',
                'unit_minutes' => 30, 'rate' => 100, 'free_minutes' => 0, 'max_rate' => 1200,
                'max_rate_period' => 'entry_custom_hours', 'max_rate_period_minutes' => 720,
                'post_max_rate_unit_minutes' => 30, 'post_max_rate' => 100,
            ]],
        ], $user);

        $this->assertDatabaseHas('parking_spot_rates', [
            'max_rate_period' => 'entry_custom_hours',
            'max_rate_period_minutes' => 720,
            'post_max_rate_unit_minutes' => 30,
            'post_max_rate' => 100,
        ]);
    }

    public function test_free_rate_is_normalized_before_persistence(): void
    {
        [, $user, $postalcode] = $this->createParkingSpot();
        Http::fake([
            '*' => Http::response([
                'Feature' => [[
                    'Geometry' => ['Coordinates' => '139.753000,35.685000'],
                    'Property' => ['Address' => '東京都千代田区千代田1-2'],
                ]],
            ]),
        ]);

        $response = $this->actingAs($user)
            ->from(route('parking_spot.create'))
            ->post(route('parking_spot.confirm'), $this->validParkingSpotInput($postalcode, [
                'rates' => [$this->validRateInput([
                    'rate' => 100,
                    'free_minutes' => 30,
                    'max_rate' => 1200,
                    'is_free' => '1',
                ])],
            ]));

        $response->assertOk();
        $response->assertSee('無料');
        $response->assertSessionHas(ParkingSpotConfirmationService::SESSION_KEY.'.input.rates.0.rate', 0);
        $response->assertSessionHas(ParkingSpotConfirmationService::SESSION_KEY.'.input.rates.0.no_max_rate', '1');

        $this->post(route('parking_spot.store'))
            ->assertRedirect(route('home'));

        $this->assertDatabaseHas('parking_spot_rates', [
            'rate' => 0,
            'free_minutes' => 0,
            'max_rate' => null,
        ]);
    }

    public function test_parking_spot_can_replace_rates_on_update(): void
    {
        [$parkingSpot, $user] = $this->createParkingSpot();

        ParkingSpotRates::create([
            'parking_spot_id' => $parkingSpot->id,
            'day_type' => '平日',
            'start_time' => '08:00:00',
            'end_time' => '20:00:00',
            'unit_minutes' => 30,
            'rate' => 100,
            'free_minutes' => 0,
            'max_rate' => 1200,
        ]);

        app(ParkingSpotPersistenceService::class)->update([
            'id' => $parkingSpot->id,
            'lock_version' => $parkingSpot->fresh()->lock_version,
            'name' => $parkingSpot->name,
            'postalcode' => '1000001',
            'address' => $parkingSpot->address,
            'longitude' => $parkingSpot->longitude,
            'latitude' => $parkingSpot->latitude,
            'capacity' => $parkingSpot->capacity,
            'max_displacement_class' => $parkingSpot->max_displacement_class?->value
                ?? EngineDisplacementClass::UpTo400cc->value,
            'opening_time' => '00:00',
            'closing_time' => '00:00',
            'rates' => [
                [
                    'day_type' => '全日',
                    'start_time' => '20:00',
                    'end_time' => '08:00',
                    'unit_minutes' => 60,
                    'rate' => 200,
                    'free_minutes' => 0,
                    'max_rate' => 800,
                ],
                [
                    'day_type' => '全日',
                    'start_time' => '00:00',
                    'end_time' => '00:00',
                    'unit_minutes' => 15,
                    'rate' => 50,
                    'free_minutes' => 0,
                    'max_rate' => 500,
                ],
            ],
        ], $user);

        $this->assertDatabaseMissing('parking_spot_rates', [
            'parking_spot_id' => $parkingSpot->id,
            'day_type' => '平日',
        ]);
        $this->assertDatabaseHas('parking_spot_rates', [
            'parking_spot_id' => $parkingSpot->id,
            'day_type' => '全日',
            'rate' => 200,
        ]);
        $this->assertDatabaseHas('parking_spot_rates', [
            'parking_spot_id' => $parkingSpot->id,
            'day_type' => '全日',
            'rate' => 50,
        ]);
        $this->assertDatabaseCount('parking_spot_rates', 2);
    }

    public function test_parking_spot_update_replaces_rates_from_confirmed_form(): void
    {
        [$parkingSpot, $user, $postalcode] = $this->createParkingSpot();
        Storage::fake('public');
        Storage::disk('public')->put('parking-spots/original.jpg', 'original');
        $parkingSpot->forceFill(['image_path' => 'parking-spots/original.jpg'])->save();
        $tempImagePath = UploadedFile::fake()->image('updated.jpg')->store('temp/parking-spots', 'public');

        ParkingSpotRates::create([
            'parking_spot_id' => $parkingSpot->id,
            'day_type' => '平日',
            'start_time' => '08:00:00',
            'end_time' => '20:00:00',
            'unit_minutes' => 30,
            'rate' => 100,
            'free_minutes' => 0,
            'max_rate' => 1200,
        ]);

        $input = [
            'id' => $parkingSpot->id,
            'name' => '更新Featureテスト駐輪場',
            'postalcode' => $postalcode->postalcode,
            'address' => '東京都千代田区千代田1-3',
            'longitude' => 139.754000,
            'latitude' => 35.686000,
            'capacity' => 1,
            'max_displacement_class' => EngineDisplacementClass::UpTo400cc->value,
            'image_path' => $tempImagePath,
            'opening_time' => '00:00',
            'closing_time' => '00:00',
            'rates' => [
                $this->validRateInput([
                    'day_type' => '土日祝',
                    'start_time' => '09:00',
                    'end_time' => '18:00',
                    'unit_minutes' => 60,
                    'rate' => 300,
                    'free_minutes' => 30,
                    'max_rate' => 1800,
                ]),
            ],
        ];

        $response = $this->actingAs($user)
            ->withSession($this->confirmationState(ParkingSpotConfirmationService::MODE_EDIT, $input))
            ->put(route('parking_spot.update', $parkingSpot));

        $response
            ->assertRedirect(route('home'))
            ->assertSessionHas('success', '駐輪場情報を更新しました。');
        $parkingSpot->refresh();
        $this->assertNotSame('parking-spots/original.jpg', $parkingSpot->image_path);
        $this->assertMatchesRegularExpression(
            '/^parking-spots\/'.$parkingSpot->id.'_\d{17}\.webp$/',
            $parkingSpot->image_path
        );
        Storage::disk('public')->assertMissing('parking-spots/original.jpg');
        Storage::disk('public')->assertMissing($tempImagePath);
        Storage::disk('public')->assertExists($parkingSpot->image_path);
        $this->assertDatabaseMissing('parking_spot_rates', [
            'parking_spot_id' => $parkingSpot->id,
            'day_type' => '平日',
        ]);
        $this->assertDatabaseHas('parking_spot_rates', [
            'parking_spot_id' => $parkingSpot->id,
            'day_type' => '土日祝',
            'start_time' => '09:00',
            'end_time' => '18:00',
            'unit_minutes' => 60,
            'rate' => 300,
            'free_minutes' => 30,
            'max_rate' => 1800,
        ]);
        $this->assertDatabaseCount('parking_spot_rates', 1);
    }
}
