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

it('classifies dash-separated debit and credit columns', function () {
    $pages = [<<<'TXT'
Date Transaction ID Particulars Debit Credit Balance
09-10-2025 PH510092196306987 CKYC_THANKS_REGISTRATION - 21.00 21.00
10-10-2025 PH510101317184930 PAYMENT MADE VIA UPI 145.00 - 876.00
11-10-2025 PH510111317184999 MONEY LOADED SUCCESSFULLY - 1000.00 1876.00
TXT];

    $table = (new DateLedRowAssembler)->assemble($pages, 'airtel.pdf', 'Airtel Payments Bank');

    expect($table->rows)->toHaveCount(3)
        ->and($table->rows[0][1])->toContain('CKYC_THANKS_REGISTRATION')
        ->and($table->rows[0][1])->not->toContain('21.00')
        ->and($table->rows[0][3])->toBe('21.00')
        ->and($table->rows[1][2])->toBe('145.00')
        ->and($table->rows[1][3])->toBe('')
        ->and($table->rows[2][3])->toBe('1000.00');
});

it('classifies newest-first debit and credit from the next balance', function () {
    $pages = [<<<'TXT'
Sr No Date Remarks Debit Credit Balance
1 28-03-2026 IO For 013253710000152 1586.00 ₹ 390,817.29
2 28-03-2026 IO For 013243710001026 1648.00 ₹ 389,231.29
3 22-11-2025 Int:2123.00 and TAX:0.00 2123.00 ₹ 166,935.29
4 22-11-2025 TDS For 13253710000970 213.00 ₹ 164,812.29
5 04-11-2025 SBInt.Pd 975.00 ₹ 165,025.29
TXT];

    $table = (new DateLedRowAssembler)->assemble($pages, 'boi.pdf', 'Bank of India');

    expect($table->rows)->toHaveCount(5)
        ->and($table->rows[0][3])->toBe('1586.00')
        ->and($table->rows[2][3])->toBe('2123.00')
        ->and($table->rows[3][2])->toBe('213.00')
        ->and($table->rows[3][3])->toBe('')
        ->and($table->rows[4][3])->toBe('975.00');
});

it('detects misaligned slices with dash placeholders or rupee-glued amounts', function () {
    $assembler = new DateLedRowAssembler;

    $dashRows = [];
    $rupeeRows = [];

    for ($i = 0; $i < 6; $i++) {
        $dashRows[] = ['09-10-2025', 'PH'.$i, 'UPI', '40.00             -', '711.00'];
        $rupeeRows[] = [(string) $i, '28-03-2026', 'IO For A', '', '1014.00                 ₹ 149,324.29'];
    }

    expect($assembler->slicedRowsLookMisaligned($dashRows))->toBeTrue()
        ->and($assembler->slicedRowsLookMisaligned($rupeeRows))->toBeTrue()
        ->and($assembler->slicedRowsLookMisaligned([
            ['09-10-2025', 'PH1', 'UPI', '40.00', '', '711.00'],
            ['10-10-2025', 'PH2', 'UPI', '50.00', '', '661.00'],
            ['11-10-2025', 'PH3', 'UPI', '', '100.00', '761.00'],
        ]))->toBeFalse();
});

it('detects balance fragments and glued year cuts as chopped slices', function () {
    $assembler = new DateLedRowAssembler;

    $rows = [];

    for ($i = 0; $i < 6; $i++) {
        $rows[] = ['08-04-2023 18:49:39 09', 'Apr 2023', 'UPI/DR', '13,000.00 023', '7,536', '.55'];
    }

    expect($assembler->slicedRowsLookChopped($rows))->toBeTrue()
        ->and($assembler->shouldUse(178, 337, ['Txn Date', 'Value Date', 'Description', 'Debit', 'Credit', 'Balance'], $rows))->toBeTrue();
});

it('prefers date-led for credit-card DATE & TIME amount headers', function () {
    $assembler = new DateLedRowAssembler;

    expect($assembler->headersLookLikeCreditCardAmount([
        'DATE', '&', 'TIME', 'TRANSACTION DESCRIPTION', 'AMOUNT', 'PI',
    ]))->toBeTrue()
        ->and($assembler->headersLookBroken(['DATE', '&', 'TIME', 'TRANSACTION DESCRIPTION', 'AMOUNT', 'PI']))->toBeTrue()
        ->and($assembler->shouldUse(25, 36, ['DATE', '&', 'TIME', 'TRANSACTION DESCRIPTION', 'AMOUNT', 'PI'], []))->toBeTrue();
});

it('assembles hdfc credit-card datetime rows with signed C amounts', function () {
    $pages = [<<<'TXT'
Domestic Transactions
DATE & TIME                             TRANSACTION DESCRIPTION                                                                            AMOUNT          PI
Harsh Bachawat             [CKYC ID : 20030853292546 ]
15/06/2026| 00:00                       5% Swiggy Cashback                                                                                + C 112.42       l
20/06/2026| 16:26               EMI     PYU*Flipkart PaymenBangalore                                                                      C 30,842.00      l
22/06/2026| 00:00                       1% Swiggy CashBack                                                                                + C 308.42       l
29/06/2026| 00:00                       1.75% on all DCC Transaction (Ref# ST261810084000011711508)                                         C 132.80       l
29/06/2026| 03:50                       BPPY CC PAYMENT AS0161800350449z1eb                                                               + C 2,948.00      l
International Transactions
DATE & TIME                              TRANSACTION DESCRIPTION                                                                                AMOUNT       PI
16/06/2026 | 17:07                       VNPAY*EVISAVIETNAHA NOI                                                VND 677,928                     C 2,447.52   l
14/07/2026 | 00:00                       CONSOLIDATED FCY MARKUP FEE (Ref# MT261890076000010005929)                                               C 402.10   l
Cash Back Summary
SR NO.               TRANSACTION                                                                                                                                                                          AMOUNT
1                    1% Swiggy CashBack                                                                                                                                                                     C 500.00
TXT];

    $table = (new DateLedRowAssembler)->assemble($pages, 'hdfc-cc.pdf', 'HDFC Bank');

    expect($table->rows)->toHaveCount(7)
        ->and($table->rows[0][0])->toBe('15/06/2026')
        ->and($table->rows[0][1])->toBe('5% Swiggy Cashback')
        ->and($table->rows[0][3])->toBe('112.42')
        ->and($table->rows[1][1])->toBe('PYU*Flipkart PaymenBangalore')
        ->and($table->rows[1][2])->toBe('30842.00')
        ->and($table->rows[3][1])->toStartWith('1.75% on all DCC Transaction')
        ->and($table->rows[3][2])->toBe('132.80')
        ->and($table->rows[3][4])->toBe('')
        ->and($table->rows[4][3])->toBe('2948.00')
        ->and($table->rows[5][2])->toBe('2447.52')
        ->and($table->rows[6][1])->toContain('CONSOLIDATED FCY MARKUP FEE')
        ->and($table->rows[6][1])->not->toContain('Cash Back Summary');
});

it('assembles canara txn-date datetime rows with branch codes on the amount line', function () {
    $pages = [<<<'TXT'
Txn Date           Value Date      Cheque No.              Description                    Branch            Debit            Credit            Balance
                                                                                                  Code
Opening Balance Rs. 27,767.55
04-04-2023 13:09:04 04 Apr 2023 346025391557 UPI/DR/346025391557/BASHIR AH/
JAKA/**n9596@okhdfcbank/NA//
PTMa83fc6e5c48949268aec72d022a1d1e6/04/04/2023
13:09:04
33 2,000.00 25,767.55
06-04-2023 10:21:13 06 Apr 2023 309615463075 UPI/DR/309615463075/ANIKET RA/
UTIB/**71298@paytm/sonamarg//
PTM532c9430e62f4026b1cec8c344991538/06/04/2023
10:21:13
33 2,031.00 23,736.55
12-04-2023 12:34:14 12 Apr 2023 346810124039 UPI/CR/346810124039/HARSH
DIL/PYTM/**94298@paytm/NA//
PTMb5f71958aca5491999512fc75f84e49c/12/04/2023
12:34:14
33 5,000.00 28,736.55
TXT];

    $table = (new DateLedRowAssembler)->assemble($pages, 'canara23-24.pdf', 'Canara Bank');

    expect($table->rows)->toHaveCount(3)
        ->and($table->rows[0][0])->toBe('04-04-2023')
        ->and($table->rows[0][1])->toContain('UPI/DR')
        ->and($table->rows[0][1])->not->toStartWith('13:09:04')
        ->and($table->rows[0][2])->toBe('2000.00')
        ->and($table->rows[0][4])->toBe('25767.55')
        ->and($table->rows[2][3])->toBe('5000.00')
        ->and($table->rows[2][4])->toBe('28736.55');
});

it('assembles canara wrap layouts where narration and amounts sit above the date', function () {
    $pages = [<<<'TXT'
Statement for A/c XXXXXXXXXX1112 for the period 24-Sep-2022 to 03-Oct-2022
Customer Id   XXXXXXX56
IFSC Code          CNRB0018678
Date               Particulars             Deposits              Withdrawals     Balance
                                            Opening Balance                            1,000.00
              UPI/DR/226646139740/SARPRE
              /PYTM/**ALUJA@YBL/PAYMEN
  23-09-2022 //AXLE0BB55BF2A6449218F28B
            09F3CE3D7/23/09/2022 13:48:19                      100.00                       900.00
              UPI/CR/226633811783/AMIT
              UPP/SBIN/**50001@IBL/PAYME
              //IBL2E23446943194840A997B9              50.00                               950.00
 23-09-2022   58B374816C/23/09/2022
              16:06:02
 24-09-2022   SMS ALERT CHARGES NEW                                          18.00       932.00
25-09-2022    CASH WITHDRAWAL                                      100.00               832.00
                                             Closing Balance                      832.00
TXT];

    $table = (new DateLedRowAssembler)->assemble($pages, 'canara.pdf', 'Canara Bank');

    expect($table->rows)->toHaveCount(4)
        ->and($table->rows[0][0])->toBe('23-09-2022')
        ->and($table->rows[0][1])->toContain('UPI/DR')
        ->and($table->rows[0][1])->not->toContain('Statement for')
        ->and($table->rows[0][2])->toBe('100.00')
        ->and($table->rows[0][4])->toBe('900.00')
        ->and($table->rows[1][3])->toBe('50.00')
        ->and($table->rows[1][4])->toBe('950.00')
        ->and($table->rows[2][1])->toBe('SMS ALERT CHARGES NEW')
        ->and($table->rows[2][2])->toBe('18.00')
        ->and($table->rows[3][1])->toBe('CASH WITHDRAWAL')
        ->and($table->rows[3][2])->toBe('100.00');
});

it('keeps the last transaction amounts instead of absorbing a statement summary', function () {
    $pages = [<<<'TXT'
Date Narration Chq./Ref.No. Value Dt Withdrawal Amt. Deposit Amt. Closing Balance
09/10/18 ACH D- HOME LOAN-38062210600110 0000005708866326 09/10/18 1,659.00 17,254.94
10/10/18 NHDF6774869440/SBI CARDS 0000182839032247 10/10/18 10,060.00 7,194.94
STATEMENT SUMMARY :-
Opening Balance Dr Count Cr Count Debits Credits Closing Bal
14,109.95 85 0 189,017.40 0.00 7,194.94
Generated On: 22-Jan-2019 19:14 Generated By: 1001 Requesting Branch Code: NET
This is a computer generated statement and does
not require signature.
Page No .: 5
TEST ACCOUNT HOLDER
HDFC BANK LIMITED
*Closing balance includes funds earmarked for hold and uncleared funds
TXT];

    $table = (new DateLedRowAssembler)->assemble($pages, 'hdfc.pdf', 'HDFC Bank');
    $last = $table->rows[array_key_last($table->rows)];

    expect($table->rows)->toHaveCount(2)
        ->and($last[0])->toBe('10/10/18')
        ->and($last[1])->toContain('SBI CARDS')
        ->and($last[1])->not->toContain('STATEMENT SUMMARY')
        ->and($last[1])->not->toContain('require signature')
        ->and($last[1])->not->toContain('BANK LIMITED')
        ->and($last[2])->toBe('10060.00')
        ->and($last[3])->toBe('')
        ->and($last[4])->toBe('7194.94');
});
