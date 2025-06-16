<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Task::with('user');

        // Фільтр по користувачу Telegram
        if ($request->has('telegram_user_id')) {
            $query->where('telegram_user_id', $request->get('telegram_user_id'));
        }

        // Фільтр по статусу
        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        // Фільтр по пріоритету
        if ($request->has('priority')) {
            $query->where('priority', $request->get('priority'));
        }

        $tasks = $query->orderBy('created_at', 'desc')->get();

        return response()->json($tasks);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'telegram_user_id' => 'required|exists:telegram_users,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => ['nullable', 'string', Rule::in(['pending', 'in_progress', 'completed', 'cancelled'])],
            'priority' => ['nullable', 'string', Rule::in(['low', 'medium', 'high'])],
            'due_date' => 'nullable|date',
        ]);

        $task = Task::create([
            'telegram_user_id' => $validated['telegram_user_id'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? 'pending',
            'priority' => $validated['priority'] ?? 'medium',
            'due_date' => $validated['due_date'] ?? null,
        ]);

        $task->load('user');

        return response()->json($task, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Task $task): JsonResponse
    {
        $task->load('user');
        return response()->json($task);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Task $task): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'status' => ['sometimes', 'string', Rule::in(['pending', 'in_progress', 'completed', 'cancelled'])],
            'priority' => ['sometimes', 'string', Rule::in(['low', 'medium', 'high'])],
            'due_date' => 'nullable|date',
        ]);

        $task->update($validated);
        $task->load('user');

        return response()->json($task);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Task $task): JsonResponse
    {
        $task->delete();
        return response()->json(null, 204);
    }

    /**
     * Get tasks for specific telegram user
     */
    public function getUserTasks(int $telegramUserId): JsonResponse
    {
        $tasks = Task::with('user')
            ->where('telegram_user_id', $telegramUserId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($tasks);
    }

    /**
     * Update task status
     */
    public function updateStatus(Request $request, Task $task): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['pending', 'in_progress', 'completed', 'cancelled'])],
        ]);

        $task->update(['status' => $validated['status']]);
        $task->load('user');

        return response()->json($task);
    }

    /**
     * Update task priority
     */
    public function updatePriority(Request $request, Task $task): JsonResponse
    {
        $validated = $request->validate([
            'priority' => ['required', 'string', Rule::in(['low', 'medium', 'high'])],
        ]);

        $task->update(['priority' => $validated['priority']]);
        $task->load('user');

        return response()->json($task);
    }
}
