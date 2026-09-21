<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * The clinic's money destinations -- `sma_cash` -- as Rilven records them.
 *
 * ONE source table, TWO records over there, and that is the whole difficulty of this register.
 * `sma_cash` is not a till register despite its name: it answers "where did the money go", and the
 * answers are of three kinds, told apart by `paid_by`:
 *
 *   cash        a till            -> /company-cash-machine, and the money sits on 1110
 *   bank        a bank account    -> /company-bank-account,  and the money sits on 1210
 *   CC          a card terminal   -> /company-bank-account too: the acquirer pays into a bank
 *                                    account, so the money was never in a till
 *
 * Sending all of them as tills would put card takings and bank transfers on 1110. The balance
 * sheet would then show a cash box holding money that was never in it, and finding that later
 * means unpicking every payment.
 *
 * An UNKNOWN `paid_by` is refused, never defaulted. That is the one decision protecting this
 * register: the clinic adds destinations often -- one fork has ten rows, another a hundred and
 * sixty -- and a default would silently misfile every payment through the new one. A refusal
 * stops the payments for that destination and names it, which somebody notices in a day.
 */
class Rilven_cash {

    /** @var CI_Controller */
    private $CI;
    private $client;
    private $refs = NULL;

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

    /** The outbox `entity` this register writes under. */
    public function entity()
    {
        return 'cash';
    }

    /** The outbox `type_code`. Informational; it is what somebody reading the queue sees. */
    public function typeCode()
    {
        return 'money-destination';
    }

    public function enabled()
    {
        return $this->client->cfg('rilven_cash_enabled', FALSE) ? TRUE : FALSE;
    }

    // -----------------------------------------------------------------------
    // the link
    // -----------------------------------------------------------------------

    /**
     * What this destination is called over there: the clinic's own `sma_cash.id`.
     *
     * Codes are unique per company WITHIN a table over there, and these rows land in two different
     * tables -- so a till and a bank account may both be "7" without colliding. They cannot both
     * be 7 HERE, because both come from one `sma_cash`, so the codes stay distinct anyway.
     */
    public function code($sourceId)
    {
        $code = trim((string) $this->client->cfg('rilven_cash_code_prefix', '')) . trim((string) $sourceId);
        if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $code)) {
            return NULL;
        }
        return $code;
    }

    /**
     * Which of the two records this row becomes, or NULL when the clinic has invented a kind
     * nobody has decided about yet.
     */
    public function kindOf($row)
    {
        $kinds = $this->client->cfg('rilven_cash_kinds', array());
        $paidBy = $this->val($row, 'kind');
        if ($paidBy === '') {
            return NULL;
        }
        foreach ($kinds as $value => $kind) {
            if (strcasecmp((string) $value, $paidBy) === 0) {
                return $kind;
            }
        }
        return NULL;
    }

    /** Where a kind lives over there. */
    private function routeOf($kind)
    {
        return $kind === 'cash-machine' ? '/company-cash-machine' : '/company-bank-account';
    }

    /**
     * Our id for a CMS destination, or NULL when Rilven has never seen it.
     *
     * Asked of the route the KIND names, not of both: a code found under bank accounts says
     * nothing about tills, and asking both would make a row that changed kind look like it
     * exists twice.
     */
    public function lookup($code, $kind)
    {
        $answer = $this->client->get($this->routeOf($kind) . '/get/' . rawurlencode($code),
                                     array('by' => 'code'));

        if ($answer['ok']) {
            $item = isset($answer['data']['item']) ? $answer['data']['item'] : array();
            $id = isset($item['id']) ? (int) $item['id'] : 0;
            return array('ok' => TRUE, 'id' => $id > 0 ? $id : NULL, 'item' => $item,
                         'error' => '', 'retryable' => FALSE);
        }

        if (strpos($answer['error'], '[code]-not-found') === 0) {
            // Not a failure: this is the answer that means "create it".
            return array('ok' => TRUE, 'id' => NULL, 'item' => array(), 'error' => '', 'retryable' => FALSE);
        }

        return array('ok' => FALSE, 'id' => NULL, 'item' => array(),
                     'error' => $answer['error'], 'retryable' => $answer['retryable']);
    }

    /**
     * The Rilven account whose number is exactly this one, or NULL.
     *
     * The list route matches on a PREFIX, so a search for GE18LB7006236011 would also answer with
     * GE18LB70062360111110000 -- two real accounts of the same clinic differ only at the end.
     * Every row is therefore compared again here, exactly, and an answer that is not exact is no
     * answer. A search that comes back with several exact matches is refused rather than resolved:
     * the same number in two currencies is legitimate over there, and picking one would put a
     * clinic's takings in the wrong currency's account.
     */
    private function findAccountByNumber($number)
    {
        $number = trim((string) $number);
        if ($number === '') {
            return array('ok' => TRUE, 'id' => NULL, 'error' => '', 'retryable' => FALSE);
        }

        $answer = $this->client->get('/company-bank-account/list', array('key' => $number));
        if (!$answer['ok']) {
            return array('ok' => FALSE, 'id' => NULL,
                         'error' => 'bank-account-lookup: ' . $answer['error'],
                         'retryable' => $answer['retryable']);
        }

        $items = array();
        if (isset($answer['data']['pagination']['items']) && is_array($answer['data']['pagination']['items'])) {
            $items = $answer['data']['pagination']['items'];
        }

        $hits = array();
        foreach ($items as $item) {
            if (isset($item['account']) && strcasecmp(trim((string) $item['account']), $number) === 0
                    && isset($item['id']) && (int) $item['id'] > 0) {
                $hits[] = (int) $item['id'];
            }
        }
        $hits = array_values(array_unique($hits));

        if (count($hits) > 1) {
            return array('ok' => FALSE, 'id' => NULL,
                         'error' => 'bank-account-ambiguous: ' . $number . ' names '
                             . count($hits) . ' accounts over there (' . implode(', ', $hits)
                             . ') -- bind this one by setting its code',
                         'retryable' => FALSE);
        }
        return array('ok' => TRUE, 'id' => empty($hits) ? NULL : $hits[0],
                     'error' => '', 'retryable' => FALSE);
    }

    // -----------------------------------------------------------------------
    // mapping
    // -----------------------------------------------------------------------

    public function map($row, $refs)
    {
        $out = array('code' => NULL, 'kind' => NULL, 'payload' => array(), 'error' => '');

        $sourceId = isset($row->id) ? $row->id : NULL;
        $code = $this->code($sourceId);
        if ($code === NULL) {
            $out['error'] = 'code-format-is-invalid: '
                . $this->client->cfg('rilven_cash_code_prefix', '') . $sourceId;
            return $out;
        }
        $out['code'] = $code;

        $kind = $this->kindOf($row);
        if ($kind === NULL) {
            // See the class comment. This is the refusal that keeps card money off 1110.
            $out['error'] = 'cash-kind-unknown: ' . ($this->val($row, 'kind') === ''
                ? '(empty)' : $this->val($row, 'kind'));
            return $out;
        }
        $out['kind'] = $kind;

        $name = $this->val($row, 'name');
        if ($name === '') {
            $out['error'] = 'source-row-has-no-name';
            return $out;
        }

        if ($kind === 'cash-machine') {
            // Only a till needs one: it is the only record this register writes.
            if ($refs['companyBranchId'] === NULL) {
                $out['error'] = 'company-branch-not-configured';
                return $out;
            }
            // The device number Rilven demands, and what a clinic that has none sends instead.
            //
            // A till in Rilven is identified by the serial of its fiscal device; a till in this
            // CMS is a row with a name and nothing else. The route insists on one -- the column
            // is nullable but the DTO is not -- so a clinic that records no serial sends its own
            // id for the destination, which is at least unique and traceable back. Map `device`
            // to a real column and that wins.
            $device = $this->val($row, 'device');
            if ($device === '') {
                $device = $code;
            }

            $out['payload'] = array(
                'code'            => $code,
                'name'            => $this->clip($name, 250),
                'deviceNumber'    => $this->clip($device, 64),
                'companyBranchId' => $refs['companyBranchId'],
            );
            return $out;
        }

        // A bank account is NOT created from here, and that is deliberate.
        //
        // A till is an internal thing and this library may invent one. A bank account is not: it
        // is a contract with a bank and a real IBAN, and an integration that creates them fills
        // the chart with plausible fictions -- an "account" numbered cash-7 that no bank has ever
        // heard of, which somebody will later try to reconcile a statement against.
        //
        // A card terminal is not an account either. It settles INTO one, and which one is an
        // accounting decision: the acquirer, the delay, the fee. So the account is made by a
        // person in Rilven and bound to this row by putting this code on it, and until that
        // happens the row refuses and names itself. A clinic adding its hundred-and-sixty-first
        // destination gets a refusal an accountant can act on, not a new account nobody chose.
        $out['payload'] = array();
        return $out;
        return $out;
    }

    /** What is compared against the outbox's payload hash. */
    public function hash($mapped)
    {
        // The KIND is in here beside the payload. A destination that changed from a till to a
        // bank account has the same name and branch, so the payload alone would read as
        // unchanged and the move would never be noticed.
        return hash('sha256', json_encode(array($mapped['kind'], $mapped['payload'])));
    }

    // -----------------------------------------------------------------------
    // sending
    // -----------------------------------------------------------------------

    /**
     * Put one destination into Rilven.
     *
     * @return array result ('created'|'updated'|'rejected'), id, error, retryable, note
     */
    public function push($row, $refs)
    {
        $mapped = $this->map($row, $refs);
        if ($mapped['error'] !== '') {
            return $this->rejected($mapped['error'], FALSE);
        }

        $route = $this->routeOf($mapped['kind']);

        // A BANK ROW resolves by its account number, and only by its account number in the
        // ordinary case.
        //
        // The number is the key the two systems already share -- an IBAN is the same string on
        // both sides or it is not the same account -- so nobody has to remember to bind anything.
        // `code` is asked for ONLY when the number cannot answer: the clinic holds none, or it
        // holds one that names two accounts over there. Asking for the code first, as this used
        // to, spent a request per row per run to be told "not found".
        if ($mapped['kind'] === 'bank-account') {
            $number = $this->val($row, 'account');
            $byNumber = $this->findAccountByNumber($number);

            if ($byNumber['ok'] && $byNumber['id'] !== NULL) {
                // Found, and nothing is written back: the account's name, bank and number belong
                // to whoever opened it. This register's whole job for a bank row is to answer
                // "which account is destination 7", and it just has.
                return array('result' => 'updated', 'id' => (int) $byNumber['id'],
                             'error' => '', 'retryable' => FALSE, 'note' => '', 'noteRetryable' => FALSE);
            }
            if (!$byNumber['ok'] && $byNumber['retryable']) {
                return $this->rejected($byNumber['error'], TRUE);
            }

            // The number could not answer. A code is the deliberate way out of exactly this.
            $bound = $this->lookup($mapped['code'], 'bank-account');
            if (!$bound['ok']) {
                return $this->rejected($bound['error'], $bound['retryable']);
            }
            if ($bound['id'] !== NULL) {
                return array('result' => 'updated', 'id' => (int) $bound['id'],
                             'error' => '', 'retryable' => FALSE, 'note' => '', 'noteRetryable' => FALSE);
            }

            if (!$byNumber['ok']) {
                // Ambiguous, and no code to settle it.
                return $this->rejected($byNumber['error'], FALSE);
            }

            // See map(): the account is a person's to open, and this library will not invent one.
            return $this->rejected('bank-account-not-bound: ' . $mapped['code']
                . ($number === '' ? ' -- the clinic holds no account number for it'
                                  : ' -- no Rilven account has the number ' . $number)
                . '; open it in Rilven, or set its code to ' . $mapped['code'], FALSE);
        }

        $found = $this->lookup($mapped['code'], $mapped['kind']);
        if (!$found['ok']) {
            return $this->rejected($found['error'], $found['retryable']);
        }

        if ($found['id'] === NULL) {
            // Before creating, make sure this code is not already the OTHER kind over there.
            //
            // A destination whose `paid_by` was corrected -- a till someone had entered as a
            // terminal -- would otherwise be created a second time under the new route, and both
            // would answer to the same code. Payments would then land on whichever the deposit
            // register happened to resolve, and the two would drift apart for good. Moving money
            // between a till and a bank account is an accounting decision and not this library's
            // to make, so it says so and stops.
            $other = $mapped['kind'] === 'cash-machine' ? 'bank-account' : 'cash-machine';
            $twin = $this->lookup($mapped['code'], $other);
            if ($twin['ok'] && $twin['id'] !== NULL) {
                return $this->rejected('cash-kind-changed: ' . $mapped['code'] . ' is already a '
                    . $other . ' over there -- a person has to decide what happens to its money', FALSE);
            }

            $answer = $this->client->post($route . '/insert', $mapped['payload']);
            if (!$answer['ok']) {
                return $this->rejected($answer['error'], $answer['retryable']);
            }
            return array('result' => 'created',
                         'id' => isset($answer['data']['id']) ? (int) $answer['data']['id'] : 0,
                         'error' => '', 'retryable' => FALSE, 'note' => '', 'noteRetryable' => FALSE);
        }

        $payload = $mapped['payload'];
        $payload['id'] = $found['id'];

        $answer = $this->client->put($route . '/update', $payload);
        if (!$answer['ok']) {
            return $this->rejected($answer['error'], $answer['retryable']);
        }
        return array('result' => 'updated', 'id' => (int) $found['id'],
                     'error' => '', 'retryable' => FALSE, 'note' => '', 'noteRetryable' => FALSE);
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
            'companyBranchId' => $this->intOrNull($this->client->cfg('rilven_cash_company_branch_id', NULL)),
            'notes'           => array(),
        );
        return $this->refs;
    }

    /** What the run cannot proceed without, said once rather than per row. */
    public function missingReferences($refs)
    {
        $missing = array();
        if ($refs['companyBranchId'] === NULL) {
            $missing[] = 'rilven_cash_company_branch_id';
        }
        // The bank ones are only needed by bank and CC rows, so they are NOT fatal for a clinic
        // whose destinations are all tills. A row that needs them refuses individually.
        return $missing;
    }

    // -----------------------------------------------------------------------
    // reading the source row
    // -----------------------------------------------------------------------

    public function val($row, $meaning)
    {
        $fields = $this->client->cfg('rilven_cash_fields', array());
        if (!isset($fields[$meaning])) {
            return '';
        }
        $column = $fields[$meaning];
        if ($column === '' || $column === NULL || !isset($row->$column) || $row->$column === NULL) {
            return '';
        }
        return trim((string) $row->$column);
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

    private function rejected($error, $retryable)
    {
        return array('result' => 'rejected', 'id' => 0, 'error' => $error,
                     'retryable' => (bool) $retryable, 'note' => '', 'noteRetryable' => FALSE);
    }
}
