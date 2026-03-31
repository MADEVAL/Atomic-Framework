<?php
declare(strict_types=1);
namespace Engine\Atomic\Files;

if (!defined( 'ATOMIC_START' ) ) exit;

class CSV extends \Prefab {

	public function parseCSV(string $filepath, string $delimiter=";", string $enclosure='"'): array|false {
		if (!is_file($filepath)) {
			user_error('File not found: '.$filepath);
			return false;
		}
		$data = \Base::instance()->read($filepath,true);
		if(!preg_match_all('/((?:.*?)'.$delimiter.'(?:'.$enclosure.'.*?'.
			$enclosure.'|['.$delimiter.'(?:\d|\.|\/)*\d])*\n)/s',$data."\n",$matches))
			user_error('no rows found');
		$out = array_map(function($val) use($delimiter,$enclosure) {
			return str_getcsv($val,$delimiter,$enclosure,'\\');
		},$matches[0]);
		return $out;
	}

	public function applyHeader(array $rows, ?array $headers=null): array {
		if (!$headers)
			$headers=array_shift($rows);
		return array_map(function($row) use($headers) {
			return array_combine(array_values($headers),array_values($row));
		},$rows);
	}

	public function dumpXLS(array $rows, array $headers): string {
		$numColumns = count($headers);
		$numRows = count($rows);
		foreach($headers as $key=>$val)
			if (is_numeric($key)) {
				$headers[$val]=ucfirst($val);
				unset($headers[$key]);
			}
		$xls = $this->xlsBOF();
		for ($i = 0; $i <= $numRows; $i++) {
			for ($c = 0; $c < $numColumns; $c++) {
				$ckey = key($headers);
				$val='';
				if ($i==0)
					$val = current($headers);
				elseif (isset($rows[$i-1][$ckey]))
					$val = $rows[$i-1][$ckey];
				if (is_array($val))
					$val = json_encode($val);
				elseif (is_string($val))
					$val = trim($val);
				$xls.= (is_int($val)
					|| (ctype_digit(strval($val)) && (strval($val)[0]!='0' && strlen($val)>1)))
					? $this->xlsWriteNumber($i,$c,$val)
					: $this->xlsWriteString($i,$c,mb_convert_encoding((string)$val, 'ISO-8859-1', 'UTF-8'));
				next($headers);
			}
			reset($headers);
		}
		$xls .= $this->xlsEOF();
		return $xls;
	}

	public function renderXLS(array $rows, array $headers, string $filename): never {
		$data = $this->dumpXLS($rows,$headers);
		header("Expires: 0");
		header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
		header('Content-Type: application/xls');
		header("Content-Disposition: attachment;filename=".$filename);
		header("Content-Transfer-Encoding: binary");
		echo $data;
		exit();
	}

	protected function xlsBOF(): string {
		return pack("ssssss", 0x809, 0x8, 0x0, 0x10, 0x0, 0x0);
	}

	protected function xlsEOF(): string {
		return pack("ss", 0x0A, 0x00);
	}

	protected function xlsWriteNumber(int $row, int $col, int|float $val): string {
		$out = pack("sssss", 0x203, 14, $row, $col, 0x0);
		$out.= pack("d", $val);
		return $out;
	}

	protected function xlsWriteString(int $row, int $col, string $val): string {
		$l = strlen($val);
		$out = pack("ssssss", 0x204, 8+$l, $row, $col, 0x0, $l);
		$out.= $val;
		return $out;
	}

	public function dumpCSV(array $rows, array $headers, string $delimiter=';', string $enclosure='"', bool $encloseAll=true): string {
		$numColumns = count($headers);
		$numRows = count($rows);
		foreach($headers as $key=>$val)
			if (is_numeric($key)) {
				$headers[$val]=ucfirst($val);
				unset($headers[$key]);
			}
		$out = array();
		for ($i = 0; $i <= $numRows; $i++) {
			$line = array();
			for ($c = 0; $c < $numColumns; $c++) {
				$ckey = key($headers);
				$field='';
				if ($i==0)
					$field = current($headers);
				elseif (isset($rows[$i-1][$ckey]))
					$field = $rows[$i-1][$ckey];
				if (is_array($field))
					$field = json_encode($field);
				elseif (is_string($field))
					$field = trim($field);
				else
					$field = (string)$field;
				if (empty($field) && $field !== 0)
					$line[] = '';
				elseif ($encloseAll || preg_match('/(?:'.preg_quote($delimiter, '/').'|'.
						preg_quote($enclosure, '/').'|\s)/', $field))
					$line[] = $enclosure.str_replace($enclosure, $enclosure.$enclosure, $field).$enclosure;
				else
					$line[] = $field;
				next($headers);
			}
			$out[] = implode($delimiter, $line);
			reset($headers);
		}
		return implode("\n",$out);
	}

	public function renderCSV(array $rows, array $headers, string $filename, string $delimiter=';', string $enclosure='"', bool $encloseAll=true, string $encoding='UTF-8'): never {
		$data = $this->dumpCSV($rows, $headers, $delimiter, $enclosure, $encloseAll);
		header("Expires: 0");
		header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
		header('Content-Type: text/csv;charset=UTF-16LE');
		header("Content-Disposition: attachment;filename=".$filename);
		header("Content-Transfer-Encoding: binary");
		echo "\xFF"."\xFE".($encoding !== 'UTF-8' ? mb_convert_encoding($data, $encoding, 'UTF-8') : $data);
		exit();
	}

}
