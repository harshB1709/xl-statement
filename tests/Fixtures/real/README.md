# Local real PDF fixtures

Drop bank-statement PDFs here for local tests. Everything in this folder is gitignored except this file.

Suggested names (tests look for these when present):

- `axis.pdf`
- `idfc.pdf`
- `locked-cbi.pdf` (password-protected Central Bank of India; local Papier unlock checks)
- `kotak.pdf`

Example in a Pest test:

```php
$path = base_path('tests/Fixtures/real/axis.pdf');

if (! is_file($path)) {
    test()->markTestSkipped('Add axis.pdf under tests/Fixtures/real/');
}

$table = app(ExtractStatementTable::class)->handle($path);
```

Prefer synthetic text under `tests/Fixtures/layouts/` for anything that must run in CI.
