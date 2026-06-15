<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Services\SupplierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function __construct(
        private readonly SupplierService $service
    )
    {
    }

    public function index(): JsonResponse
    {
        $suppliers = $this->service->all()->map(fn(Supplier $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'enabled' => $s->enabled,
            'model_count' => $s->models_count,
            'created_at' => $s->created_at,
            'updated_at' => $s->updated_at,
        ]);

        return response()->json(['suppliers' => $suppliers]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:suppliers,name',
        ]);

        $supplier = $this->service->create($validated['name']);

        return response()->json(['status' => 'ok', 'id' => $supplier->id]);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|unique:suppliers,name,' . $supplier->id,
            'enabled' => 'sometimes|boolean',
        ]);

        $this->service->update($supplier, $validated);

        return response()->json(['status' => 'ok']);
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        if ($supplier->models()->count() > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot delete supplier with existing models',
            ], 400);
        }

        $this->service->delete($supplier);

        return response()->json(['status' => 'ok']);
    }
}
