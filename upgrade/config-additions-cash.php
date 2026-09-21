<?php
// Append to /home/www/public/app/config/rilven.php on the clinic, at the end and BEFORE any
// closing tag. Nothing here replaces an existing key.
//
// Then set rilven_cash_enabled = TRUE and run:
//     php index.php admin/rilven_sync check     -- read the "money destinations" block
//     php index.php admin/rilven_sync cron
//
// Tills are created over there automatically. Bank rows and card terminals are NOT: this library
// will not invent a bank account. Each finds the account it settles into by its NUMBER -- the
// clinic's `account_number` against Rilven's -- and refuses by name if no account has it.

// ---------------------------------------------------------------------------
// 15. Money destinations -- sma_cash
// ---------------------------------------------------------------------------
//
// One table, two records over there. `paid_by` decides which, and an unrecognised value is
// REFUSED rather than defaulted -- see Rilven_cash for why that is the whole safety of this
// register. Measured on the LJ fork: 10 rows, 4 cash, 2 bank, 4 CC.

$config['rilven_cash_enabled'] = FALSE;

// Without the prefix; CodeIgniter adds it.
$config['rilven_cash_source_table'] = 'cash';

// Narrow the scope if a fork keeps retired destinations in the same table. Empty means all.
$config['rilven_cash_where'] = array();

// What each value MEANS, named by meaning rather than by column, because forks rename columns.
//   name       what a person calls it
//   kind       the value `rilven_cash_kinds` is read against
//   account    the account NUMBER, for a bank row or a card terminal. This is how a destination
//              finds the Rilven account it settles into without anybody binding it by hand: an
//              IBAN is the same string in both systems or it is not the same account.
$config['rilven_cash_fields'] = array(
    'name'    => 'name',
    'kind'    => 'paid_by',
    'account' => 'account_number',
);

// Which Rilven record each `paid_by` becomes. A value that is not here stops its destination and
// every payment through it, by design: a till holds money on 1110 and a bank account on 1210, and
// guessing between them misstates the balance sheet in a way nobody notices for months.
$config['rilven_cash_kinds'] = array(
    'cash' => 'cash-machine',   // 1110
    'bank' => 'bank-account',   // 1210
    'CC'   => 'bank-account',   // a card terminal pays into a bank account, never into a till
);

// Where the tills belong. Same branch as the sales register.
$config['rilven_cash_company_branch_id'] = 30;

// There is NO bank or currency key here on purpose: this register never creates a bank account.
// A bank row or a card terminal is BOUND to an account a person opened in Rilven, by putting the
// sma_cash id in that account's `code`. Until that is done the destination refuses by name, which
// is what an accountant needs to see -- see Rilven_cash::push().

// A prefix for a Rilven company taking destinations from two source systems. Normally empty.
$config['rilven_cash_code_prefix'] = '';
