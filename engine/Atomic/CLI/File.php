<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Files\PDF;
use Engine\Atomic\Files\XLS;

trait File {
    public function file_csv2pdf() {
        $args = $this->get_cli_args();
        if (!isset($args[0]) || !isset($args[1])) {
            echo "Usage: php atomic file/csv2pdf <input.csv> <output.pdf>\n";
            return;
        }
        (new PDF(file_from: $args[0], file_to: $args[1]))->file2pdf(isset($args[2]) ? $args[2] : '');
    }

    public function file_xls2pdf() {
        $args = $this->get_cli_args();
        if (!isset($args[0]) || !isset($args[1])) {
            echo "Usage: php atomic file/xls2pdf <input.xls> <output.pdf>\n";
            return;
        }
        $xls_array = (new XLS($args[0]))->parse();
        (new PDF(file_to: $args[1]))->raw2pdf(isset($args[2]) ? $args[2] : '', $xls_array);
    }
}