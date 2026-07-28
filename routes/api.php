<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\PhoneOtpController;
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
use App\Http\Controllers\AccountController;
use App\Http\Controllers\PartialPaymentController;
use App\Http\Controllers\ScheduledPaymentController;
use App\Http\Controllers\PaymentReceiptController;
use App\Http\Controllers\QuickReservationController;
use App\Http\Controllers\AdminNotificationController;

// 1. Recursos y Catálogos generales públicos o semi-públicos
Route::get('packages/public', [PackageController::class, 'publicIndex']);

Route::get('/cities', [CityController::class, 'index']);

// 2. Autenticación de la API
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Acceso con codigo SMS (telefono registrado)
Route::post('/otp/send', [PhoneOtpController::class, 'send'])
    ->middleware('throttle:5,1');
Route::post('/otp/verify', [PhoneOtpController::class, 'verify'])
    ->middleware('throttle:10,1');

// Reset de contraseña por correo (opcional)
Route::post('/forgot-password', [PasswordController::class, 'forgotPassword'])
    ->middleware('throttle:5,1')
    ->name('password.email');

Route::post('/reset-password', [PasswordController::class, 'resetPassword'])
    ->middleware('throttle:10,1')
    ->name('password.reset');


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
    Route::post('/cities', [CityController::class, 'store']);
    Route::post('/roles', [RoleController::class, 'store']);
    Route::apiResource('users', UserController::class);
    Route::get('reservations/package-profits', [ReservationController::class, 'packageProfits']);
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

    Route::get('payment-receipts', [PaymentReceiptController::class, 'indexAllForAdmin']);
    Route::get('reservations/{reservation}/payment-receipts', [PaymentReceiptController::class, 'indexForAdmin']);
    Route::patch('payment-receipts/{paymentReceipt}', [PaymentReceiptController::class, 'updateForAdmin']);
    Route::delete('payment-receipts/{paymentReceipt}', [PaymentReceiptController::class, 'destroyForAdmin']);

    // 📸 Endpoints para subir y borrar fotos del carrusel
    Route::post('/package-images', [PackageImageController::class, 'store']);
    Route::delete('/package-images/{id}', [PackageImageController::class, 'destroy']);

    // 💰 Cuentas, pagos parciales y abonos programados
    Route::get('accounts', [AccountController::class, 'index']);
    Route::get('accounts/{account}', [AccountController::class, 'show']);
    Route::put('accounts/{account}', [AccountController::class, 'update']);
    Route::patch('accounts/{account}', [AccountController::class, 'update']);
    Route::get('reservations/{reservation}/account', [AccountController::class, 'showByReservation']);
    Route::apiResource('accounts.partial-payments', PartialPaymentController::class);
    Route::apiResource('accounts.scheduled-payments', ScheduledPaymentController::class);

    Route::get('admin/notifications/count', [AdminNotificationController::class, 'count']);
    Route::get('admin/notifications', [AdminNotificationController::class, 'index']);
});

// C) 👥 SOLO CLIENTES 
Route::middleware(['auth:sanctum', 'role:cliente'])->group(function () {
    Route::get('/mis-reservas', [ReservationController::class, 'misReservas']);
    Route::get('/mis-reservas/{id}', [ReservationController::class, 'misReservaDetalle']);
    Route::get('/mis-reservas/{reservation}/account', [AccountController::class, 'showByReservationForClient']);
    Route::get('/mis-reservas/{reservation}/payment-receipts', [PaymentReceiptController::class, 'indexForClient']);
    Route::post('/mis-reservas/{reservation}/payment-receipts', [PaymentReceiptController::class, 'storeForClient']);
    Route::delete('/mis-reservas/{reservation}/payment-receipts/{paymentReceipt}', [PaymentReceiptController::class, 'destroyForClient']);
    Route::get('/mis-reservas/{reservation}/passengers', [PassengerController::class, 'index']);
    Route::post('/mis-reservas/{reservation}/passengers', [PassengerController::class, 'store']);
    Route::get('/mis-reservas/{reservation}/passengers/{passenger}', [PassengerController::class, 'show']);
    Route::put('/mis-reservas/{reservation}/passengers/{passenger}', [PassengerController::class, 'update']);
    Route::delete('/mis-reservas/{reservation}/passengers/{passenger}', [PassengerController::class, 'destroy']);
});
