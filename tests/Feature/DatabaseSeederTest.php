<?php

namespace Tests\Feature;

use App\Domain\ParkingSpots\EngineDisplacementClass;
use App\Models\ParkingSpot;
use App\Models\Postalcode;
use App\Models\Prefecture;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PrefectureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    private ?string $postalcodeCsvPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PrefectureSeeder::class);
        $this->postalcodeCsvPath = $this->createNationwidePostalcodeCsv();
        config()->set('parking_spot.sample_data.postalcode_csv_path', $this->postalcodeCsvPath);
        config()->set('parking_spot.sample_data.location_buffer_per_prefecture', 0);
    }

    protected function tearDown(): void
    {
        if ($this->postalcodeCsvPath !== null) {
            @unlink($this->postalcodeCsvPath);
        }

        parent::tearDown();
    }

    public function test_it_skips_required_prefecture_seeding_in_the_testing_environment(): void
    {
        Prefecture::query()->delete();

        $migration = require database_path('migrations/2026_09_30_000001_seed_required_prefectures.php');
        $migration->up();

        $this->assertDatabaseCount('prefectures', 0);
    }

    public function test_it_seeds_users_with_the_existing_prefectures(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertFalse(Schema::hasTable('postalcode_lat_lons'));
        $factoryParkingSpot = ParkingSpot::factory()
            ->for(User::query()->firstOrFail())
            ->for(Postalcode::query()->firstOrFail())
            ->make();
        $this->assertNotNull($factoryParkingSpot->postalcode_id);
        $this->assertContains($factoryParkingSpot->max_displacement_class->value, EngineDisplacementClass::values());
        $this->assertSame(48, Prefecture::query()->count());
        $this->assertSame(100, User::query()->count());
        $this->assertDatabaseCount('parking_spots', 10_000);
        $this->assertGreaterThan(0, DB::table('parking_spot_rates')->count());
        $this->assertGreaterThan(1, DB::table('parking_spots')->distinct()->count('capacity'));
        $this->assertGreaterThan(1, DB::table('parking_spots')->distinct()->count('opening_time'));
        $this->assertGreaterThan(1, DB::table('parking_spot_rates')->distinct()->count('rate'));
        $this->assertSame(
            collect(array_fill_keys(EngineDisplacementClass::values(), 2_500))->sortKeys()->all(),
            DB::table('parking_spots')
                ->selectRaw('max_displacement_class, COUNT(*) as aggregate')
                ->groupBy('max_displacement_class')
                ->pluck('aggregate', 'max_displacement_class')
                ->map(fn (int $count): int => $count)
                ->sortKeys()
                ->all(),
        );
        $this->assertSame(
            0,
            User::query()
                ->whereNotIn('prefecture_id', Prefecture::query()->select('id'))
                ->count(),
        );
        $this->assertSame(
            0,
            DB::table('parking_spots')
                ->join('postalcodes', 'parking_spots.postalcode_id', '=', 'postalcodes.id')
                ->join('cities', 'postalcodes.city_id', '=', 'cities.id')
                ->join('prefectures', 'cities.prefecture_id', '=', 'prefectures.id')
                ->where('prefectures.name', '海外')
                ->count(),
        );

        $parkingSpotCountsByPrefecture = DB::table('parking_spots')
            ->join('postalcodes', 'parking_spots.postalcode_id', '=', 'postalcodes.id')
            ->join('cities', 'postalcodes.city_id', '=', 'cities.id')
            ->join('prefectures', 'cities.prefecture_id', '=', 'prefectures.id')
            ->selectRaw('prefectures.name, COUNT(*) as parking_spot_count')
            ->groupBy('prefectures.name')
            ->pluck('parking_spot_count', 'name');

        $this->assertCount(47, $parkingSpotCountsByPrefecture);
        $this->assertLessThanOrEqual(
            1,
            $parkingSpotCountsByPrefecture->max() - $parkingSpotCountsByPrefecture->min(),
        );
    }

    public function test_it_skips_optional_parking_spot_samples_when_the_coordinate_csv_is_missing(): void
    {
        config()->set('parking_spot.sample_data.postalcode_csv_path', '/tmp/missing-parking-spot-samples.csv');

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(48, Prefecture::query()->count());
        $this->assertSame(100, User::query()->count());
        $this->assertDatabaseCount('parking_spots', 0);
    }

    private function createNationwidePostalcodeCsv(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'database-seeder-postalcode-');
        $this->assertNotFalse($path);
        $rows = [];

        Prefecture::query()
            ->where('name', '!=', '海外')
            ->orderBy('id')
            ->pluck('name')
            ->each(function (string $prefecture, int $prefectureIndex) use (&$rows): void {
                foreach (range(0, 212) as $locationIndex) {
                    $rows[] = implode(',', [
                        sprintf('%07d', 1_000_000 + ($prefectureIndex * 1_000) + $locationIndex),
                        $prefecture,
                        $prefecture.'テスト市',
                        'テスト町',
                        number_format(24 + ($prefectureIndex * 0.4) + ($locationIndex / 100_000), 6, '.', ''),
                        number_format(123 + ($prefectureIndex * 0.4) + ($locationIndex / 100_000), 6, '.', ''),
                    ]);
                }
            });

        $this->assertNotFalse(file_put_contents($path, implode("\n", $rows)."\n"));

        return $path;
    }
}
