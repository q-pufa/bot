<?php

namespace App\Telegraph;

use DefStudio\Telegraph\Handlers\WebhookHandler;
use App\Actions\Telegram\StoreTelegramUserAction;
use Illuminate\Support\Facades\Http;
use DefStudio\Telegraph\Models\TelegraphBot;
use Illuminate\Http\Request;

class BotHandler extends WebhookHandler
{
    public function handle(Request $request, TelegraphBot $bot): void
    {
        parent::handle($request, $bot);

        $msg = $this->message->text();
        $userId = $this->message->from()->id();
        $step = cache()->get("tg:step:$userId");

        // Якщо користувач у діалозі створення задачі
        if ($step === 'wait_title' || $step === 'wait_description') {
            $this->newtask($msg);
            return;
        }

        if ($msg === '/tasks') {
            $this->tasks();
        } elseif (str_starts_with($msg, '/newtask')) {
            $this->newtask(trim(str_replace('/newtask', '', $msg)));
        } elseif (str_starts_with($msg, '/updatetask')) {
            $this->updatetask(trim(str_replace('/updatetask', '', $msg)));
        } elseif (str_starts_with($msg, '/deletetask')) {
            $this->deletetask(trim(str_replace('/deletetask', '', $msg)));
        }
    }


    public function start()
    {
        $from = $this->message->from();

        app(StoreTelegramUserAction::class)->execute([
            'telegram_id' => $from->id(),
            'username'    => $from->username(),
            'first_name'  => $from->firstName(),
            'last_name'   => $from->lastName(),
        ]);

        $name = $from->firstName() ?: $from->username() ?: 'користувачу';

        $this->reply("Вітаю, $name! 👋\nВи зареєстровані в Task Manager Bot.");
    }

    public function help()
    {
        $this->reply(
            "Доступні команди:\n" .
            "/start - Запуск бота та реєстрація користувача.\n" .
            "/help - Вивід довідки по командам бота.\n" .
            "/tasks - Показати список ваших задач.\n" .
            "/newtask [текст] - Створити нову задачу.\n" .
            "/updatetask [id] [текст] - Оновити задачу.\n" .
            "/deletetask [id] - Видалити задачу."
        );
    }

    public function tasks()
    {
        $from = $this->message->from();
        $response = Http::get(config('services.api.url') . '/api/tasks', [
            'telegram_user_id' => $from->id(),
        ]);

        if ($response->successful() && count($response->json())) {
            $tasks = collect($response->json())
                ->map(fn($task) => "• *{$task['title']}* [{$task['status']}] (ID: {$task['id']})")
                ->implode("\n");
            $this->reply("Ваші задачі:\n\n$tasks");
        } else {
            $this->reply('У вас ще немає задач або сталася помилка.');
        }
    }

// Импровизированный state-менеджмент через cache
    public function newtask($text = null)
    {
        $from = $this->message->from();
        $userId = $from->id();

        // Если только /newtask — спрашиваем title
        if (empty(trim($text))) {
            cache()->put("tg:step:$userId", 'wait_title', 300);
            $this->reply('✍️ Введіть назву задачі:');
            return;
        }

        // Проверяем, ждем ли мы title
        $step = cache()->get("tg:step:$userId");
        if ($step === 'wait_title') {
            $title = trim($this->message->text());
            if (mb_strlen($title) < 2) {
                $this->reply('🤏 Назва має бути хоча б 2 символи! Введіть ще раз:');
                return;
            }
            cache()->put("tg:task_title:$userId", $title, 300);
            cache()->put("tg:step:$userId", 'wait_description', 300);

            $this->reply("📝 Бажаєте додати опис? (напишіть опис або натисніть /skip)");
            return;
        }

        // Ждем описание
        if ($step === 'wait_description') {
            $description = trim($this->message->text());
            if ($description === '/skip') $description = '';

            $title = cache()->pull("tg:task_title:$userId");
            cache()->forget("tg:step:$userId");

            // Теперь отправляем API
            $response = Http::post(config('services.api.url') . '/api/tasks', [
                'telegram_user_id' => $userId,
                'title' => $title,
                'description' => $description,
            ]);
            if ($response->successful()) {
                $this->reply("🎉 Задачу '$title' створено! Молодець 💪");
            } else {
                $this->reply("Щось пішло не так 😕 Спробуй пізніше.");
            }
            return;
        }

        // Если команда с title через | — старий варіант (залишаємо для сумісності)
        [$title, $description] = explode('|', $text.'|');
        $title = trim($title);
        $description = trim($description);

        if (empty($title)) {
            $this->reply("❗️ Напишіть назву задачі після /newtask або просто відправте /newtask для діалогу!");
            return;
        }

        $response = Http::post(config('services.api.url') . '/api/tasks', [
            'telegram_user_id' => $userId,
            'title' => $title,
            'description' => $description,
        ]);

        if ($response->successful()) {
            $this->reply("✅ Задачу '{$title}' створено!");
        } else {
            $this->reply("Не вдалося створити задачу 😔");
        }
    }



    public function updatetask($text)
    {
        $from = $this->message->from();
        [$id, $status] = explode(' ', $text);

        $response = Http::put(config('services.api.url') . "/api/tasks/{$id}", [
            'status' => $status,
        ]);

        if ($response->successful()) {
            $this->reply("Задачу оновлено ✅");
        } else {
            $this->reply("Не вдалося оновити задачу 😔");
        }
    }

    public function deletetask($id)
    {
        $response = Http::delete(config('services.api.url') . "/api/tasks/{$id}");

        if ($response->status() === 204) {
            $this->reply("Задачу видалено 🗑️");
        } else {
            $this->reply("Не вдалося видалити задачу 😔");
        }
    }
}

