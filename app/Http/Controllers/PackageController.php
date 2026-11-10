<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Support\PackageInfoPriceValidation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PackageController extends Controller
{
    // 1. Listar TODOS los paquetes con su carrusel de imágenes (Admin View)
    public function index(Request $request)
    {
        $query = Package::with(['city', 'user', 'images'])
            ->where('is_private', false);

        if ($request->boolean('bookable_only')) {
            $query->where('stock', '>', 0);
        }

        $packages = $query->get();

        return response()->json($packages, 200);
    }

    // 2. Crear un nuevo paquete
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'city_id' => 'required|exists:cities,id',
            'duration' => 'required|string|max:255',
            'departure_date' => 'required|date|after_or_equal:today',
            'return_date' => 'required|date|after_or_equal:departure_date',
            'stock' => 'required|integer|min:0',
            'adults' => 'nullable|integer|min:0',
            'juniors' => 'nullable|integer|min:0',
            'children' => 'nullable|integer|min:0',
            'quota_adults' => 'nullable|integer|min:0',
            'quota_juniors' => 'nullable|integer|min:0',
            'quota_children' => 'nullable|integer|min:0',
            'price_adult' => 'required|numeric|min:0',
            'price_junior' => 'required|numeric|min:0',
            'price_child' => 'required|numeric|min:0',
            'package_total_cost' => 'nullable|numeric|min:0',
            'platform_profit_percent' => 'nullable|integer|in:17,18',
            'provider_cost' => 'nullable|numeric|min:0',
            'client_cost' => 'nullable|numeric|min:0',
            'supplier_payment' => 'nullable|numeric|min:0',
            'bonus_discount' => 'nullable|numeric|min:0',
            'bonus_discount_note' => 'nullable|string|max:500',
            'status' => 'sometimes|in:active,inactive',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        $validated = self::normalizePackageQuotas($validated);

        PackageInfoPriceValidation::assert($validated);

        $packageData = collect($validated)->except([
            'image',
            'adults',
            'juniors',
            'children',
        ])->all();
        $packageData['user_id'] = $request->user()->id;
        $packageData['status'] = $packageData['status'] ?? 'active';
        $packageData['is_private'] = false;

        if ($request->hasFile('image')) {
            $packageData['image_path'] = $request->file('image')->store('packages', 'public');
        }

        $package = Package::create($packageData);

        return response()->json(['message' => '¡Paquete registrado con éxito!', 'package' => $package], 201);
    }

    // 3. Ver detalle de un paquete específico (Con sus imágenes de carrusel)
    public function show($id)
    {
        $package = Package::with(['city', 'user', 'images'])->find($id);

        if (!$package) {
            return response()->json(['message' => 'Paquete no encontrado'], 404);
        }

        $package->reserved_seats_total = $package->reservations()
            ->where('state', '!=', 'canceled')
            ->sum('reserved_seats');

        return response()->json($package, 200);
    }

    // 4. Actualizar un paquete
    public function update(Request $request, $id)
    {
        $package = Package::find($id);
        if (!$package) {
            return response()->json(['message' => 'Paquete no encontrado'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:2000',
            'city_id' => 'sometimes|exists:cities,id',
            'duration' => 'sometimes|string|max:255',
            'departure_date' => 'sometimes|date',
            'return_date' => 'sometimes|date|after_or_equal:' . $request->input('departure_date', $package->departure_date->format('Y-m-d')),
            'stock' => 'sometimes|integer|min:0',
            'adults' => 'nullable|integer|min:0',
            'juniors' => 'nullable|integer|min:0',
            'children' => 'nullable|integer|min:0',
            'quota_adults' => 'nullable|integer|min:0',
            'quota_juniors' => 'nullable|integer|min:0',
            'quota_children' => 'nullable|integer|min:0',
            'price_adult' => 'sometimes|numeric|min:0',
            'price_junior' => 'sometimes|numeric|min:0',
            'price_child' => 'sometimes|numeric|min:0',
            'package_total_cost' => 'sometimes|nullable|numeric|min:0',
            'platform_profit_percent' => 'sometimes|nullable|integer|in:17,18',
            'provider_cost' => 'nullable|numeric|min:0',
            'client_cost' => 'nullable|numeric|min:0',
            'supplier_payment' => 'nullable|numeric|min:0',
            'bonus_discount' => 'nullable|numeric|min:0',
            'bonus_discount_note' => 'nullable|string|max:500',
            'status' => 'sometimes|in:active,inactive',
            'image' => 'nullable|image|max:2048',
        ]);

        $merged = array_merge($package->only([
            'package_total_cost',
            'price_adult',
            'price_junior',
            'price_child',
            'quota_adults',
            'quota_juniors',
            'quota_children',
        ]), $validated);

        $merged = self::normalizePackageQuotas($merged);

        PackageInfoPriceValidation::assert($merged);

        $data = collect($validated)->except([
            'image',
            'adults',
            'juniors',
            'children',
        ])->all();

        if (isset($merged['stock'])) {
            $data['stock'] = $merged['stock'];
        }
        if (array_key_exists('quota_adults', $merged)) {
            $data['quota_adults'] = $merged['quota_adults'];
            $data['quota_juniors'] = $merged['quota_juniors'];
            $data['quota_children'] = $merged['quota_children'];
        }

        if ($request->hasFile('image')) {
            if ($package->image_path) {
                Storage::disk('public')->delete($package->image_path);
            }
            $data['image_path'] = $request->file('image')->store('packages', 'public');
        }

        $package->update($data);

        return response()->json(['message' => 'Paquete actualizado correctamente', 'package' => $package], 200);
    }

    // 5. Eliminar un paquete por completo
    public function destroy($id)
    {
        $package = Package::with('images')->find($id);
        if (!$package) {
            return response()->json(['message' => 'Paquete no encontrado'], 404);
        }

        if ($package->image_path) {
            Storage::disk('public')->delete($package->image_path);
        }

        foreach ($package->images as $img) {
            $img->deleteStoredFile();
        }

        $package->delete();

        return response()->json(['message' => 'Paquete y sus imágenes eliminados por completo'], 200);
    }

    // 6. Vista pública (Aptos con stock e imágenes cargadas de golpe)
    public function publicIndex()
    {
        $packages = Package::with(['city', 'images'])
            ->where('status', 'active')
            ->where('is_private', false) 
            ->where('stock', '>', 0)
            ->get();

        return response()->json($packages, 200);
    }

    /**
     * Unifica cupos por edad y stock total; alimenta validación de precios por edad.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normalizePackageQuotas(array $data): array
    {
        $qa = (int) ($data['quota_adults'] ?? $data['adults'] ?? 0);
        $qj = (int) ($data['quota_juniors'] ?? $data['juniors'] ?? 0);
        $qc = (int) ($data['quota_children'] ?? $data['children'] ?? 0);
        $sum = $qa + $qj + $qc;

        if ($sum > 0) {
            $data['quota_adults'] = $qa;
            $data['quota_juniors'] = $qj;
            $data['quota_children'] = $qc;
            $data['stock'] = $sum;
        } elseif (isset($data['stock']) && (int) $data['stock'] > 0) {
            $stock = (int) $data['stock'];
            $data['quota_adults'] = $data['quota_adults'] ?? $stock;
            $data['quota_juniors'] = $data['quota_juniors'] ?? 0;
            $data['quota_children'] = $data['quota_children'] ?? 0;
            $qa = (int) $data['quota_adults'];
            $qj = (int) $data['quota_juniors'];
            $qc = (int) $data['quota_children'];
        }

        $data['adults'] = $qa;
        $data['juniors'] = $qj;
        $data['children'] = $qc;

        return $data;
    }
}
