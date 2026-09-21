<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Money arriving at the clinic -- `sma_deposits` -- as a Rilven cash-flow document.
 *
 * TWO ROWS PER PAYMENT, and half of them must be ignored. The CMS writes every payment twice:
 * one row with `paid_by` of `cash` or `CC` carrying the money in `amount`, and a second with
 * `payment_link` carrying the same figure in `amount_credit` -- same till, same case, same
 * moment. They are the two legs of one entry, kept in one table. Sending a document per row
 * would double every payment the clinic has ever taken. Measured on this fork: 343,180 rows,
 * 172,844 of them real movements.
 *
 * `payment_link` is therefore not filtered out as noise -- it IS the other leg, and Rilven writes
 * that leg itself from the posting rule. Refunds are the mirror: `refund` rows carry their figure
 * in `amount_credit` and reverse the entry.
 *
 * WHAT IT POSTS, as the auditor settled it:
 *
 *   Дт 1110 (till) or 1210 (bank)   /   Кт 3120 (advance received from the patient)
 *
 * Every receipt lands on 3120 first, with no test of whether the service has been accrued yet.
 * Clearing 3120 against 1410 is a separate posting and not this register's business. The
 * alternative -- crediting 1410 directly when an accrual exists -- was rejected because most of
 * this clinic's money arrives before the service: 1410 would sit in credit, which is an asset
 * account pretending to be a liability.
 *
 * WHO OWES THE ADVANCE BACK is `company_id`, the payer -- NOT `customer_id`, the patient.
 *
 * They are the same person in all 172,844 historical movements, and for years nothing but a
 * patient ever paid. They are not the same thing: an insurer settling for a patient writes its
 * own id in `company_id` and the patient's in `customer_id`, and the money it handed over is
 * owed back to IT, not to the patient it paid for. Reading `customer_id` would credit 3120 to
 * somebody who never paid, and the clinic would appear to owe a refund to the wrong party.
 *
 * The patient is kept, in the comment, because "who was this for" is the first thing anybody
 * asks of a payment -- but it is context, not the counterparty.
 */
class Rilven_deposit {

    /** @var CI_Controller */
    private $CI;
    private $client;
    private $refs = NULL;

    /** sma_cash.id => array(id, kind), resolved once and kept for this run. */
    private $destinations = array();

    public function __construct()
    {
        $this->CI = get_instance();
        $this->CI->load->library('rilven_client');
        $this->client = $this->CI->rilven_client;
    }

    public function client()
    {
        return $this->client;
    }

    public function entity()
    {
        return 'deposit';
    }

    public function typeCode()
    {
        return 'money-received';
    }

    public function enabled()
    {
        return $this->client->cfg('rilven_deposit_enabled', FALSE) ? TRUE : FALSE;
    }

    // -----------------------------------------------------------------------
    // the link
    // -----------------------------------------------------------------------

    public function code($sourceId)
    {
        $code = trim((string) $this->client->cfg('rilven_deposit_code_prefix', '')) . trim((string) $sourceId);
        if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $code)) {
            return NULL;
        }
        return $code;
    }

    /** Our cash-flow id for a CMS payment, or NULL when Rilven has never seen it. */
    public function lookup($code)
    {
        $answer = $this->client->get('/cash-flow/get/' . rawurlencode($code), array('by' => 'code'));

        if ($answer['ok']) {
            $item = isset($answer['data']['item']) ? $answer['data']['item'] : array();
            $id = isset($item['id']) ? (int) $item['id'] : 0;
            return array('ok' => TRUE, 'id' => $id > 0 ? $id : NULL, 'item' => $item,
                         'error' => '', 'retryable' => FALSE);
        }
        if (strpos($answer['error'], '[code]-not-found') === 0) {
            return array('ok' => TRUE, 'id' => NULL, 'item' => array(), 'error' => '', 'retryable' => FALSE);
        }
        return array('ok' => FALSE, 'id' => NULL, 'item' => array(),
                     'error' => $answer['error'], 'retryable' => $answer['retryable']);
    }

    // -----------------------------------------------------------------------
    // what kind of movement this row is
    // -----------------------------------------------------------------------

    /**
     * 'in', 'out', or NULL when this row is not a movement at all.
     *
     * NULL is the ordinary answer for more than half the table -- see the class comment -- and
     * says "there is nothing here to send", which the queue treats as out of scope. It is NOT a
     * refusal: a `payment_link` row is correct data doing its job, and complaining about it every
     * run would bury the refusals that matter.
     */
    public function directionOf($row)
    {
        $kinds = $this->client->cfg('rilven_deposit_kinds', array());
        $paidBy = $this->val($row, 'kind');
        if ($paidBy === '') {
            return NULL;
        }
        foreach ($kinds as $value => $direction) {
            if (strcasecmp((string) $value, $paidBy) === 0) {
                return $direction;
            }
        }
        return NULL;
    }

    /** Money in comes from `amount`; money out from `amount_credit`. The CMS keeps them apart. */
    public function amountOf($row, $direction)
    {
        $column = $direction === 'out' ? 'amount_out' : 'amount_in';
        return $this->money($this->val($row, $column));
    }

    // -----------------------------------------------------------------------
    // mapping
    // -----------------------------------------------------------------------

    public function map($row, $refs)
    {
        $out = array('code' => NULL, 'payload' => array(), 'error' => '', 'retryable' => FALSE);

        $sourceId = isset($row->id) ? $row->id : NULL;
        $code = $this->code($sourceId);
        if ($code === NULL) {
            $out['error'] = 'code-format-is-invalid: '
                . $this->client->cfg('rilven_deposit_code_prefix', '') . $sourceId;
            return $out;
        }
        $out['code'] = $code;

        $direction = $this->directionOf($row);
        if ($direction === NULL) {
            $out['error'] = 'not-a-money-movement';
            return $out;
        }

        $amount = $this->amountOf($row, $direction);
        if ($amount <= 0) {
            // A zero payment is a row the clinic wrote and then emptied. There is nothing to post
            // and a document for it would be noise in the register.
            $out['error'] = 'amount-is-zero';
            return $out;
        }

        // Where the money went, and what kind of place that is. Both come from the cash register,
        // which has to have arrived first -- see Rilven::registers() for why it is sent before
        // this one.
        $destination = $this->destination($this->val($row, 'destination'));
        if (!$destination['ok']) {
            $out['error'] = $destination['error'];
            $out['retryable'] = $destination['retryable'];
            return $out;
        }

        if ((int) $row->rilven_contractor_branch_id <= 0) {
            $out['error'] = $row->patient_id_error !== '' ? $row->patient_id_error : 'payer-not-synced-yet';
            $out['retryable'] = TRUE;
            return $out;
        }

        $helper = $this->helperFor($destination['kind'], $direction, $refs);
        if ($helper === NULL) {
            $out['error'] = 'posting-rule-not-configured: ' . $destination['kind'] . '/' . $direction;
            return $out;
        }

        $date = $this->timestamp($this->val($row, 'date'));
        if ($date === NULL) {
            $out['error'] = 'payment-has-no-date';
            return $out;
        }

        $payload = array(
            'code'              => $code,
            'companyBranchId'   => $refs['companyBranchId'],
            'currencyId'        => $refs['currencyId'],
            'amount'            => $amount,
            'localAmount'       => $amount,
            'currencyRate'      => 1,
            'date'              => substr($date, 0, 10),
            // 1 bank, 2 cash -- the route turns this into the document type the posting rule is
            // found by, so it has to agree with the rule chosen above.
            'sourceType'        => $destination['kind'] === 'cash-machine' ? 2 : 1,
            'type'              => $direction === 'out' ? 2 : 1,
            'transactionHelperId' => $helper,
            // The OTHER side of the entry: whoever handed the money over. The rule names
            // tb_contractor_branch for both directions, so this is the payer either way --
            // credited on a receipt, debited on a refund.
            'targetId'          => (int) $row->rilven_contractor_branch_id,
            'comment'           => $this->comment($row),
            'status'            => 1,
        );

        if ($destination['kind'] === 'cash-machine') {
            $payload['companyCashMachineId'] = $destination['id'];
        } else {
            $payload['companyBankAccountId'] = $destination['id'];
        }

        $out['payload'] = $payload;
        return $out;
    }

    /** What is compared against the outbox's payload hash. */
    public function hash($mapped)
    {
        return hash('sha256', json_encode($mapped['payload']));
    }

    // -----------------------------------------------------------------------
    // sending
    // -----------------------------------------------------------------------

    public function push($row, $refs)
    {
        $mapped = $this->map($row, $refs);
        if ($mapped['error'] !== '') {
            return $this->rejected($mapped['error'], $mapped['retryable'],
                                   strpos($mapped['error'], 'not-synced-yet') !== FALSE);
        }

        $found = $this->lookup($mapped['code']);
        if (!$found['ok']) {
            return $this->rejected($found['error'], $found['retryable']);
        }

        if ($found['id'] === NULL) {
            $answer = $this->client->post('/cash-flow/insert', $mapped['payload']);
            if (!$answer['ok']) {
                return $this->rejected($answer['error'], $answer['retryable']);
            }
            $result = array('result' => 'created',
                            'id' => isset($answer['data']['id']) ? (int) $answer['data']['id'] : 0,
                            'error' => '', 'retryable' => FALSE, 'note' => '', 'noteRetryable' => FALSE);
            return $this->post($result);
        }

        $payload = $mapped['payload'];
        $payload['id'] = $found['id'];

        $answer = $this->client->put('/cash-flow/update', $payload);
        if (!$answer['ok']) {
            return $this->rejected($answer['error'], $answer['retryable']);
        }
        $result = array('result' => 'updated', 'id' => (int) $found['id'],
                        'error' => '', 'retryable' => FALSE, 'note' => '', 'noteRetryable' => FALSE);
        return $this->post($result);
    }

    /**
     * Confirm the document, so the entry is actually in the books.
     *
     * A receipt is not a proposal. The patient handed money over, the till holds it, and the
     * posting Дт 1110|1210 / Кт 3120 describes something that has already happened -- so unlike a
     * waybill, which states an intention until somebody confirms it, this document has nothing to
     * wait for. {@code /cash-flow/insert} forces status 1 whatever it is sent, so the confirmation
     * is a second call and cannot be folded into the first.
     *
     * A failure here is a NOTE and not a refusal, for the reason the sale register gives: the
     * document itself landed, and a draft somebody can confirm by hand is a far better outcome
     * than a row that goes round the queue again and inserts nothing.
     */
    private function post($result)
    {
        // Reported to the outbox whatever happens, which this did NOT do before the period close
        // existed. Nothing had asked: the CMS's edit guard reads the status of a CASE, never of a
        // receipt, so the column stayed empty here and nobody missed it. The close does ask, and
        // an empty column would have made it read every receipt ever sent as an unposted draft.
        $result['rilvenStatus'] = 1;

        if (!$this->client->cfg('rilven_deposit_post', TRUE) || (int) $result['id'] <= 0) {
            return $result;
        }

        $answer = $this->client->put('/cash-flow/update-status', array(
            'ids'    => array((int) $result['id']),
            'status' => 2,
        ));

        if (!$answer['ok']) {
            $result['note'] = 'not-posted: ' . $answer['error'];
            $result['noteRetryable'] = $answer['retryable'];
        } else {
            $result['rilvenStatus'] = 2;
        }
        return $result;
    }

    /**
     * Confirm a receipt that was left as a draft, for the period close.
     *
     * {@see post} already confirms at insert time, so in a healthy run this finds nothing. What
     * it is for is the receipt whose confirmation call failed: that is deliberately a note and
     * not a refusal, so the document is in Rilven, the money is in the till, and the entry
     * Дт 1110|1210 / Кт 3120 is missing -- with nothing in the queue to say so, because the row
     * counts as delivered. The close is where those are found.
     *
     * @return array ok, error, retryable
     */
    public function confirm($rilvenId)
    {
        if ((int) $rilvenId <= 0) {
            return array('ok' => FALSE, 'error' => 'no-document', 'retryable' => FALSE);
        }

        $answer = $this->client->put('/cash-flow/update-status', array(
            'ids'    => array((int) $rilvenId),
            'status' => 2,
        ));

        if (!$answer['ok']) {
            return array('ok' => FALSE, 'error' => $answer['error'],
                         'retryable' => $answer['retryable']);
        }
        return array('ok' => TRUE, 'error' => '', 'retryable' => FALSE);
    }

    // -----------------------------------------------------------------------
    // references
    // -----------------------------------------------------------------------

    public function references($refresh = FALSE)
    {
        if ($this->refs !== NULL && !$refresh) {
            return $this->refs;
        }
        $this->refs = array(
            'companyBranchId' => $this->intOrNull($this->client->cfg('rilven_deposit_company_branch_id', NULL)),
            'currencyId'      => $this->intOrNull($this->client->cfg('rilven_deposit_currency_id', NULL)),
            'helpers'         => $this->client->cfg('rilven_deposit_helpers', array()),
            'notes'           => array(),
        );
        return $this->refs;
    }

    public function missingReferences($refs)
    {
        $missing = array();
        if ($refs['companyBranchId'] === NULL) {
            $missing[] = 'rilven_deposit_company_branch_id';
        }
        if ($refs['currencyId'] === NULL) {
            $missing[] = 'rilven_deposit_currency_id';
        }
        if (empty($refs['helpers'])) {
            $missing[] = 'rilven_deposit_helpers';
        }
        return $missing;
    }

    /** The posting rule for this combination, or NULL when nobody has said what it is. */
    private function helperFor($kind, $direction, $refs)
    {
        $key = $kind . '/' . $direction;
        $helpers = isset($refs['helpers']) && is_array($refs['helpers']) ? $refs['helpers'] : array();
        return isset($helpers[$key]) ? $this->intOrNull($helpers[$key]) : NULL;
    }

    /**
     * The Rilven till or account this payment went to, from the cash register's own outbox row.
     *
     * Read from the queue rather than asked for over the wire: the cash register has already
     * resolved every destination and written the id down, and a clinic's day names the same four
     * tills ten thousand times.
     */
    private function destination($sourceId)
    {
        $sourceId = trim((string) $sourceId);
        if ($sourceId === '' || (int) $sourceId <= 0) {
            return array('ok' => FALSE, 'id' => NULL, 'kind' => NULL,
                         'error' => 'payment-names-no-destination', 'retryable' => FALSE);
        }
        if (isset($this->destinations[$sourceId])) {
            return $this->destinations[$sourceId];
        }

        $row = $this->CI->db->select('o.rilven_id, c.' . $this->cashKindColumn(), FALSE)
            ->from($this->CI->db->dbprefix . 'rilven_outbox o', FALSE)
            ->join($this->CI->db->dbprefix . $this->client->cfg('rilven_cash_source_table', 'cash') . ' c',
                   'c.id = o.external_id + 0', 'inner', FALSE)
            ->where('o.entity', 'cash')
            ->where('o.external_id', (string) $sourceId)
            ->get()->row();

        if (!$row || (int) $row->rilven_id <= 0) {
            // Not a failure of this payment: its destination simply has not travelled yet, or was
            // refused. Retryable, so the payment waits rather than being given up on.
            $answer = array('ok' => FALSE, 'id' => NULL, 'kind' => NULL,
                            'error' => 'destination-not-synced-yet: ' . $sourceId, 'retryable' => TRUE);
            return $answer;
        }

        $kinds = $this->client->cfg('rilven_cash_kinds', array());
        $column = $this->cashKindColumn();
        $paidBy = isset($row->$column) ? trim((string) $row->$column) : '';
        $kind = NULL;
        foreach ($kinds as $value => $k) {
            if (strcasecmp((string) $value, $paidBy) === 0) {
                $kind = $k;
                break;
            }
        }
        if ($kind === NULL) {
            return array('ok' => FALSE, 'id' => NULL, 'kind' => NULL,
                         'error' => 'cash-kind-unknown: ' . ($paidBy === '' ? '(empty)' : $paidBy),
                         'retryable' => FALSE);
        }

        $this->destinations[$sourceId] = array('ok' => TRUE, 'id' => (int) $row->rilven_id,
                                               'kind' => $kind, 'error' => '', 'retryable' => FALSE);
        return $this->destinations[$sourceId];
    }

    /** The column the CASH register reads `paid_by` from -- its mapping, not this one's. */
    private function cashKindColumn()
    {
        $fields = $this->client->cfg('rilven_cash_fields', array());
        return isset($fields['kind']) && $fields['kind'] !== '' ? $fields['kind'] : 'paid_by';
    }

    // -----------------------------------------------------------------------
    // reading the source row
    // -----------------------------------------------------------------------

    public function val($row, $meaning)
    {
        $fields = $this->client->cfg('rilven_deposit_fields', array());
        if (!isset($fields[$meaning])) {
            return '';
        }
        $column = $fields[$meaning];
        if ($column === '' || $column === NULL || !isset($row->$column) || $row->$column === NULL) {
            return '';
        }
        return trim((string) $row->$column);
    }

    /** What a person reading the cash-flow register sees. */
    private function comment($row)
    {
        $parts = array();
        $reference = $this->val($row, 'reference');
        if ($reference !== '') {
            $parts[] = $reference;
        }
        $case = $this->val($row, 'case');
        if ($case !== '' && (int) $case > 0) {
            $parts[] = 'case ' . $case;
        }
        // Only when somebody OTHER than the patient paid. Saying "for patient 70684" on the
        // ninety-nine per cent of payments a patient made for themselves is noise.
        $payer   = $this->val($row, 'payer');
        $patient = $this->val($row, 'patient');
        if ($patient !== '' && $payer !== '' && $patient !== $payer) {
            $parts[] = 'for patient ' . $patient;
        }
        $note = $this->val($row, 'note');
        if ($note !== '') {
            $parts[] = $note;
        }
        return $this->clip(implode(' / ', $parts), 250);
    }

    /** Money as Rilven stores it: four decimal places, as a whole number. */
    private function money($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return 0;
        }
        return (int) round(((float) $raw) * 10000);
    }

    private function timestamp($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '' || strpos($raw, '0000-00-00') === 0) {
            return NULL;
        }
        $stamp = strtotime($raw);
        return $stamp === FALSE ? NULL : date('Y-m-d H:i:s', $stamp);
    }

    private function clip($value, $max)
    {
        $value = (string) $value;
        return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
    }

    private function intOrNull($value)
    {
        if ($value === NULL || $value === '' || (int) $value <= 0) {
            return NULL;
        }
        return (int) $value;
    }

    private function rejected($error, $retryable, $dependency = FALSE)
    {
        return array('result' => 'rejected', 'id' => 0, 'error' => $error,
                     'retryable' => (bool) $retryable, 'note' => '', 'noteRetryable' => FALSE,
                     'dependency' => (bool) $dependency);
    }
}
