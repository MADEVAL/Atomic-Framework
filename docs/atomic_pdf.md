## PDF ##

`Engine\Atomic\Files\PDF` generates simple PDF tables from raw row data, CSV files, or XLS files.

### Basic usage

```php
use Engine\Atomic\Files\PDF;

$pdf = new PDF(
    file_to: __DIR__ . '/report.pdf',
    font_name: 'dejavusans',
    font_size: 12
);

$pdf->raw2pdf('Sales Report', [
    ['Product', 'Qty', 'Price'],
    ['Keyboard', 10, '49.99'],
    ['Mouse', 25, '19.99'],
]);
```

### From CSV or XLS

```php
$pdf = new PDF(
    file_to: __DIR__ . '/catalog.pdf',
    file_from: __DIR__ . '/catalog.csv'
);

$pdf->file2pdf('Catalog');
```

Supported input extensions:

- `csv`
- `xls`

For `.xls` input the class internally uses `Engine\Atomic\Files\XLS`.

### Constructor options

```php
new PDF(
    file_to: '/path/to/output.pdf',
    file_from: '/path/to/input.csv',
    font_name: 'dejavusans',
    font_size: 14,
    page_width: 612,
    page_height: 792,
    cell_padding_x: 13.0,
    cell_padding_y: 7.0,
    offset_percentage_x: 7,
    offset_percentage_y: 5,
);
```

### Font requirements

The generator expects these font assets to exist in the configured font directory:

- `FONTS/<font>.php`
- `FONTS/<font>.ttf`

It also writes compressed font cache files into `FONTS_TEMP`.

### Behavior

- the first row is rendered like a table header
- long tables automatically continue onto new pages
- page numbers are printed at the bottom
- cell values are stringified before rendering
- only a limited number of columns fit on a page, based on page width and padding

### Limitations

- intended for table-like output, not general-purpose PDF layout
- only `csv` and legacy `xls` sources are supported
- boolean values are rendered as `1` and `0`
- invalid or unreadable font files throw exceptions
