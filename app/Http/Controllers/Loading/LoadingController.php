<?php

declare(strict_types=1);

namespace App\Http\Controllers\Loading;

use App\Http\Controllers\Controller;
use App\Services\Loading\LoadingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class LoadingController extends Controller
{
    public function __construct(
        private readonly LoadingService $loadingService,
    ) {}

    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'order_item_id' => ['required', 'integer', 'exists:order_items,id'],
            'barcode' => ['required', 'string', 'max:150'],
        ]);

        $user = $request->user();

        try {
            $pesoItem = $this->loadingService->scan(
                organizationId: (int) ($user?->organization_id ?? 1),
                warehouseId: (int) $validated['warehouse_id'],
                orderItemId: (int) $validated['order_item_id'],
                barcode: $validated['barcode'],
                userId: $user?->id,
            );

            return response()->json([
                'success' => true,
                'message' => 'Barcode assigned to order item.',
                'barcode' => $pesoItem->barcode,
                'peso_item_id' => $pesoItem->id,
                'order_item_id' => $pesoItem->order_item_id,
                'warehouse_id' => $pesoItem->warehouse_id,
                'product_id' => $pesoItem->product_id,
                'status' => $pesoItem->status,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())
                    ->flatten()
                    ->first() ?? 'Barcode could not be assigned.',
                'errors' => $e->errors(),
            ], 422);
        }
    }
}