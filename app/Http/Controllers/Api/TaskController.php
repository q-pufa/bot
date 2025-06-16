<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $tasks = Task::query()
            ->where('telegram_user_id', $request->get('telegram_user_id'))
            ->get();

        return response()->json($tasks);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $task = Task::create([
            'telegram_user_id' => $request->get('telegram_user_id'),
            'title' => $request->get('title'),
            'description' => $request->get('description'),
            'status' => $request->get('status', 'pending'),
            'priority' => $request->get('priority', 'medium'),
            'due_date' => $request->get('due_date'),
        ]);

        return response()->json($task, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Task $task)
    {
        return response()->json($task);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Task $task)
    {
        $task->update($request->only([
            'title', 'description', 'status', 'priority', 'due_date'
        ]));

        return response()->json($task);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Task $task)
    {
        $task->delete();
        return response()->json(null, 204);
    }
}
