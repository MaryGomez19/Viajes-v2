<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Reservation;

trait AssertsReservationServiceable
{
    protected function assertReservationServiceable(int $reservationId): Reservation
    {
        $reservation = Reservation::find($reservationId);

        if (!$reservation) {
            abort(404, 'Reserva no encontrada.');
        }

        if ($reservation->trashed()) {
            abort(422, 'No se puede asignar servicio a una reserva archivada.');
        }

        if ($reservation->state === 'canceled') {
            abort(422, 'No se puede asignar servicio a una reserva cancelada.');
        }

        return $reservation;
    }
}
