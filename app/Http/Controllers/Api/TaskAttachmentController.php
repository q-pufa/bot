<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Request;

class TaskAttachmentController extends Controller
{
    public function store(Request $request, Task $task): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|string',
            'file_id' => 'nullable|string',
            'file_url' => 'nullable|url',
            'original_name' => 'nullable|string',
            'mime_type' => 'nullable|string',
        ]);

        $attachment = $task->attachments()->create($validated);

        return response()->json($attachment, 201);
    }

    public function destroy(Attachment $attachment): JsonResponse
    {
        $attachment->delete();
        return response()->json(null, 204);
    }
}
