<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use DefStudio\Telegraph\Facades\Telegraph;
use DefStudio\Telegraph\Models\TelegraphBot;

class RegisterTelegramCommands extends Command
{
    protected $signature = 'telegram:register-commands';
    protected $description = 'Реєстрація команд Telegram бота';

    public function handle()
    {
        $bot = TelegraphBot::first();

        if (!$bot) {
            $this->error('❌ Бот не знайдений у базі.');
            return;
        }

        Telegraph::bot($bot)->registerBotCommands([
            'start'   => 'Запустити бота',
            'help'    => 'Допомога по командам',
            'tasks'   => 'Список задач',
            'create'  => 'Створити нову задачу',
            'search'  => 'Пошук задач (назва/опис)',
            'filter'  => 'Фільтрація задач (статус, пріоритет, дедлайн)',
        ])->send();

        $this->info('✅ Команди Telegram бота зареєстровано.');
    }
}
