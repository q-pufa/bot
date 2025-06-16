<?php

namespace App\Telegraph;

use DefStudio\Telegraph\Handlers\WebhookHandler;
use DefStudio\Telegraph\Keyboard\Button;
use DefStudio\Telegraph\Keyboard\Keyboard;
use App\Actions\Telegram\StoreTelegramUserAction;
use App\Services\TaskService;
use App\Models\Task;

class BotHandler extends WebhookHandler
{
    protected TaskService $taskService;

    public function __construct()
    {
        $this->taskService = app(TaskService::class);
    }

    public function start()
    {
        $from = $this->message->from();

        $user = app(StoreTelegramUserAction::class)->execute([
            'telegram_id' => $from->id(),
            'username'    => $from->username(),
            'first_name'  => $from->firstName(),
            'last_name'   => $from->lastName(),
        ]);

        $name = $from->firstName() ?: $from->username() ?: 'користувачу';

        $keyboard = Keyboard::make()->buttons([
            Button::make('📋 Мої задачі')->action('listTasks'),
            Button::make('➕ Створити задачу')->action('createTaskPrompt'),
        ]);

        $this->chat
            ->message("Вітаю, $name! 👋\nВи зареєстровані в Task Manager Bot.")
            ->keyboard($keyboard)
            ->send();

    }

    public function help()
    {
        $this->reply(
            "Доступні команди:\n" .
            "/start - Запуск бота та реєстрація користувача.\n" .
            "/help - Вивід довідки по командам бота.\n" .
            "/tasks - Переглянути всі ваші задачі.\n" .
            "/create - Створити нову задачу.\n\n" .
            "Або використовуйте кнопки для швидкого доступу:"
        );
    }

    public function tasks()
    {
        $this->listTasks();
    }

    public function create()
    {
        $this->createTaskPrompt();
    }

    public function listTasks()
    {
        if ($this->message) {
            $telegramId = $this->message->from()->id();
        } else {
            // fallback для callback-кнопок
            $telegramId = $this->chat->storage()->get('telegram_user_id');
        }

        $tasks = $this->taskService->getUserTasks($telegramId);

        if ($tasks->isEmpty()) {
            $keyboard = Keyboard::make()->buttons([
                Button::make('➕ Створити задачу')->action('createTaskPrompt'),
            ]);

            $this->chat->message("У вас ще немає задач. Створіть першу!")->keyboard($keyboard)->send();
            return;
        }

        $message = "📋 Ваші задачі:\n\n";
        $buttons = [];

        foreach ($tasks as $task) {
            $status = $this->getStatusEmoji($task->status);
            $priority = $this->getPriorityEmoji($task->priority);

            $message .= "{$status} {$priority} {$task->title}\n";
            $message .= "   Статус: {$task->status}\n";
            $message .= "   Пріоритет: {$task->priority}\n";
            if ($task->due_date) {
                $message .= "   Дедлайн: " . $task->due_date->format('d.m.Y H:i') . "\n";
            }
            $message .= "\n";

            $buttons[] = Button::make("📝 {$task->title}")->action('showTask')->param('task_id', $task->id);
        }

        $buttons[] = Button::make('➕ Створити задачу')->action('createTaskPrompt');

        $keyboard = Keyboard::make()->buttons($buttons);
        $this->chat->message($message)->keyboard($keyboard)->send();

    }

    public function showTask()
    {
        $taskId = $this->data->get('task_id');
        $task = Task::find($taskId);

        if (!$task || $task->user->telegram_id !== $this->message->from()->id()) {
            $this->reply("Задачу не знайдено або у вас немає доступу до неї.");
            return;
        }

        $status = $this->getStatusEmoji($task->status);
        $priority = $this->getPriorityEmoji($task->priority);

        $message = "{$status} {$priority} *{$task->title}*\n\n";
        $message .= "📝 *Опис:* " . ($task->description ?: 'Немає опису') . "\n";
        $message .= "🎯 *Статус:* {$task->status}\n";
        $message .= "⚡ *Пріоритет:* {$task->priority}\n";

        if ($task->due_date) {
            $message .= "⏰ *Дедлайн:* " . $task->due_date->format('d.m.Y H:i') . "\n";
        }

        $keyboard = Keyboard::make()->buttons([
            Button::make('✏️ Редагувати')->action('editTaskMenu')->param('task_id', $task->id),
            Button::make('🗑️ Видалити')->action('deleteTaskConfirm')->param('task_id', $task->id),
            Button::make('📋 Всі задачі')->action('listTasks'),
        ]);

        $this->chat->message($message)->keyboard($keyboard)->send();

    }

    public function editTaskMenu()
    {
        $taskId = $this->data->get('task_id');

        $keyboard = Keyboard::make()->buttons([
            Button::make('📝 Змінити статус')->action('changeStatus')->param('task_id', $taskId),
            Button::make('⚡ Змінити пріоритет')->action('changePriority')->param('task_id', $taskId),
            Button::make('🔙 Назад до задачі')->action('showTask')->param('task_id', $taskId),
        ]);

        $this->chat->message("Що ви хочете змінити в задачі?")->keyboard($keyboard)->send();

    }

    public function changeStatus()
    {
        $taskId = $this->data->get('task_id');

        $keyboard = Keyboard::make()->buttons([
            Button::make('⏳ Очікує')->action('updateTaskStatus')->param('task_id', $taskId)->param('status', 'pending'),
            Button::make('🔄 В процесі')->action('updateTaskStatus')->param('task_id', $taskId)->param('status', 'in_progress'),
            Button::make('✅ Завершено')->action('updateTaskStatus')->param('task_id', $taskId)->param('status', 'completed'),
            Button::make('❌ Скасовано')->action('updateTaskStatus')->param('task_id', $taskId)->param('status', 'cancelled'),
            Button::make('🔙 Назад')->action('editTaskMenu')->param('task_id', $taskId),
        ]);

        $this->chat->message("Оберіть новий статус:")->keyboard($keyboard)->send();

    }

    public function changePriority()
    {
        $taskId = $this->data->get('task_id');

        $keyboard = Keyboard::make()->buttons([
            Button::make('🔴 Високий')->action('updateTaskPriority')->param('task_id', $taskId)->param('priority', 'high'),
            Button::make('🟡 Середній')->action('updateTaskPriority')->param('task_id', $taskId)->param('priority', 'medium'),
            Button::make('🟢 Низький')->action('updateTaskPriority')->param('task_id', $taskId)->param('priority', 'low'),
            Button::make('🔙 Назад')->action('editTaskMenu')->param('task_id', $taskId),
        ]);

        $this->chat->message("Оберіть новий пріоритет:")->keyboard($keyboard)->send();

    }

    public function updateTaskStatus()
    {
        $taskId = $this->data->get('task_id');
        $status = $this->data->get('status');

        $success = $this->taskService->updateTask($taskId, ['status' => $status]);

        if ($success) {
            $this->reply("✅ Статус задачі оновлено!");
            $this->showTask();
        } else {
            $this->reply("❌ Помилка при оновленні статусу задачі.");
        }
    }

    public function updateTaskPriority()
    {
        $taskId = $this->data->get('task_id');
        $priority = $this->data->get('priority');

        $success = $this->taskService->updateTask($taskId, ['priority' => $priority]);

        if ($success) {
            $this->reply("✅ Пріоритет задачі оновлено!");
            $this->showTask();
        } else {
            $this->reply("❌ Помилка при оновленні пріоритету задачі.");
        }
    }

    public function createTaskPrompt()
    {
        $this->reply(
            "📝 Створення нової задачі\n\n" .
            "Введіть назву задачі:"
        );

        // Зберігаємо стан очікування назви задачі
        $this->chat->storage()->set('awaiting_task_title', true);
    }

    public function deleteTaskConfirm()
    {
        $taskId = $this->data->get('task_id');

        $keyboard = Keyboard::make()->buttons([
            Button::make('✅ Так, видалити')->action('deleteTask')->param('task_id', $taskId),
            Button::make('❌ Скасувати')->action('showTask')->param('task_id', $taskId),
        ]);

        $this->chat->message("❓ Ви впевнені, що хочете видалити цю задачу?")->keyboard($keyboard)->send();

    }

    public function deleteTask()
    {
        $taskId = $this->data->get('task_id');
        $success = $this->taskService->deleteTask($taskId);

        if ($success) {
            $this->reply("🗑️ Задачу видалено!");
            $this->listTasks();
        } else {
            $this->reply("❌ Помилка при видаленні задачі.");
        }
    }

    protected function handleChatMessage(\Illuminate\Support\Stringable $text): void
    {
        $plainText = $text->toString(); // або (string)$text

        // Далі використання $plainText замість $text!
        if ($this->chat->storage()->get('awaiting_task_title')) {
            $this->handleTaskTitle($plainText);
        } elseif ($this->chat->storage()->get('awaiting_task_description')) {
            $this->handleTaskDescription($plainText);
        } else {
            parent::handleChatMessage($text);
        }
    }


    protected function handleTaskTitle($title)
    {
        $this->chat->storage()->set('task_title', $title);
        $this->chat->storage()->forget('awaiting_task_title');
        $this->chat->storage()->set('awaiting_task_description', true);

        $keyboard = Keyboard::make()->buttons([
            Button::make('⏭️ Пропустити опис')->action('skipDescription'),
        ]);

        $this->chat
            ->message("✅ Назва збережена: *{$title}*\n\nТепер введіть опис задачі (або натисніть 'Пропустити опис'):")
            ->keyboard($keyboard)
            ->send();

    }

    protected function handleTaskDescription($description)
    {
        $title = $this->chat->storage()->get('task_title');
        $telegramId = $this->message->from()->id();

        $task = $this->taskService->createTask([
            'telegram_user_id' => $telegramId,
            'title' => $title,
            'description' => $description,
        ]);

        $this->chat->storage()->forget(['task_title', 'awaiting_task_description']);

        if ($task) {
            $this->reply("🎉 Задачу '{$title}' створено успішно!");
            $this->listTasks();
        } else {
            $this->reply("❌ Помилка при створенні задачі.");
        }
    }

    public function skipDescription()
    {
        $title = $this->chat->storage()->get('task_title');
        $telegramId = $this->message->from()->id();

        $task = $this->taskService->createTask([
            'telegram_user_id' => $telegramId,
            'title' => $title,
        ]);

        $this->chat->storage()->forget(['task_title', 'awaiting_task_description']);

        if ($task) {
            $this->reply("🎉 Задачу '{$title}' створено успішно!");
            $this->listTasks();
        } else {
            $this->reply("❌ Помилка при створенні задачі.");
        }
    }

    protected function getStatusEmoji($status)
    {
        return match($status) {
            'pending' => '⏳',
            'in_progress' => '🔄',
            'completed' => '✅',
            'cancelled' => '❌',
            default => '❓'
        };
    }

    protected function getPriorityEmoji($priority)
    {
        return match($priority) {
            'high' => '🔴',
            'medium' => '🟡',
            'low' => '🟢',
            default => '⚪'
        };
    }
}
