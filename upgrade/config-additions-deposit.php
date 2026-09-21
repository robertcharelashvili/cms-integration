<?php
// Append to /home/www/public/app/config/rilven.php on the clinic, at the end and BEFORE any
// closing tag. Requires section 15 (money destinations) -- a payment names the till or account
// it went to, and is refused until that has travelled.
//
// Keep rilven_deposit_enabled = FALSE, run:
//     php index.php admin/rilven_sync check
// and read the "money received" block. "other leg" is EXPECTED -- it is the half of the table
// the CMS writes twice. "UNKNOWN kind" is not: those payments would never post.
//
// Only then set it TRUE.

// ---------------------------------------------------------------------------
// 16. Money received -- sma_deposits
// ---------------------------------------------------------------------------
//
// TWO ROWS PER PAYMENT. The CMS writes the money once as `cash`/`CC`/`bank` and again as
// `payment_link` -- the two legs of one entry in one table. Only the first is sent; Rilven
// writes the other leg itself from the posting rule. Sending both would double every payment.
// Measured on this fork: 343,180 rows, 172,844 real movements.

$config['rilven_deposit_enabled'] = FALSE;

$config['rilven_deposit_source_table'] = 'deposits';

// The window. Payments must not run ahead of the accruals they belong to, so this is normally
// the same date as rilven_sale_from -- load a year of payments against two days of revenue and
// 3120 swells by everything the clinic has ever taken.
$config['rilven_deposit_from']        = '2026-09-18';
$config['rilven_deposit_date_column'] = 'date';
$config['rilven_deposit_where']       = array();

// Who the advance is owed back to. NOT the patient: an insurer settling for a patient puts its
// own id here and the patient's in customer_id, and the money is owed to whoever handed it over.
$config['rilven_deposit_payer_column'] = 'company_id';

//   kind       the value `rilven_deposit_kinds` is read against
//   amount_in  money received; amount_out money returned -- the CMS keeps them in two columns
//   destination  sma_cash.id, which the cash register has already resolved
//   payer / patient / case / reference / note  for the comment a person reads
$config['rilven_deposit_fields'] = array(
    'kind'        => 'paid_by',
    'amount_in'   => 'amount',
    'amount_out'  => 'amount_credit',
    'destination' => 'cash_type',
    'date'        => 'date',
    'payer'       => 'company_id',
    'patient'     => 'customer_id',
    'case'        => 'sale_id',
    'reference'   => 'reference_no',
    'note'        => 'note',
);

// Which way the money went. A value that is not here and not in `rilven_deposit_other_leg` is
// reported by `check` as unknown -- its payments are not sent at all, which is the safe answer.
$config['rilven_deposit_kinds'] = array(
    'cash'                => 'in',
    'CC'                  => 'in',
    'bank'                => 'in',
    'refund'              => 'out',
    'payment_link_refund' => 'out',
);

// The other leg, written by the CMS and by Rilven both. Listed so that `check` can count it
// apart from the kinds nobody has decided about -- it is expected, not a problem.
$config['rilven_deposit_other_leg'] = array('payment_link');

// The posting rule per destination and direction. These are tb_transaction_helper ids:
//   2852  Дт 1110 / Кт 3120   cash receipt in till -- from advances received
//   2853  Дт 3120 / Кт 1110   advance returned from the till
//    240  Дт 1210 / Кт 3120   cash receipt in bank -- from advances received
//    161  Дт 3120 / Кт 1210   advance returned from the bank
// The till pair did not exist in the product and was added by db/cash_advance_till_helper.sql:
// Rilven could take an advance into a bank account but not into a till, and a till is where
// this clinic takes most of its money.
$config['rilven_deposit_helpers'] = array(
    'cash-machine/in'  => 2852,
    'cash-machine/out' => 2853,
    'bank-account/in'  => 240,
    'bank-account/out' => 161,
);

$config['rilven_deposit_company_branch_id'] = 30;
$config['rilven_deposit_currency_id']       = 44;
$config['rilven_deposit_code_prefix']       = '';
