<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Managers;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\Log;

class ProcessManager
{
    private bool $can_check_processes = false;

    public function __construct() {
        $this->can_check_processes = $this->check_process_capabilities();
        
        if (!$this->can_check_processes) {
            Log::warning("AtomicProcessManager cannot read process information. Signals will not be sent.");
        }
    }

    public function can_check_processes(): bool {
        return $this->can_check_processes;
    }

    public function check_process_capabilities(): bool
    {
        if (!\is_dir('/proc') || !\is_readable('/proc')) {
            return false;
        }
        
        $pid = \getmypid();
        $stat_file = "/proc/$pid/stat";

        if (!\is_readable($stat_file)) {
            return false;
        }
        
        try {
            $start_ticks = $this->get_process_start_ticks($pid);
            return $start_ticks !== null;
        } catch (\Throwable $e) {
            Log::error("Error checking process capabilities: " . $e->getMessage());
            return false;
        }
    }

    public function get_process_start_ticks(int $pid): ?int
    {
        try {
            $stat_file = "/proc/$pid/stat";
            if (!\is_readable($stat_file)) {
                return null;
            }

            $content = \file_get_contents($stat_file);
            if ($content === false) {
                return null;
            }
            
            $last_paren_pos = \strrpos($content, ')');
            if ($last_paren_pos === false) {
                return null;
            }
            
            $remaining = \ltrim(\substr($content, $last_paren_pos + 1));
            $parts = \preg_split('/\s+/', $remaining);
            
            if ($parts === false || \count($parts) < 20) {
                return null;
            }
            
            return (int)$parts[19];
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function is_our_process(int $pid, array $job): bool
    {
        if (!$this->can_check_processes) {
            return false;
        }
        
        $current_start_ticks = $this->get_process_start_ticks($pid);
        if ($current_start_ticks === null) {
            return false;
        }
        
        $stored_start_ticks = $job['process_start_ticks'] ?? null;
        if ($stored_start_ticks === null) {
            return false;
        }

        return $current_start_ticks === (int) $stored_start_ticks;
    }
}