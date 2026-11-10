<?php

namespace App\Http\Controllers;

use App\Services\PrivatePackageReservationCreator;
use Illuminate\Http\Request;

class QuickReservationController extends Controller
{
    public function store(Request $request, PrivatePackageReservationCreator $creator)
    {
        return $creator->createFromRequest($request, [
            'booking_channel' => 'quick_private',
            'default_package_prefix' => 'Reserva privada -',
            'success_message' => '¡Reserva privada creada con éxito!',
        ]);
    }
}
