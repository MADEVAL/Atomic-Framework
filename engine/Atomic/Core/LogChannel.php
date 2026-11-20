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

    public function emergency(string $msg, array $context = []): void { Log::write_to_channel($this->name, LogLevel::EMERGENCY, $msg, $context); }
    public function alert(string $msg, array $context = []): void     { Log::write_to_channel($this->name, LogLevel::ALERT, $msg, $context); }
    public function critical(string $msg, array $context = []): void  { Log::write_to_channel($this->name, LogLevel::CRITICAL, $msg, $context); }
    public function error(string $msg, array $context = []): void     { Log::write_to_channel($this->name, LogLevel::ERROR, $msg, $context); }
    public function warning(string $msg, array $context = []): void   { Log::write_to_channel($this->name, LogLevel::WARNING, $msg, $context); }
    public function notice(string $msg, array $context = []): void    { Log::write_to_channel($this->name, LogLevel::NOTICE, $msg, $context); }
    public function info(string $msg, array $context = []): void      { Log::write_to_channel($this->name, LogLevel::INFO, $msg, $context); }
    public function debug(string $msg, array $context = []): void     { Log::write_to_channel($this->name, LogLevel::DEBUG, $msg, $context); }
}
