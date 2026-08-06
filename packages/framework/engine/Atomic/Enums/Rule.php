<?php
declare(strict_types=1);
namespace Engine\Atomic\Enums;

if (!defined('ATOMIC_START')) exit;

enum Rule: string
{
    case UUID_V4 = 'uuid_v4';
    case EMAIL = 'email';
    case URL = 'url';
    case ENUM = 'enum';
    case REGEX = 'regex';
    case CALLBACK = 'callback';
    case NUM_MIN = 'num_min';
    case NUM_MAX = 'num_max';
    case STR_MIN = 'str_min';
    case STR_MAX = 'str_max';
    case MB_MIN = 'mb_min';
    case MB_MAX = 'mb_max';
    case PASSWORD_ENTROPY = 'password_entropy';
}