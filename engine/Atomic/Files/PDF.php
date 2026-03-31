<?php
declare(strict_types=1);
namespace Engine\Atomic\Files;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;

// Known limitations:
// - Boolean values are rendered as '1'/'0' (no Yes/No localization)

class PDF
{
    private const  PDF_VERSION           = '1.4';
    private const  PDF_BYTES             = '%âãÏÓ';

    private const  ZLIB_LEVEL            = 9;
    private const  CACHE_EXTENSION       = '.zz';

    private array  $supported_extensions = ['csv', 'xls'];

    private string $font_path            = '';
    private string $font_cash_path       = '';

    private int    $page_number          = 1;

    private array  $structure            = [];
    private int    $obj_cnt              = 0;
    private int    $xref_table_starts_at = 0;
    private array  $font_width_table     = [];
    private int    $default_width        = 0;
    private array  $desc                 = [];
    private float  $offset_x             = 0;
    private float  $offset_y             = 0;

    public function __construct(
        private string $file_to,
        private string $file_from           = '',
        private string $font_name           = 'dejavusans',
        private int    $font_size           = 14,
        private float  $page_width          = 612,
        private float  $page_height         = 792,
        private float  $cell_padding_x      = 13.00,
        private float  $cell_padding_y      = 7.00,
        private float  $offset_percentage_x = 7,
        private float  $offset_percentage_y = 5,
    ) {
        $this->offset_x = $offset_percentage_x / 100 * $this->page_width;
        $this->offset_y = (100 - $offset_percentage_y) / 100 * $this->page_height;
        $this->load_font();
    }

    private function load_font(): void
    {
        $font = \strtolower($this->font_name);
        $file_to_load = App::instance()->get('FONTS') . $font . '.php';
        if (!\is_readable($file_to_load)) throw new \Exception('Font (php array) not found');
        $ttf_path = App::instance()->get('FONTS') . $font . '.ttf';
        if (!\is_readable($ttf_path)) throw new \Exception('Font (ttf) not found');
        $this->font_path = $ttf_path;
        $this->font_cash_path = App::instance()->get('FONTS_TEMP') . $font;
        $font_data = include $file_to_load;
        $this->font_width_table = $font_data['cw'];
        $this->default_width = $font_data['dw'];
        $this->desc = $font_data['desc'];
    }

    private function embed_font_binary(): void
    {
        $ttf_path = App::instance()->get('FONTS') . $this->font_name . '.ttf';
        $ttf_binary_path = $this->font_cash_path . '_stream' . self::CACHE_EXTENSION;
        $font_stream = null;

        if (!\file_exists($ttf_path)) {
            throw new \Exception("Font file not found: $ttf_path\n");
        }
        if (\file_exists($ttf_binary_path)) {
            $font_stream = \file_get_contents($ttf_binary_path);
        } else {
            $font_binary = \file_get_contents($ttf_path);
            $font_stream = \gzcompress($font_binary, self::ZLIB_LEVEL);
            \file_put_contents($ttf_binary_path, $font_stream);
        }
        $this->add_obj('<</Length ' . \strlen($font_stream) . " /Filter /FlateDecode >>\nstream\n{$font_stream}\nendstream");
    }

    private function add_start(): void {
        $this->add_content("%PDF-" . self::PDF_VERSION . "\n" . self::PDF_BYTES . "\n\n");
    }
    private function add_end(): void {
        $this->add_content('%%EOF');
    }

    private function add_basic_objects(): void
    {
        $this->add_catalog_obj();
        $this->add_pages_obj();
        $this->add_page_obj();
        $this->embed_font_binary();
        $this->add_desc_obj();
        $this->add_font_obj();
        $this->add_cid_obj();
        $this->add_cid();
    }

    private function add_catalog_obj(): void
    {
        $this->add_obj(<<<CATALOG
        << /Type /Catalog 
           /Pages 2 0 R
        >>
        CATALOG);
    }

    private function add_pages_obj(): void {
        /* oneline required for regex replace */
        $this->add_obj('<</Type/Pages/Kids[3 0 R]/Count 1>>');
    }

    private function add_page_obj(): void
    {
        $this->add_obj(<<<PAGE
        <</Type /Page
           /Parent 2 0 R
           /Resources <</Font <</F1 6 0 R>>>>
           /MediaBox [0 0 {$this->page_width} {$this->page_height}]
           /Contents 10 0 R
        >>
        PAGE);
    }

    private function add_desc_obj(): void
    {
        $desc = '';
        foreach ($this->desc as $key => $val) {
            $desc .= "/{$key} $val\n";
        }
        $this->add_obj(<<<DESCR
        << /Type /FontDescriptor
           /FontName /DejaVuSans
           {$desc} 
           /FontFile2 4 0 R
        >>
        DESCR);
    }

    private function add_font_obj(): void
    {
        $this->add_obj(<<<FONT
        << /Type /Font
           /Subtype /Type0
           /BaseFont /DejaVuSans
           /Encoding /Identity-H
           /DescendantFonts [7 0 R]
           /ToUnicode 8 0 R
        >>
        FONT);

        $glyphs = $this->generate_wa_array();

        $this->add_obj(<<<FONT2
        << /Type /Font
           /Subtype /CIDFontType2
           /BaseFont /DejaVuSans
           /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >>
           /FontDescriptor 5 0 R
           /CIDToGIDMap 9 0 R
           /DW {$this->default_width}
           {$glyphs} 
        >>
        FONT2);
    }

    private function add_cid_obj(): void
    {
        $this->add_obj(<<<CID
        /CIDInit /ProcSet findresource begin
        12 dict begin
        begincmap
        /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> def
        /CMapName /DejaVuSans-UCS def
        /CMapType 2 def

        1 begincodespacerange
        <0000> <FFFF>
        endcodespacerange

        1 beginbfrange
        <0020> <007E> <0020>
        endbfrange

        1 beginbfrange
        <0400> <04FF> <0400>
        endbfrange

        endcmap
        CMapName currentdict /CMap defineresource pop
        end
        CID, true);
    }

    private function add_content(string $content): void
    {
        $last_element = \end($this->structure);
        $prev_offset = $last_element['offset'] ?? 0;

        $this->structure[] = [
            'content' => $content,
            'offset' => $prev_offset + \strlen($content)
        ];
    }

    private function add_obj(string $obj_content, bool $is_stream = false, bool $is_decode = false): void
    {
        $this->obj_cnt++;
        if ($is_stream) {
            $obj_content_len = \strlen($obj_content);
            $decode = $is_decode ? '/Filter /FlateDecode' : '';
            $line = "{$this->obj_cnt} 0 obj\n<</Length {$obj_content_len} {$decode}>>\nstream\n{$obj_content}\nendstream\nendobj\n\n";
        } else $line = "{$this->obj_cnt} 0 obj\n{$obj_content}\nendobj\n\n";
        $this->add_content($line);
    }

    private function generate_file(): void {
        \file_put_contents($this->file_to, \implode("", \array_column($this->structure, 'content')));
    }

    private function set_xref_table(): void
    {
        $this->xref_table_starts_at = end($this->structure)['offset'];
        $this->add_content("xref\n0 " . ($this->obj_cnt + 1) . "\n");
        $this->add_content("0000000000 65535 f\n");
        for ($i = 0; $i <= $this->obj_cnt - 1; $i++) {
            $this->add_content(\sprintf("%010d 00000 n\n", $this->structure[$i]['offset']));
        }
    }

    private function set_xref_start(): void {
        $this->add_content("startxref\n" . $this->xref_table_starts_at . "\n");
    }

    private function set_trailer(): void {
        $trailer_line = "\ntrailer\n<</Size " . ($this->obj_cnt + 1) . "/Root 1 0 R>>\n";
        $this->add_content($trailer_line);
    }

    public function get_string_width(string $string): float
    {
        $width = 0;
        foreach (mb_str_split($string) as $char) {
            $code = \mb_ord($char, 'UTF-8');
            if (isset($this->font_width_table[$code])) {
                $width += $this->font_width_table[$code];
            } else {
                $width += $this->default_width;
            }
        }
        return $width * ($this->font_size / 1000);
    }

    private function handle_new_page_adding(): void
    {
        $old_content = $this->structure[2]['content'];
        $res_content = \preg_replace_callback('/\[([^\]]*?)\]|(Count (\d*?)>)/', function ($matches) {
            if (isset($matches[1]) && $matches[1] !== '') {
                return "[" . $matches[1] . " {$this->obj_cnt} 0 R]";
            } elseif (isset($matches[3]) && $matches[3] !== '') {
                return "Count " . ((int)$matches[3] + 1) . ">";
            }
            return $matches[0];
        }, $old_content);
        $cur_offset = $this->structure[2]['offset'];
        $offset_diff = \strlen($res_content) - \strlen($old_content);
        $this->structure[2]['content'] = $res_content;
        $this->structure[2]['offset'] = $cur_offset + $offset_diff;
        for ($i = 3; $i < \count($this->structure); $i++) {
            $this->structure[$i]['offset'] += $offset_diff;
        }
    }

    private function load_cmap($ttfFile)
    {
        $f = \fopen($ttfFile, 'rb');
        if (!$f) throw new \Exception("Cannot open TTF file: $ttfFile\n");
        $hdr = \fread($f, 12);
        $numTables = \unpack('n', \substr($hdr, 4, 2))[1];
        $cmapOffset = $cmapLength = null;
        for ($i = 0; $i < $numTables; $i++) {
            $rec = \fread($f, 16);
            $tag = \substr($rec, 0, 4);
            if ($tag === 'cmap') {
                $entry = \unpack('Noffset/Nlength', \substr($rec, 8, 8));
                $cmapOffset = $entry['offset'];
                $cmapLength = $entry['length'];
                break;
            }
        }
        if (!$cmapOffset) throw new \Exception("No 'cmap' table found.\n");
        \fseek($f, $cmapOffset);
        $cmap = \fread($f, $cmapLength);
        \fclose($f);
        $numSub = \unpack('n', \substr($cmap, 2, 2))[1];
        $pos = 4;
        $subOffset = null;
        for ($i = 0; $i < $numSub; $i++) {
            $p = \unpack('nplatform/nenc/Noff', \substr($cmap, $pos, 8));
            if ($p['platform'] === 3 && $p['enc'] === 1) {
                $subOffset = $p['off'];
                break;
            }
            $pos += 8;
        }
        if ($subOffset === null) throw new \Exception("No Windows BMP cmap subtable");
        $sub = \substr($cmap, $subOffset);
        if (\unpack('n', \substr($sub, 0, 2))[1] !== 4) throw new \Exception("Only cmap format 4 supported");
        $segCount = \unpack('n', \substr($sub, 6, 2))[1] / 2;
        $ptr = 14;
        $endCode   = \array_values(\unpack('n*', \substr($sub, $ptr, 2 * $segCount)));
        $ptr += 2 * $segCount;
        $ptr += 2;
        $startCode = \array_values(\unpack('n*', \substr($sub, $ptr, 2 * $segCount)));
        $ptr += 2 * $segCount;
        $idDelta   = \array_values(\unpack('n*', \substr($sub, $ptr, 2 * $segCount)));
        $ptr += 2 * $segCount;
        $idROff    = \array_values(\unpack('n*', \substr($sub, $ptr, 2 * $segCount)));
        $ptr += 2 * $segCount;
        $glyphs    = \substr($sub, $ptr);
        $map = [];
        for ($i = 0; $i < $segCount; $i++) {
            for ($c = $startCode[$i]; $c <= $endCode[$i]; $c++) {
                if ($idROff[$i] === 0) {
                    $gid = ($c + $idDelta[$i]) & 0xFFFF;
                } else {
                    $off = $idROff[$i] + 2 * ($c - $startCode[$i]) - 2 * $segCount;
                    $g = \unpack('n', \substr($glyphs, $off, 2))[1];
                    $gid = $g ? (($g + $idDelta[$i]) & 0xFFFF) : 0;
                }
                $map[$c] = $gid;
            }
        }
        return $map;
    }

    private function add_cid()
    {
        $cmap_path = $this->font_cash_path . '_cmap' . self::CACHE_EXTENSION;
        $map_stream = null;
        if (\file_exists($cmap_path)) {
            $map_stream = \file_get_contents($cmap_path);
        } else {
            $cmap_map = $this->load_cmap($this->font_path);
            $max_cid = \max(\array_keys($cmap_map));
            $cid_to_gid = '';
            for ($cid = 0; $cid <= $max_cid; $cid++) {
                $gid = isset($cmap_map[$cid]) ? $cmap_map[$cid] : 0;
                $cid_to_gid .= \pack('n', $gid);
            }

            $map_stream = \gzcompress($cid_to_gid, self::ZLIB_LEVEL);
            \file_put_contents($cmap_path, $map_stream);
        }

        $this->add_obj($map_stream, true, true);
    }

    function generate_wa_array()
    {
        $cw = $this->font_width_table;
        $defaultWidth = $this->default_width;
        $codes = [];

        foreach ($cw as $char => $width) {
            $code = \is_int($char) ? $char : \ord($char);
            if ($width != $defaultWidth) {
                $codes[$code] = $width;
            }
        }

        \ksort($codes);

        $w = [];
        $keys = \array_keys($codes);
        $i = 0;
        $n = \count($keys);

        while ($i < $n) {
            $start = $keys[$i];
            $group = [$codes[$start]];
            $j = $i + 1;

            while ($j < $n && $keys[$j] == $keys[$j - 1] + 1) {
                $group[] = $codes[$keys[$j]];
                $j++;
            }

            if (count($group) == 1) {
                $w[] = "$start $start {$group[0]}";
            } else {
                $w[] = "$start [" . \implode(' ', $group) . "]";
            }

            $i = $j;
        }

        return "/W [ " . \implode(' ', $w) . " ]";
    }

    private function prepare_string(string $str): string {
        return strtoupper(bin2hex(mb_convert_encoding($str, 'UTF-16BE', 'UTF-8')));
    }

    public function raw2pdf(string $header, array $data): void {
        if(empty($data)) {
            throw new \Exception('Data array cannot be empty');
        }
        $this->file2pdf($header, $data);
    }

    public function file2pdf(string $header = '', array $data = []): void
    {
        $max_columns = (int) floor(($this->page_width - 2 * $this->offset_x) / ($this->cell_padding_x * 4));
        $csv = [];
        $line_width_list = [];
        
        if (!empty($data)) {
            foreach ($data as $line) {
                $cell_max_row_cnt = 0;
                if (!\is_array($line)) throw new \Exception('Line data must be an array');
                foreach ($line as $i => $cell) {
                    if (\is_array($cell)) throw new \Exception('Cell data must not be an array');
                    $cell_str = (string) $cell;
                    $line_break_list = \explode("\n", $cell_str);
                    foreach ($line_break_list as $cell_line) {
                        $line_width_list[$i] = \max($line_width_list[$i] ?? 0, $this->get_string_width($cell_line));
                    }
                    $cell_max_row_cnt = \max($cell_max_row_cnt, \count(\explode("\n", $cell_str)));
                }
                $csv[] = [
                    'cell_list' => $line,
                    'cell_max_row_cnt' => $cell_max_row_cnt
                ];
            }
        } else {
            $ext = pathinfo($this->file_from, PATHINFO_EXTENSION);
            if (!\in_array(strtolower($ext), $this->supported_extensions)) {
                throw new \Exception("Incorrect passed file extension. Supported extensions are: " . \implode(', ', $this->supported_extensions));
            }
    
            if (!\is_readable($this->file_from)) throw new \Exception('File (from) is not readable');
            $file = \fopen($this->file_from, 'r');

            if($ext === 'csv') {
                $max_cell_cnt = 0;
                while (($line = \fgetcsv($file, 0, ",", "\"", "\\")) !== false) {
                    $cell_max_row_cnt = 0;
                    $max_cell_cnt = \max($max_cell_cnt, \count($line));
                    foreach ($line as $i => $cell) {
                        $line_break_list = \explode("\n", $cell);
                        foreach ($line_break_list as $cell_line) {
                            $line_width_list[$i] = \max($line_width_list[$i] ?? 0, $this->get_string_width($cell_line));
                        }
                        $cell_max_row_cnt = \max($cell_max_row_cnt, \count(\explode("\n", $cell)));
                    }
                    $csv[] = [
                        'cell_list' => $line,
                        'cell_max_row_cnt' => $cell_max_row_cnt
                    ];
                }
                foreach ($csv as &$line) {
                    if (\count($line['cell_list']) < $max_cell_cnt) {
                        $line['cell_list'] = \array_pad($line['cell_list'], $max_cell_cnt, '');
                    }
                }
                unset($line);
            } elseif ($ext === 'xls') {
                $xls = new XLS($this->file_from);
                $rows = $xls->parse();
                $max_cell_cnt = 0;
                foreach ($rows as $row) {
                    $line = array_values($row);
                    $cell_max_row_cnt = 0;
                    $max_cell_cnt = \max($max_cell_cnt, \count($line));
                    foreach ($line as $i => $cell) {
                        $cell_str = (string) $cell;
                        $line_break_list = \explode("\n", $cell_str);
                        foreach ($line_break_list as $cell_line) {
                            $line_width_list[$i] = \max($line_width_list[$i] ?? 0, $this->get_string_width($cell_line));
                        }
                        $cell_max_row_cnt = \max($cell_max_row_cnt, \count(\explode("\n", $cell_str)));
                    }
                    $csv[] = [
                        'cell_list' => $line,
                        'cell_max_row_cnt' => $cell_max_row_cnt
                    ];
                }
                foreach ($csv as &$line) {
                    if (\count($line['cell_list']) < $max_cell_cnt) {
                        $line['cell_list'] = \array_pad($line['cell_list'], $max_cell_cnt, '');
                    }
                }
                unset($line);
            } 
        }

        $line_width_list = array_slice($line_width_list, 0, $max_columns, true);

        foreach ($csv as &$line) {
            $line['cell_list'] = \array_map(function($cell) {
                return (string) $cell;
            }, $line['cell_list']);
            $line['cell_list'] = array_intersect_key($line['cell_list'], $line_width_list);
        }
        unset($line);

        $this->add_start();
        $this->add_basic_objects();

        if (!empty($header)) {
            $center = \array_sum($line_width_list) / 2 - ($this->get_string_width($header) / 2) + $this->offset_x + \count($line_width_list) * $this->cell_padding_x;
            $header_text = $this->prepare_string($header);
            $header =
                <<<HEADER
                BT
                /F1 {$this->font_size} Tf
                {$center} {$this->offset_y} Td
                <{$header_text}> Tj
                ET\n
                HEADER;
        }
        $table_content = [$header];
        $offset_x = $this->offset_x;
        $offset_y = $this->offset_y - (empty($header) ? 0 : $this->font_size + $this->cell_padding_y * 2);
        $cell_height = $this->font_size;

        foreach ($csv as $line_index => $line) {
            $cell_offset_y = $offset_y - $this->font_size * ($line['cell_max_row_cnt'] - 1);
            $line_height = $cell_offset_y - $this->cell_padding_y * 2;
            foreach ($line['cell_list'] as $cell_index => $cell) {
                if($offset_y < $this->cell_padding_y * 2 + $this->font_size) {
                    $table_content[] = $this->render_page_number();
                    $this->add_obj(\implode("", $table_content), true);
                    $this->add_obj("<</Type/Page/Parent 2 0 R/Resources<</Font<</F1 6 0 R>>>>/MediaBox[0 0 {$this->page_width} {$this->page_height}]/Contents " . ($this->obj_cnt + 2) . " 0 R>>");
                    $this->handle_new_page_adding();
                    $this->page_number++;
                    $table_content = [];
                    $offset_y = $this->offset_y;
                    $line_height = $offset_y - $this->cell_padding_y * 2;
                    $offset_x = $this->offset_x;
                };

                $cell_text_full = '';
                $header_offset_x = $offset_x + $line_width_list[$cell_index] / 2 + $this->cell_padding_x;
                foreach (\explode("\n", $cell) as $cell_text_index => $cell_text) {
                    $header_offset_x -= $this->get_string_width($cell_text) / 2;
                    $offset_cell_text_x = $offset_x + $this->cell_padding_x;
                    $offset_cell_text_y = $offset_y - $this->cell_padding_y;
                    $td = !$cell_text_index ? "{$offset_cell_text_x} {$offset_cell_text_y}" : "0 -{$this->font_size}";
                    if (!$line_index) $td = "{$header_offset_x} {$offset_cell_text_y}";
                    $cell_text = $this->prepare_string($cell_text);
                    $cell_text_full .= <<<CELL_TEXT
                    {$td} Td
                    <{$cell_text}> Tj\n
                    CELL_TEXT;
                }
                $cell_height_current = $cell_height * $line['cell_max_row_cnt'] + $this->cell_padding_y * 2;
                $cell_width = $line_width_list[$cell_index] + $this->cell_padding_x * 2;

                $table_content[] =
                    <<<LINE
                {$offset_x} {$line_height} {$cell_width} {$cell_height_current} re S
                BT
                /F1 {$this->font_size} Tf
                {$cell_text_full}
                ET\n
                LINE;
                $offset_x += $line_width_list[$cell_index] + $this->cell_padding_x * 2;
            }
            $offset_y -= $this->font_size * $line['cell_max_row_cnt'] + $this->cell_padding_y * 2;
            $offset_x = $this->offset_x;
            unset($csv[$line_index]);
        }
        $table_content[] = $this->render_page_number();
        $this->add_obj(\implode("", $table_content), true);
        $this->set_xref_table();
        $this->set_trailer();
        $this->set_xref_start();
        $this->add_end();
        $this->generate_file();

        if(isset($file) && \is_resource($file)) {
            \fclose($file);
        }
    }

    private function render_page_number(): string {
        $text = (string) $this->page_number;
        $page_text = $this->prepare_string($text);
        $text_width = $this->get_string_width($text);
        $x = ($this->page_width - $text_width) / 2;
        $y = $this->offset_x / 2;
        $font_size = max(8, $this->font_size - 4);
        return <<<PAGE_NUM
        BT
        /F1 {$font_size} Tf
        {$x} {$y} Td
        <{$page_text}> Tj
        ET\n
        PAGE_NUM;
    }
}
