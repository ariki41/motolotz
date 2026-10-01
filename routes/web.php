<?php

use App\Http\Controllers\Admin\ParkingSpotReportController as AdminParkingSpotReportController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ParkingSpotController;
use App\Http\Controllers\ParkingSpotLifecycleController;
use App\Http\Controllers\ParkingSpotReportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::get('/', [HomeController::class, 'index'])->name('home');

Route::get('/search', [SearchController::class, 'index'])->name('search');

Route::view('/privacy', 'privacy')->name('privacy');
Route::view('/terms', 'terms')->name('terms');
Route::view('/contact', 'contact')->name('contact');

Route::get('/ads.txt', function () {
    $client = config('advertising.adsense.client');

    abort_unless(
        (config('advertising.enabled') || config('advertising.verification_enabled'))
            && is_string($client)
            && Str::startsWith($client, 'ca-pub-'),
        404,
    );

    return response(
        'google.com, '.Str::after($client, 'ca-').", DIRECT, f08c47fec0942fa0\n",
        200,
        ['Content-Type' => 'text/plain; charset=UTF-8'],
    );
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::get('/profile/reviews', [ProfileController::class, 'reviews'])->name('profile.reviews');
    Route::get('/profile/images', [ProfileController::class, 'images'])->name('profile.images');
    Route::get('/profile/parking-spots', [ProfileController::class, 'parkingSpots'])->name('profile.parking-spots');
    Route::get('/profile/edited-parking-spots', [ProfileController::class, 'editedParkingSpots'])->name('profile.edited-parking-spots');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/favorites', [FavoriteController::class, 'index'])->name('favorites.index');
    Route::post('/parking-spot/{parkingSpot}/favorite', [FavoriteController::class, 'store'])->name('favorites.store');
    Route::delete('/parking-spot/{parkingSpot}/favorite', [FavoriteController::class, 'destroy'])->name('favorites.destroy');

    Route::post('/parking-spot/{parkingSpot}/reviews', [ReviewController::class, 'store'])->name('reviews.store');
    Route::get('/parking-spots/{parkingSpot}/reports/create', [ParkingSpotReportController::class, 'create'])->name('parking_spot.reports.create');
    Route::post('/parking-spots/{parkingSpot}/reports', [ParkingSpotReportController::class, 'store'])->name('parking_spot.reports.store');
    Route::get('/parking-spots/create', [ParkingSpotController::class, 'create'])->name('parking_spot.create');
    Route::post('/parking-spots/confirm', [ParkingSpotController::class, 'confirm'])->name('parking_spot.confirm');
    Route::post('/parking-spots/confirm/location', [ParkingSpotController::class, 'updateConfirmedLocation'])->name('parking_spot.confirm.location');
    // 確認画面からの同時送信で、同一セッションの登録・更新が重複しないように直列化する。
    Route::post('/parking-spots', [ParkingSpotController::class, 'store'])->block()->name('parking_spot.store');
    Route::get('/parking-spots/{parkingSpot}/edit', [ParkingSpotController::class, 'edit'])->name('parking_spot.edit');
    Route::match(['put', 'patch'], '/parking-spots/{parkingSpot}', [ParkingSpotController::class, 'update'])->block()->name('parking_spot.update');
    Route::post('/parking-spots/{parkingSpot}/close', [ParkingSpotLifecycleController::class, 'close'])->name('parking_spot.close');
    Route::post('/parking-spots/{parkingSpot}/deletion-requests', [ParkingSpotLifecycleController::class, 'requestDeletion'])->name('parking_spot.deletion_requests.store');
});

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/parking-spot-reports', [AdminParkingSpotReportController::class, 'index'])->name('parking_spot_reports.index');
    Route::post('/parking-spots/{parkingSpot}/hide', [AdminParkingSpotReportController::class, 'hide'])->name('parking_spots.hide');
    Route::post('/parking-spots/{parkingSpot}/publish', [AdminParkingSpotReportController::class, 'publish'])->name('parking_spots.publish');
    Route::post('/parking-spots/{parkingSpot}/histories/{history}/restore', [AdminParkingSpotReportController::class, 'restore'])->name('parking_spots.histories.restore');
    Route::post('/parking-spot-reports/{report}/resolve', [AdminParkingSpotReportController::class, 'resolve'])->name('parking_spot_reports.resolve');
    Route::post('/parking-spot-deletion-requests/{deletionRequest}/delete', [AdminParkingSpotReportController::class, 'delete'])->name('parking_spot_deletion_requests.delete');
});

Route::get('/parking-spots/{parkingSpot}/reviews', [ReviewController::class, 'index'])->name('reviews.index');
Route::get('/parking-spots/{parkingSpot}', [ParkingSpotController::class, 'show'])->name('parking_spot.show');

require __DIR__.'/auth.php';
