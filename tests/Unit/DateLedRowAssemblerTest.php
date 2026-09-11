<?php

use App\Services\Table\DateLedRowAssembler;

it('rejoins mid-token wraps and stops before legend pollution', function () {
    $pages = [<<<'TXT'
Tran Date Particulars Debit Credit Balance
14-05-2025 MOB/SELFFT/911010013455873/911010013455
873
25000.00 50000.00018
05-07-2025 SB:925010021868871:Int.Pd:01-07-2025 to 30-
09-2025
123.45 50123.45018
01-10-2025 SB:925010021868871:Int.Pd:01-10-2025 to 31-
Legends :
VMT-ICON-Visa Money Transfer through Internet Banking
AUTOSWEEP-Transfer to linked fixed deposit
10-10-2025 NEXT/TXN
50.00 50173.45018
TXT];

    $table = (new DateLedRowAssembler)->assemble($pages, 'axis.pdf', 'Axis Bank');

    expect($table->rows)->toHaveCount(3)
        ->and($table->rows[0][1])->toBe('MOB/SELFFT/911010013455873/911010013455873')
        ->and($table->rows[1][1])->toBe('SB:925010021868871:Int.Pd:01-07-2025 to 30-09-2025')
        ->and($table->rows[2][1])->toBe('NEXT/TXN')
        ->and($table->rows[1][1])->not->toContain('VMT-ICON')
        ->and($table->rows[1][1])->not->toContain('AUTOSWEEP');
});

it('assembles named-month date rows with indian amounts', function () {
    $pages = [<<<'TXT'
Opening Balance	Total Debit	Total Credit	Closing Balance
0.00	15,12,082.00	15,63,525.00	51,443.00
Transaction Date Value Date Particulars Debit Credit Balance
Opening Balance	0.00
08-Apr-2025 09-Apr-2025
BB/CHQ
DEP/000065/04-04-2025/
KURUP NITIN MOHAN/
HDF
000065	1,00,000.00 1,00,000.00
08-Apr-2025 09-Apr-2025
BB/CHQ DEP/038598/04-04-2025/NANASAHEB SURESH PAT/
038598	1,00,000.00 2,00,000.00
12-Apr-2025 12-Apr-2025
NEFT/HDFCH00181428429/PREETI ARVIND LALI/
1,00,000.00 3,00,000.00
13-Apr-2025 13-Apr-2025
Sweepout FD
10225449548 booked
1,00,000.00	2,00,000.00
REGISTERED OFFICE: IDFC FIRST BANK LIMITED
Page 1 of 10
TXT];

    $table = (new DateLedRowAssembler)->assemble($pages, 'idfc.pdf', 'IDFC FIRST Bank');

    expect($table->rows)->toHaveCount(4)
        ->and($table->rows[0][0])->toBe('08-Apr-2025')
        ->and($table->rows[0][1])->toContain('BB/CHQ')
        ->and($table->rows[0][3])->toBe('100000.00')
        ->and($table->rows[0][4])->toBe('100000.00')
        ->and($table->rows[3][1])->toContain('Sweepout FD')
        ->and($table->rows[3][2])->toBe('100000.00')
        ->and($table->rows[3][4])->toBe('200000.00');
});

it('parses plain axis-style amounts with glued balances', function () {
    $pages = [<<<'TXT'
Tran Date Particulars Debit Credit Balance
14-05-2025 Initial Funding
25000.00 25000.00018
22-05-2025 IMPS/P2A/514217137595/NEILANIL
50000.00 75000.00018
22-05-2025 MOB/SELFFT/911010013455873
42500.00 32500.00018
TXT];

    $table = (new DateLedRowAssembler)->assemble($pages, 'axis.pdf', 'Axis Bank');

    expect($table->rows)->toHaveCount(3)
        ->and($table->rows[0][3])->toBe('25000.00')
        ->and($table->rows[0][4])->toBe('25000.00')
        ->and($table->rows[1][3])->toBe('50000.00')
        ->and($table->rows[1][4])->toBe('75000.00')
        ->and($table->rows[2][2])->toBe('42500.00')
        ->and($table->rows[2][4])->toBe('32500.00');
});

it('unglues papier-style dates and classifies via balance deltas', function () {
    $pages = [<<<'TXT'
Transaction Date Value Date Description Debit Credit Balance
01/04/202501/04/2025INITIAL CREDIT
1,00,000.00 1,00,000.00 DR
01/04/202501/04/2025RECOVERY OF CHARGES
30,000.00 70,000.00 DR
01/04/202501/04/2025GST ON CHARGES 5,400.00 75,400.00 DR
02/04/202502/04/2025NEFT CREDIT FROM ACME
1,00,000.00 1,75,400.00 DR
TXT];

    $assembler = new DateLedRowAssembler;
    expect($assembler->unglueTokens('01/04/202501/04/2025RECOVERY'))
        ->toBe('01/04/2025 01/04/2025 RECOVERY');

    $table = $assembler->assemble($pages, 'cbi.pdf', 'Central Bank of India');

    expect($table->rows)->toHaveCount(4)
        ->and($table->rows[0][0])->toBe('01/04/2025')
        ->and($table->rows[0][3])->toBe('100000.00')
        ->and($table->rows[1][1])->toContain('RECOVERY')
        ->and($table->rows[1][2])->toBe('30000.00')
        ->and($table->rows[1][4])->toBe('70000.00')
        ->and($table->rows[2][3])->toBe('5400.00')
        ->and($table->rows[3][3])->toBe('100000.00');
});

it('detects glued headers without false-positiving on adjacent cells', function () {
    $assembler = new DateLedRowAssembler;

    expect($assembler->headersLookBroken(['Txn Date', 'Particulars', 'Amount', 'Dr/Cr', 'Balance']))
        ->toBeFalse()
        ->and($assembler->headersLookBroken(['DescriptionDebitCreditBalance']))
        ->toBeTrue()
        ->and($assembler->headersLookBroken(['DateParticulars', 'Debit', 'Credit']))
        ->toBeTrue()
        ->and($assembler->headersLookBroken(['Post', 'Date', 'Value', 'Date', 'Transaction', 'Description', 'Debit', 'Credit', 'Balance']))
        ->toBeTrue()
        ->and($assembler->headersLookBroken(['Post Date', 'Value Date', 'Transaction Description', 'Debit', 'Credit', 'Balance']))
        ->toBeFalse();
});

it('prefers date-led assembly when poppler splits cbi compound headers', function () {
    $assembler = new DateLedRowAssembler;

    expect($assembler->shouldUse(
        slicedRowCount: 390,
        dateLikeLineCount: 420,
        headerCells: ['Post', 'Date', 'Value', 'Date', 'Debit', 'Credit', 'Balance'],
    ))->toBeTrue()
        ->and($assembler->shouldUse(
            slicedRowCount: 390,
            dateLikeLineCount: 420,
            headerCells: ['Post Date', 'Value', 'Branch', 'Cheque', 'Debit', 'Credit', 'Balance'],
        ))->toBeTrue()
        ->and($assembler->slicedRowsLookChopped([
            ['01/04/2025 01', '/04/2025', '621', 'R P', 'ECOVERY', '30,000.00', '9', '0,46,057.06 DR'],
            ['01/04/2025 01', '/04/2025', '621', 'G', 'ST', '5,400.00', '9', '0,51,457.06 DR'],
            ['02/04/2025 02', '/04/2025', '621', 'N C', 'EFT', ',23,500.00', '8', '1,87,268.58 DR'],
        ]))->toBeTrue();
});

it('assembles kotak-style numbered rows with spaced month dates', function () {
    $pages = [<<<'TXT'
Savings Account Transactions
# Date Description Chq/Ref. No. Withdrawal (Dr.) Deposit (Cr.) Balance
- - Opening Balance - - - 1,34,919.64
1 09 Sep 2025UPI/SNEHAL RAJEEV M/561820055373/Chanda
1,000.00 1,35,919.64
2 10 Sep 2025UPI/AAFIYA HANAFI/525399798992/UPI
1,000.00 1,36,919.64
5 14 Sep 2025INSTALLMENT AMOUNT FOR RD 3149842777
4,000.00 1,33,060.64
07 Sep 2025 - 07 Sep 2026
Account Statement
TXT];

    $assembler = new DateLedRowAssembler;
    expect($assembler->unglueTokens('1 09 Sep 2025UPI/FOO'))
        ->toContain('09 Sep 2025 UPI');

    $table = $assembler->assemble($pages, 'kotak.pdf', 'Kotak Mahindra Bank');

    expect($table->rows)->toHaveCount(3)
        ->and($table->rows[0][0])->toBe('09 Sep 2025')
        ->and($table->rows[0][1])->toContain('UPI/SNEHAL')
        ->and($table->rows[0][3])->toBe('1000.00')
        ->and($table->rows[0][4])->toBe('135919.64')
        ->and($table->rows[1][3])->toBe('1000.00')
        ->and($table->rows[2][2])->toBe('4000.00')
        ->and($table->rows[2][4])->toBe('133060.64');
});
