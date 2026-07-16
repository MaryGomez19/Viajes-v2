<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\HotelController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ReservationDocumentController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\PackageImageController;
use App\Http\Controllers\FlightController;
use App\Http\Controllers\TransportController;
use App\Http\Controllers\RoomPriceController;
use App\Http\Controllers\PassengerController;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\QuickReservationController;

// 1. Recursos y Catálogos generales públicos o semi-públicos
Route::get('packages/public', [PackageController::class, 'publicIndex']);

Route::get('/cities', [CityController::class, 'index']);
Route::post('/cities', [CityController::class, 'store']);
Route::post('/roles', [RoleController::class, 'store']);

// 2. Autenticación de la API
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Reset de la contraseña
Route::post('/forgot-password', function (Request $request) {
    Password::sendResetLink($request->only('email'));
    return response()->json(['message' => 'Link enviado si el correo existe.']);
})->name('password.email');

Route::post('/reset-password', function (Request $request) {
    $status = Password::reset(
        $request->only('email', 'password', 'password_confirmation', 'token'),
        function ($user, $password) {
            $user->password = Hash::make($password);
            $user->save();
        }
    );

    return $status === Password::PASSWORD_RESET
        ? response()->json(['message' => 'Contraseña actualizada.'])
        : response()->json(['message' => 'Token inválido o expirado.'], 400);
})->name('password.reset');


// =========================================================================
// 4. 🔐 RUTAS PROTEGIDAS
// =========================================================================

// A) Para CUALQUIER usuario autenticado 
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/reservations', [ReservationController::class, 'store']);
    Route::get('/perfil', [UserController::class, 'perfil']);
    Route::put('/perfil', [UserController::class, 'actualizarPerfil']);
});

// B) 👑 SOLO ADMINISTRADORES
Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::apiResource('users', UserController::class);
    Route::apiResource('reservations', ReservationController::class)->except(['store']);
    Route::apiResource('reservations.passengers', PassengerController::class);
    Route::post('/reservations/quick', [QuickReservationController::class, 'store']);

    //reservaciones
    Route::patch('reservations/{id}/restore', [ReservationController::class, 'restore']);
    Route::delete('reservations/{id}/force', [ReservationController::class, 'forceDestroy']);
    Route::get('clientes/archivados', [ReservationController::class, 'clientesArchivados']);
    Route::get('clientes/{userId}/expediente', [ReservationController::class, 'porCliente']);

    Route::apiResource('flights', FlightController::class);
    Route::apiResource('packages', PackageController::class);
    Route::get('/hotels/suggestions', [HotelController::class, 'suggestions']);
    Route::apiResource('hotels', HotelController::class);
    Route::apiResource('hotels.room-prices', RoomPriceController::class);
    Route::apiResource('transports', TransportController::class);

    //documentos de reserva
    Route::get('reservations/{reservation}/documents', [ReservationDocumentController::class, 'index']);
    Route::post('reservations/{reservation}/documents', [ReservationDocumentController::class, 'store']);
    Route::delete('reservation-documents/{document}', [ReservationDocumentController::class, 'destroy']);

    // 📸 Endpoints para subir y borrar fotos del carrusel
    Route::post('/package-images', [PackageImageController::class, 'store']);
    Route::delete('/package-images/{id}', [PackageImageController::class, 'destroy']);
});

// C) 👥 SOLO CLIENTES 
Route::middleware(['auth:sanctum', 'role:cliente'])->group(function () {
    Route::get('/mis-reservas', [ReservationController::class, 'misReservas']);
    Route::get('/mis-reservas/{id}', [ReservationController::class, 'misReservaDetalle']);
    Route::get('/mis-reservas/{reservation}/passengers', [PassengerController::class, 'index']);
    Route::post('/mis-reservas/{reservation}/passengers', [PassengerController::class, 'store']);
    Route::get('/mis-reservas/{reservation}/passengers/{passenger}', [PassengerController::class, 'show']);
    Route::put('/mis-reservas/{reservation}/passengers/{passenger}', [PassengerController::class, 'update']);
    Route::delete('/mis-reservas/{reservation}/passengers/{passenger}', [PassengerController::class, 'destroy']);
});
