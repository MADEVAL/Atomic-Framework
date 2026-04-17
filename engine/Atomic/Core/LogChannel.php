<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

if (!defined('ATOMIC_START')) exit;

use \Engine\Atomic\Enums\LogLevel;

class LogChannel
{
    protected string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function get_name(): string
    {
        return $this->name;
    }

    public function emergency(string $msg): void { Log::write_to_channel($this->name, LogLevel::EMERGENCY, $msg); }
    public function alert(string $msg): void     { Log::write_to_channel($this->name, LogLevel::ALERT, $msg); }
    public function critical(string $msg): void  { Log::write_to_channel($this->name, LogLevel::CRITICAL, $msg); }
    public function error(string $msg): void     { Log::write_to_channel($this->name, LogLevel::ERROR, $msg); }
    public function warning(string $msg): void   { Log::write_to_channel($this->name, LogLevel::WARNING, $msg); }
    public function notice(string $msg): void    { Log::write_to_channel($this->name, LogLevel::NOTICE, $msg); }
    public function info(string $msg): void      { Log::write_to_channel($this->name, LogLevel::INFO, $msg); }
    public function debug(string $msg): void     { Log::write_to_channel($this->name, LogLevel::DEBUG, $msg); }
}
