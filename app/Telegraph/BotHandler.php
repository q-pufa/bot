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
            $this->chat->storage()->set('telegram_user_id', $telegramId);
        } else {
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
            $status = $this->getStatusEmoji($task->status instanceof \BackedEnum ? $task->status->value : $task->status);
            $priority = $this->getPriorityEmoji($task->priority instanceof \BackedEnum ? $task->priority->value : $task->priority);

            $statusText = $task->status instanceof \BackedEnum ? $task->status->value : $task->status;
            $priorityText = $task->priority instanceof \BackedEnum ? $task->priority->value : $task->priority;

            $message .= "{$status} {$priority} {$task->title}\n";
            $message .= "   Статус: {$statusText}\n";
            $message .= "   Пріоритет: {$priorityText}\n";
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

        $telegramId = $this->message?->from()?->id() ?? $this->chat->storage()->get('telegram_user_id');

        if (!$task || $task->user->telegram_id != $telegramId) {
            $this->chat->message("Задачу не знайдено або у вас немає доступу до неї.")->send();
            return;
        }

        $status = $this->getStatusEmoji($task->status);
        $priority = $this->getPriorityEmoji($task->priority);

        $message = "{$status} {$priority} *{$task->title}*\n\n";
        $message .= "📝 *Опис:* " . ($task->description ?: 'Немає опису') . "\n";
        $message .= "🎯 *Статус:* {$task->status->value}\n";
        $message .= "⚡ *Пріоритет:* {$task->priority->value}\n";
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
            Button::make('✏️ Змінити назву')->action('editTitle')->param('task_id', $taskId),
            Button::make('📝 Змінити опис')->action('editDescription')->param('task_id', $taskId),
            Button::make('📝 Змінити статус')->action('changeStatus')->param('task_id', $taskId),
            Button::make('⚡ Змінити пріоритет')->action('changePriority')->param('task_id', $taskId),
            Button::make('⏰ Змінити дедлайн')->action('editDeadline')->param('task_id', $taskId),
            Button::make('🔙 Назад до задачі')->action('showTask')->param('task_id', $taskId),

        ]);

        $this->chat->message("Що ви хочете змінити в задачі?")->keyboard($keyboard)->send();

    }

    public function editTitle()
    {
        $taskId = $this->data->get('task_id');
        $this->chat->storage()->set('edit_task_id', $taskId);
        $this->chat->storage()->set('awaiting_new_title', true);
        $this->chat->message("Введіть нову назву задачі:")->send();
    }

    public function editDescription()
    {
        $taskId = $this->data->get('task_id');
        $this->chat->storage()->set('edit_task_id', $taskId);
        $this->chat->storage()->set('awaiting_new_description', true);
        $this->chat->message("Введіть новий опис задачі:")->send();
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

    public function editDeadline()
    {
        $taskId = $this->data->get('task_id');
        $this->chat->storage()->set('edit_task_id', $taskId);
        $this->chat->storage()->set('awaiting_new_deadline', true);
        $this->chat->message("Введіть новий дедлайн у форматі `дд.мм.рррр год:хв` (або тільки дату):")->send();
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
        $this->chat->message(
            "📝 Створення нової задачі\n\n" .
            "Введіть назву задачі:"
        )->send();

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
        $plainText = $text->toString();

        if ($this->chat->storage()->get('awaiting_task_title')) {
            $this->handleTaskTitle($plainText);
        } elseif ($this->chat->storage()->get('awaiting_task_description')) {
            $this->handleTaskDescription($plainText);
        } elseif ($this->chat->storage()->get('awaiting_task_due_date')) {
            $this->setTaskDueDate($plainText);
        } elseif ($this->chat->storage()->get('awaiting_new_title')) {
            $this->saveNewTitle($plainText);
        } elseif ($this->chat->storage()->get('awaiting_new_description')) {
            $this->saveNewDescription($plainText);
        } elseif ($this->chat->storage()->get('awaiting_new_deadline')) {
            $this->saveNewDeadline($plainText);
        } else {
            parent::handleChatMessage($text);
        }
    }

    protected function saveNewTitle($title)
    {
        $taskId = $this->chat->storage()->get('edit_task_id');
        $this->chat->storage()->forget('awaiting_new_title');
        $this->taskService->updateTask($taskId, ['title' => $title]);
        $this->chat->message("✅ Назву задачі змінено!")->send();
        $this->showTaskWithId($taskId);
    }


    protected function saveNewDeadline($date)
    {
        $taskId = $this->chat->storage()->get('edit_task_id');
        $this->chat->storage()->forget('awaiting_new_deadline');
        $dateTime = \DateTime::createFromFormat('d.m.Y H:i', trim($date));
        if ($dateTime === false) {
            $dateTime = \DateTime::createFromFormat('d.m.Y', trim($date));
            if ($dateTime !== false) {
                $dateTime->setTime(23, 59, 59);
            }
        }
        if ($dateTime === false) {
            $this->chat->message("❌ Неправильний формат дати.")->send();
            return;
        }
        $this->taskService->updateTask($taskId, ['due_date' => $dateTime->format('Y-m-d H:i:s')]);
        $this->chat->message("✅ Дедлайн змінено!")->send();
        $this->showTaskWithId($taskId);
    }

    protected function showTaskWithId($taskId)
    {
        $this->data = collect(['task_id' => $taskId]);
        $this->showTask();
    }


    public function setTaskDueDate($date = null)
    {
        $this->chat->storage()->forget('awaiting_task_due_date');

        if ($date && trim($date) !== '') {
            try {
                $dateTime = \DateTime::createFromFormat('d.m.Y H:i', trim($date));
                if ($dateTime === false) {
                    $dateTime = \DateTime::createFromFormat('d.m.Y', trim($date));
                    if ($dateTime !== false) {
                        $dateTime->setTime(23, 59, 59);
                    }
                }

                if ($dateTime !== false) {
                    $this->chat->storage()->set('task_due_date', $dateTime->format('Y-m-d H:i:s'));
                }
            } catch (\Exception $e) {
                \Log::error('Date parsing error: ' . $e->getMessage());
            }
        }

        $this->createTaskFromStorage();
    }


    public function skipTaskDueDate()
    {
        $this->chat->storage()->forget('awaiting_task_due_date');
        $this->createTaskFromStorage();
    }

    protected function createTaskFromStorage()
    {
        $telegramId = $this->chat->storage()->get('telegram_user_id') ?? $this->message->from()->id();
        $title = $this->chat->storage()->get('task_title');
        $description = $this->chat->storage()->get('task_description');
        $status = $this->chat->storage()->get('task_status');
        $priority = $this->chat->storage()->get('task_priority');
        $due_date = $this->chat->storage()->get('task_due_date');

        $taskData = [
            'telegram_user_id' => $telegramId,
            'title' => $title,
            'description' => $description,
            'status' => 'pending',
            'priority' => $priority,
        ];
        if ($due_date) $taskData['due_date'] = $due_date;

        $task = $this->taskService->createTask($taskData);

        foreach (['task_title', 'task_description', 'task_status', 'task_priority', 'task_due_date'] as $key) {
            $this->chat->storage()->forget($key);
        }

        if ($task) {
            $this->reply("🎉 Задачу '{$title}' створено успішно!");
            $this->listTasks();
        } else {
            $this->reply("❌ Помилка при створенні задачі.");
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
        $this->chat->storage()->set('task_description', $description);
        $this->chat->storage()->forget('awaiting_task_description');
        $this->askTaskStatus();
    }

    public function setTaskStatus()
    {
        $status = $this->data->get('status');
        $this->chat->storage()->set('task_status', $status);
        $this->chat->storage()->forget('awaiting_task_status');
        $this->chat->storage()->set('awaiting_task_priority', true);

        $keyboard = Keyboard::make()->buttons([
            Button::make('🔴 Високий')->action('setTaskPriority')->param('priority', 'high'),
            Button::make('🟡 Середній')->action('setTaskPriority')->param('priority', 'medium'),
            Button::make('🟢 Низький')->action('setTaskPriority')->param('priority', 'low'),
        ]);

        $this->chat
            ->message("Оберіть пріоритет задачі:")
            ->keyboard($keyboard)
            ->send();
    }

    public function setTaskPriority()
    {
        $priority = $this->data->get('priority');
        $this->chat->storage()->set('task_priority', $priority);
        $this->chat->storage()->forget('awaiting_task_priority');
        $this->chat->storage()->set('awaiting_task_due_date', true);

        $this->chat
            ->message("Введіть дедлайн у форматі `дд.мм.рррр год:хв` або натисніть 'Пропустити':")
            ->keyboard(
                Keyboard::make()->buttons([
                    Button::make('⏭️ Пропустити дедлайн')->action('skipTaskDueDate'),
                ])
            )->send();
    }

    public function skipDescription()
    {
        $this->chat->storage()->set('task_description', null);
        $this->chat->storage()->forget('awaiting_task_description');
        $this->askTaskStatus();
    }

    protected function askTaskStatus()
    {
        $this->chat->storage()->set('awaiting_task_status', true);

        $keyboard = Keyboard::make()->buttons([
            Button::make('⏳ Очікує')->action('setTaskStatus')->param('status', 'pending'),
            Button::make('🔄 В процесі')->action('setTaskStatus')->param('status', 'in_progress'),
            Button::make('✅ Завершено')->action('setTaskStatus')->param('status', 'completed'),
            Button::make('❌ Скасовано')->action('setTaskStatus')->param('status', 'cancelled'),
        ]);

        $this->chat
            ->message("Оберіть статус задачі:")
            ->keyboard($keyboard)
            ->send();
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
