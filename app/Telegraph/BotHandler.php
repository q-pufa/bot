<?php

namespace App\Telegraph;

use App\Models\TelegramUser;
use DefStudio\Telegraph\Handlers\WebhookHandler;
use DefStudio\Telegraph\Keyboard\Button;
use DefStudio\Telegraph\Keyboard\Keyboard;
use App\Actions\Telegram\StoreTelegramUserAction;
use App\Services\TaskService;
use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Stringable;

class BotHandler extends WebhookHandler
{
    protected TaskService $taskService;

    public function __construct()
    {
        $this->taskService = app(TaskService::class);
    }

    public function start(  ): void
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
            Button::make('Мої задачі')->action('listTasks'),
            Button::make('Створити задачу')->action('createTaskPrompt'),
            Button::make('Пошук задач')->action('searchTaskPrompt'),
            Button::make('Довідка')->action('help'),
        ]);

        $this->chat
            ->message("Вітаю, $name! 👋\nВи зареєстровані в Task Manager Bot.")
            ->keyboard($keyboard)
            ->send();

    }

    public function help(): void
    {
        $this->reply(
            "Доступні команди:\n" .
            "/start - Запуск бота та реєстрація користувача.\n" .
            "/help - Вивід довідки по командам бота.\n" .
            "/tasks - Переглянути всі ваші задачі.\n" .
            "/create - Створити нову задачу.\n" .
            "Або використовуйте кнопки для швидкого доступу:"
        );
    }

    public function tasks(): void
    {
        $this->listTasks();
    }

    public function create(): void
    {
        $this->createTaskPrompt();
    }
    public function filter(): void
    {
        $this->filterMenu();
    }

    public function search(): void
    {
        $this->searchTaskPrompt();
    }

    public function filterMenu(): void
    {
        $keyboard = Keyboard::make()->buttons([
            Button::make('Статус')->action('filterByStatusMenu'),
            Button::make('Пріоритет')->action('filterByPriorityMenu'),
            Button::make('Дата дедлайну')->action('filterByDeadlinePrompt'),
            Button::make('Всі задачі')->action('listTasks'),
        ]);

        $this->chat->message("Оберіть параметр фільтрації:")->keyboard($keyboard)->send();
    }

    public function filterByStatusMenu(): void
    {
        $keyboard = Keyboard::make()->buttons([
            Button::make('Очікує')->action('applyFilter')->param('status', 'pending'),
            Button::make('В процесі')->action('applyFilter')->param('status', 'in_progress'),
            Button::make('Завершено')->action('applyFilter')->param('status', 'completed'),
            Button::make('Скасовано')->action('applyFilter')->param('status', 'cancelled'),
            Button::make('Назад')->action('filterMenu'),
        ]);

        $this->chat->message("Оберіть статус для фільтрації:")->keyboard($keyboard)->send();
    }

    public function filterByPriorityMenu(): void
    {
        $keyboard = Keyboard::make()->buttons([
            Button::make('Високий')->action('applyFilter')->param('priority', 'high'),
            Button::make('Середній')->action('applyFilter')->param('priority', 'medium'),
            Button::make('Низький')->action('applyFilter')->param('priority', 'low'),
            Button::make('Назад')->action('filterMenu'),
        ]);

        $this->chat->message("Оберіть пріоритет для фільтрації:")->keyboard($keyboard)->send();
    }

    public function filterByDeadlinePrompt(): void
    {
        $this->chat->storage()->set('awaiting_deadline_filter', true);
        $this->chat->message("Введіть дату дедлайну у форматі `дд.мм.рррр` для фільтрації:")->send();
    }

    public function applyFilter(): void
    {
        $status = $this->data->get('status');
        $priority = $this->data->get('priority');
        $telegramId = $this->chat->storage()->get('telegram_user_id') ?? $this->message->from()->id();
        $user = TelegramUser::where('telegram_id', $telegramId)->first();

        if (!$user) {
            $this->chat->message("Спочатку зареєструйтесь через /start.")->send();
            return;
        }

        $filters = ['telegram_user_id' => $user->id];
        if ($status) {
            $filters['status'] = $status;
        }
        if ($priority) {
            $filters['priority'] = $priority;
        }

        $tasks = $this->taskService->getUserTasksFilteredWithQuery($filters);

        if ($tasks->isEmpty()) {
            $this->chat->message("Задач за обраними фільтрами не знайдено.")->send();
            return;
        }

        $formatted = $this->formatTasks($tasks);
        $formatted['buttons'][] = Button::make('Назад до фільтрів')->action('filterMenu');
        $keyboard = Keyboard::make()->buttons($formatted['buttons']);
        $this->chat->message($formatted['message'])->keyboard($keyboard)->send();
    }

    public function applyFilterWithParams(array $params): void
    {
        $telegramId = $this->chat->storage()->get('telegram_user_id') ?? $this->message->from()->id();
        $user = TelegramUser::where('telegram_id', $telegramId)->first();

        if (!$user) {
            $this->chat->message("Спочатку зареєструйтесь через /start.")->send();
            return;
        }

        $filters = ['telegram_user_id' => $user->id];
        if (isset($params['due_date_from'])) {
            $filters['due_date_from'] = $params['due_date_from'];
        }
        if (isset($params['due_date_to'])) {
            $filters['due_date_to'] = $params['due_date_to'];
        }

        $tasks = $this->taskService->getUserTasksFilteredWithQuery($filters);

        $keyboard = Keyboard::make()->buttons([
            Button::make('Фільтрувати ще раз')->action('filterByDeadlinePrompt'),
            Button::make('Назад до фільтрів')->action('filterMenu'),
        ]);

        if ($tasks->isEmpty()) {
            $this->chat->message("Задач за обраними фільтрами не знайдено.")->keyboard($keyboard)->send();
            return;
        }

        $formatted = $this->formatTasks($tasks);
        $formatted['buttons'] = array_merge($formatted['buttons'], [
            Button::make('Фільтрувати ще раз')->action('filterByDeadlinePrompt'),
            Button::make('Назад до фільтрів')->action('filterMenu'),
        ]);
        $keyboard = Keyboard::make()->buttons($formatted['buttons']);
        $this->chat->message($formatted['message'])->keyboard($keyboard)->send();
    }

    public function listTasks(): void
    {
        $telegramId = $this->message?->from()->id() ?? $this->chat->storage()->get('telegram_user_id');
        $this->chat->storage()->set('telegram_user_id', $telegramId);
        $tasks = $this->taskService->getUserTasks($telegramId);

        if ($tasks->isEmpty()) {
            $keyboard = Keyboard::make()->buttons([
                Button::make('◾️ Створити задачу')->action('createTaskPrompt'),
                Button::make('⚫️ Пошук задач')->action('searchTaskPrompt'),
                Button::make('Справка')->action('help'),
            ]);
            $this->chat->message("У вас ещё нет задач. Создайте первую!")->keyboard($keyboard)->send();
            return;
        }

        $formatted = $this->formatTasks($tasks);
        $formatted['buttons'][] = Button::make('◾️ Створити задачу')->action('createTaskPrompt');
        $formatted['buttons'][] = Button::make('⚫️ Пошук задач')->action('searchTaskPrompt');
        $keyboard = Keyboard::make()->buttons($formatted['buttons']);
        $this->chat->message($formatted['message'])->keyboard($keyboard)->send();
    }

    public function showTask(): void
    {
        $taskId = $this->data->get('task_id');
        $task = Task::find($taskId);

        $telegramId = $this->message?->from()?->id() ?? $this->chat->storage()->get('telegram_user_id');

        if (!$task || $task->user->telegram_id != $telegramId) {
            $this->chat->message("Задачу не знайдено або у вас немає доступу до неї.")->send();
            return;
        }

        $message = "*{$task->title}*\n\n";
        $message .= "*Опис*: " . ($task->description ?: 'Немає опису') . "\n";
        $message .= "*Статус*: {$task->status->value}\n";
        $message .= "*Пріоритет*: {$task->priority->value}\n";
        if ($task->due_date) {
            $message .= "*Дедлайн*: " . $task->due_date->format('d.m.Y H:i') . "\n";
        }

        $keyboard = Keyboard::make()->buttons([
            Button::make('Редагувати')->action('editTaskMenu')->param('task_id', $task->id),
            Button::make('Видалити')->action('deleteTaskConfirm')->param('task_id', $task->id),
            Button::make('Всі задачі')->action('listTasks'),
        ]);

        $this->chat->message($message)->keyboard($keyboard)->send();
    }

    public function editTaskMenu(): void
    {
        $taskId = $this->data->get('task_id');

        $keyboard = Keyboard::make()->buttons([
            Button::make('Змінити назву')->action('editTitle')->param('task_id', $taskId),
            Button::make('Змінити опис')->action('editDescription')->param('task_id', $taskId),
            Button::make('Змінити статус')->action('changeStatus')->param('task_id', $taskId),
            Button::make('Змінити пріоритет')->action('changePriority')->param('task_id', $taskId),
            Button::make('Змінити дедлайн')->action('editDeadline')->param('task_id', $taskId),
            Button::make('Назад до задачі')->action('showTask')->param('task_id', $taskId),

        ]);

        $this->chat->message("Що ви хочете змінити в задачі?")->keyboard($keyboard)->send();

    }

    public function editTitle(): void
    {
        $taskId = $this->data->get('task_id');
        $this->chat->storage()->set('edit_task_id', $taskId);
        $this->chat->storage()->set('awaiting_new_title', true);
        $this->chat->message("Введіть нову назву задачі:")->send();
    }

    public function editDescription(): void
    {
        $taskId = $this->data->get('task_id');
        $this->chat->storage()->set('edit_task_id', $taskId);
        $this->chat->storage()->set('awaiting_new_description', true);
        $this->chat->message("Введіть новий опис задачі:")->send();
    }

    public function changeStatus(): void
    {
        $taskId = $this->data->get('task_id');

        $keyboard = Keyboard::make()->buttons([
            Button::make('Очікує')->action('updateTaskStatus')->param('task_id', $taskId)->param('status', 'pending'),
            Button::make('В процесі')->action('updateTaskStatus')->param('task_id', $taskId)->param('status', 'in_progress'),
            Button::make('Завершено')->action('updateTaskStatus')->param('task_id', $taskId)->param('status', 'completed'),
            Button::make('Скасовано')->action('updateTaskStatus')->param('task_id', $taskId)->param('status', 'cancelled'),
            Button::make('Назад')->action('editTaskMenu')->param('task_id', $taskId),
        ]);

        $this->chat->message("Оберіть новий статус:")->keyboard($keyboard)->send();
    }

    public function changePriority(): void
    {
        $taskId = $this->data->get('task_id');

        $keyboard = Keyboard::make()->buttons([
            Button::make('Високий')->action('updateTaskPriority')->param('task_id', $taskId)->param('priority', 'high'),
            Button::make('Середній')->action('updateTaskPriority')->param('task_id', $taskId)->param('priority', 'medium'),
            Button::make('Низький')->action('updateTaskPriority')->param('task_id', $taskId)->param('priority', 'low'),
            Button::make('Назад')->action('editTaskMenu')->param('task_id', $taskId),
        ]);

        $this->chat->message("Оберіть новий пріоритет:")->keyboard($keyboard)->send();

    }

    public function editDeadline(): void
    {
        $taskId = $this->data->get('task_id');
        $this->chat->storage()->set('edit_task_id', $taskId);
        $this->chat->storage()->set('awaiting_new_deadline', true);
        $this->chat->message("Введіть новий дедлайн у форматі `дд.мм.рррр год:хв` (або тільки дату):")->send();
    }


    public function updateTaskStatus(): void
    {
        $taskId = $this->data->get('task_id');
        $status = $this->data->get('status');

        $success = $this->taskService->updateTask($taskId, ['status' => $status]);

        if ($success) {
            $this->reply("Статус задачі оновлено!");
            $this->showTask();
        } else {
            $this->reply("Помилка при оновленні статусу задачі.");
        }
    }

    public function updateTaskPriority(): void
    {
        $taskId = $this->data->get('task_id');
        $priority = $this->data->get('priority');

        $success = $this->taskService->updateTask($taskId, ['priority' => $priority]);

        if ($success) {
            $this->reply("Пріоритет задачі оновлено!");
            $this->showTask();
        } else {
            $this->reply("Помилка при оновленні пріоритету задачі.");
        }
    }

    public function createTaskPrompt(): void
    {
        $this->chat->message(
            "Створення нової задачі\n\n" .
            "Введіть назву задачі:"
        )->send();

        $this->chat->storage()->set('awaiting_task_title', true);
    }

    public function deleteTaskConfirm(): void
    {
        $taskId = $this->data->get('task_id');

        $keyboard = Keyboard::make()->buttons([
            Button::make('Так, видалити')->action('deleteTask')->param('task_id', $taskId),
            Button::make('Скасувати')->action('showTask')->param('task_id', $taskId),
        ]);

        $this->chat->message("Ви впевнені, що хочете видалити цю задачу?")->keyboard($keyboard)->send();

    }

    public function deleteTask(): void
    {
        $taskId = $this->data->get('task_id');
        $success = $this->taskService->deleteTask($taskId);

        if ($success) {
            $this->reply("Задачу видалено!");
            $this->listTasks();
        } else {
            $this->reply("Помилка при видаленні задачі.");
        }
    }

    public function searchTaskPrompt(): void
    {
        $this->chat->storage()->set('awaiting_search_query', true);
        $this->chat->message("Введіть текст для пошуку по задачах (назва або опис):")->send();
    }

    protected function handleChatMessage(Stringable $text): void
    {
        $plainText = trim($text->toString());

        if ($this->chat->storage()->get('awaiting_deadline_filter')) {
            $this->chat->storage()->forget('awaiting_deadline_filter');
            try {
                $date = \DateTime::createFromFormat('d.m.Y', $plainText);
                if ($date === false) {
                    $this->chat->message("Невірний формат дати. Використовуйте формат `дд.мм.рррр`.")->send();
                    $this->filterByDeadlinePrompt();
                    return;
                }
                $this->applyFilterWithParams([
                    'due_date_from' => $date->format('Y-m-d'),
                    'due_date_to' => $date->format('Y-m-d')
                ]);
            } catch (\Exception $e) {
                \Log::error('Помилка обробки дати: ' . $e->getMessage());
                $this->chat->message("Помилка при обробці дати. Спробуйте ще раз.")->send();
                $this->filterByDeadlinePrompt();
            }
            return;
        }

        if ($this->chat->storage()->get('awaiting_search_query')) {
            $this->chat->storage()->forget('awaiting_search_query');
            $this->performTaskSearch($plainText);
            return;
        }

        if ($this->chat->storage()->get('awaiting_task_title')) {
            $this->handleTaskTitle($plainText);
            return;
        }

        if ($this->chat->storage()->get('awaiting_task_description')) {
            $this->handleTaskDescription($plainText);
            return;
        }

        if ($this->chat->storage()->get('awaiting_task_due_date')) {
            $this->setTaskDueDate($plainText);
            return;
        }

        if ($this->chat->storage()->get('awaiting_new_title')) {
            $this->saveNewTitle($plainText);
            return;
        }

        if ($this->chat->storage()->get('awaiting_new_deadline')) {
            $this->saveNewDeadline($plainText);
            return;
        }

        parent::handleChatMessage($text);
    }

    protected function performTaskSearch($query): void
    {
        $telegramId = $this->chat->storage()->get('telegram_user_id') ?? $this->message->from()->id();
        $user = TelegramUser::where('telegram_id', $telegramId)->first();

        if (!$user) {
            $this->chat->message("Сначала зарегистрируйтесь через /start.")->send();
            return;
        }

        $tasks = $this->taskService->getUserTasksFiltered($user->id, $query);

        if ($tasks->isEmpty()) {
            $this->chat->message("Задач не найдено по этому запросу.")->send();
            return;
        }

        $formatted = $this->formatTasks($tasks);
        $keyboard = Keyboard::make()->buttons($formatted['buttons']);
        $this->chat->message($formatted['message'])->keyboard($keyboard)->send();
    }

    protected function saveNewTitle($title): void
    {
        $taskId = $this->chat->storage()->get('edit_task_id');
        $this->chat->storage()->forget('awaiting_new_title');
        $this->taskService->updateTask($taskId, ['title' => $title]);
        $this->chat->message("Назву задачі змінено!")->send();
        $this->showTaskWithId($taskId);
    }


    protected function saveNewDeadline($date): void
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
            $this->chat->message("Неправильний формат дати.")->send();
            return;
        }
        $this->taskService->updateTask($taskId, ['due_date' => $dateTime->format('Y-m-d H:i:s')]);
        $this->chat->message("Дедлайн змінено!")->send();
        $this->showTaskWithId($taskId);
    }

    protected function showTaskWithId($taskId): void
    {
        $this->data = collect(['task_id' => $taskId]);
        $this->showTask();
    }


    public function setTaskDueDate($date = null): void
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


    public function skipTaskDueDate(): void
    {
        $this->chat->storage()->forget('awaiting_task_due_date');
        $this->createTaskFromStorage();
    }

    protected function createTaskFromStorage(): void
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
            $this->reply("Задачу '{$title}' створено успішно!");
            $this->listTasks();
        } else {
            $this->reply("Помилка при створенні задачі.");
        }
    }

    protected function handleTaskTitle($title): void
    {
        $this->chat->storage()->set('task_title', $title);
        $this->chat->storage()->forget('awaiting_task_title');
        $this->chat->storage()->set('awaiting_task_description', true);

        $keyboard = Keyboard::make()->buttons([
            Button::make('Пропустити опис')->action('skipDescription'),
        ]);

        $this->chat
            ->message("Назва збережена: *{$title}*\n\nТепер введіть опис задачі (або натисніть 'Пропустити опис'):")
            ->keyboard($keyboard)
            ->send();

    }

    protected function handleTaskDescription($description): void
    {
        $this->chat->storage()->set('task_description', $description);
        $this->chat->storage()->forget('awaiting_task_description');
        $this->askTaskStatus();
    }

    public function setTaskStatus(): void
    {
        $status = $this->data->get('status');
        $this->chat->storage()->set('task_status', $status);
        $this->chat->storage()->forget('awaiting_task_status');
        $this->chat->storage()->set('awaiting_task_priority', true);

        $keyboard = Keyboard::make()->buttons([
            Button::make('Високий')->action('setTaskPriority')->param('priority', 'high'),
            Button::make('Середній')->action('setTaskPriority')->param('priority', 'medium'),
            Button::make('Низький')->action('setTaskPriority')->param('priority', 'low'),
        ]);

        $this->chat
            ->message("Оберіть пріоритет задачі:")
            ->keyboard($keyboard)
            ->send();
    }

    public function setTaskPriority(): void
    {
        $priority = $this->data->get('priority');
        $this->chat->storage()->set('task_priority', $priority);
        $this->chat->storage()->forget('awaiting_task_priority');
        $this->chat->storage()->set('awaiting_task_due_date', true);

        $this->chat
            ->message("Введіть дедлайн у форматі `дд.мм.рррр год:хв` або натисніть 'Пропустити':")
            ->keyboard(
                Keyboard::make()->buttons([
                    Button::make('Пропустити дедлайн')->action('skipTaskDueDate'),
                ])
            )->send();
    }

    public function skipDescription(): void
    {
        $this->chat->storage()->set('task_description', null);
        $this->chat->storage()->forget('awaiting_task_description');
        $this->askTaskStatus();
    }

    protected function askTaskStatus(): void
    {
        $this->chat->storage()->set('awaiting_task_status', true);

        $keyboard = Keyboard::make()->buttons([
            Button::make('Очікує')->action('setTaskStatus')->param('status', 'pending'),
            Button::make('В процесі')->action('setTaskStatus')->param('status', 'in_progress'),
            Button::make('Завершено')->action('setTaskStatus')->param('status', 'completed'),
            Button::make('Скасовано')->action('setTaskStatus')->param('status', 'cancelled'),
        ]);

        $this->chat
            ->message("Оберіть статус задачі:")
            ->keyboard($keyboard)
            ->send();
    }

    protected function formatTasks(Collection $tasks, bool $includeButtons = true): array
    {
        $message = "Результати:\n\n";
        $buttons = [];

        foreach ($tasks as $task) {
            $message .= "{$task->title}\n";
            $message .= "Статус: {$task->status->value}\n";
            $message .= "Пріоритет: {$task->priority->value}\n";
            if ($task->due_date) {
                $message .= "Дедлайн: " . $task->due_date->format('d.m.Y H:i') . "\n";
            }
            $message .= "\n";
            if ($includeButtons) {
                $buttons[] = Button::make(" {$task->title}")->action('showTask')->param('task_id', $task->id);
            }
        }

        return ['message' => $message, 'buttons' => $buttons];
    }
}
