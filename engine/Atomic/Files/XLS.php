<?php
declare(strict_types=1);
namespace Engine\Atomic\Files;

if (!defined( 'ATOMIC_START' ) ) exit;

// Known limitations:
// - Number separator (locale-dependent) is not handled
// - Basic formula evaluation (SUM, etc.) is not supported — formulas return cached values only
class XLS {
    private const OLE2_HEADER = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    private const FAT_END_MARK = 0xFFFFFFFE;

    private const BLANK = 0x201;      // Blank cell (BLANK)
    private const NUMBER = 0x203;     // Number (NUMBER)
    private const LABEL = 0x204;      // Text label (LABEL)
    private const BOOLERR = 0x205;    // Boolean value or error (BOOLERR)
    private const STRING = 0x207;     // String (STRING)
    private const ROW = 0x208;        // Row (ROW)
    private const RK = 0x27E;         // Mixed type (RK - integer or float)
    private const LABELSST = 0xFD;    // String from shared string table (LABELSST)
    private const MULRK = 0x00BD;     // Multiple RK (compressed numbers)
    // Additional record types
    private const FORMULA = 0x06;     // Formula (FORMULA)
    // Record types for structural data and metadata
    private const EXTENDED_FORMAT = 0x20A;  // Extended cell format
    private const SST = 0x00FC;             // Shared String Table (SST)
    private const SST_RECORD = 0x00FC;      // Shared String Table Record
    private const BOF = 0x0809;             // Beginning of File (BOF)
    private const EOF = 0x000A;             // End of File (EOF)
    private const BOUNDSHEET = 0x0085;      // Workbook sheet (Bound Sheet)
    // Formatting and styles
    private const FORMAT = 0x041E;          // Cell format (CELL FORMAT)
    private const STYLE = 0x041F;           // Cell style (CELL STYLE)
    private const CELL_XF = 0x00E0;         // Cell with extended formatting (CELL XF)
    // References and external data
    private const EXTERNAL_REFERENCE = 0x2A; // External reference
    // Ranges, merges, continuations
    private const MERGED_CELLS = 0x00E5;    // Merged cells
    private const COLINFO = 0x007D;         // Column information
    private const CONTINUE = 0x003C;        // Continuation of long record (CONTINUE)
    private const ERROR = 0x00F0;           // Cell error (ERROR)

    private const VALID_BOF = [
        0x0809, // BIFF8 / BIFF5
        0x0409, // BIFF4
        0x0309, // BIFF3
        0x0209, // BIFF2
    ];

    // Standard Excel date format indices
    private const DATE_FORMAT_INDICES = [
        14, 15, 16, 17, 18, 19, 20, 21, 22, // Standard date/time formats
        27, 28, 29, 30, 31, 32, 33, 34, 35, 36, // Additional date formats
        45, 46, 47, // Time formats
        50, 51, 52, 53, 54, 55, 56, 57, 58, // CJK date formats
        164, 165, 166, 167, 168, 169, 170, 171, 172, 173, 174, 175, 176, 177, 178, 179, 180, // Custom date range
    ];

    private $file;

    private bool $mini = false;
    private string $ole_header;
    private int $sector_size;
    private int $dir_first_sector;
    private int $fat_sectors_count;
    private int $mini_stream_cutoff_size;
    private int $mini_fat_first_sector;
    private int $mini_fat_sector_count;
    private array $fat = [];
    private array $dir = [];
    private array $workbook = [];
    private array $strings = [];
    private array $worksheet_offsets = [];
    private array $worksheet_data = [];
    private array $formats = [];
    private array $xfs = [];
    private int $xf_count = 0;

    private int $current_sheet_depth = 0;
    private array $merged_cells = [];

    private string $pending_sst_data = '';
    private int $last_record_type = 0;
    private bool $expecting_continue = false;
    private int $sst_pos = 0;
    private int $sst_current_string = 0;
    private int $sst_unique_count = 0;

    public function __construct(string $filename) {
        $handle = @fopen($filename, 'rb');
        if ($handle === false) {
            throw new \Exception("Cannot read file: $filename");
        }

        $this->file = $this->ensure_seekable($handle);

        $initial = fread($this->file, 8);
        fseek($this->file, 0);

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (empty($ext) || $ext === '') {
            if ($initial === self::OLE2_HEADER) {
                $this->mini = false;
            } else {
                throw new \Exception("Unsupported file format: File has no extension and does not match XLS signature");
            }
        } elseif ($ext === 'xls') {
            $this->mini = false;
        } else {
            throw new \Exception("Unsupported file format: $ext");
        }
    }

    public function parse(): array {
        $this->parse_header();
        return $this->worksheet_data;
    }

    public function __destruct() {
        if ($this->file) {
            fclose($this->file);
        }
    }

    private function ensure_seekable($stream)
    {
        if (!is_resource($stream)) {
            return $stream;
        }

        $meta = stream_get_meta_data($stream);
        $seekable = $meta['seekable'] ?? false;

        if ($seekable) {
            return $stream;
        }

        $temp = tmpfile();
        if ($temp === false) {
            throw new \Exception('Failed to create temporary file for seekable stream');
        }

        stream_copy_to_stream($stream, $temp);
        @fclose($stream);
        
        rewind($temp);
        
        return $temp;
    }

    private function parse_header() {
        $header = fread($this->file, 8);
        if ($header === self::OLE2_HEADER) {
            fseek($this->file, 0);
            $this->parse_ole();
            return;
        }

        fseek($this->file, 0);
        $bof = fread($this->file, 2);
        $record_type = unpack('v', $bof)[1];

        if(!in_array($record_type, self::VALID_BOF)) {
            throw new \Exception('Expected one of ' . implode(', ', self::VALID_BOF) . ', got: ' . dechex($record_type));
        }

        throw new \Exception('Placeholder for BIFF parsing');
    }

    private function parse_ole() {
        $this->parse_headers();

        $this->fat = $this->parse_fat();
        $this->dir = $this->parse_dir();
        $this->workbook = $this->find_workbook();
        $workbook_stream = $this->parse_workbook();
        $this->parse_workbook_data($workbook_stream);
    }

    private function parse_headers() {
        $this->ole_header = fread($this->file, 512);
        $this->sector_size = (int)pow(2, unpack('v', substr($this->ole_header, 30, 2))[1]);
        $this->dir_first_sector = unpack('V', substr($this->ole_header, 48, 4))[1];
        $this->fat_sectors_count = unpack('V', substr($this->ole_header, 44, 4))[1];
        $this->mini_stream_cutoff_size = unpack('V', substr($this->ole_header, 56, 4))[1];
        $this->mini_fat_first_sector = unpack('V', substr($this->ole_header, 60, 4))[1];
        $this->mini_fat_sector_count = unpack('V', substr($this->ole_header, 64, 4))[1];
    }

    private function safe_unpack_V(string $data, int $offset): ?int
    {
        if (strlen($data) < $offset + 4) {
            return null;
        }
        $chunk = substr($data, $offset, 4);
        if (strlen($chunk) < 4) {
            return null;
        }
        $result = unpack('V', $chunk);
        return $result ? $result[1] : null;
    }

    private function parse_fat(): array {
        $fat = [];
        
        $pos = 76; 
        for ($i = 0; $i < min($this->fat_sectors_count, 109); $i++) {
            $fat_sector = $this->safe_unpack_V($this->ole_header, $pos);
            if ($fat_sector === null) break;
            if ($fat_sector != self::FAT_END_MARK && $fat_sector != 0xFFFFFFFF) {
                $this->load_fat_sector($fat, $fat_sector);
            }
            $pos += 4;
        }

        $num_extension_blocks = $this->safe_unpack_V($this->ole_header, 72) ?? 0;
        $extension_block_start = $this->safe_unpack_V($this->ole_header, 68) ?? self::FAT_END_MARK;

        if ($num_extension_blocks > 0 && $extension_block_start != self::FAT_END_MARK) {
            $current_block = $extension_block_start;
            
            for ($i = 0; $i < $num_extension_blocks; $i++) {
                if ($current_block == self::FAT_END_MARK) break;

                fseek($this->file, 512 + ($current_block * $this->sector_size));
                $difat_data = fread($this->file, $this->sector_size);
                
                if ($difat_data === false || strlen($difat_data) < 4) {
                    break;
                }
                
                $limit = intdiv($this->sector_size, 4) - 1; 
                $data_len = strlen($difat_data);
                
                for ($j = 0; $j < $limit; $j++) {
                    $offset = $j * 4;
                    if ($offset + 4 > $data_len) break;
                    
                    $fat_sector = $this->safe_unpack_V($difat_data, $offset);
                    if ($fat_sector === null || $fat_sector == self::FAT_END_MARK || $fat_sector == 0xFFFFFFFF) break; 
                    $this->load_fat_sector($fat, $fat_sector);
                }
                
                $next_offset = $limit * 4;
                $current_block = $this->safe_unpack_V($difat_data, $next_offset) ?? self::FAT_END_MARK;
            }
        }
        return $fat;
    }

    private function load_fat_sector(&$fat, $sector_index) {
        fseek($this->file, 512 + ($sector_index * $this->sector_size));
        $data = fread($this->file, $this->sector_size);
        $ints = array_values(unpack('V*', $data));
        foreach ($ints as $int) {
            $fat[] = $int;
        }
    }

    private function parse_dir() {
        $current_sector = $this->dir_first_sector;
        $directory_entries = [];
        $max_loops = 50000;

        while ($current_sector != self::FAT_END_MARK) {
            if ($max_loops-- <= 0) throw new \Exception("Infinite loop detected in Directory chain");
            
            fseek($this->file, 512 + ($current_sector * $this->sector_size));
            $dir_data = fread($this->file, $this->sector_size);

            for ($i = 0; $i < intdiv($this->sector_size, 128); $i++) {
                $entry_data = substr($dir_data, $i * 128, 128);
                $name_len = unpack('v', substr($entry_data, 64, 2))[1];

                if ($name_len > 0) {
                    $name_raw = substr($entry_data, 0, min($name_len - 2, 64));
                    $name = mb_convert_encoding($name_raw, 'UTF-8', 'UTF-16LE');

                    $type = ord($entry_data[66]);
                    if ($type === 0) continue; 

                    $start_sector = unpack('V', substr($entry_data, 116, 4))[1];
                    $size = unpack('V', substr($entry_data, 120, 4))[1];

                    $directory_entries[] = [
                        'name' => $name,
                        'type' => $type,
                        'start_sector' => $start_sector,
                        'size' => $size
                    ];
                }
            }

            $current_sector = $this->fat[$current_sector] ?? self::FAT_END_MARK;
        }
        return $directory_entries;
    }

    private function find_workbook() {
        $workbook_entry = null;

        foreach ($this->dir as $entry) {
            if ($entry['name'] === 'Workbook' || $entry['name'] === 'Book') {
                $workbook_entry = $entry;
                break;
            } 
        }

        if(!$workbook_entry) {
            throw new \Exception("Workbook entry not found");
        }

        if($workbook_entry['size'] < $this->mini_stream_cutoff_size && 
           $this->mini_fat_first_sector != self::FAT_END_MARK) {
            $this->mini = true;
        }

        return $workbook_entry;
    }

    private function parse_workbook()
    {
        $temp_file = tmpfile();
        $remaining_size = $this->workbook['size'];
        
        $max_loops = 50000; 

        if ($this->mini) {
            $root_entry = null;
            foreach ($this->dir as $entry) {
                if ($entry['name'] === 'Root Entry') {
                    $root_entry = $entry;
                    break;
                }
            }
            if (!$root_entry) throw new \Exception('Root Entry not found');

            $mini_stream_size = $root_entry['size'];
            $mini_stream_sector = $root_entry['start_sector'];
            $mini_stream_data = '';
            $cur = $mini_stream_sector;
            $mini_loops = $max_loops;
            while ($cur != self::FAT_END_MARK && strlen($mini_stream_data) < $mini_stream_size) {
                if ($mini_loops-- <= 0) throw new \Exception("Infinite loop detected in MiniStream chain");
                fseek($this->file, 512 + ($cur * $this->sector_size));
                $mini_stream_data .= fread($this->file, $this->sector_size);
                $cur = isset($this->fat[$cur]) ? $this->fat[$cur] : self::FAT_END_MARK;
            }
            $mini_stream_data = substr($mini_stream_data, 0, $mini_stream_size);

            $mini_fat = [];
            $cur = $this->mini_fat_first_sector;
            $mini_fat_bytes = $this->mini_fat_sector_count * $this->sector_size;
            $read_bytes = 0;
            $mini_fat_loops = $max_loops;
            while ($cur != self::FAT_END_MARK && $read_bytes < $mini_fat_bytes) {
                if ($mini_fat_loops-- <= 0) throw new \Exception("Infinite loop detected in MiniFAT chain");
                fseek($this->file, 512 + ($cur * $this->sector_size));
                $data = fread($this->file, $this->sector_size);
                for ($i = 0; $i < $this->sector_size; $i += 4) {
                    $mini_fat[] = unpack('V', substr($data, $i, 4))[1];
                }
                $read_bytes += $this->sector_size;
                $cur = isset($this->fat[$cur]) ? $this->fat[$cur] : self::FAT_END_MARK;
            }

            $mini_sector_size = 64;
            $mini_sector = $this->workbook['start_sector'];
            $read_bytes = 0;
            $workbook_loops = $max_loops;
            while ($mini_sector != self::FAT_END_MARK && $read_bytes < $remaining_size) {
                if ($workbook_loops-- <= 0) throw new \Exception("Infinite loop detected in Workbook mini-sector chain");
                $offset = $mini_sector * $mini_sector_size;
                $to_read = min($mini_sector_size, $remaining_size - $read_bytes);
                $chunk = substr($mini_stream_data, $offset, $to_read);
                fwrite($temp_file, $chunk);
                $read_bytes += $to_read;
                $mini_sector = isset($mini_fat[$mini_sector]) ? $mini_fat[$mini_sector] : self::FAT_END_MARK;
            }
            if ($read_bytes < $remaining_size) {
                throw new \Exception("MiniFAT Workbook extraction failed, remaining size: " . ($remaining_size - $read_bytes));
            }
        } else {
            $current_sector = $this->workbook['start_sector'];
            while ($current_sector != self::FAT_END_MARK && $remaining_size > 0) {
                if ($max_loops-- <= 0) throw new \Exception("Infinite loop detected in Workbook chain");

                $offset = 512 + ($current_sector * $this->sector_size);
                fseek($this->file, $offset);
                
                $chunk_size = min($this->sector_size, $remaining_size);
                $sector_data = fread($this->file, $chunk_size);
                
                fwrite($temp_file, $sector_data);
                
                $remaining_size -= strlen($sector_data);
                $current_sector = isset($this->fat[$current_sector]) ? $this->fat[$current_sector] : self::FAT_END_MARK;
            }
            
            if ($remaining_size > 0) {
                throw new \Exception("Workbook data extraction failed, remaining size: {$remaining_size} bytes.");
            }
        }
        
        $temp_file = $this->ensure_seekable($temp_file);
        rewind($temp_file);
        return $temp_file;
    }

    private function parse_workbook_data($temp_file) {
        while (!feof($temp_file)) {
            $header = fread($temp_file, 4);
            $type = $length = $data = null;

            if (strlen($header) < 4) {
                $type = self::EOF;
                $length = 0;
                $data = '';
            } else {
                $type = unpack('v', substr($header, 0, 2))[1];
                $length = unpack('v', substr($header, 2, 2))[1];
                $data = $length > 0 ? fread($temp_file, $length) : '';
            }

            switch ($type) {
                case self::BOF:
                    $this->current_sheet_depth++;
                    break;
                case self::EOF:
                    $this->current_sheet_depth--;
                    break;
                case self::FORMULA:
                    $this->parse_formula($type, $data, $length);
                    $this->last_record_type = $type;
                    break;
                case self::MERGED_CELLS:
                    $this->parse_merged_cells($data);
                    $this->last_record_type = $type;
                    break;
                case self::CONTINUE:
                    $this->handle_continue($data);
                    break;
                case self::CELL_XF:
                    $this->parse_xf($data);
                    $this->last_record_type = $type;
                    break;
                case self::FORMAT:
                    $this->parse_format($data);
                    $this->last_record_type = $type;
                    break;
                case self::SST:
                    $this->parse_sst($data);
                    $this->last_record_type = $type;
                    break;
                case self::BOUNDSHEET:
                    $this->parse_bound_sheet($data);
                    $this->last_record_type = $type;
                    break;
                case self::MULRK:
                    $this->parse_mulrk($data, $length);
                    $this->last_record_type = $type;
                    break;
                case self::BLANK:
                case self::NUMBER:
                case self::LABEL:
                case self::BOOLERR:
                case self::RK:
                case self::LABELSST:
                    $this->parse_cell($type, $data, $length);
                    $this->last_record_type = $type;
                    break;
                default:
                    $this->last_record_type = $type;
                    break;
            }
        }

        fclose($temp_file);
    }

    private function parse_sst($data) {
        if ($this->last_record_type === self::SST && $this->expecting_continue) {
            $this->pending_sst_data .= $data;
            $this->expecting_continue = false;

            $this->parse_complete_sst();
            return;
        }
        
        $this->pending_sst_data = $data;
        $this->expecting_continue = false;
        $this->sst_pos = 0;
        $this->sst_current_string = 0;
        $this->sst_unique_count = 0;
        
        $this->parse_complete_sst();
    }
    
    private function parse_complete_sst() {
        $data = $this->pending_sst_data;
        
        if ($this->sst_unique_count === 0) {
            // Skip total strings count
            $this->sst_pos = 4;
            
            if ($this->sst_pos + 4 > strlen($data)) {
                $this->expecting_continue = true;
                return;
            }
            $this->sst_unique_count = unpack('V', substr($data, $this->sst_pos, 4))[1]; 
            $this->sst_pos += 4;
        }
        
        for ($i = $this->sst_current_string; $i < $this->sst_unique_count; $i++) {
            
            $start_pos = $this->sst_pos;
            
            // Read string length
            if ($this->sst_pos + 2 > strlen($data)) {
                $this->expecting_continue = true;
                $this->sst_current_string = $i;
                return;
            }
            $len = unpack('v', substr($data, $this->sst_pos, 2))[1];
            $temp_pos = $this->sst_pos + 2;            // Read option byte
            if ($temp_pos + 1 > strlen($data)) {
                $this->expecting_continue = true;
                $this->sst_current_string = $i;
                return;
            }
            $option = ord($data[$temp_pos]); 
            $temp_pos += 1;
            
            // Check for Rich-Text formatting
            $rich_text_runs = 0;
            if ($option & 0x08) {
                // Rich-Text flag is set, read formatting run count
                if ($temp_pos + 2 > strlen($data)) {
                    $this->expecting_continue = true;
                    $this->sst_current_string = $i;
                    return;
                }
                $rich_text_runs = unpack('v', substr($data, $temp_pos, 2))[1];
                $temp_pos += 2;
            }
            
            // Check for Extended String
            $extended_size = 0;
            if ($option & 0x04) {
                // Extended String flag is set, read extended data size
                if ($temp_pos + 4 > strlen($data)) {
                    $this->expecting_continue = true;
                    $this->sst_current_string = $i;
                    return;
                }
                $extended_size = unpack('V', substr($data, $temp_pos, 4))[1];
                $temp_pos += 4;
            }
            
            // Calculate required bytes for string
            $string_bytes = ($option & 0x01) ? $len * 2 : $len;
            
            // Check if we have enough data for the string
            if ($temp_pos + $string_bytes > strlen($data)) {
                $this->expecting_continue = true;
                $this->sst_current_string = $i;
                return;
            }
            
            // Extract string
            if (($option & 0x01) == 0) {
                $str = substr($data, $temp_pos, $len); 
                $temp_pos += $len;
            } else {
                $str = mb_convert_encoding(substr($data, $temp_pos, $len * 2), 'UTF-8', 'UTF-16LE');
                $temp_pos += $len * 2;
            }
            
            // Skip Rich-Text formatting runs (4 bytes per run)
            if ($rich_text_runs > 0) {
                $rich_text_data_size = $rich_text_runs * 4;
                if ($temp_pos + $rich_text_data_size > strlen($data)) {
                    $this->expecting_continue = true;
                    $this->sst_current_string = $i;
                    return;
                }
                $temp_pos += $rich_text_data_size;
            }
            
            // Skip Extended String data
            if ($extended_size > 0) {
                if ($temp_pos + $extended_size > strlen($data)) {
                    $this->expecting_continue = true;
                    $this->sst_current_string = $i;
                    return;
                }
                $temp_pos += $extended_size;
            }
            
            // Only update the position if we successfully parsed the entire string
            $this->sst_pos = $temp_pos;
            
            $this->strings[] = $str;
            $this->sst_current_string = $i + 1;
        }
        
        // If we got here, SST parsing is complete
        $this->pending_sst_data = '';
        $this->expecting_continue = false;
        $this->sst_pos = 0;
        $this->sst_current_string = 0;
        $this->sst_unique_count = 0;
    }

    private function parse_formula($type, $data, $length) {
        if (strlen($data) < 14) return;

        $row = unpack('v', substr($data, 0, 2))[1];
        $col = unpack('v', substr($data, 2, 2))[1];
        $xf  = unpack('v', substr($data, 4, 2))[1];

        $result_type = ord($data[6]);

        if ($result_type === 0x01) {
            $bool_value = ord($data[8]);
            $this->worksheet_data[$row][$col] = (bool) $bool_value;
            return;
        }

        $this->worksheet_data[$row][$col] = '#FORMULA';
    }

    private function parse_bound_sheet($data) {
        if (strlen($data) >= 6) {
            $worksheet_offset = unpack('V', substr($data, 0, 4))[1];
            $sheet_type = ord($data[4]);
            $sheet_state = ord($data[5]);
            
            $name_len = ord($data[6]);
            $sheet_name = substr($data, 8, $name_len);
            
            $this->worksheet_offsets[] = $worksheet_offset;
        }
    }

    private function parse_cell($type, $data, $length)
    {
        if (strlen($data) < 4) return;

        $row = unpack('v', substr($data, 0, 2))[1];
        $col = unpack('v', substr($data, 2, 2))[1];
        $xf = unpack('v', substr($data, 4, 2))[1];

        $format_index = isset($this->xfs[$xf]) ? $this->xfs[$xf] : null;
        $format_string = isset($this->formats[$format_index]) ? $this->formats[$format_index] : null;
        
        if (!$format_string && $format_index !== null) {
            $format_string = $this->get_builtin_format($format_index);
        }

        $value = '';
        $data_len = strlen($data);

        switch ($type) {
            case self::NUMBER:
                if ($data_len < 14) break;
                $value = unpack('d', substr($data, 6, 8))[1];
                break;

            case self::LABEL:
                if ($data_len < 8) break;
                $length = unpack('v', substr($data, 6, 2))[1];
                if ($data_len < 8 + $length) break;
                $value = substr($data, 8, $length);
                break;

            case self::LABELSST:
                if ($data_len < 10) break;
                $sst_index = unpack('V', substr($data, 6, 4))[1];
                $value = isset($this->strings[$sst_index]) ? $this->strings[$sst_index] : '';
                break;

            case self::RK:
                if ($data_len < 10) break;
                $rk = unpack('V', substr($data, 6, 4))[1];
                $value = $this->parse_rk($rk);
                break;

            case self::BOOLERR:
                if ($data_len < 8) break;
                $boolVal = ord($data[6]);
                $isBool = ord($data[7]) == 0;
                if ($isBool) {
                    $value = (bool) $boolVal;
                } else {
                    $value = '#ERROR!';
                }
                break;

            case self::BLANK:
                $value = '';
                break;
        }

        if ($this->is_date_format($format_string, $format_index)) {
            if(is_numeric($value)) {
                $value = $this->convert_excel_date($value);
            }
        } elseif (is_string($format_string) && $this->is_currency_format($format_string)) {
            if (is_numeric($value)) {
                $value = $this->format_currency($value, $format_string);
            }
        } elseif (is_string($format_string) && $this->is_percentage_format($format_string)) {
            if (is_numeric($value)) {
                $value = $this->format_percentage($value, $format_string);
            }
        }

        $this->worksheet_data[$row][$col] = $value;
    }

    private function parse_mulrk($data, $length)
    {
        if (strlen($data) < 6) return;
        
        $row = unpack('v', substr($data, 0, 2))[1];
        $first_col = unpack('v', substr($data, 2, 2))[1];
        
        $last_col = unpack('v', substr($data, $length - 2, 2))[1];
        
        $num_cols = $last_col - $first_col + 1;
        
        $rk_data = substr($data, 4, $length - 6);
        
        for ($i = 0; $i < $num_cols; $i++) {
            $offset = $i * 6;
            if ($offset + 6 <= strlen($rk_data)) {
                $col = $first_col + $i;
                
                $xf = unpack('v', substr($rk_data, $offset, 2))[1];
                $rk_bytes = substr($rk_data, $offset + 2, 4);
                $rk = unpack('V', $rk_bytes)[1];
                
                $value = $this->parse_rk($rk);
                
                $format_index = isset($this->xfs[$xf]) ? $this->xfs[$xf] : null;
                $format_string = isset($this->formats[$format_index]) ? $this->formats[$format_index] : null;
                
                if (!$format_string && $format_index !== null) {
                    $format_string = $this->get_builtin_format($format_index);
                }

                if ($this->is_date_format($format_string, $format_index)) {
                    if (is_numeric($value)) {
                        $value = $this->convert_excel_date($value);
                    }
                } elseif (is_string($format_string) && $this->is_currency_format($format_string)) {
                    if (is_numeric($value)) {
                        $value = $this->format_currency($value, $format_string);
                    }
                } elseif (is_string($format_string) && $this->is_percentage_format($format_string)) {
                    if (is_numeric($value)) {
                        $value = $this->format_percentage($value, $format_string);
                    }
                }

                $this->worksheet_data[$row][$col] = $value;
            }
        }
    }

    private function parse_rk($rk)
    {
        $is_multiplied = ($rk & 0x01) !== 0;
        $is_integer = ($rk & 0x02) !== 0;

        if ($is_integer) {
            $value = $rk >> 2;
            if ($value & 0x20000000) { 
                $value -= 0x40000000; 
            }
        } else {
            $sign = ($rk & 0x80000000) >> 31;
            $exp = ($rk & 0x7ff00000) >> 20;
            $mantissa = (0x100000 | ($rk & 0x000ffffc));
            $value = $mantissa / pow(2, 20 - ($exp - 1023));
            if ($sign) $value = -1 * $value;
        }

        if ($is_multiplied) $value /= 100;
        return $value;
    }

    private function parse_xf($data) {
        if (strlen($data) < 6) return;
        $format_index = unpack('v', substr($data, 2, 2))[1];
        $this->xfs[$this->xf_count] = $format_index;
        $this->xf_count++;
    }

    private function parse_format($data) {
        if (strlen($data) < 5) return;

        $pos = 0;
        $fmt_idx = unpack('v', substr($data, $pos, 2))[1];
        $pos += 2;

        $fmtlen = unpack('v', substr($data, $pos, 2))[1];
        $pos += 2;

        $option = ord($data[$pos]);
        $pos += 1;

        if (($option & 0x01) === 0) { // ANSI single-byte
            $fmtstr = substr($data, $pos, $fmtlen);
        } else { // UTF-16LE
            $fmtstr = mb_convert_encoding(substr($data, $pos, $fmtlen * 2), 'UTF-8', 'UTF-16LE');
        }

        $this->formats[$fmt_idx] = $fmtstr;
    }

    private function is_date_format($format_string, $format_index = null) {
        if ($format_index !== null && in_array($format_index, self::DATE_FORMAT_INDICES, true)) {
            return true;
        }

        if (!$format_string || !is_string($format_string)) {
            return false;
        }

        $excluded = ['$', '€', '£', '¥', '₹', 'E+', 'E-', '#,##0', 'General'];
        foreach ($excluded as $ex) {
            if (stripos($format_string, $ex) !== false) {
                return false;
            }
        }

        $lower = strtolower($format_string);
        
        $has_year = (strpos($lower, 'y') !== false);
        $has_month = (strpos($lower, 'm') !== false);
        $has_day = (strpos($lower, 'd') !== false);
        $has_hour = (strpos($lower, 'h') !== false);
        $has_second = (strpos($lower, 's') !== false);
        $has_ampm = (strpos($lower, 'am') !== false || strpos($lower, 'pm') !== false);
        
        if (($has_year && $has_month) || ($has_month && $has_day)) {
            return true;
        }
        
        if ($has_hour && ($has_second || $has_ampm)) {
            return true;
        }
        
        if ($has_hour && strpos($lower, ':') !== false) {
            return true;
        }
        
        return false;
    }

    private function convert_excel_date(float|int $serial_number) {
        if (!is_numeric($serial_number) || $serial_number < 1) return $serial_number;

        $excel_epoch = new \DateTime('1900-01-01');
        
        if ($serial_number >= 60) {
            $serial_number -= 1;
        }
        
        $days = floor($serial_number) - 1;
        $time_fraction = $serial_number - floor($serial_number);
        
        $date = clone $excel_epoch;
        $date->add(new \DateInterval('P' . (int)$days . 'D'));
        
        if ($time_fraction > 0) {
            $seconds = round($time_fraction * 86400);
            $date->add(new \DateInterval('PT' . (int)$seconds . 'S'));
            return $date->format('Y-m-d H:i:s');
        }
        
        return $date->format('Y-m-d');
    }

    private function is_currency_format($format_string) {
        if (!$format_string) return false;
        
        $currency_symbols = ['$', '€', '£', '¥', '₹', '₽', '₩', '₪'];
        foreach ($currency_symbols as $symbol) {
            if (strpos($format_string, $symbol) !== false) {
                return true;
            }
        }
        
        if (strpos($format_string, '[$') !== false) {
            return true;
        }
        
        if (stripos($format_string, 'accounting') !== false) {
            return true;
        }
        
        return false;
    }

    private function format_currency($value, $format_string) {
        if (!is_numeric($value)) {
            return $value;
        }

        $currency_symbol = '$';

        if (preg_match('/\[([$][^]]+)\]/', $format_string, $matches)) {
            $format_string = $matches[1];
        } 

        $symbols = ['€', '£', '¥', '₹', '₽', '₩', '₪'];
        foreach ($symbols as $symbol) {
            if (strpos($format_string, $symbol) !== false) {
                $currency_symbol = $symbol;
                break;
            }
        }

        $formatted_value = number_format($value, 2);

        return $currency_symbol . $formatted_value;
    }

    private function is_percentage_format($format_string) {
        if (!$format_string) return false;
        
        // Simple check: contains % sign
        return strpos($format_string, '%') !== false;
    }

    private function format_percentage($value, $format_string) {
        if (!is_numeric($value)) {
            return $value;
        }
        
        $percentage_value = $value;
        
        if ($value >= 0 && $value <= 1) {
            $percentage_value = $value * 100;
        }
        
        $decimal_places = 0;
        if (strpos($format_string, '.') !== false && strpos($format_string, '%') !== false) {
            if (preg_match('/\.(\d+)%/', $format_string, $matches)) {
                $decimal_places = strlen($matches[1]);
            } elseif (preg_match('/\.(0+)/', $format_string, $matches)) {
                $decimal_places = strlen($matches[1]);
            } else {
                $decimal_places = 2;
            }
        }
        
        return number_format($percentage_value, $decimal_places) . '%';
    }

    public function get_builtin_format(int $format_index): ?string {
        // Built-in Excel number formats (ECMA-376 §18.8.30)
        $builtin_formats = [
            // General
            0 => 'General',
            1 => '0',
            2 => '0.00',
            3 => '#,##0',
            4 => '#,##0.00',
            
            // Currency
            5 => '$#,##0_);($#,##0)',
            6 => '$#,##0_);[Red]($#,##0)',
            7 => '$#,##0.00_);($#,##0.00)',
            8 => '$#,##0.00_);[Red]($#,##0.00)',
            
            // Percentage
            9 => '0%',
            10 => '0.00%',
            11 => '0.00E+00',
            12 => '# ?/?',
            13 => '# ??/??',
            
            // Date formats
            14 => 'm/d/yy',
            15 => 'd-mmm-yy',
            16 => 'd-mmm',
            17 => 'mmm-yy',
            18 => 'h:mm am/pm',
            19 => 'h:mm:ss am/pm',
            20 => 'h:mm',
            21 => 'h:mm:ss',
            22 => 'm/d/yy h:mm',
            
            // Currency formats
            37 => '#,##0_);(#,##0)',
            38 => '#,##0_);[Red](#,##0)',
            39 => '#,##0.00_);(#,##0.00)',
            40 => '#,##0.00_);[Red](#,##0.00)',
            41 => '_("$"* #,##0_);_("$"* \\(#,##0\\);_("$"* "-"_);_(@_)',
            42 => '_(* #,##0_);_(* \\(#,##0\\);_(* "-"_);_(@_)',
            43 => '_("$"* #,##0.00_);_("$"* \\(#,##0.00\\);_("$"* "-"??_);_(@_)',
            44 => '_(* #,##0.00_);_(* \\(#,##0.00\\);_(* "-"??_);_(@_)',
            
            // Time formats
            45 => 'mm:ss',
            46 => '[h]:mm:ss',
            47 => 'mm:ss.0',
            48 => '##0.0E+0',
            49 => '@',
        ];
        
        return isset($builtin_formats[$format_index]) ? $builtin_formats[$format_index] : null;
    }

    public function get_builtin_date_format(int $format_index): ?string {
        $format_string = $this->get_builtin_format($format_index);
        
        if ($format_string && $this->is_date_format($format_string, $format_index)) {
            return $format_string;
        }
        
        return null;
    }

    private function parse_merged_cells($data) {
        if (strlen($data) < 2) return;
        $count = unpack('v', substr($data, 0, 2))[1];
        $offset = 2;
        for ($i = 0; $i < $count; $i++) {
            if ($offset + 8 > strlen($data)) break;
            $first_row = unpack('v', substr($data, $offset, 2))[1];
            $last_row = unpack('v', substr($data, $offset + 2, 2))[1];
            $first_col = unpack('v', substr($data, $offset + 4, 2))[1];
            $last_col = unpack('v', substr($data, $offset + 6, 2))[1];
            $this->merged_cells[] = [$first_row, $last_row, $first_col, $last_col];
            $offset += 8;
        }
    }

    private function handle_continue($data) {
        switch($this->last_record_type) {
            case self::SST:
                $this->parse_sst($data);
                break;
        }
    }
}
