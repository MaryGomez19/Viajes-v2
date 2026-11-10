<?php

namespace App\Http\Controllers;

use App\Services\PrivatePackageReservationCreator;
use Illuminate\Http\Request;

class TourReservationController extends Controller
{
    public function store(Request $request, PrivatePackageReservationCreator $creator)
    {
        return $creator->createFromRequest($request, [
            'booking_channel' => 'tour',
            'default_package_prefix' => 'Tour -',
            'success_message' => '¡Reserva de tour creada con éxito!',
        ]);
    }
}
