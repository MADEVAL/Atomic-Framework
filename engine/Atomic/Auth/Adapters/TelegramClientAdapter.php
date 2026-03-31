<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth\Adapters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Tools\Telegram;

class TelegramClientAdapter
{
    public function verify_login_widget(array $auth_data, string $bot_token): array|false
    {
        return Telegram::instance()->verifyLoginWidget($auth_data, $bot_token);
    }

    public function get_widget_attributes(string $size, bool $request_access, bool $use_avatar, int $corner_radius): array
    {
        return Telegram::instance()->getWidgetAttributes($size, $request_access, $use_avatar, $corner_radius);
    }
}
