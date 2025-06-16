<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TelegramUser;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Collection;

class TaskService
{
    protected string $apiUrl;

    public function __construct()
    {
        $this->apiUrl = config('app.url') . '/api';
    }

    /**
     * Отримати всі задачі користувача
     */
    public function getUserTasks(int $telegramId): Collection
    {
        $user = TelegramUser::where('telegram_id', $telegramId)->first();

        if (!$user) {
            return collect();
        }

        try {
            $response = Http::get("{$this->apiUrl}/tasks", [
                'telegram_user_id' => $user->id
            ]);

            if ($response->successful()) {
                $tasksData = $response->json();
                return collect($tasksData)->map(function ($taskData) {
                    return $this->hydrateTask($taskData);
                });
            }
        } catch (\Exception $e) {
            \Log::error('Error fetching user tasks: ' . $e->getMessage());
        }

        return collect();
    }

    /**
     * Створити нову задачу
     */
    public function createTask(array $data): ?Task
    {
        $user = TelegramUser::where('telegram_id', $data['telegram_user_id'])->first();

        if (!$user) {
            return null;
        }

        try {
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
        } catch (\Exception $e) {
            \Log::error('Error creating task: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Оновити задачу
     */
    public function updateTask(int $taskId, array $data): bool
    {
        try {
            $response = Http::put("{$this->apiUrl}/tasks/{$taskId}", $data);
            return $response->successful();
        } catch (\Exception $e) {
            \Log::error('Error updating task: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Видалити задачу
     */
    public function deleteTask(int $taskId): bool
    {
        try {
            $response = Http::delete("{$this->apiUrl}/tasks/{$taskId}");
            return $response->successful();
        } catch (\Exception $e) {
            \Log::error('Error deleting task: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Отримати конкретну задачу
     */
    public function getTask(int $taskId): ?Task
    {
        try {
            $response = Http::get("{$this->apiUrl}/tasks/{$taskId}");

            if ($response->successful()) {
                return $this->hydrateTask($response->json());
            }
        } catch (\Exception $e) {
            \Log::error('Error fetching task: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Перетворити дані з API в модель Task
     */
    protected function hydrateTask(array $data): Task
    {
        $task = new Task();
        $task->id = $data['id'];
        $task->telegram_user_id = $data['telegram_user_id'];
        $task->title = $data['title'];
        $task->description = $data['description'];
        $task->status = $data['status'];
        $task->priority = $data['priority'];
        $task->due_date = $data['due_date'] ? new \Carbon\Carbon($data['due_date']) : null;
        $task->created_at = new \Carbon\Carbon($data['created_at']);
        $task->updated_at = new \Carbon\Carbon($data['updated_at']);

        // Завантажуємо користувача
        $task->setRelation('user', TelegramUser::find($data['telegram_user_id']));

        return $task;
    }
}
