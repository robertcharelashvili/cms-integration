<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Who, other than the patient, is paying for a case.
 *
 * The case's own document already accrues the WHOLE of it against the patient: Дт1410 patient /
 * Кт6110, written by the waybill the sale register sends. That is right as far as it goes and
 * wrong as a final answer, because most of the money is owed by somebody else. Measured over
 * September 2026: patients 291 995, financiers 350 809 — fifty-five per cent on the wrong
 * counterparty if nothing moves it.
 *
 * This register moves it, with a settlement document (Rilven document_type 81). One case, one
 * document, one line per share:
 *
 *     Дт 1410 National Health Agency / Кт 1410 the patient    4 475.31
 *     Дт 1410 Kutaisi city hall      / Кт 1410 the patient    1 000.00
 *
 * and the 500.00 the patient actually owes is what is left on them.
 *
 * WHERE THE SHARE LIVES, because it is not where the column names suggest. `insurance_group_id`
 * on sma_payments is NULL in every row of this database. The financier is
 * {@code sma_payments.company_id} pointing at a company in GROUP 5, on an `accruing` row that
 * also carries `sale_item_id` — the case-level row with no item is the PATIENT's share. Two
 * financiers regularly name the same service line, which is why the ceiling Rilven enforces is
 * per service and not per line.
 *
 * NOT EVERY GROUP-5 ROW IS A DEBTOR. `კლინიკის შეღავათი (ლჯ-ის დაფინანსება)` is the clinic's own
 * concession: nobody owes it, the service was given away, and it posts 8290/1410 as a
 * non-operating expense which is taxed as one. Those companies are listed in
 * `rilven_financing_concession_ids` and get the other rule.
 *
 * EVERYTHING IS RESOLVED FROM THE OUTBOX, not asked of Rilven: the patient's branch, the
 * financier's branch, the case's document and the service. All four are already recorded there
 * by the registers that sent them, so a share whose financier has not been synced yet is refused
 * and left in the queue rather than sent to whatever id happens to be free.
 */
class Rilven_financing
{
    /** @var CI_Controller */
    private $CI;

    /** @var Rilven_client */
    private $client;

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
        return 'financing';
    }

    /** The outbox `type_code`. Informational; it is what somebody reading the queue sees. */
    public function typeCode()
    {
        return 'financier-share';
    }

    /**
     * Whether financier shares are sent at all.
     *
     * FALSE by default and deliberately separate from `rilven_sale_enabled`. The case documents
     * can run for weeks before anybody is ready for the settlements, and turning the two on
     * together would mean the first mistake here lands on top of a register that was working.
     */
    public function enabled()
    {
        return $this->client->cfg('rilven_financing_enabled', FALSE) ? TRUE : FALSE;
    }

    /** What is compared against the outbox's payload hash. */
    public function hash($mapped)
    {
        return hash('sha256', json_encode(array($mapped['document'], $mapped['items'])));
    }

    // -----------------------------------------------------------------------
    // the link
    // -----------------------------------------------------------------------

    /**
     * The settlement's code over there: the clinic's own case id.
     *
     * The same number the case's waybill carries, on purpose. One case has one settlement, and
     * sharing the code means either document can be found from the other without a map.
     */
    public function code($sourceId)
    {
        $code = trim((string) $this->client->cfg('rilven_financing_code_prefix', '')) . trim((string) $sourceId);
        if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $code)) {
            return NULL;
        }
        return $code;
    }

    /**
     * Our settlement id for a case, or NULL when Rilven has never seen one.
     *
     * @return array ok, id (NULL when absent), error, retryable
     */
    public function lookup($code)
    {
        $answer = $this->client->get('/settlement/get/' . rawurlencode($code), array('by' => 'code'));

        if ($answer['ok']) {
            $doc = isset($answer['data']['settlement']) ? $answer['data']['settlement'] : array();
            $id = isset($doc['id']) ? (int) $doc['id'] : 0;
            return array('ok' => TRUE, 'id' => $id > 0 ? $id : NULL,
                         'status' => isset($doc['status']) ? (int) $doc['status'] : 0,
                         'error' => '', 'retryable' => FALSE);
        }

        if (strpos($answer['error'], '[code]-not-found') === 0 || strpos($answer['error'], 'invalid-data') === 0) {
            // Not a failure: this is the answer that means "create it".
            return array('ok' => TRUE, 'id' => NULL, 'status' => 0, 'error' => '', 'retryable' => FALSE);
        }

        return array('ok' => FALSE, 'id' => NULL, 'status' => 0,
                     'error' => $answer['error'], 'retryable' => $answer['retryable']);
    }

    // -----------------------------------------------------------------------
    // references
    // -----------------------------------------------------------------------

    /**
     * What this register needs resolved before it can send anything.
     *
     * Almost nothing, and that is the design: the patient's branch, the financier's branch, the
     * case's document and the service are all read out of our own outbox, recorded there by the
     * registers that sent them. Nothing is asked of Rilven to resolve a reference, so this
     * register cannot be stopped by a permission it was never granted -- which is exactly what
     * stopped the insurer register on its first run.
     *
     * The branch, the currency and the two posting rules are configuration, not lookups.
     */
    public function references($refresh = FALSE)
    {
        return array(
            'companyBranchId'   => $this->intOrNull($this->client->cfg('rilven_financing_company_branch_id', NULL)),
            'currencyId'        => $this->intOrNull($this->client->cfg('rilven_financing_currency_id', NULL)),
            'helperShare'       => $this->intOrNull($this->client->cfg('rilven_financing_helper_share', NULL)),
            'helperConcession'  => $this->intOrNull($this->client->cfg('rilven_financing_helper_concession', NULL)),
            'notes'             => array(),
        );
    }

    /**
     * What is missing, by name, so the run stops ONCE instead of refusing every row for it.
     *
     * A settlement cannot be written without a branch, a currency and the rule that says which
     * pair of accounts to post to. Without this the first tick would reject a hundred and
     * forty-seven cases one at a time, each with the same sentence, and the log would say
     * nothing a person could act on.
     *
     * The concession rule is NOT required. A clinic that never gives a service away never needs
     * it, and demanding it would stop a register that has everything it actually uses.
     */
    public function missingReferences($refs)
    {
        $missing = array();
        if ($refs['companyBranchId'] === NULL) { $missing[] = 'companyBranchId'; }
        if ($refs['currencyId'] === NULL)      { $missing[] = 'currencyId'; }
        if ($refs['helperShare'] === NULL)     { $missing[] = 'helperShare'; }
        return $missing;
    }

    /**
     * The case no longer has a financier share: take the settlement away.
     *
     * Called by the orchestrator when the source row has gone out of scope, which for this
     * register means the shares were removed or zeroed. A settlement that moves nothing is not
     * harmless — it is a document claiming a receivable belongs to somebody, still sitting in
     * the register for a person to post.
     *
     * A POSTED one is refused rather than unposted. Its lines hold the ids of real ledger
     * entries, and taking those out of the books because a row changed in the CMS is a person's
     * decision — the same line {@see push} draws.
     */
    public function remove($rilvenId)
    {
        $answer = $this->client->request('DELETE', '/settlement/delete',
                                         array('ids' => array((int) $rilvenId)));
        if ($answer['ok']) {
            return array('ok' => TRUE, 'error' => '', 'retryable' => FALSE);
        }
        if (strpos($answer['error'], 'settlement-is-posted') !== FALSE) {
            return array('ok' => FALSE, 'retryable' => FALSE,
                         'error' => 'shares-gone-but-settlement-is-posted: ' . $rilvenId);
        }
        return array('ok' => FALSE, 'error' => $answer['error'], 'retryable' => $answer['retryable']);
    }

    // -----------------------------------------------------------------------
    // the mapping
    // -----------------------------------------------------------------------

    /**
     * One case's financier shares as the settlement route wants them.
     *
     * Returns 'error' filled in when the case cannot be sent at all. Most of those are NOT
     * faults: a case whose patient or financier has not reached Rilven yet is refused and stays
     * in the queue, because the answer is for the other register to catch up, not for this one
     * to try harder.
     */
    public function map($row, $refs)
    {
        $out = array('code' => NULL, 'document' => array(), 'items' => array(), 'error' => '');

        $code = $this->code(isset($row->id) ? $row->id : NULL);
        if ($code === NULL) {
            $out['error'] = 'code-format-is-invalid';
            return $out;
        }
        $out['code'] = $code;

        if (empty($row->shares)) {
            // Nothing to move. The case is the patient's alone, which is the commonest case of
            // all and not a problem -- the orchestrator does not queue these at all.
            $out['error'] = 'case-has-no-financier-share';
            return $out;
        }

        // The patient, who is on the CREDIT side of every line: their debt is what moves.
        if ((int) $row->rilven_contractor_branch_id <= 0) {
            $out['error'] = $row->patient_id_error !== ''
                ? $row->patient_id_error : 'patient-branch-unknown';
            return $out;
        }
        $patientBranchId = (int) $row->rilven_contractor_branch_id;

        // The case document. Optional to the API, but not to us: it is what lets Rilven enforce
        // the ceiling, and a settlement without it can move more than the case ever charged.
        $waybillId = $this->rilvenIdFor('sale', $row->id);
        if ($waybillId === NULL) {
            $out['error'] = 'case-not-synced-yet';
            return $out;
        }

        $branchId   = $this->intOrNull($this->client->cfg('rilven_financing_company_branch_id', NULL));
        $currencyId = $this->intOrNull($this->client->cfg('rilven_financing_currency_id', NULL));
        if ($branchId === NULL || $currencyId === NULL) {
            $out['error'] = 'financing-branch-or-currency-not-configured';
            return $out;
        }

        $items = array();
        foreach ($row->shares as $share) {
            $line = $this->line($share, $patientBranchId);
            if ($line['error'] !== '') {
                // One unresolvable share refuses the WHOLE document. A settlement that carries
                // some of a case's shares and not others is worse than none: the receivable is
                // then wrong on the patient by exactly the part that was left out, and nothing
                // in the data says a part is missing.
                $out['error'] = $line['error'];
                return $out;
            }
            $items[] = $line['item'];
        }

        $out['document'] = array(
            'code'               => $code,
            'documentDate'       => $this->instant($row),
            'companyBranchId'    => $branchId,
            'currencyId'         => $currencyId,
            'contractorBranchId' => $patientBranchId,
            'waybillId'          => $waybillId,
            'comment'            => $this->comment($row),
        );
        $out['items'] = $items;
        return $out;
    }

    /**
     * One share as a settlement line.
     *
     * The rule is chosen per line and that is the whole point of the document: a financier
     * taking over part of a receivable is 1410/1410, while the clinic's own concession is
     * nobody's receivable at all and goes to 8290/1410 as a taxable expense.
     */
    private function line($share, $patientBranchId)
    {
        $out = array('item' => array(), 'error' => '');

        $amount = $this->money(isset($share->amount_credit) ? $share->amount_credit : 0);
        if ($amount === 0) {
            $out['error'] = 'share-amount-is-zero';
            return $out;
        }

        $companyId  = (int) (isset($share->company_id) ? $share->company_id : 0);
        $concession = $this->isConcession($companyId);

        $helperId = $this->intOrNull($this->client->cfg(
            $concession ? 'rilven_financing_helper_concession' : 'rilven_financing_helper_share', NULL));
        if ($helperId === NULL) {
            $out['error'] = 'financing-posting-rule-not-configured';
            return $out;
        }

        $item = array(
            'code'                => $this->shareCode($share),
            'transactionHelperId' => $helperId,
            'creditTableRowId'    => $patientBranchId,
            'amount'              => $amount,
            'comment'             => $this->shareComment($share),
        );

        if ($concession) {
            // 8290 is an expense of the CLINIC, so the debit side is a company branch and not a
            // counterparty. Nobody owes this: the service was given away.
            $item['debitTableRowId'] = $this->intOrNull(
                $this->client->cfg('rilven_financing_company_branch_id', NULL));
        } else {
            $financierBranchId = $this->branchIdFor('insurer', $companyId);
            if ($financierBranchId === NULL) {
                $out['error'] = 'financier-not-synced-yet';
                return $out;
            }
            $item['debitTableRowId'] = $financierBranchId;
        }

        // Which service the money is about. Optional over there, and genuinely optional here:
        // a share may sit on the case rather than on one of its lines.
        $serviceId = $this->assetServiceIdFor($share);
        if ($serviceId !== NULL) {
            $item['assetServiceId'] = $serviceId;
        }

        $out['item'] = $item;
        return $out;
    }

    // -----------------------------------------------------------------------
    // sending
    // -----------------------------------------------------------------------

    /**
     * Create the settlement, or bring the existing one up to date.
     *
     * A POSTED settlement is not touched. Rilven freezes one on purpose -- its lines hold the ids
     * of the ledger entries they wrote -- and unposting it from here would take real postings out
     * of the books because a number changed in the CMS. That is a person's decision, so it is
     * reported and left.
     *
     * @param int $rilvenId the settlement id this case is already known by, 0 when it is new
     */
    public function push($row, $refs, $rilvenId = 0)
    {
        if (isset($row->patient_id_error) && $row->patient_id_error !== '') {
            return $this->rejected($row->patient_id_error, !empty($row->patient_id_retryable),
                                   strpos($row->patient_id_error, 'patient-not-synced-yet') === 0);
        }

        $mapped = $this->map($row, $refs);
        if ($mapped['error'] !== '') {
            // The three that clear themselves are dependencies, not faults: the register they
            // wait on runs before this one, so the next tick very likely has the answer. Counting
            // them towards the outage guard stopped the whole run after ten cases while the
            // patients were still catching up.
            $dependency = strpos($mapped['error'], '-not-synced-yet') !== FALSE
                       || strpos($mapped['error'], 'patient-branch-unknown') === 0;
            return $this->rejected($mapped['error'], $dependency, $dependency);
        }

        // What the outbox believes, checked against what Rilven actually holds. The lookup is
        // what makes the link rebuildable from either side and is the duplicate guard: found
        // means update, missing means create.
        $found = NULL;
        if ($rilvenId > 0) {
            $found = array('id' => $rilvenId, 'status' => 0);
        }
        $seen = $this->lookup($mapped['code']);
        if (!$seen['ok']) {
            return $this->rejected($seen['error'], $seen['retryable']);
        }
        if ($seen['id'] !== NULL) {
            $found = array('id' => $seen['id'], 'status' => $seen['status']);
        } elseif ($rilvenId > 0) {
            // The outbox names a document Rilven does not have. Creating a second one would be
            // the wrong repair; saying so is the right one.
            $found = NULL;
        }

        $payload = $mapped['document'];
        $payload['items'] = $mapped['items'];

        if ($found === NULL) {
            $answer = $this->client->post('/settlement/insert', $payload);
            if (!$answer['ok']) {
                return $this->rejected($answer['error'], $answer['retryable']);
            }
            $id = isset($answer['data']['id']) ? (int) $answer['data']['id'] : 0;
            return array('result' => 'created', 'id' => $id, 'error' => '', 'retryable' => FALSE,
                         'note' => '', 'noteRetryable' => FALSE, 'dependency' => FALSE,
                         'rilvenStatus' => 1);
        }

        if ((int) $found['status'] === 2) {
            // Posted. Reported, not forced: taking real entries out of the books because a
            // number moved in the CMS is a decision, and this is how it reaches a person.
            return $this->rejected('settlement-is-posted', FALSE);
        }

        $payload['id'] = (int) $found['id'];
        $answer = $this->client->put('/settlement/update', $payload);
        if (!$answer['ok']) {
            return $this->rejected($answer['error'], $answer['retryable']);
        }
        return array('result' => 'updated', 'id' => (int) $found['id'], 'error' => '',
                     'retryable' => FALSE, 'note' => '', 'noteRetryable' => FALSE,
                     'dependency' => FALSE, 'rilvenStatus' => 1);
    }

    /**
     * Confirm a settlement that is still a draft, for the period close.
     *
     * This register posts NOTHING by itself, by design: it writes the financier's share the
     * moment the case is accrued, when the contract is known, and the money behind it is not.
     * So every one of its documents is a draft until a close confirms it -- the 147 that exist
     * today are all drafts -- and the close is the only thing that turns Дт1410 / Кт1410 into an
     * entry.
     *
     * The order the close runs in is what makes this safe. The case's own accrual goes first, so
     * the receivable this document moves exists before it is moved.
     *
     * @return array ok, error, retryable
     */
    public function confirm($rilvenId)
    {
        return $this->confirmMany(array($rilvenId));
    }

    /**
     * Confirm SEVERAL documents in one call.
     *
     * The route takes `ids` as a list and always did; the close was sending them one at a time,
     * which for a year of documents is a quarter of a million requests and about eleven hours.
     *
     * A batch is ALL-OR-NOTHING on the far side: the handler is `@Transactional` and refuses the
     * whole call if one id is not loadable. So the caller must treat a failed batch as "unknown
     * which one" and fall back to sending them singly -- {@see Rilven::closePeriod}. Speed when
     * everything is well, precision when it is not.
     *
     * @param  array $rilvenIds
     * @return array ok, error, retryable
     */
    public function confirmMany($rilvenIds)
    {
        $ids = array();
        foreach ((array) $rilvenIds as $one) {
            if ((int) $one > 0) {
                $ids[] = (int) $one;
            }
        }
        if (empty($ids)) {
            return array('ok' => FALSE, 'error' => 'no-document', 'retryable' => FALSE);
        }

        $answer = $this->client->put('/settlement/update-status', array('ids' => $ids, 'status' => 2));

        if (!$answer['ok']) {
            return array('ok' => FALSE, 'error' => $answer['error'],
                         'retryable' => $answer['retryable']);
        }
        return array('ok' => TRUE, 'error' => '', 'retryable' => FALSE);
    }

    private function rejected($error, $retryable, $dependency = FALSE)
    {
        return array('result' => 'rejected', 'id' => 0, 'error' => $error,
                     'retryable' => (bool) $retryable, 'note' => '', 'noteRetryable' => FALSE,
                     'dependency' => (bool) $dependency);
    }

    // -----------------------------------------------------------------------
    // reading what the other registers already recorded
    // -----------------------------------------------------------------------

    /** Rilven's id for a row this library has already sent, out of our own outbox. */
    private function rilvenIdFor($entity, $sourceId)
    {
        $row = $this->CI->db->select('rilven_id')
            ->from('rilven_outbox')
            ->where('entity', $entity)
            ->where('external_id', (string) $sourceId)
            ->get()->row();
        return ($row && (int) $row->rilven_id > 0) ? (int) $row->rilven_id : NULL;
    }

    /** The same, for the BRANCH — which is what a posting actually names. */
    private function branchIdFor($entity, $sourceId)
    {
        $row = $this->CI->db->select('rilven_branch_id')
            ->from('rilven_outbox')
            ->where('entity', $entity)
            ->where('external_id', (string) $sourceId)
            ->get()->row();
        return ($row && (int) $row->rilven_branch_id > 0) ? (int) $row->rilven_branch_id : NULL;
    }

    /**
     * The Rilven service this share is about, if it can be told.
     *
     * The share names a case LINE; the line names the clinic's product; the service register
     * recorded what Rilven called it. Any link missing simply means no service on the settlement
     * line, which the document allows.
     */
    private function assetServiceIdFor($share)
    {
        if (empty($share->sale_item_id)) {
            return NULL;
        }
        $table = (string) $this->client->cfg('rilven_sale_item_table', 'sale_items');
        $item = $this->CI->db->select('product_id')->from($table)
            ->where('id', (int) $share->sale_item_id)->get()->row();
        if (!$item || (int) $item->product_id <= 0) {
            return NULL;
        }
        return $this->rilvenIdFor('service', (int) $item->product_id);
    }

    // -----------------------------------------------------------------------
    // small things
    // -----------------------------------------------------------------------

    /**
     * A line's code: the SERVICE and the FINANCIER, never the payment's own id.
     *
     * Sales_model deletes every accruing row of a case and re-inserts them on each edit, so
     * `sma_payments.id` is a different number after every save. Keyed on it, each line would
     * read as a new line for ever: the old one deleted, a new one created, and the
     * transaction_id tying it to the ledger thrown away with it. `sale_item_id` survives -- the
     * delete of sale_items is commented out in that same model.
     *
     * A share with no service line at all falls back to the case, so the code is still stable
     * and still unique within the document.
     */
    private function shareCode($share)
    {
        $itemId = (int) (isset($share->sale_item_id) ? $share->sale_item_id : 0);
        $companyId = (int) (isset($share->company_id) ? $share->company_id : 0);
        if ($itemId > 0) {
            return $itemId . '-' . $companyId;
        }
        return 'case-' . (int) $share->sale_id . '-' . $companyId;
    }

    /** Whether this payer is the clinic paying for itself rather than somebody owing money. */
    private function isConcession($companyId)
    {
        $ids = $this->client->cfg('rilven_financing_concession_ids', array());
        if (!is_array($ids)) {
            return FALSE;
        }
        foreach ($ids as $id) {
            if ((int) $id === (int) $companyId) {
                return TRUE;
            }
        }
        return FALSE;
    }

    /**
     * The settlement's date: the case's own, converted to UTC.
     *
     * The same instant the case document carries, so the two land in one period. Rilven stores
     * UTC and its console adds the company's offset, so a bare local string arrives four hours
     * into the future — see Rilven_sale::instant(), which this mirrors.
     */
    private function instant($row)
    {
        // `posting_date` first: the date the case's ACCRUAL carries, which is the latest
        // post_date among its service lines and not the day the case was opened. This register
        // used to date by the case, so a case opened in February whose work was done in May put
        // its accrual in May and its share of that accrual in February -- dividing a receivable
        // that did not exist yet, in a month already closed. The orchestrator attaches it; the
        // case date remains the fallback, exactly as it is for the accrual itself.
        $column = (string) $this->client->cfg('rilven_sale_date_column', 'date');
        $raw = isset($row->posting_date) ? trim((string) $row->posting_date) : '';
        if ($raw === '' || strpos($raw, '0000-00-00') === 0) {
            $raw = isset($row->$column) ? trim((string) $row->$column) : '';
        }
        if ($raw === '' || strpos($raw, '0000-00-00') === 0) {
            return NULL;
        }
        $stamp = strtotime($raw);
        if ($stamp === FALSE) {
            return NULL;
        }
        $local = date('Y-m-d H:i:s', $stamp);

        $zone = trim((string) $this->client->cfg('rilven_source_timezone', ''));
        if ($zone === '') {
            return $local;
        }
        try {
            $dt = new DateTime($local, new DateTimeZone($zone));
            $dt->setTimezone(new DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            log_message('error', 'rilven: financing cannot convert ' . $local . ': ' . $e->getMessage());
            return $local;
        }
    }

    private function comment($row)
    {
        $parts = array('CMS case ' . $row->id);
        $reference = isset($row->reference_no) ? trim((string) $row->reference_no) : '';
        if ($reference !== '') {
            array_unshift($parts, $reference);
        }
        return implode(' / ', $parts);
    }

    private function shareComment($share)
    {
        $name = isset($share->financier_name) ? trim((string) $share->financier_name) : '';
        return $name !== '' ? $name : ('payment ' . $share->id);
    }

    private function money($value)
    {
        return (int) round(((float) $value) * 10000);
    }

    private function intOrNull($value)
    {
        if ($value === NULL || $value === '' || (int) $value <= 0) {
            return NULL;
        }
        return (int) $value;
    }
}
