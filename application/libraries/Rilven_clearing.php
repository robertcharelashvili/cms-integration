<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * The patient's payment meeting the patient's debt.
 *
 * Every payment the clinic takes lands on 3120, advances received -- Дт1110 from the till,
 * Дт1210 from the bank -- because the money arrives before anything is performed and posting it
 * straight to 1410 would leave the receivable in credit. Correct, and incomplete: nothing ever
 * brought the two together. Measured 2026-09-21, no rule in this chart posted 3120/1410 at all,
 * so a patient who had paid in full still showed a full receivable with the advance sitting
 * beside it.
 *
 * This register closes them, as the clinic's auditor asked: one settlement per case, one line
 * per payment, Дт3120 / Кт1410 -- BOTH SIDES THE SAME PATIENT. Their own advance against their
 * own debt.
 *
 * ITS OWN DOCUMENT, not extra lines on the financier settlement. The shares are known when the
 * case is accrued; payments arrive later and go on arriving. Sharing one document would mean
 * re-opening a posted one every time somebody paid, and a posted settlement is frozen on
 * purpose -- its lines hold the ids of real ledger entries.
 *
 * THE LINE KEY IS THE PAYMENT'S OWN ID, and here that is safe. Sales_model deletes and
 * re-inserts accruing rows on every edit, which is why the financier shares cannot be keyed that
 * way -- but it deletes only `type = 'accruing'`. A `received` row survives, so its id is stable.
 *
 * The patient comes from the CASE, never from the payment: sma_payments.customer_id is filled on
 * fewer than half of them.
 */
class Rilven_clearing
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
        return 'clearing';
    }

    /** The outbox `type_code`. Informational; it is what somebody reading the queue sees. */
    public function typeCode()
    {
        return 'advance-settled';
    }

    /**
     * Whether the advance-settles-debt entries are sent at all.
     *
     * FALSE by default and separate from every other switch. This one closes balances, and a
     * mistake here reads as a patient who has paid when they have not.
     */
    public function enabled()
    {
        return $this->client->cfg('rilven_clearing_enabled', FALSE) ? TRUE : FALSE;
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
        $code = trim((string) $this->client->cfg('rilven_clearing_code_prefix', '')) . trim((string) $sourceId);
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
            'companyBranchId'   => $this->intOrNull($this->client->cfg('rilven_clearing_company_branch_id', NULL)),
            'currencyId'        => $this->intOrNull($this->client->cfg('rilven_clearing_currency_id', NULL)),
            'helper'            => $this->intOrNull($this->client->cfg('rilven_clearing_helper', NULL)),
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
     */
    public function missingReferences($refs)
    {
        $missing = array();
        if ($refs['companyBranchId'] === NULL) { $missing[] = 'companyBranchId'; }
        if ($refs['currencyId'] === NULL)      { $missing[] = 'currencyId'; }
        if ($refs['helper'] === NULL)          { $missing[] = 'clearingHelper'; }
        return $missing;
    }

    /**
     * The case no longer has a financier share: take the settlement away.
     *
     * Called by the orchestrator when the source row has gone out of scope, which for this
     * register means every payment was removed or reversed. A settlement that clears nothing is
     * not harmless -- it is a document saying a debt was paid, sitting in the register for
     * somebody to post.
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
                         'error' => 'payments-gone-but-settlement-is-posted: ' . $rilvenId);
        }
        return array('ok' => FALSE, 'error' => $answer['error'], 'retryable' => $answer['retryable']);
    }

    // -----------------------------------------------------------------------
    // the mapping
    // -----------------------------------------------------------------------

    /**
     * One case's payments as the settlement route wants them.
     *
     * Returns 'error' filled in when the case cannot be sent at all. Most of those are NOT
     * faults: a case whose patient has not reached Rilven yet is refused and stays in the queue,
     * because the answer is for the other register to catch up, not for this one to try harder.
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

        if (empty($row->payments)) {
            // Nobody has paid yet. Not a fault -- the orchestrator does not queue a case until
            // there is a payment to settle.
            $out['error'] = 'case-has-no-payment';
            return $out;
        }

        // The patient. BOTH sides of every line: their own advance against their own debt.
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

        $branchId   = $this->intOrNull($this->client->cfg('rilven_clearing_company_branch_id', NULL));
        $currencyId = $this->intOrNull($this->client->cfg('rilven_clearing_currency_id', NULL));
        if ($branchId === NULL || $currencyId === NULL) {
            $out['error'] = 'financing-branch-or-currency-not-configured';
            return $out;
        }

        $items = array();
        foreach ($row->payments as $payment) {
            $line = $this->line($payment, $patientBranchId);
            if ($line['error'] !== '') {
                // One unusable payment refuses the WHOLE document, for the reason the financier
                // register gives: carrying some of what was paid and not the rest leaves the
                // receivable wrong by exactly the part left out, and nothing says so.
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
     * One payment as a settlement line: the advance meeting the debt.
     *
     * Both sides are the same counterparty, which looks odd written down and is exactly right:
     * 3120 holds what this patient handed over and 1410 what they owe, and this is the entry
     * that lets the two cancel.
     */
    private function line($payment, $patientBranchId)
    {
        $out = array('item' => array(), 'error' => '');

        $amount = $this->money(isset($payment->amount) ? $payment->amount : 0);
        if ($amount <= 0) {
            // Refunds are not settled here. A negative row is a different event, and posting it
            // through this rule would credit a receivable that was never charged.
            $out['error'] = 'payment-amount-is-not-positive';
            return $out;
        }

        $helperId = $this->intOrNull($this->client->cfg('rilven_clearing_helper', NULL));
        if ($helperId === NULL) {
            $out['error'] = 'clearing-posting-rule-not-configured';
            return $out;
        }

        // WHOSE advance this is. Taken from the payment, with the case's patient only as a
        // fallback: at this clinic the two always agree -- 115 359 payments since 2025, the
        // payer filled every time, a patient every time, the case's own every time -- but
        // agreeing today is not the same as being the same field.
        //
        // AND THE PAYER IS NOT ALWAYS A PATIENT. At Todua a financier pays through this path and
        // sits on the payment as an insurance id. A financier lives in the outbox under
        // `insurer`, not `contractor`, so looking in only one of them would answer
        // payer-not-synced-yet about a counterparty that is perfectly well synced.
        $branchId = $patientBranchId;
        $payerId = (int) (isset($payment->company_id) ? $payment->company_id : 0);
        if ($payerId > 0) {
            $payerBranchId = $this->payerBranchId($payerId);
            if ($payerBranchId === NULL) {
                $out['error'] = 'payer-not-synced-yet';
                return $out;
            }
            $branchId = $payerBranchId;
        }

        $item = array(
            'code'                => (string) $payment->id,
            'transactionHelperId' => $helperId,
            'debitTableRowId'     => $branchId,
            'creditTableRowId'    => $branchId,
            'amount'              => $amount,
            'comment'             => $this->paymentComment($payment),
        );

        $serviceId = $this->assetServiceIdFor($payment);
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
     * Like the financier register, this one posts nothing on its own. It is confirmed LAST of
     * the four legs: the advance it consumes is put on 3120 by the receipt, and the receivable
     * it closes is put on 1410 by the accrual, so both have to be entries and not drafts first.
     *
     * A refusal here is worth reading rather than retrying. The settlement ceiling means the
     * lines of one case may not settle more than that case's services cost, and the financier's
     * share has already taken part of that room. So `service-ceiling-exceeded` on this leg is
     * not a fault in the sync -- it is a patient who has paid more than their own share, and a
     * person has to decide what the excess is.
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

    /**
     * The branch of whoever paid, whichever register sent them.
     *
     * A patient is in the outbox under `contractor` and a financier under `insurer`, and both
     * can be the payer. The two never share a source id -- both key on sma_companies.id -- so
     * asking one and then the other cannot answer with the wrong counterparty, only with none.
     */
    private function payerBranchId($sourceId)
    {
        $branchId = $this->branchIdFor('contractor', $sourceId);
        if ($branchId !== NULL) {
            return $branchId;
        }
        return $this->branchIdFor('insurer', $sourceId);
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
     * The document's instant, in UTC.
     *
     * `posting_date` first: the date the case's ACCRUAL carries, which is the latest post_date
     * among its service lines and not the day the case was opened. A clearing settles against
     * that accrual, so it has to be dated with it -- otherwise the advance meets a receivable
     * that, in ledger time, is not there yet.
     */
    private function instant($row)
    {
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
            log_message('error', 'rilven: clearing cannot convert ' . $local . ': ' . $e->getMessage());
            return $local;
        }
    }

    /** What the settlement says about itself: the case it belongs to. */
    private function comment($row)
    {
        $parts = array('CMS case ' . $row->id);
        $reference = isset($row->reference_no) ? trim((string) $row->reference_no) : '';
        if ($reference !== '') {
            array_unshift($parts, $reference);
        }
        return implode(' / ', $parts);
    }

    /** What one line says: how the money came in, so the ledger line is readable. */
    private function paymentComment($payment)
    {
        $method = isset($payment->paid_by) ? trim((string) $payment->paid_by) : '';
        $id     = isset($payment->id) ? $payment->id : '?';
        return $method !== '' ? ($method . ' ' . $id) : ('payment ' . $id);
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
