<?php
declare(strict_types=1);
namespace Engine\Atomic\App;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Filesystem;
use Engine\Atomic\Core\ID;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\Response;
use Engine\Atomic\Core\Redactor;
use Engine\Atomic\Queue\Enums\State;
use Engine\Atomic\Queue\Enums\Driver;
use Engine\Atomic\Queue\Managers\TelemetryManager;
use Engine\Atomic\Telemetry\Data\Dump;
use Engine\Atomic\Telemetry\Data\LogPage;
use Engine\Atomic\Telemetry\Data\Range;
use Engine\Atomic\Telemetry\Reporters\ExceptionsReporter;
use Engine\Atomic\Telemetry\Reporters\DatabaseReporter;
use Engine\Atomic\Telemetry\Reporters\EnvironmentReporter;
use Engine\Atomic\Telemetry\Reporters\HiveReporter;
use Engine\Atomic\Telemetry\Reporters\LogsReporter;
use Engine\Atomic\Telemetry\Reporters\MigrationsReporter;
use Engine\Atomic\Telemetry\Reporters\MutexReporter;
use Engine\Atomic\Telemetry\Reporters\PluginsReporter;
use Engine\Atomic\Telemetry\Reporters\QueueReporter;
use Engine\Atomic\Telemetry\Reporters\QuotaReporter;
use Engine\Atomic\Telemetry\Reporters\RateLimitReporter;
use Engine\Atomic\Telemetry\Reporters\SchedulerReporter;
use Engine\Atomic\Telemetry\Reporters\SecurityReporter;
use Engine\Atomic\Telemetry\Reporters\SummaryReporter;
use Engine\Atomic\Telemetry\Stats\StatsConnection;
use Engine\Atomic\Telemetry\Stats\StatsRecorder;
use Engine\Atomic\Telemetry\View\Html;
use Engine\Atomic\Telemetry\View\Links;
use Engine\Atomic\Telemetry\View\Shell;
use Engine\Atomic\Telemetry\View\Templates;
use Engine\Atomic\Telemetry\View\Ui;
use Engine\Atomic\Theme\Assets;
use Engine\Atomic\Theme\Theme;
use Engine\Atomic\Tools\Transient;

class Telemetry extends Controller
{

    public function __construct()
    {
        parent::__construct();
        $app = App::instance();
        if (!$app->get('__theme_booted')) {
            Theme::instance('Telemetry');
            $app->set('__theme_booted', true);
        }
    }

    public function beforeroute(\Base $atomic): bool
    {
        if (!parent::beforeroute($atomic)) {
            return false;
        }

        Redactor::init_from_hive($atomic);

        // Requests without a DB connection spool their stats locally; persist
        // them before reporting so the dashboard reads current numbers.
        try {
            StatsRecorder::instance()->drain();
        } catch (\Throwable $e) {
            // Stats must never break the dashboard.
        }

        return true;
    }

    /**
     * The API base follows BASE, so apps served from a subdirectory fetch the right URLs.
     *
     * @return array{base:string}
     */
    public static function client_config(\Base $atomic): array
    {
        // Mirrors routes/telemetry.php: unknown modes fall back to 'none'.
        $mode = strtolower(trim((string)$atomic->get('TELEMETRY_ACCESS_MODE')));

        return [
            'base' => rtrim((string)$atomic->get('BASE'), '/') . '/telemetry',
            'publicAccess' => !in_array($mode, ['config', 'auth'], true),
        ];
    }

    public const TABS = ['dashboard', 'queues', 'scheduled', 'migrations', 'quota', 'ratelimit', 'plugins',
        'exceptions', 'mutex', 'security', 'database', 'hive', 'logs'];

    public function queue(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        $tab = (string)$atomic->get('GET.tab');
        $tab = in_array($tab, self::TABS, true) ? $tab : 'dashboard';
        $config = self::client_config($atomic);

        try {
            $shell = new Shell(
                tab: $tab,
                pane: $this->render_tab($tab, $config['base'], Range::of($this->range($atomic))),
                badges: (new SummaryReporter())->typed()->badges(),
                public_access: $config['publicAccess'],
                range: $this->range($atomic),
                stats_missing: StatsConnection::needs_migration(),
                retention_days: StatsConnection::retention_days(),
            );
            Assets::instance()->localize_script('telemetry', $config + ['history' => self::HISTORY_TABS], 'TELEMETRY_CONFIG');
            $atomic->set('title', 'Atomic Telemetry');
            echo $this->templates()->render('shell', new Ui(), $shell);
        } catch (\Throwable $e) {
            Log::error('Failed to render telemetry template: ' . $e->getMessage());
            $this->render_fallback($atomic);
        }
    }

    private const HISTORY_TABS = ['quota', 'exceptions', 'security'];

    public function fragment(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        $tab = (string)($params['tab'] ?? '');
        if (!in_array($tab, self::TABS, true)) {
            $atomic->error(404, 'Unknown telemetry tab');
            return;
        }
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo $this->render_tab($tab, self::client_config($atomic)['base'], Range::of($this->range($atomic)));
    }

    /** Every printed text is masked too, so no template can print a secret by mistake. */
    private function render_tab(string $tab, string $base, Range $range): Html
    {
        return Html::filtered(self::mask(...), fn (): Html => $this->render_tab_content($tab, $base, $range));
    }

    private static function mask(string $text): string
    {
        return (string)Redactor::redact_secrets($text);
    }

    private function render_tab_content(string $tab, string $base, Range $range): Html
    {
        $templates = $this->templates();
        $ui = new Ui();
        $links = new Links($base);
        try {
            $html = match ($tab) {
                'dashboard' => $templates->render('dashboard', $ui,
                    (new SummaryReporter())->typed(),
                    (new EnvironmentReporter(\Base::instance()))->environment()),
                'queues' => $templates->render('queues', $ui,
                    (new SummaryReporter())->typed()->queues,
                    $links,
                    time(),
                    ...(new QueueReporter())->job_list()),
                'exceptions' => $templates->render('exceptions', $ui, (new ExceptionsReporter())->exceptions($range), $range, $links),
                'logs' => $templates->render('logs', $ui, $this->log_page(\Base::instance(), 1), $links),
                'database' => $templates->render('database', $ui, (new DatabaseReporter())->schema()),
                'hive' => $templates->render('hive', $ui, ...(new HiveReporter(\Base::instance()))->nodes()),
                'quota' => $templates->render('quota', $ui, (new QuotaReporter())->quota($range), $range),
                'security' => $templates->render('security', $ui, (new SecurityReporter())->security($range), $range),
                'migrations' => $templates->render('migrations', $ui, (new MigrationsReporter())->migrations(), $links),
                'plugins' => $templates->render('plugins', $ui, ...(new PluginsReporter())->plugins()),
                'mutex' => $templates->render('mutex', $ui, (new MutexReporter())->mutex()),
                'ratelimit' => $templates->render('ratelimit', $ui, ...(new RateLimitReporter())->keys()),
                'scheduled' => $templates->render('scheduled', $ui, ...(new SchedulerReporter())->jobs()),
                default => throw new \UnexpectedValueException('Unknown telemetry tab ' . $tab),
            };
        } catch (\Throwable $e) {
            Log::error('Telemetry tab ' . $tab . ' failed to render: ' . $e->getMessage());
            $html = $ui->failed('This tab failed to render.');
        }

        return $html;
    }

    /** Masked like the tabs, except source files: the redactor would mask code lines like `$db = ...`. */
    public function part(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        $ui = new Ui();
        $templates = $this->templates();
        $part = (string)($params['part'] ?? '');
        $masked = static fn (\Closure $render): ?Html => Html::filtered(self::mask(...), $render);
        $html = match ($part) {
            'job-events' => $masked(fn (): ?Html => $this->job_events_part($atomic, $templates, $ui)),
            'log-lines' => $masked(fn (): Html => $templates->render('log-lines', $ui, $this->log_page($atomic, max(2, (int)$atomic->get('GET.page'))), new Links(self::client_config($atomic)['base']))),
            'dump' => $masked(fn (): ?Html => ($dump = $this->read_dump($atomic, (string)$atomic->get('GET.id'))) === null ? null : $templates->render('dump', $ui, $dump)),
            'migration-source' => ($source = (new MigrationsReporter())->source((string)$atomic->get('GET.source'), (string)$atomic->get('GET.name'))) === null
                ? null
                : $templates->render('migration-source', $ui, $source),
            default => null,
        };
        if ($html === null) {
            $atomic->error(404, 'Not available');
            return;
        }
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo $html;
    }

    private function log_page(\Base $atomic, int $page): LogPage
    {
        $channel = $atomic->clean((string)($atomic->get('GET.channel') ?? ''));

        return (new LogsReporter($atomic))->view(
            $channel !== '' ? $channel : LogPage::ALL,
            $atomic->clean((string)($atomic->get('GET.date') ?? '')),
            $page,
        );
    }

    private function templates(): Templates
    {
        return new Templates(Theme::instance()->get_theme_dir() . 'views');
    }

    private function job_events_part(\Base $atomic, Templates $templates, Ui $ui): ?Html
    {
        $driver = (string)$atomic->get('GET.driver');
        $uuid = (string)$atomic->get('GET.uuid');
        if (!ID::is_valid_uuid_v4($uuid) || !Driver::is_valid($driver)) {
            return null;
        }

        return $templates->render('job-events', $ui, ...(new QueueReporter())->events($driver, $uuid));
    }

    public function events(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        $driver = $atomic->clean($params['driver'] ?? '');
        $job_uuid = $atomic->clean($params['job_uuid'] ?? '');
        if (!ID::is_valid_uuid_v4($job_uuid)) {
            $atomic->error(400, 'Invalid UUID format');
            return;
        }
        if (!Driver::is_valid($driver ?? '')) {
            $atomic->error(400, 'Invalid driver. Must be one of: ' . implode(', ', Driver::all()));
            return;
        }
        $telemetry_manager = new TelemetryManager();
        $events = $telemetry_manager->fetch_events($driver, 'default', $job_uuid);
        Response::instance()->send_json([
            'job_uuid' => $job_uuid,
            'driver'   => $driver,
            'events'   => Redactor::redact_secrets($events),
        ], terminate: false);
    }

    public function dashboard(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        Response::instance()->send_json((new EnvironmentReporter($atomic))->environment()->to_array(), terminate: false);
    }

    public function hive(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        Response::instance()->send_json((new HiveReporter($atomic))->hive(), terminate: false);
    }

    public function log_channels(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        Response::instance()->send_json((new LogsReporter($atomic))->channels(), terminate: false);
    }

    public function log_stat(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        Response::instance()->send_json((new LogsReporter($atomic))->stat(
            $atomic->clean((string)($atomic->get('GET.channel') ?? '')),
            $atomic->clean((string)($atomic->get('GET.date') ?? '')),
        ), terminate: false);
    }

    public function logs(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        Response::instance()->send_json((new LogsReporter($atomic))->page(
            $atomic->clean((string)($atomic->get('GET.channel') ?? '')),
            $atomic->clean((string)($atomic->get('GET.date') ?? '')),
            (int)($atomic->get('GET.page') ?? 1),
            (int)($atomic->get('GET.per_page') ?? 100),
        ), terminate: false);
    }

    public function dump(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        $dump_id = (string)($params['dump_id'] ?? '');
        if (!ID::is_valid_uuid_v4($dump_id)) {
            $atomic->error(400, 'Invalid dump id');
            return;
        }
        $dump = $this->read_dump($atomic, $dump_id);
        if ($dump === null) {
            $atomic->error(404, 'Dump not found');
            return;
        }
        Response::instance()->send_json($dump->data, terminate: false);
    }

    private function read_dump(\Base $atomic, string $dump_id): ?Dump
    {
        if (!ID::is_valid_uuid_v4($dump_id)) {
            return null;
        }
        $filesystem = Filesystem::instance();
        $dumps_dir = rtrim((string)$atomic->get('DUMPS'), '/\\');
        $file = $dumps_dir ? ($dumps_dir . DIRECTORY_SEPARATOR . $dump_id . '.json') : null;
        if (!$file || !$filesystem->is_file($file)) {
            return null;
        }
        $decoded = json_decode($filesystem->read($file) ?: '', true);

        return new Dump($dump_id, json_last_error() === JSON_ERROR_NONE
            ? Redactor::redact_secrets($decoded)
            : ['dump_id' => $dump_id, 'error' => 'dump file could not be decoded']);
    }

    private function range(\Base $atomic): string
    {
        $range = strtolower(trim((string)($atomic->get('GET.range') ?? '24h')));

        return in_array($range, ['24h', '7d', '30d'], true) ? $range : '24h';
    }

    public function jobs(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        Response::instance()->send_json(
            Redactor::redact_secrets((new QueueReporter())->jobs()),
            terminate: false,
        );
    }

    public function summary(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        Response::instance()->send_json(
            Redactor::redact_secrets((new SummaryReporter())->summary()),
            terminate: false,
        );
    }

    public function schedule(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        Response::instance()->send_json(
            Redactor::redact_secrets((new SchedulerReporter())->report()),
            terminate: false,
        );
    }

    public function migrations(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        Response::instance()->send_json(
            Redactor::redact_secrets((new MigrationsReporter())->report()),
            terminate: false,
        );
    }

    public function migration_source(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        $source = (new MigrationsReporter())->source(
            (string)$atomic->get('GET.source'),
            (string)$atomic->get('GET.name'),
        );
        if ($source === null) {
            $atomic->error(404, 'Migration file not available');
            return;
        }
        Response::instance()->send_json($source->to_array(), terminate: false);
    }

    public function database(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        Response::instance()->send_json((new DatabaseReporter())->report(), terminate: false);
    }

    public function plugins(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        Response::instance()->send_json(
            Redactor::redact_secrets((new PluginsReporter())->report()),
            terminate: false,
        );
    }

    public function mutex(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        Response::instance()->send_json(
            Redactor::redact_secrets((new MutexReporter())->report()),
            terminate: false,
        );
    }

    public function rate_limits(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        Response::instance()->send_json(
            Redactor::redact_secrets((new RateLimitReporter())->report()),
            terminate: false,
        );
    }

    public function quota(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        Response::instance()->send_json(
            Redactor::redact_secrets((new QuotaReporter())->report($this->range($atomic))),
            terminate: false,
        );
    }

    public function exceptions(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        Response::instance()->send_json(
            Redactor::redact_secrets((new ExceptionsReporter())->report($this->range($atomic))),
            terminate: false,
        );
    }

    public function security(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        Response::instance()->send_json(
            Redactor::redact_secrets((new SecurityReporter())->report($this->range($atomic))),
            terminate: false,
        );
    }

    private function render_fallback(\Base $atomic): void
    {
        $atomic->status(500);
        if (!headers_sent()) {
            header('HTTP/1.1 500 Internal Server Error');
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Telemetry</title></head><body>';
        echo '<h1>Telemetry Unavailable</h1>';
        echo '<p>The telemetry theme is not installed. Place a custom override in the configured public themes directory.</p>';
        echo '</body></html>';
    }
}
