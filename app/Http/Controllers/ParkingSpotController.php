<?php

namespace App\Http\Controllers;

use App\Domain\ParkingSpotRates\RateDisplay;
use App\Domain\ParkingSpots\EngineDisplacementClass;
use App\Exceptions\ParkingSpotVersionConflictException;
use App\Exceptions\YolpApiException;
use App\Http\Requests\ParkingSpotRequest;
use App\Models\ParkingSpot;
use App\Services\ParkingSpotConfirmationService;
use App\Services\ParkingSpotDuplicateCandidateService;
use App\Services\ParkingSpotGeocodingService;
use App\Services\ParkingSpotImageService;
use App\Services\ParkingSpotPersistenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ParkingSpotController extends Controller
{
    public function __construct(
        private readonly ParkingSpotPersistenceService $persistence,
        private readonly ParkingSpotGeocodingService $geocoding,
        private readonly ParkingSpotImageService $images,
        private readonly ParkingSpotConfirmationService $confirmation,
        private readonly ParkingSpotDuplicateCandidateService $duplicateCandidates,
    ) {}

    public function show(Request $request, ParkingSpot $parkingSpot)
    {
        $user = $request->user();
        $parkingSpot
            ->load(['postalcode.city.prefecture', 'images', 'rates', 'businessHours', 'updateHistories.user'])
            ->loadCount(['favorites', 'reviews'])
            ->loadAvg('reviews', 'rating');

        $recentReviews = $parkingSpot->reviews()
            ->with('user')
            ->limit(10)
            ->get();

        if ($user) {
            $parkingSpot->loadExists([
                'favorites as is_favorited' => fn ($favoriteQuery) => $favoriteQuery->where('user_id', $user->id),
            ]);
        }

        $userReview = $user
            ? $parkingSpot->reviews()->where('user_id', $user->id)->first()
            : null;

        $parkingSpot['opening_time'] = date('H:i', strtotime($parkingSpot['opening_time']));
        $parkingSpot['closing_time'] = $parkingSpot['closing_time'] === '00:00:00' ? '24:00' : date('H:i', strtotime($parkingSpot['closing_time']));

        return view('parking_spot.show', compact('parkingSpot', 'recentReviews', 'userReview'));

    }

    public function create(Request $request)
    {
        $this->confirmation->beginCreate($request);

        $capacity = config('categories.parking_spot_capacity');
        $displacementClasses = EngineDisplacementClass::cases();
        $rateDayTypes = config('categories.parking_spot_rate_day_types');
        $rateUnitMinutes = config('categories.parking_spot_rate_unit_minutes');
        $maxRatePeriods = config('categories.parking_spot_max_rate_periods');
        $businessHourDayTypes = config('categories.parking_spot_business_hour_day_types');
        $formValues = [
            'name' => '',
            'postalcode' => '',
            'address1' => '',
            'address2' => '',
            'capacity' => '',
            'max_displacement_class' => '',
            'opening_time' => '00:00',
            'closing_time' => '00:00',
        ];
        $ratesInput = [$this->defaultRateInput()];
        $businessHoursInput = [$this->defaultBusinessHourInput()];
        $imagePaths = [];

        return view('parking_spot.create', compact('capacity', 'displacementClasses', 'rateDayTypes', 'rateUnitMinutes', 'maxRatePeriods', 'businessHourDayTypes', 'formValues', 'ratesInput', 'businessHoursInput', 'imagePaths'));
    }

    public function confirm(ParkingSpotRequest $request)
    {
        $validatedData = $request->validated();
        unset($validatedData['image'], $validatedData['images']);
        $validatedData['id'] = isset($validatedData['id']) ? (int) $validatedData['id'] : null;
        $mode = $validatedData['id']
            ? ParkingSpotConfirmationService::MODE_EDIT
            : ParkingSpotConfirmationService::MODE_CREATE;
        $parkingSpot = null;

        if ($mode === ParkingSpotConfirmationService::MODE_CREATE && ! $this->confirmation->hasState($request)) {
            $this->confirmation->beginCreate($request);
        }

        if (! $this->confirmation->matches($request, $mode, $validatedData['id'])) {
            return $this->redirectToTrustedForm($request)
                ->withErrors(['confirmation' => '入力内容を確認できませんでした。入力画面からやり直してください。']);
        }

        if ($validatedData['id']) {
            $parkingSpot = ParkingSpot::published()->with('images')->findOrFail($validatedData['id']);
            Gate::authorize('update', $parkingSpot);
        }

        $previousInput = $this->confirmation->confirmedInput($request, $mode);

        $currentImagePaths = $validatedData['image_paths']
            ?? array_filter([$validatedData['image_path'] ?? null]);
        $validatedData['image_paths'] = $this->images->prepareForConfirmation(
            $request,
            $currentImagePaths,
            $parkingSpot === null ? [] : $parkingSpot->image_paths,
            $this->confirmation->allowedTemporaryImagePaths($request, $mode, $validatedData['id']),
        );
        $validatedData['image_path'] = $validatedData['image_paths'][0] ?? null;
        $this->confirmation->trackTemporaryImagePaths(
            $request,
            $mode,
            $validatedData['id'],
            $validatedData['image_paths'],
        );
        $validatedData['address'] = mb_convert_kana($validatedData['address1'].$validatedData['address2'], 'rn');
        $validatedData['postalcode'] = mb_convert_kana(str_replace('-', '', $validatedData['postalcode']), 'rn');

        if ($this->canReuseCorrectedCoordinates($previousInput, $validatedData)) {
            $validatedData['longitude'] = (float) $validatedData['longitude'];
            $validatedData['latitude'] = (float) $validatedData['latitude'];
        } else {
            try {
                $yolpLocation = $this->geocoding->geocode($validatedData['address']);
            } catch (YolpApiException $exception) {
                Log::warning('YOLP API is unavailable while geocoding a parking spot.', [
                    'category' => $exception->category(),
                    'previous_exception' => $exception->getPrevious() ? $exception->getPrevious()::class : null,
                ]);

                return $this->redirectToTrustedForm($request)
                    ->withErrors(['address2' => $exception->userMessage()])
                    ->withInput($validatedData);
            }

            if (is_null($yolpLocation)) {
                return $this->redirectToTrustedForm($request)
                    ->withErrors(['address2' => '住所が見つかりません。'])
                    ->withInput($validatedData);
            }

            // A changed address always starts from its newly geocoded position.
            $validatedData['longitude'] = $yolpLocation['lon'];
            $validatedData['latitude'] = $yolpLocation['lat'];
            $validatedData['address'] = $yolpLocation['address'];
        }

        $capacity = config('categories.parking_spot_capacity');
        $displacementClass = EngineDisplacementClass::from($validatedData['max_displacement_class']);
        $rateDisplays = array_map(RateDisplay::fromArray(...), $validatedData['rates']);

        $this->confirmation->confirm($request, $mode, $validatedData['id'], $validatedData);

        $duplicateCandidates = $mode === ParkingSpotConfirmationService::MODE_CREATE
            ? $this->duplicateCandidates->find(
                $validatedData['name'], $validatedData['address'],
                (float) $validatedData['latitude'], (float) $validatedData['longitude'],
            )
            : collect();

        return view('parking_spot.confirm', compact('validatedData', 'capacity', 'displacementClass', 'duplicateCandidates', 'rateDisplays'));
    }

    public function store(Request $request)
    {
        $input = $this->confirmation->confirmedInput($request, ParkingSpotConfirmationService::MODE_CREATE);

        if ($input === null) {
            return redirect()->route('parking_spot.create')
                ->withErrors(['confirmation' => '確認情報の有効期限が切れました。入力内容を確認して、もう一度お試しください。']);
        }

        if ($request->input('back') === 'back') {
            return redirect()->route('parking_spot.create')->withInput($input);
        }

        try {
            $this->persistence->create($input, $request->user());
        } catch (ValidationException $exception) {
            return redirect()->route('parking_spot.create')
                ->withErrors($exception->errors())
                ->withInput($input);
        }

        $this->confirmation->forget($request);
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('success', '駐輪場を登録しました。');
    }

    public function edit(Request $request, ParkingSpot $parkingSpot)
    {
        $parkingSpot->load(['postalcode.city.prefecture', 'images', 'rates', 'businessHours']);
        Gate::authorize('update', $parkingSpot);
        $this->confirmation->beginEdit($request, $parkingSpot->id, $parkingSpot->lock_version);

        $capacity = config('categories.parking_spot_capacity');
        $displacementClasses = EngineDisplacementClass::cases();
        $rateDayTypes = config('categories.parking_spot_rate_day_types');
        $rateUnitMinutes = config('categories.parking_spot_rate_unit_minutes');
        $maxRatePeriods = config('categories.parking_spot_max_rate_periods');
        $businessHourDayTypes = config('categories.parking_spot_business_hour_day_types');

        $address1 = $parkingSpot->postalcode->fullAddress();
        $formValues = [
            'name' => $parkingSpot->name,
            'postalcode' => $parkingSpot->postalcode->postalcode,
            'address1' => $address1,
            'address2' => str_replace($address1, '', $parkingSpot->address),
            'capacity' => $parkingSpot->capacity,
            'max_displacement_class' => $parkingSpot->max_displacement_class?->value,
            'opening_time' => date('H:i', strtotime($parkingSpot->opening_time)),
            'closing_time' => date('H:i', strtotime($parkingSpot->closing_time)),
        ];

        $ratesInput = $parkingSpot->rates->map(fn ($rate) => [
            'day_type' => $rate->day_type,
            'start_time' => date('H:i', strtotime($rate->start_time)),
            'end_time' => date('H:i', strtotime($rate->end_time)),
            'unit_minutes' => $rate->unit_minutes,
            'rate' => $rate->rate,
            'is_free' => $rate->rate === 0 ? '1' : '0',
            'free_minutes' => $rate->free_minutes,
            'no_free_minutes' => $rate->free_minutes === 0 ? '1' : '0',
            'max_rate' => $rate->max_rate,
            'no_max_rate' => $rate->max_rate === null ? '1' : '0',
            'max_rate_period' => $rate->max_rate_period,
            'max_rate_period_minutes' => $rate->max_rate_period_minutes,
            'max_rate_repeats' => $rate->max_rate_repeats,
            'post_max_rate_unit_minutes' => $rate->post_max_rate_unit_minutes,
            'post_max_rate' => $rate->post_max_rate,
        ])->values()->all() ?: [$this->defaultRateInput()];
        $imagePaths = $parkingSpot->image_paths;
        $businessHoursInput = $parkingSpot->businessHours->map(fn ($hour) => [
            'day_type' => $hour->day_type,
            'is_closed' => $hour->is_closed,
            'opening_time' => $hour->opening_time === null ? '00:00' : substr($hour->opening_time, 0, 5),
            'closing_time' => $hour->closing_time === null ? '00:00' : substr($hour->closing_time, 0, 5),
        ])->values()->all() ?: [[
            'day_type' => '全日', 'is_closed' => false,
            'opening_time' => $formValues['opening_time'], 'closing_time' => $formValues['closing_time'],
        ]];

        return view('parking_spot.edit', compact('parkingSpot', 'capacity', 'displacementClasses', 'rateDayTypes', 'rateUnitMinutes', 'maxRatePeriods', 'businessHourDayTypes', 'formValues', 'ratesInput', 'businessHoursInput', 'imagePaths'));
    }

    public function update(Request $request, ParkingSpot $parkingSpot)
    {
        $input = $this->confirmation->confirmedInput($request, ParkingSpotConfirmationService::MODE_EDIT);

        if ($input === null || (int) $input['id'] !== $parkingSpot->id) {
            return redirect()->route('home')
                ->with('error', '確認情報の有効期限が切れました。編集画面からやり直してください。');
        }

        Gate::authorize('update', $parkingSpot);

        if ($request->input('back') === 'back') {
            return redirect()->route('parking_spot.edit', $parkingSpot)->withInput($input);
        }

        try {
            $this->persistence->update($input, $request->user());
        } catch (ParkingSpotVersionConflictException) {
            $this->confirmation->discard($request);

            return redirect()->route('parking_spot.edit', $parkingSpot)
                ->withErrors(['confirmation' => '他のユーザーによって駐輪場情報が更新されました。最新の内容を確認して、もう一度編集してください。']);
        } catch (ValidationException $exception) {
            return redirect()->route('parking_spot.edit', $parkingSpot)
                ->withErrors($exception->errors())
                ->withInput($input);
        }

        $this->confirmation->forget($request);
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('success', '駐輪場情報を更新しました。');
    }

    private function redirectToTrustedForm(Request $request)
    {
        $parkingSpotId = $this->confirmation->trustedParkingSpotId($request);

        return $parkingSpotId
            ? redirect()->route('parking_spot.edit', ['parkingSpot' => $parkingSpotId])
            : redirect()->route('parking_spot.create');
    }

    private function defaultRateInput(): array
    {
        return [
            'day_type' => '全日',
            'start_time' => '00:00',
            'end_time' => '00:00',
            'unit_minutes' => 30,
            'rate' => '',
            'is_free' => '0',
            'free_minutes' => 0,
            'no_free_minutes' => '1',
            'max_rate' => '',
            'no_max_rate' => '0',
            'max_rate_period' => 'entry_24_hours',
            'max_rate_period_minutes' => '',
            'max_rate_repeats' => false,
            'post_max_rate_unit_minutes' => '',
            'post_max_rate' => '',
        ];
    }

    private function defaultBusinessHourInput(): array
    {
        return ['day_type' => '全日', 'is_closed' => false, 'opening_time' => '00:00', 'closing_time' => '00:00'];
    }

    private function canReuseCorrectedCoordinates(?array $previousInput, array $input): bool
    {
        if ($previousInput === null
            || ! isset($input['latitude'], $input['longitude'])
            || ! isset($previousInput['latitude'], $previousInput['longitude'])) {
            return false;
        }

        return ($previousInput['postalcode'] ?? null) === $input['postalcode']
            && ($previousInput['address1'] ?? null) === $input['address1']
            && ($previousInput['address2'] ?? null) === $input['address2'];
    }

    public function updateConfirmedLocation(Request $request)
    {
        $mode = $this->confirmation->confirmedMode($request);

        if ($mode === null) {
            return response()->json([
                'message' => '確認情報の有効期限が切れました。入力画面からやり直してください。',
            ], 422);
        }

        $coordinates = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $this->confirmation->updateConfirmedCoordinates($request, $mode, [
            'latitude' => (float) $coordinates['latitude'],
            'longitude' => (float) $coordinates['longitude'],
        ]);

        return response()->json([
            'message' => '位置を反映しました。',
        ]);
    }
}
