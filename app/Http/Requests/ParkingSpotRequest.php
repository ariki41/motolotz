<?php

namespace App\Http\Requests;

use App\Domain\ParkingSpotRates\MaxRatePeriod;
use App\Domain\ParkingSpotRates\RateConflictDetector;
use App\Domain\ParkingSpotRates\RateDayType;
use App\Domain\ParkingSpotRates\RatePeriod;
use App\Domain\ParkingSpots\EngineDisplacementClass;
use App\Models\Postalcode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ParkingSpotRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $rates = collect($this->input('rates', []))
            ->map(function ($rate) {
                if (($rate['is_free'] ?? false)) {
                    // 無料設定は金額・上限の手入力より優先し、料金0円・最大料金なしとして保存する。
                    $rate['rate'] = 0;
                    $rate['free_minutes'] = 0;
                    $rate['max_rate'] = null;
                    $rate['no_max_rate'] = '1';
                }

                if (($rate['no_max_rate'] ?? false)) {
                    $rate['max_rate_period'] = null;
                    $rate['max_rate_period_minutes'] = null;
                    $rate['max_rate_repeats'] = false;
                    $rate['post_max_rate_unit_minutes'] = null;
                    $rate['post_max_rate'] = null;
                }

                if (($rate['max_rate_period'] ?? null) !== MaxRatePeriod::EntryCustomHours->value) {
                    $rate['max_rate_period_minutes'] = null;
                }

                if (($rate['no_free_minutes'] ?? false)) {
                    // 入力欄の値よりも「無料時間なし」の明示的な選択を優先して保存する。
                    $rate['free_minutes'] = 0;
                }

                return $rate;
            })
            ->all();

        $businessHours = $this->input('business_hours');
        if (! $this->exists('business_hours')) {
            // 旧フォーム・確認セッションからの入力も全日営業時間として受け入れる。
            $businessHours = [[
                'day_type' => '全日', 'is_closed' => false,
                'opening_time' => $this->input('opening_time', '00:00'),
                'closing_time' => $this->input('closing_time', '00:00'),
            ]];
        }

        if (is_array($businessHours)) {
            $businessHours = collect($businessHours)->values()->map(function (mixed $hour): mixed {
                if (! is_array($hour)) {
                    return $hour;
                }

                $hour['is_closed'] = filter_var($hour['is_closed'] ?? false, FILTER_VALIDATE_BOOLEAN);

                return $hour;
            })->all();
        }

        $representative = is_array($businessHours)
            ? collect($businessHours)->first(fn (mixed $hour) => is_array($hour) && ! ($hour['is_closed'] ?? false)) ?? []
            : [];
        $this->merge([
            'rates' => $rates,
            'business_hours' => $businessHours,
            'opening_time' => $this->input('opening_time', $representative['opening_time'] ?? '00:00'),
            'closing_time' => $this->input('closing_time', $representative['closing_time'] ?? '00:00'),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $imagePaths = is_array($this->input('image_paths'))
                ? $this->normalizeImagePaths($this->input('image_paths'))
                : $this->normalizeImagePaths([$this->input('image_path')]);
            $uploadedImages = $this->file('images', []);
            $uploadedImageCount = is_array($uploadedImages) ? count($uploadedImages) : 0;
            $imageCount = count($imagePaths) + $uploadedImageCount + ($this->hasFile('image') ? 1 : 0);

            if ($imageCount > 4) {
                $validator->errors()->add('images', '保持する画像と追加する画像の合計は4枚までです。');
            }

            $this->validateRateTimeConflicts($validator);
            $this->validateMaxRateConditions($validator);
            $this->validateBusinessHourConflicts($validator);
        });
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'id' => 'nullable|integer|exists:parking_spots,id',
            'name' => 'required|string|max:255',
            'postalcode' => 'required|regex:/^\d{3}-?\d{4}$/',
            'address1' => ['required', 'string', 'max:255',
                function ($attribute, $value, $fail) {
                    $postalcode = Postalcode::query()
                        ->with('city.prefecture')
                        ->active()
                        ->where('postalcode', str_replace('-', '', (string) $this->input('postalcode')))
                        ->first();

                    if ($postalcode !== null && $postalcode->fullAddress() !== $value) {
                        $fail('郵便番号と住所が一致しません。');
                    }
                },
            ],
            'address2' => 'required|string|max:255',
            'latitude' => 'nullable|required_with:longitude|numeric|between:-90,90',
            'longitude' => 'nullable|required_with:latitude|numeric|between:-180,180',
            'capacity' => 'required|integer|min:1',
            'max_displacement_class' => ['required', Rule::enum(EngineDisplacementClass::class)],
            'images' => 'nullable|array|max:4',
            'images.*' => 'image|mimes:jpg,jpeg,png,webp|max:20480',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:20480',
            'image_paths' => 'nullable|array|max:4',
            'image_paths.*' => 'string|max:255',
            'image_path' => 'nullable|string|max:255',
            'opening_time' => 'required|date_format:H:i',
            'closing_time' => 'required|date_format:H:i',
            'business_hours' => 'required|array|min:1|max:8',
            'business_hours.*' => 'array',
            'business_hours.*.day_type' => ['required', 'string', Rule::in(array_keys(config('categories.parking_spot_business_hour_day_types')))],
            'business_hours.*.is_closed' => 'nullable|boolean',
            'business_hours.*.opening_time' => 'required|date_format:H:i',
            'business_hours.*.closing_time' => 'required|date_format:H:i',
            'rates' => 'required|array|min:1|max:4',
            'rates.*.day_type' => ['required', 'string', Rule::in(RateDayType::values())],
            'rates.*.start_time' => 'required|date_format:H:i',
            'rates.*.end_time' => 'required|date_format:H:i',
            'rates.*.unit_minutes' => ['required', 'integer', Rule::in(array_keys(config('categories.parking_spot_rate_unit_minutes')))],
            'rates.*.rate' => 'required|integer|min:0',
            'rates.*.is_free' => 'nullable|boolean',
            'rates.*.free_minutes' => 'nullable|integer|min:0',
            'rates.*.no_free_minutes' => 'nullable|boolean',
            'rates.*.no_max_rate' => 'nullable|boolean',
            'rates.*.max_rate' => 'required_unless:rates.*.no_max_rate,1|nullable|integer|min:1',
            'rates.*.max_rate_period' => ['nullable', Rule::enum(MaxRatePeriod::class)],
            'rates.*.max_rate_period_minutes' => 'nullable|integer|min:1|max:10080',
            'rates.*.max_rate_repeats' => 'nullable|boolean',
            'rates.*.post_max_rate_unit_minutes' => ['nullable', 'integer', Rule::in(array_keys(config('categories.parking_spot_rate_unit_minutes')))],
            'rates.*.post_max_rate' => 'nullable|integer|min:1',
        ];
    }

    /**
     * エラーメッセージ
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'id.integer' => '編集対象の情報が正しくありません。',
            'id.exists' => '編集対象の駐輪場が見つかりません。',
            'name.required' => '駐輪場名は必須です。',
            'name.string' => '駐輪場名は文字列で入力してください。',
            'name.max' => '駐輪場名は255文字以内で入力してください。',

            'postalcode.required' => '郵便番号は必須です。',
            'postalcode.regex' => '郵便番号の形式が正しくありません。例: 123-4567 または 1234567',

            'address1.required' => '都道府県・市区町村・町域は必須です。',
            'address1.string' => '都道府県・市区町村・町域は文字列で入力してください。',
            'address1.max' => '都道府県・市区町村・町域は255文字以内で入力してください。',

            'address2.required' => '続きの住所は必須です。',
            'address2.string' => '続きの住所は文字列で入力してください。',
            'address2.max' => '続きの住所は255文字以内で入力してください。',

            'latitude.required_with' => '緯度と経度はセットで指定してください。',
            'latitude.numeric' => '緯度は数値で指定してください。',
            'latitude.between' => '緯度は-90から90の範囲で指定してください。',
            'longitude.required_with' => '緯度と経度はセットで指定してください。',
            'longitude.numeric' => '経度は数値で指定してください。',
            'longitude.between' => '経度は-180から180の範囲で指定してください。',

            'capacity.required' => '駐輪場台数は必須です。',
            'capacity.integer' => '駐輪場台数は整数で入力してください。',
            'capacity.min' => '駐輪場台数を設定してください。',

            'max_displacement_class.required' => '駐車可能な排気量区分は必須です。',
            'max_displacement_class.enum' => '駐車可能な排気量区分を選択してください。',

            'images.array' => '画像の選択内容が正しくありません。',
            'images.max' => '画像は4枚までアップロードできます。',
            'images.*.image' => '画像ファイルを選択してください。',
            'images.*.mimes' => '画像は jpg / jpeg / png / webp 形式でアップロードしてください。',
            'images.*.max' => '画像は1枚あたり20MB以下でアップロードしてください。',
            'image.image' => '画像ファイルを選択してください。',
            'image.mimes' => '画像は jpg / jpeg / png / webp 形式でアップロードしてください。',
            'image.max' => '画像は20MB以下でアップロードしてください。',
            'image_paths.array' => '画像の保持情報が正しくありません。',
            'image_paths.max' => '画像は4枚まで保持できます。',
            'image_paths.*.string' => '画像の保持情報が正しくありません。',
            'image_paths.*.max' => '画像の保持情報が長すぎます。',
            'image_path.string' => '画像の保持情報が正しくありません。',
            'image_path.max' => '画像の保持情報が長すぎます。',

            'opening_time.required' => '開場時間は必須です。',
            'opening_time.date_format' => '開場時間の形式が正しくありません。例: 10:00',

            'closing_time.required' => '閉場時間は必須です。',
            'closing_time.date_format' => '閉場時間の形式が正しくありません。例: 22:00',

            'business_hours.required' => '営業時間は1件以上入力してください。',
            'business_hours.array' => '営業時間の形式が正しくありません。',
            'business_hours.*.array' => '営業時間の形式が正しくありません。',
            'business_hours.min' => '営業時間は1件以上入力してください。',
            'business_hours.max' => '営業時間は8件まで入力できます。',
            'business_hours.*.day_type.required' => '曜日区分を選択してください。',
            'business_hours.*.day_type.in' => '曜日区分を選択してください。',
            'business_hours.*.opening_time.required' => '開始時間を入力してください。',
            'business_hours.*.opening_time.date_format' => '開場時間の形式が正しくありません。例: 10:00',
            'business_hours.*.closing_time.required' => '終了時間を入力してください。',
            'business_hours.*.closing_time.date_format' => '閉場時間の形式が正しくありません。例: 22:00',

            'rates.required' => '料金は必須です。',
            'rates.array' => '料金の形式が正しくありません。',
            'rates.min' => '料金は1件以上入力してください。',
            'rates.max' => '料金帯は4件まで入力できます。',

            'rates.*.day_type.required' => '適用曜日は必須です。',
            'rates.*.day_type.string' => '適用曜日は文字列で入力してください。',
            'rates.*.day_type.in' => '適用曜日を選択してください。',

            'rates.*.start_time.required' => '料金開始時間は必須です。',
            'rates.*.start_time.date_format' => '料金開始時間の形式が正しくありません。例: 08:00',

            'rates.*.end_time.required' => '料金終了時間は必須です。',
            'rates.*.end_time.date_format' => '料金終了時間の形式が正しくありません。例: 20:00',

            'rates.*.unit_minutes.required' => '料金単位は必須です。',
            'rates.*.unit_minutes.integer' => '料金単位は整数で入力してください。',
            'rates.*.unit_minutes.in' => '料金単位を選択してください。',

            'rates.*.rate.required' => '料金は必須です。',
            'rates.*.rate.integer' => '料金は整数で入力してください。',
            'rates.*.rate.min' => '料金は0円以上で入力してください。',

            'rates.*.free_minutes.integer' => '無料時間は整数で入力してください。',
            'rates.*.free_minutes.min' => '無料時間は0分以上で入力してください。',

            'rates.*.max_rate.integer' => '最大料金は整数で入力してください。',
            'rates.*.max_rate.min' => '最大料金は1円以上で入力してください。',
            'rates.*.max_rate.required_unless' => '最大料金なしを選択しない場合、最大料金は必須です。',
            'rates.*.max_rate_period.enum' => '最大料金の適用期間を選択してください。',
            'rates.*.max_rate_period_minutes.integer' => '最大料金の適用時間は整数で入力してください。',
            'rates.*.max_rate_period_minutes.min' => '最大料金の適用時間は1分以上で入力してください。',
            'rates.*.max_rate_period_minutes.max' => '最大料金の適用時間は7日以内で入力してください。',
            'rates.*.post_max_rate_unit_minutes.integer' => '適用後の料金単位は整数で入力してください。',
            'rates.*.post_max_rate_unit_minutes.in' => '適用後の料金単位を選択してください。',
            'rates.*.post_max_rate.integer' => '適用後の料金は整数で入力してください。',
            'rates.*.post_max_rate.min' => '適用後の料金は1円以上で入力してください。',
        ];
    }

    private function validateRateTimeConflicts(Validator $validator): void
    {
        $rates = $this->input('rates');

        if (! is_array($rates) || $validator->errors()->has('rates')) {
            return;
        }

        $periods = collect($rates)
            ->map(fn ($rate, $index) => ['index' => $index, 'rate' => $rate])
            ->filter(fn (array $item) => $this->isReadyForTimeConflictCheck($item['rate'], $item['index'], $validator))
            ->mapWithKeys(fn (array $item) => [
                $item['index'] => RatePeriod::fromValues(
                    $item['rate']['day_type'],
                    $item['rate']['start_time'],
                    $item['rate']['end_time'],
                ),
            ])
            ->all();

        foreach ((new RateConflictDetector)->detect($periods) as $conflict) {
            $leftIndex = $conflict['left'];
            $rightIndex = $conflict['right'];
            $message = $this->buildTimeConflictMessage(
                $leftIndex,
                $rates[$leftIndex]['day_type'],
                $rightIndex,
                $rates[$rightIndex]['day_type'],
            );

            $validator->errors()->add("rates.{$leftIndex}.time_conflict", $message);
            $validator->errors()->add("rates.{$rightIndex}.time_conflict", $message);
        }
    }

    private function validateMaxRateConditions(Validator $validator): void
    {
        foreach ($this->input('rates', []) as $index => $rate) {
            if (! is_array($rate) || ($rate['no_max_rate'] ?? false)) {
                continue;
            }

            if (($rate['max_rate_period'] ?? null) === MaxRatePeriod::EntryCustomHours->value
                && empty($rate['max_rate_period_minutes'])) {
                $validator->errors()->add("rates.{$index}.max_rate_period_minutes", '指定時間を入力してください。');
            }

            $hasUnit = filled($rate['post_max_rate_unit_minutes'] ?? null);
            $hasRate = filled($rate['post_max_rate'] ?? null);
            if ($hasUnit !== $hasRate) {
                $field = $hasUnit ? 'post_max_rate' : 'post_max_rate_unit_minutes';
                $validator->errors()->add("rates.{$index}.{$field}", '最大料金の適用後の料金単位と料金を両方入力してください。');
            }

            if (($rate['max_rate_repeats'] ?? false) && $hasUnit && $hasRate) {
                $validator->errors()->add("rates.{$index}.post_max_rate", '繰り返し適用と最大料金の適用後の加算は同時に設定できません。');
            }
        }
    }

    private function validateBusinessHourConflicts(Validator $validator): void
    {
        $hours = $this->input('business_hours');
        if (! is_array($hours)
            || $validator->errors()->has('business_hours')
            || collect($hours)->contains(fn (mixed $hour) => ! is_array($hour))) {
            return;
        }

        $scopes = [
            '全日' => ['月曜', '火曜', '水曜', '木曜', '金曜', '土曜', '日曜', '祝日'],
            '平日' => ['月曜', '火曜', '水曜', '木曜', '金曜'],
            '土日祝' => ['土曜', '日曜', '祝日'],
            '月曜' => ['月曜'], '火曜' => ['火曜'], '水曜' => ['水曜'], '木曜' => ['木曜'],
            '金曜' => ['金曜'], '土曜' => ['土曜'], '日曜' => ['日曜'],
        ];
        foreach ($hours as $index => $hour) {
            $dayType = $hour['day_type'] ?? null;
            if (! isset($scopes[$dayType])) {
                continue;
            }
            foreach (array_slice($hours, 0, $index) as $other) {
                $otherDayType = $other['day_type'] ?? null;
                if (($hour['is_closed'] ?? false) === ($other['is_closed'] ?? false)
                    && isset($scopes[$otherDayType]) && array_intersect($scopes[$dayType], $scopes[$otherDayType]) !== []) {
                    $validator->errors()->add("business_hours.{$index}.day_type", '曜日区分が他の営業時間と重複しています。');
                    break;
                }
            }
        }

        $covered = collect($hours)->flatMap(fn (array $hour) => $scopes[$hour['day_type'] ?? ''] ?? [])->unique();
        if ($covered->count() !== 8) {
            $validator->errors()->add('business_hours', 'すべての曜日と祝日の営業時間を設定してください。');
        }
    }

    /**
     * @param  array<int, mixed>  $imagePaths
     * @return list<string>
     */
    private function normalizeImagePaths(array $imagePaths): array
    {
        return collect($imagePaths)
            ->filter(fn ($path) => is_string($path) && filled($path))
            ->unique()
            ->values()
            ->all();
    }

    private function isReadyForTimeConflictCheck(array $rate, int $index, Validator $validator): bool
    {
        $fields = ['day_type', 'start_time', 'end_time'];

        foreach ($fields as $field) {
            if ($validator->errors()->has("rates.{$index}.{$field}")) {
                return false;
            }
        }

        if (! isset($rate['day_type'], $rate['start_time'], $rate['end_time'])) {
            return false;
        }

        return RateDayType::tryFrom($rate['day_type']) !== null;
    }

    private function buildTimeConflictMessage(int $leftIndex, string $leftDayType, int $rightIndex, string $rightDayType): string
    {
        $leftNumber = $leftIndex + 1;
        $rightNumber = $rightIndex + 1;

        return "料金帯{$leftNumber}の「{$leftDayType}」と料金帯{$rightNumber}の「{$rightDayType}」は適用条件が重複しています。";
    }
}
