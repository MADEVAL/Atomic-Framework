## XLS ##

`Engine\Atomic\Files\XLS` reads legacy Excel `.xls` files and returns worksheet cell data as arrays.

### Basic usage

```php
use Engine\Atomic\Files\XLS;

$xls = new XLS(__DIR__ . '/report.xls');
$rows = $xls->parse();

foreach ($rows as $rowIndex => $row) {
    foreach ($row as $colIndex => $value) {
        echo $rowIndex . ':' . $colIndex . ' = ' . $value . PHP_EOL;
    }
}
```

### Output shape

`parse()` returns worksheet data indexed by zero-based row and column numbers:

```php
[
    0 => [0 => 'Name', 1 => 'Price'],
    1 => [0 => 'Keyboard', 1 => '$49.99'],
]
```

### Supported cell types

The parser handles the main BIFF/OLE workbook records used by old `.xls` files, including:

- strings from the shared string table
- plain labels
- numbers
- RK and MULRK compact numeric values
- booleans
- cached formula results
- merged-cells metadata
- format and XF records for display formatting

### Formatting behavior

When format metadata is available, the parser can convert:

- Excel dates to `Y-m-d` or `Y-m-d H:i:s`
- currency values to formatted strings
- percentages to formatted strings

### Limitations

- only legacy `.xls` files are supported
- `.xlsx` is not supported
- formulas are not evaluated live; cached workbook results are used
- locale-specific number separators are not normalized
- if BIFF parsing fails, the parser throws exceptions rather than guessing
