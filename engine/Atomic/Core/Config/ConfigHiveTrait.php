<?php
declare(strict_types=1);
namespace Engine\Atomic\Core\Config;

if (!defined('ATOMIC_START')) exit;

trait ConfigHiveTrait
{
    protected function build_cache_string(
        string $driver,
        string $folder_path = '',
        string $server     = '',
        string $port       = '',
        string $password   = '',
        string $login      = ''
    ): string|false {
        switch ($driver) {
            case 'folder':
                return "folder={$folder_path}";

            case 'memcache':
            case 'memcached':
                return "memcache={$server}:{$port}";

            case 'redis':
                $cs = "redis={$server}:{$port}";
                if ($login !== '' && $password !== '') {
                    $cs .= "?auth={$login}:{$password}";
                } elseif ($password !== '') {
                    $cs .= "?auth={$password}";
                }
                return $cs;

            case 'apc':      return 'apc';
            case 'xcache':   return 'xcache';
            case 'wincache': return 'wincache';

            default:
                return false;
        }
    }
    
    protected function apply_settings_to_hive(\Base $atomic, array $settings): void
    {
        foreach ($settings as $key => $value) {
            if ($key === 'TZ' && $value) {
                @date_default_timezone_set($value);
            }
            if ($value !== null && $value !== '') {
                $atomic->set($key, $value);
            }
        }
        $this->sync_domain_to_hive($atomic, $settings);
    }

    protected function apply_mail_settings_to_hive(\Base $atomic, array $mail): void
    {
        $encryption = strtolower(trim((string)$mail['encryption']));
        $scheme = in_array($encryption, ['ssl', 'tls'], true) ? $encryption : '';

        $atomic->set('MAILER.smtp.host', (string)$mail['host']);
        $atomic->set('MAILER.smtp.port', (int)$mail['port']);
        $atomic->set('MAILER.smtp.scheme', $scheme);
        $atomic->set('MAILER.smtp.user', (string)$mail['username']);
        $atomic->set('MAILER.smtp.pw', (string)$mail['password']);
        $atomic->set('MAILER.from_mail', (string)$mail['from_address']);
        $atomic->set('MAILER.from_name', (string)$mail['from_name']);
        $atomic->set('MAILER.force_tls', $encryption === 'tls');
    }

    protected function sync_domain_to_hive(\Base $atomic, array $settings): void
    {
        $domain = $settings['DOMAIN'] ?? '';
        if ($domain === '') {
            return;
        }
        $domain = rtrim($domain, '/') . '/';
        $atomic->set('DOMAIN', $domain);
        $parsed = parse_url($domain);
        $host   = $parsed['host'] ?? '';
        if ($host === '') {
            return;
        }
        $scheme = $parsed['scheme'] ?? 'http';
        $port   = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);
        $atomic->set('HOST',   $host);
        $atomic->set('SCHEME', $scheme);
        $atomic->set('PORT',   (int)$port);
    }
}
