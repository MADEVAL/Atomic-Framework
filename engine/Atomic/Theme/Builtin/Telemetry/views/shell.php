<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Telemetry\View\Html;
use Engine\Atomic\Telemetry\View\Shell;
use Engine\Atomic\Telemetry\View\Ui;

return static function (Ui $ui, Shell $shell): void {
    // [tab, icon, label, badge id]
    $nav = [
        ['dashboard', 'dash', 'Dashboard', null],
        ['queues', 'list', 'Queues', 'q-badge'],
        ['scheduled', 'cal', 'Scheduled', null],
        ['migrations', 'layers', 'Migrations', 'm-badge'],
        ['quota', 'gauge', 'Quota', null],
        ['ratelimit', 'timer', 'Rate limits', 'rl-badge'],
        ['plugins', 'puzzle', 'Plugins', 'pl-badge'],
        ['exceptions', 'bug', 'Exceptions', null],
        ['mutex', 'lock', 'Mutex', 'mx-badge'],
        ['security', 'shield', 'Security', 'sy-badge'],
        ['database', 'table', 'Database', null],
        ['hive', 'db', 'Hive', null],
        ['logs', 'scroll', 'Logs', null],
    ];
    $ranges = ['24h' => '24 hours', '7d' => '7 days', '30d' => '30 days'];
    $auto_refresh = ['0' => 'Auto-refresh: off', '10' => 'Every 10 s', '30' => 'Every 30 s', '60' => 'Every 60 s'];

    get_head();
    get_section('icons');
?>
<div class="app">
  <aside class="side">
    <h1 class="brand"><span class="brand-dot"></span>Telemetry</h1>
    <nav class="nav">
<?php foreach ($nav as [$tab, $icon, $label, $badge]): ?>
      <button class="nav-tab<?= $tab === $shell->tab ? ' is-on' : '' ?>" data-tab="<?= $tab ?>"><?= $ui->icon($icon) ?><?= $ui->e($label) ?><?php if ($badge !== null): ?><span class="badge<?= $shell->badge($badge) ? '' : ' hide' ?>" id="<?= $badge ?>"><?= $shell->badge($badge) ?></span><?php endif ?></button>
<?php endforeach ?>
    </nav>
    <div class="side-tools">
      <button class="btn-icon btn-icon-lg" id="theme-btn" title="Toggle theme" aria-label="Toggle theme"></button>
      <select class="select" id="auto-refresh" title="Auto refresh">
<?php foreach ($auto_refresh as $seconds => $label): ?>
        <option value="<?= $seconds ?>"><?= $ui->e($label) ?></option>
<?php endforeach ?>
      </select>
      <button class="btn" id="refresh-btn"><?= $ui->icon('refresh') ?><span>Refresh</span></button>
    </div>
  </aside>
  <main class="main">
<?php if ($shell->public_access): ?>
    <div class="note" role="alert" id="public-note">
      <?= $ui->icon('alert') ?>
      <div class="note-body"><b>Telemetry is not access-protected.</b> Anyone who can reach this URL sees logs, sessions and config. Set <span class="mono">TELEMETRY_ACCESS_MODE=auth</span> (or <span class="mono">config</span>) unless another layer guards it.</div>
      <?= $ui->icon_button('x', 'Dismiss on this browser', ['data-dismiss' => 'public-note']) ?>
    </div>
<?php endif ?>
    <div class="rangebar hide" id="rangebar">
      <div class="pills">
<?php foreach ($ranges as $range => $label): ?>
        <button class="pill<?= $range === $shell->range ? ' is-on' : '' ?>" data-range="<?= $range ?>"><?= $ui->e($label) ?></button>
<?php endforeach ?>
      </div>
      <span class="muted">History is kept for 30 days. All-time figures are lifetime counters.</span>
    </div>
<?php foreach ($nav as [$tab]): ?>
    <section class="pane<?= $tab === $shell->tab ? ' is-on' : '' ?>" id="tab-<?= $tab ?>"><?= $tab === $shell->tab ? $shell->pane : '' ?></section>
<?php endforeach ?>
  </main>
</div>
<?php
    get_footer();
};
