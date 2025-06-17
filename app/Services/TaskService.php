<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TelegramUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Collection;

class TaskService
{
    protected string $apiUrl;

    public function __construct()
    {
        $this->apiUrl = config('app.url') . '/api';
    }

    public function getUserTasks(int $telegramId): Collection
    {
        $user = TelegramUser::where('telegram_id', $telegramId)->first();

        if (!$user) {
            return collect();
        }
        $response = Http::get("{$this->apiUrl}/tasks", [
            'telegram_user_id' => $user->id
        ]);

        if ($response->successful()) {
            $tasksData = $response->json();
            return collect($tasksData)->map(function ($taskData) {
                return $this->hydrateTask($taskData);
            });
        }

        return collect();
    }

    public function createTask(array $data): ?Task
    {
        $user = TelegramUser::where('telegram_id', $data['telegram_user_id'])->first();

        if (!$user) {
            return null;
        }
        $response = Http::post("{$this->apiUrl}/tasks", [
            'telegram_user_id' => $user->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'pending',
            'priority' => $data['priority'] ?? 'medium',
            'due_date' => $data['due_date'] ?? null,
        ]);

        if ($response->successful()) {
            return $this->hydrateTask($response->json());
        }

        return null;
    }

    public function updateTask(int $taskId, array $data): bool
    {
        $response = Http::put("{$this->apiUrl}/tasks/{$taskId}", $data);
        return $response->successful();

    }

    public function deleteTask(int $taskId): bool
    {
        $response = Http::delete("{$this->apiUrl}/tasks/{$taskId}");
        return $response->successful();
    }

    public function getUserTasksFiltered(int $telegramUserId, string $search): Collection
    {
            $response = Http::get("{$this->apiUrl}/tasks", [
                'telegram_user_id' => $telegramUserId,
                'search' => $search,
            ]);
            if ($response->successful()) {
                $tasksData = $response->json();
                return collect($tasksData)->map(fn($taskData) => $this->hydrateTask($taskData));
            }

        return collect();
    }

    public function getUserTasksFilteredWithQuery(array $filters): Collection
    {
            $response = Http::get("{$this->apiUrl}/tasks", $filters);
            if ($response->successful()) {
                $tasksData = $response->json();
                return collect($tasksData)->map(fn($taskData) => $this->hydrateTask($taskData));
            }

        return collect();
    }

    protected function hydrateTask(array $data): Task
    {
        $task = new Task();
        $task->id = $data['id'];
        $task->telegram_user_id = $data['telegram_user_id'];
        $task->title = $data['title'];
        $task->description = $data['description'];
        $task->status = $data['status'];
        $task->priority = $data['priority'];
        $task->due_date = $data['due_date'] ? new Carbon($data['due_date']) : null;
        $task->created_at = new Carbon($data['created_at']);
        $task->updated_at = new Carbon($data['updated_at']);

        $task->setRelation('user', TelegramUser::find($data['telegram_user_id']));

        return $task;
    }
}
