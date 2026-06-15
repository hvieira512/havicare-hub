<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceModel;
use App\Services\DeviceModelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceModelController extends Controller
{
    public function __construct(
        private readonly DeviceModelService $service
    )
    {
    }

    public function index(): JsonResponse
    {
        $models = $this->service->all()->map(fn(DeviceModel $m) => [
            'id' => $m->id,
            'supplier_id' => $m->supplier_id,
            'supplier' => $m->supplier->name,
            'model' => $m->model,
            'protocol' => $m->protocol,
            'image' => $m->image_path,
        ]);

        return response()->json(['models' => $models]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'model' => 'required|string|max:255',
            'protocol' => 'required|string|max:255',
            'image_path' => 'sometimes|string|max:255',
        ]);

        $model = $this->service->create(
            $validated['supplier_id'],
            $validated['model'],
            $validated['protocol'],
            $validated['image_path'] ?? '',
        );

        return response()->json(['status' => 'ok', 'id' => $model->id]);
    }

    public function destroy(DeviceModel $deviceModel): JsonResponse
    {
        $this->service->delete($deviceModel);

        return response()->json(['status' => 'ok']);
    }
}
