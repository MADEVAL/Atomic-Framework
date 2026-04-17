<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Seeder as CoreSeed;

trait Seeder
{
    public function seed_users() {
        $atomic = App::instance();
        $seed_path = $atomic->get('SEEDS_BUNDLED');
        CoreSeed::run($seed_path . 'atomic_seed_users.php');
    }

    public function seed_roles() {
        $atomic = App::instance();
        $seed_path = $atomic->get('SEEDS_BUNDLED');
        CoreSeed::run($seed_path . 'atomic_seed_roles.php');
    }

    public function seed_pages() {
        $atomic = App::instance();
        $seed_path = $atomic->get('SEEDS_BUNDLED');
        CoreSeed::run($seed_path . 'atomic_seed_pages.php');
    }
}
