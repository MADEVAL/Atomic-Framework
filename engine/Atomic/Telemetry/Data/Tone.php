<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

/** Neutral is the component's own default: green for status icons, grey for timeline dots. */
enum Tone: string
{
    case Neutral = '';
    case Ok = 'ok';
    case Bad = 'bad';
    case Warn = 'warn';
    case Mute = 'mute';
}
