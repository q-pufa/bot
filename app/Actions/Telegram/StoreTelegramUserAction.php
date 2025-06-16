<?php

namespace App\Actions\Telegram;

use App\Models\TelegramUser;

class StoreTelegramUserAction
{
    public function execute(array $data): TelegramUser
    {
        return TelegramUser::updateOrCreate(
            ['telegram_id' => $data['telegram_id']],
            [
                'username'   => $data['username'] ?? null,
                'first_name' => $data['first_name'] ?? null,
                'last_name'  => $data['last_name'] ?? null,
            ]
        );
    }
}
