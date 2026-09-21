<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * One treatment case of this clinic, as a SERVICE SALE document of Rilven.
 *
 * This is the register that finally carries money. The three before it -- patients, service
 * categories, services -- exist so that this one has something to point at.
 *
 *   /waybill   documentType 13 (SALE_SERVICE). One document per case, its lines the services
 *              performed within that case.
 *
 * **The posting is not written here, and must not be.** Rilven's own engine writes it when the
 * document is confirmed, and writes exactly what the specification asks for:
 *
 *     debit  = SLOT_AR_TRADE                   1410, against the patient's BRANCH
 *     credit = serviceItem.account_plan_id     6110, the account carried by the service itself
 *
 * That second line is why the service register had to get {@code accountPlanMapId} right: the
 * credit leg of every accrual is read off the service, not off this document. See
 * {@code WaybillService.insertServiceItemTransaction}.
 *
 * **Two calls, not one.** {@code /waybill/insert} creates a DRAFT (status 1) and posts nothing;
 * the accrual appears only when the status goes 1 → 2 through {@code /waybill/update-status}.
 * They are kept apart on purpose -- see {@code rilven_sale_post}. A draft can be looked at and
 * deleted with no trace in the books; a confirmed document cannot, and is read-only besides.
 *
 * **The link.** `code` = sma_sales.id, as everywhere else in this library. But unlike the
 * reference registers, {@code /waybill/get} has NO {@code ?by=code}: there is no way to ask
 * Rilven "which document is this case of mine". So the mapping lives in the outbox's
 * {@code rilven_id}, and {@code /waybill/code/check} is the guard that keeps a lost mapping from
 * becoming a second document. If that column is ever lost the link cannot be rebuilt from either
 * side -- which is exactly what happened to 69 778 patients in September.
 */
class Rilven_sale
{
    /** @var CI_Controller */
    private $CI;

    /** @var Rilven_client */
    private $client;

    /** Resolved once per run and remembered. */
    private $refs = NULL;

    /** CMS product id => Rilven asset-service id, for this run only. */
    private $serviceIds = array();

    /** CMS warehouse id => Rilven warehouse id, for this run only. */
    private $warehouseIds = array();

    /** The posting cutoff date, read from the database once per run. FALSE when unreadable. */
    private $postCutoff = NULL;

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
        return 'sale';
    }

    public function typeCode()
    {
        return 'service-sale';
    }

    public function enabled()
    {
        return $this->client->cfg('rilven_sale_enabled', FALSE) ? TRUE : FALSE;
    }

    // -----------------------------------------------------------------------
    // the link
    // -----------------------------------------------------------------------

    public function code($sourceId)
    {
        $code = trim((string) $this->client->cfg('rilven_sale_code_prefix', '')) . trim((string) $sourceId);
        if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $code)) {
            return NULL;
        }
        return $code;
    }

    /**
     * Our document id for a case, or NULL when Rilven has never seen it.
     *
     * `/waybill/get/{code}?by=code` -- the same question every reference register answers, which
     * a document could not answer until 2026-09-19. Without it the outbox's `rilven_id` was the
     * ONLY record of which document belonged to which case, and clearing that table orphaned
     * twenty-six of them: they existed, their code said which case they were, and nothing could
     * ask. This is what makes the link rebuildable from either side, and it is the duplicate
     * guard too -- found means update, missing means create.
     *
     * @return array ok, id (NULL when absent), error, retryable
     */
    public function lookup($code)
    {
        $answer = $this->client->get('/waybill/get/' . rawurlencode($code), array('by' => 'code'));

        if ($answer['ok']) {
            $item = isset($answer['data']['item']) ? $answer['data']['item'] : array();
            $id = isset($item['id']) ? (int) $item['id'] : 0;
            return array('ok' => TRUE, 'id' => $id > 0 ? $id : NULL,
                         'lines' => $this->lineIndexOf($item), 'error' => '', 'retryable' => FALSE);
        }

        if (strpos($answer['error'], '[code]-not-found') === 0) {
            // Not a failure: this is the answer that means "create it".
            return array('ok' => TRUE, 'id' => NULL, 'lines' => NULL,
                         'error' => '', 'retryable' => FALSE);
        }

        return array('ok' => FALSE, 'id' => NULL, 'lines' => NULL,
                     'error' => $answer['error'], 'retryable' => $answer['retryable']);
    }

    /** The ids of the service lines a fetched document currently carries. */
    /**
     * The document's lines, indexed by the code this library put on them.
     *
     * `code` on a service line is the clinic's own `sma_sale_items.id`, so it is the one thing
     * that survives a round trip and identifies the same performed service on both sides. With
     * it an edit can UPDATE the line that is already there instead of deleting every line and
     * writing them all again -- which is what this did, and what made a one-word price change
     * read in the activity timeline as "every line destroyed and recreated".
     *
     * `orphans` are lines carrying no code we can match: ones a person added by hand on the
     * screen, or ones written before the code was being sent. They cannot be recognised, so on an
     * update they are the ones that go.
     *
     * @return array {byCode: array<string,int>, orphans: int[], ids: int[]}
     */
    private function lineIndexOf($item)
    {
        $byCode = array(); $orphans = array(); $ids = array();
        $lines = isset($item['serviceItems']) && is_array($item['serviceItems'])
            ? $item['serviceItems'] : array();
        foreach ($lines as $line) {
            if (!isset($line['id']) || (int) $line['id'] <= 0) {
                continue;
            }
            $id = (int) $line['id'];
            $ids[] = $id;
            $code = isset($line['code']) ? trim((string) $line['code']) : '';
            if ($code === '' || isset($byCode[$code])) {
                // No code, or a code already claimed by an earlier line. Either way this one
                // cannot be matched to exactly one incoming line, and guessing would silently
                // overwrite the wrong service.
                $orphans[] = $id;
                continue;
            }
            $byCode[$code] = $id;
        }
        return array('byCode' => $byCode, 'orphans' => $orphans, 'ids' => $ids);
    }

    /** The document as it stands, by OUR id, for the lines it carries. */
    private function existingLines($rilvenId)
    {
        $answer = $this->client->get('/waybill/get/' . (int) $rilvenId);
        if (!$answer['ok']) {
            return array('ok' => FALSE, 'lines' => NULL,
                         'error' => $answer['error'], 'retryable' => $answer['retryable']);
        }
        $item = isset($answer['data']['item']) ? $answer['data']['item'] : array();
        return array('ok' => TRUE, 'lines' => $this->lineIndexOf($item), 'error' => '', 'retryable' => FALSE);
    }

    /**
     * Take the document's service lines away, so the ones being sent replace them.
     *
     * /waybill/update NEVER removes a line the payload leaves out -- removal is its own endpoint,
     * which the screen calls. So an update that sends lines carrying no id (and this library's
     * lines carry none: a CMS line id is not stable, the clinic deletes and reinserts them on
     * every edit) is read as "add all of these" and the document ends with both sets. Twice the
     * services, twice the revenue.
     *
     * Replacing is the honest shape here: the source replaces its own lines, so the copy does too.
     */
    private function clearLines($rilvenId, $ids)
    {
        if (empty($ids)) {
            return array('ok' => TRUE, 'error' => '', 'retryable' => FALSE);
        }
        $answer = $this->client->request('DELETE', '/waybill/delete-waybill-service-item', array(
            'waybillId' => (int) $rilvenId,
            'ids'       => array_values($ids),
        ));
        if (!$answer['ok']) {
            return array('ok' => FALSE, 'error' => 'clear-lines: ' . $answer['error'],
                         'retryable' => $answer['retryable']);
        }
        return array('ok' => TRUE, 'error' => '', 'retryable' => FALSE);
    }

    /**
     * Rilven's id for one of our services, by the CMS product id we sent as its code.
     *
     * Asked once per service per run: a day's cases name the same few hundred services over and
     * over, and this would otherwise be one request per line.
     *
     * @return array ok, id (NULL when the service has not been synced), error, retryable
     */
    public function assetServiceId($sourceProductId)
    {
        $key = (string) $sourceProductId;
        if ($key === '' || (int) $sourceProductId <= 0) {
            return array('ok' => TRUE, 'id' => NULL, 'error' => '', 'retryable' => FALSE);
        }
        if (array_key_exists($key, $this->serviceIds)) {
            return array('ok' => TRUE, 'id' => $this->serviceIds[$key], 'error' => '', 'retryable' => FALSE);
        }

        $code = trim((string) $this->client->cfg('rilven_service_code_prefix', '')) . $key;
        $answer = $this->client->get('/asset-service/get/' . rawurlencode($code), array('by' => 'code'));

        if ($answer['ok']) {
            $item = isset($answer['data']['item']) ? $answer['data']['item'] : array();
            $id = isset($item['id']) ? (int) $item['id'] : 0;
            $this->serviceIds[$key] = $id > 0 ? $id : NULL;
            return array('ok' => TRUE, 'id' => $this->serviceIds[$key], 'error' => '', 'retryable' => FALSE);
        }

        if (strpos($answer['error'], '[code]-not-found') === 0) {
            $this->serviceIds[$key] = NULL;
            return array('ok' => TRUE, 'id' => NULL, 'error' => '', 'retryable' => FALSE);
        }

        return array('ok' => FALSE, 'id' => NULL,
                     'error' => $answer['error'], 'retryable' => $answer['retryable']);
    }

    /** Forget the resolved services, so the next run asks again. */
    public function forgetServices()
    {
        $this->serviceIds = array();
        $this->warehouseIds = array();
    }

    /**
     * Rilven's id for the warehouse a case was treated in, by the CMS id we sent as its code.
     *
     * The warehouses are already synced and carry the CMS id as their `code`, so the document
     * names the place the work actually happened rather than one warehouse chosen for all of
     * them. Fifty-five of them, a few dozen cases per run, so it is cached like the services.
     *
     * @return array ok, id (NULL when that warehouse is not synced), error, retryable
     */
    public function warehouseId($sourceWarehouseId)
    {
        $key = (string) $sourceWarehouseId;
        if ($key === '' || (int) $sourceWarehouseId <= 0) {
            return array('ok' => TRUE, 'id' => NULL, 'error' => '', 'retryable' => FALSE);
        }
        if (array_key_exists($key, $this->warehouseIds)) {
            return array('ok' => TRUE, 'id' => $this->warehouseIds[$key], 'error' => '', 'retryable' => FALSE);
        }

        $code = trim((string) $this->client->cfg('rilven_warehouse_code_prefix', '')) . $key;
        $answer = $this->client->get('/warehouse/get/' . rawurlencode($code), array('by' => 'code'));

        if ($answer['ok']) {
            $item = isset($answer['data']['item']) ? $answer['data']['item'] : array();
            $id = isset($item['id']) ? (int) $item['id'] : 0;
            $this->warehouseIds[$key] = $id > 0 ? $id : NULL;
            return array('ok' => TRUE, 'id' => $this->warehouseIds[$key], 'error' => '', 'retryable' => FALSE);
        }

        if (strpos($answer['error'], '[code]-not-found') === 0) {
            $this->warehouseIds[$key] = NULL;
            return array('ok' => TRUE, 'id' => NULL, 'error' => '', 'retryable' => FALSE);
        }

        return array('ok' => FALSE, 'id' => NULL,
                     'error' => $answer['error'], 'retryable' => $answer['retryable']);
    }

    // -----------------------------------------------------------------------
    // the mapping
    // -----------------------------------------------------------------------

    /**
     * A case, with its items and its patient's branch already attached by {@see Rilven::saleRow},
     * as {@code /waybill/insert} wants it.
     *
     * Everything that makes the row unsendable is decided here and reported as a refusal of the
     * source row: no lines, no patient, a patient Rilven has never been told about. None of those
     * is worth retrying, with one exception -- see {@code patient-not-synced-yet} in {@see push}.
     */
    public function map($row, $refs)
    {
        $out = array('code' => NULL, 'waybill' => array(), 'lines' => array(),
                     'sourceWarehouseId' => NULL, 'post' => FALSE, 'error' => '');

        $sourceId = isset($row->id) ? $row->id : NULL;
        $code = $this->code($sourceId);
        if ($code === NULL) {
            $out['error'] = 'code-format-is-invalid: '
                . $this->client->cfg('rilven_sale_code_prefix', '') . $sourceId;
            return $out;
        }
        $out['code'] = $code;

        $items = isset($row->items) && is_array($row->items) ? $row->items : array();
        if (empty($items)) {
            // Every line was a subservice, or the case has none at all. Not an error worth
            // retrying: there is nothing to accrue.
            $out['error'] = 'case-has-no-service-lines';
            return $out;
        }

        if (!isset($row->rilven_contractor_branch_id) || (int) $row->rilven_contractor_branch_id <= 0) {
            // The 1410 leg is keyed on the patient's BRANCH and the engine reads it unboxed, so a
            // missing one is not a blank field over there -- it is a failed posting.
            $out['error'] = 'patient-branch-unknown';
            return $out;
        }

        $beginDate = $this->accrualDate($row);
        if ($beginDate === NULL) {
            $out['error'] = 'case-has-no-usable-date';
            return $out;
        }

        $lines = array();
        foreach ($items as $item) {
            $line = $this->line($item);
            if ($line['error'] !== '') {
                $out['error'] = $line['error'] . ' (line ' . (isset($item->id) ? $item->id : '?') . ')';
                return $out;
            }
            $lines[] = $line;
        }
        $out['lines'] = $lines;

        $waybill = array(
            'code'              => $code,
            'documentType'      => (int) $this->client->cfg('rilven_sale_document_type', 13),
            'beginDate'         => $beginDate,
            'companyBranchId'   => $refs['companyBranchId'],
            // Mandatory for document type 13 and for nothing else. validateWaybill reads it
            // unboxed, so an absent one is not a refusal but a NullPointerException -- which the
            // product answers with system-error[0001.9999]. It must also belong to the branch
            // above; a bank account of another branch is refused by name.
            'companyBankAccountId' => $refs['companyBankAccountId'],
            // Filled in by push() once the case's own warehouse has been resolved. The
            // configured one is only the fallback, and only when it is set.
            'warehouseId'       => $refs['warehouseId'],
            'currencyId'        => $refs['currencyId'],
            'contractorId'      => (int) $row->rilven_contractor_id,
            'contractorBranchId'=> (int) $row->rilven_contractor_branch_id,
            'vat'               => $this->client->cfg('rilven_sale_vat', FALSE) ? TRUE : FALSE,
            'status'            => 1,
        );

        // The clinic's own number for the case. The register showed an empty column for every
        // document because nothing was ever sent here; `code` is the machine's link and this is
        // what a person recognises the case by.
        $reference = $this->val($row, 'reference_no');
        if ($reference !== '') {
            $waybill['waybillNumber'] = $this->clip($reference, 255);
        }

        // When the episode ended.
        //
        // For an inpatient case that is the discharge; for an outpatient one there is nothing to
        // span, so it is the accrual date itself. Note that the inpatient accrual is ALREADY dated
        // at discharge, so today these two agree -- if the intention was for the document to span
        // the stay, it is beginDate that should move to `indate`, and that changes which period
        // the revenue lands in.
        $dueDate = $this->isOutpatient($row)
            ? $beginDate
            : $this->instant($this->val($row, 'outdate'));
        if ($dueDate !== NULL) {
            $waybill['dueDate'] = $dueDate;
        }

        $comment = $this->comment($row);
        if ($comment !== '') {
            $waybill['comment'] = $this->clip($comment, 255);
        }

        $out['sourceWarehouseId'] = $this->sourceWarehouse($row);
        $out['post'] = $this->shouldPost($row);

        $out['waybill'] = $waybill;
        return $out;
    }

    /** The CMS warehouse the case was treated in. */
    private function sourceWarehouse($row)
    {
        $column = (string) $this->client->cfg('rilven_sale_warehouse_column', 'warehouse_id');
        if ($column === '' || !isset($row->$column) || $row->$column === NULL) {
            return NULL;
        }
        $value = (int) $row->$column;
        return $value > 0 ? $value : NULL;
    }

    /**
     * One performed service, as a line of the document.
     *
     * Money is scaled ×10000, which is how Rilven stores it. The CMS keeps these as
     * decimal(25,4), so the multiplication is exact and nothing is being rounded into existence.
     *
     * QUANTITY IS NOT SCALED. A waybill's quantity is a plain count -- it is the requisition
     * register that stores quantity ×10000, and mixing the two turned 20 units into 200 000 once
     * already. A fractional quantity therefore cannot be represented and is refused rather than
     * silently rounded: half a service billed as one is a real amount of money.
     */
    private function line($item)
    {
        $out = array('error' => '');

        $productId = isset($item->product_id) ? (int) $item->product_id : 0;
        if ($productId <= 0) {
            $out['error'] = 'line-has-no-product';
            return $out;
        }

        $quantity = isset($item->quantity) ? (float) $item->quantity : 0.0;
        if (abs($quantity - round($quantity)) > 0.0001) {
            $out['error'] = 'fractional-quantity: ' . $quantity;
            return $out;
        }
        $quantity = (int) round($quantity);
        if ($quantity === 0) {
            $out['error'] = 'line-has-zero-quantity';
            return $out;
        }

        $out['productId'] = $productId;
        $out['quantity']  = $quantity;

        // sma_sale_items.id -- the performed service, not the catalogue entry it names.
        //
        // The specification calls it service_instance_id and is firm that the two are different
        // things: "код услуги в справочнике не является идентификатором факта её оказания".
        // Without it a document line traces back only to WHICH service, never to WHICH
        // performance of it, and a case that billed the same test twice is two identical lines
        // with nothing to tell them apart.
        //
        // NOT a stable key. The clinic deletes and reinserts its lines on every edit, so the id
        // changes -- which is why this library replaces a document's lines rather than matching
        // them. It is a trace back to the row that produced this line at the time it was sent.
        $out['lineId'] = isset($item->id) && (int) $item->id > 0 ? (int) $item->id : NULL;

        // The room this one service was performed in. A case collects an X-ray from radiology, a
        // consultation from a doctor's room and a test from the laboratory, and this register says
        // so per line beside the case's own warehouse. NULL means "wherever the case was", which
        // is what Rilven understands a line without one to mean.
        $lineWarehouse = (string) $this->client->cfg('rilven_sale_item_warehouse_column', 'warehouse_id');
        $out['sourceWarehouseId'] = ($lineWarehouse !== '' && isset($item->$lineWarehouse)
                && (int) $item->$lineWarehouse > 0)
            ? (int) $item->$lineWarehouse
            : NULL;

        $out['unitPrice'] = $this->money(isset($item->unit_price) ? $item->unit_price : 0);
        $out['subtotal']  = $this->money(isset($item->subtotal) ? $item->subtotal : 0);

        // A NEGATIVE discount is dropped, because it is not a discount.
        //
        // Rilven computes `total = subtotal - discount`, so a negative one ADDS to the line. The
        // CMS does not: for these rows `sma_sales.grand_total` and the accrual both equal the
        // subtotal, so the CMS treats the field as a note and Rilven treats it as money. Passing
        // it through makes the document disagree with the case by exactly that amount -- found
        // on case 76810, where the ledger held 1,510.05 against services of 1,505.05.
        //
        // Four lines in four cases over 2025 onward, -190.02 between them. Logged rather than
        // noted on the row: a note withholds the payload hash so the row is sent again next
        // tick, and this condition never goes away by itself.
        $discount = $this->money(isset($item->item_discount) ? $item->item_discount : 0);
        if ($discount < 0) {
            log_message('error', 'rilven: item ' . (isset($item->id) ? $item->id : '?')
                . ' has a negative discount (' . $item->item_discount . '), dropped -- it would'
                . ' have raised the accrual above the case');
            $discount = 0;
        }
        $out['discount'] = $discount;
        return $out;
    }

    /** A decimal from the CMS as the integer Rilven stores: the value ×10000. */
    private function money($value)
    {
        return (int) round(((float) $value) * 10000);
    }

    /**
     * The date the accrual belongs to.
     *
     * The rule is the clinic's own, lifted from the query it already runs for this purpose:
     *
     *   an INPATIENT case (`service` > 2) is dated at ADMISSION, and runs to discharge in
     *   dueDate: the episode is one thing and the document spans it;
     *   an outpatient case accrues on the day the work was done.
     *
     * One document per case means one date, so an outpatient case whose lines span several days
     * takes the LAST of them: the accrual cannot predate the work, and the last line is the point
     * by which all of it had been performed.
     *
     * Each step falls back to the next, because a register of this age has blanks everywhere and
     * a case with no date at all is the only one worth refusing.
     */
    private function accrualDate($row)
    {
        $inpatient = isset($row->service) && (int) $row->service > 2;

        // An inpatient case is dated at ADMISSION and runs to discharge: the document spans the
        // stay, beginDate to dueDate, which is how the clinic describes it -- "the service began
        // at indate and ended at outdate".
        //
        // This decides which period the revenue lands in, and it is not the same answer as
        // "when was it finished". A stay that opened last month and closed this one now accrues
        // LAST month. That is the clinic's call, taken 2026-09-19; the alternative was to date it
        // at discharge, which is what this did before.
        $candidates = $inpatient
            ? array($this->val($row, 'indate'), $this->val($row, 'outdate'), $this->lastPostDate($row), $this->val($row, 'date'))
            : array($this->lastPostDate($row), $this->val($row, 'date'), $this->val($row, 'indate'));

        foreach ($candidates as $candidate) {
            $stamp = $this->instant($candidate);
            if ($stamp !== NULL) {
                return $stamp;
            }
        }
        return NULL;
    }

    /** The latest post_date among the case's lines. */
    private function lastPostDate($row)
    {
        $latest = '';
        foreach ((array) $row->items as $item) {
            $value = isset($item->post_date) ? trim((string) $item->post_date) : '';
            if ($value !== '' && strpos($value, '0000-00-00') !== 0 && $value > $latest) {
                $latest = $value;
            }
        }
        return $latest;
    }

    /**
     * A CMS date as the timestamp Rilven's binder accepts, or NULL for one that means nothing.
     *
     * "yyyy-MM-dd HH:mm:ss" and not the bare date: the field is a Timestamp over there, and both
     * forms bind, but a time of midnight said out loud is easier to recognise in a register than
     * a form the far side filled in for us.
     */
    private function timestamp($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '' || strpos($raw, '0000-00-00') === 0) {
            return NULL;
        }
        $stamp = strtotime($raw);
        if ($stamp === FALSE) {
            return NULL;
        }
        $formatted = date('Y-m-d', $stamp);
        if ($formatted < '1990-01-01' || $formatted > date('Y-m-d', strtotime('+1 day'))) {
            // A typo or a zero date that survived strtotime. An accrual dated 1970 or 2099 lands
            // in a period nobody is looking at, and in a closed one it cannot be undone.
            return NULL;
        }
        return date('Y-m-d H:i:s', $stamp);
    }

    /**
     * A CMS wall-clock time as the UTC instant Rilven stores.
     *
     * Rilven's backend is UTC throughout and the console converts on the way out, using the
     * company's legal offset. Georgia is +4, so a case recorded at 12:55 in the clinic and sent
     * as the bare string "12:55" was read as 12:55 UTC and shown back as 16:55 -- four hours
     * into the future, every time. Measured on case of 2026-09-21: CMS 12:55, Rilven 16:55.
     *
     * The zone is named in the config rather than taken from PHP, and that is the point. This
     * process does not agree with itself about what time it is: the cron's PHP runs in the
     * clinic's zone and an interactive one in UTC. A conversion that leaned on the ambient
     * setting would send a different instant depending on who started the script.
     *
     * Only INSTANTS come through here -- beginDate, dueDate. A calendar date must NOT be shifted:
     * a birthday is the same day in every zone, and moving it can cross a day boundary. That is
     * why {@see shortDate}, which writes a date into a comment for a person to read, still uses
     * {@see timestamp} and the clinic's own clock.
     */
    private function instant($raw)
    {
        $local = $this->timestamp($raw);
        if ($local === NULL) {
            return NULL;
        }

        $zone = trim((string) $this->client->cfg('rilven_source_timezone', ''));
        if ($zone === '') {
            // Not configured: send what the CMS holds, which is what this did before the key
            // existed. Wrong by the offset, but wrong in the way the installation already is,
            // rather than newly wrong in the other direction.
            return $local;
        }

        try {
            $dt = new DateTime($local, new DateTimeZone($zone));
            $dt->setTimezone(new DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            log_message('error', 'rilven: cannot convert ' . $local . ' from ' . $zone
                . ' to UTC: ' . $e->getMessage());
            return $local;
        }
    }

    /**
     * What kind of case this is, in the clinic's own words.
     *
     * `service` says it: 1 outpatient, 3 DRG inpatient, 4 inpatient -- and an outpatient case
     * flagged `gad_amb` is an EMERGENCY one, which is a different thing again and is why the
     * flag is tested before the plain label.
     */
    public function caseLabel($row)
    {
        $service = isset($row->service) ? (int) $row->service : 0;

        // The emergency flag hangs off service 4, NOT off the outpatient 1 -- which reads oddly,
        // since what it names is an outpatient kind. It is the clinic's register and this is what
        // it says, so the value is configured rather than assumed from the outpatient one.
        $emergencyService = $this->client->cfg('rilven_sale_emergency_service', NULL);
        $emergencyColumn  = (string) $this->client->cfg('rilven_sale_emergency_column', '');
        if ($emergencyService !== NULL && $emergencyColumn !== ''
                && $service === (int) $emergencyService
                && isset($row->$emergencyColumn) && (int) $row->$emergencyColumn === 1) {
            return (string) $this->client->cfg('rilven_sale_emergency_label', '');
        }

        $labels = $this->client->cfg('rilven_sale_case_types', array());
        return isset($labels[$service]) ? (string) $labels[$service] : '';
    }

    /** Whether this case is an outpatient one. */
    private function isOutpatient($row)
    {
        return isset($row->service)
            && (int) $row->service === (int) $this->client->cfg('rilven_sale_outpatient_service', 1);
    }

    /**
     * Whether the accrual is finished, and the document may therefore be CONFIRMED.
     *
     * The clinic's rule, and it differs by kind:
     *
     *   OUTPATIENT -- finished the moment it is recorded. The visit is over; there is nothing
     *   more to add to it, so the document is confirmed as it is created.
     *
     *   INPATIENT -- finished only when a person marks the case `completed`. Until then the
     *   episode is still running and its services are still being added, and an accrual posted
     *   mid-stay would have to be corrected by every line that came after it.
     *
     * {@code rilven_sale_post} sits above both as a master switch: FALSE confirms nothing at all,
     * whatever this says, which is what makes a first run reviewable.
     */
    public function shouldPost($row)
    {
        if (!$this->client->cfg('rilven_sale_post', FALSE)) {
            return FALSE;
        }
        if (!$this->hasRipened($row)) {
            return FALSE;
        }
        if ($this->isOutpatient($row)) {
            return $this->client->cfg('rilven_sale_post_outpatient', TRUE) ? TRUE : FALSE;
        }

        $column = (string) $this->client->cfg('rilven_sale_status_column', 'sale_status');
        $wanted = (string) $this->client->cfg('rilven_sale_completed_status', 'completed');
        if ($column === '' || $wanted === '' || !isset($row->$column)) {
            return FALSE;
        }
        return strcasecmp(trim((string) $row->$column), $wanted) === 0;
    }

    /**
     * Whether this case has stood still long enough to be closed.
     *
     * A posted document is frozen: the guard on the CMS side refuses an edit once Rilven reports
     * a status above draft. Post a case on the day of the visit and the doctor cannot finish
     * writing it. So an accrual waits -- `rilven_sale_post_after_days` days from the case date --
     * and until then the document goes on being a draft that both sides may still change.
     *
     * The age is counted from the case DATE, not from its last edit. From the last edit, a case
     * touched every day would never ripen at all; from the date, "everything older than two days
     * is closed" is a sentence the clinic can hold in its head, and it is what a daily close
     * means to the people doing it.
     *
     * Zero, the default, posts immediately -- what this library did before the key existed.
     */
    private function hasRipened($row)
    {
        $days = (int) $this->client->cfg('rilven_sale_post_after_days', 0);
        if ($days <= 0) {
            return TRUE;
        }

        $column = (string) $this->client->cfg('rilven_sale_date_column', 'date');
        if ($column === '' || !isset($row->$column) || $row->$column === NULL) {
            // No date to judge by. Left as a draft rather than posted on a guess: an accrual
            // written too early has to be reversed, one written late costs nothing but time.
            return FALSE;
        }

        $cutoff = $this->postCutoff($days);
        if ($cutoff === NULL) {
            return FALSE;
        }
        return substr((string) $row->$column, 0, 10) <= $cutoff;
    }

    /**
     * The newest case date that may be posted today, ON THE CLINIC'S CLOCK.
     *
     * This asked MySQL, and the reasoning was that the process does not agree with itself about
     * what time it is -- the cron's PHP runs in the clinic's zone and an interactive one in UTC
     * -- so both sides should come off one clock. The premise was right and the clock was
     * wrong. THIS SERVER IS SET TO America/New_York: MySQL was answering eight hours behind the
     * clinic, while every date in the schema is written by PHP in the clinic's zone. Consistent
     * and incorrect, which is the worse of the two failures because it looks settled.
     *
     * {@see Rilven_client::clinicNow} names the zone outright, so the boundary reads the same
     * whoever runs the script AND agrees with the data.
     *
     * Resolved once per run.
     */
    private function postCutoff($days)
    {
        if ($this->postCutoff !== NULL) {
            return $this->postCutoff === FALSE ? NULL : $this->postCutoff;
        }
        $this->postCutoff = $this->client->clinicToday('-' . (int) $days . ' days');
        return $this->postCutoff === FALSE ? NULL : $this->postCutoff;
    }

    /**
     * What the document says about itself, for somebody reading the register over there.
     *
     * The kind of case first, because that is what an accountant looking at a service sale wants
     * to know and nothing else on the document carries it. An inpatient case also gets its
     * episode -- admission to discharge -- since its single accrual date says only when the stay
     * ended, not that it lasted a fortnight.
     */
    private function comment($row)
    {
        $parts = array();

        $label = $this->caseLabel($row);
        if ($label !== '') {
            $parts[] = $label;
        }

        if (!$this->isOutpatient($row)) {
            $in  = $this->shortDate($this->val($row, 'indate'));
            $out = $this->shortDate($this->val($row, 'outdate'));
            if ($in !== '' || $out !== '') {
                $parts[] = ($in === '' ? '?' : $in) . ' - ' . ($out === '' ? '?' : $out);
            }
        }

        $reference = $this->val($row, 'reference_no');
        if ($reference !== '') {
            $parts[] = $reference;
        }
        $parts[] = 'CMS case ' . $row->id;
        return implode(' / ', $parts);
    }

    /** A date as a reader wants it in a comment, or nothing at all. */
    private function shortDate($raw)
    {
        $stamp = $this->timestamp($raw);
        return $stamp === NULL ? '' : substr($stamp, 0, 10);
    }

    /** What is compared against the outbox's payload hash. */
    public function hash($mapped)
    {
        // `post` is in here on purpose. An inpatient case that a person has just marked
        // `completed` is otherwise byte-for-byte what was sent before, so the run would read it
        // as unchanged and the accrual would never be written.
        return hash('sha256', json_encode(array($mapped['waybill'], $mapped['lines'],
                                                $mapped['post'])));
    }

    // -----------------------------------------------------------------------
    // sending
    // -----------------------------------------------------------------------

    /**
     * Send a document, and disambiguate its number if that number is already taken.
     *
     * The clinic's `reference_no` is not unique -- two cases share one out of the three hundred
     * seen -- and Rilven requires it to be. Appending the case id up front would make every
     * number uglier to fix one; so the plain number goes first, and only a refusal of
     * `duplicate-waybill-number` makes it "SL-123 / 5001", which is still the number a person
     * recognises with the thing that separates it.
     *
     * ONCE. A second refusal is not about the number.
     */
    private function sendDocument($method, $path, $payload, $caseId)
    {
        $answer = $this->client->request($method, $path, $payload);

        if (!$answer['ok'] && strpos($answer['error'], 'duplicate-waybill-number') !== FALSE
                && isset($payload['waybillNumber']) && $payload['waybillNumber'] !== '') {
            $payload['waybillNumber'] = $this->clip(
                $payload['waybillNumber'] . ' / ' . $caseId, 255);
            $answer = $this->client->request($method, $path, $payload);
        }

        return $answer;
    }

    /**
     * Put one case into Rilven.
     *
     * @param object $row      the case, with ->items and the patient's ids attached
     * @param array  $refs     the resolved reference ids
     * @param int    $rilvenId the document id this case is already known by, 0 when it is new
     */
    public function push($row, $refs, $rilvenId = 0)
    {
        // Why the patient's ids could not be found, said in the resolver's own words rather than
        // flattened into "unknown". `patient-not-synced-yet` clears itself on the next tick;
        // `[permission]-denied` on the branch route never will, and the difference is whether
        // anybody has to do something.
        if (isset($row->patient_id_error) && $row->patient_id_error !== '') {
            return $this->rejected($row->patient_id_error, !empty($row->patient_id_retryable),
                                   strpos($row->patient_id_error, 'patient-not-synced-yet') === 0);
        }

        $mapped = $this->map($row, $refs);
        if ($mapped['error'] !== '') {
            return $this->rejected($mapped['error'], FALSE);
        }

        // Everything worth saying about a document that landed anyway: a line whose room is not
        // synced, a case whose warehouse is not. Declared HERE, above the loop that first writes
        // to it -- it was declared below, which quietly erased whatever the lines had said.
        $note = '';

        // The services, resolved to their ids over there. A service the reference does not hold
        // yet is RETRYABLE: the service register runs before this one, so the next tick very
        // likely has it. Sending the line without it is not an option -- the credit account of
        // the accrual is read off the service.
        $serviceItems = array();
        foreach ($mapped['lines'] as $line) {
            $found = $this->assetServiceId($line['productId']);
            if (!$found['ok']) {
                return $this->rejected($found['error'], $found['retryable']);
            }
            if ($found['id'] === NULL) {
                return $this->rejected('service-not-synced-yet: ' . $line['productId'], TRUE, TRUE);
            }
            // Its own room, when the line names one and that warehouse is synced. A line whose
            // warehouse is unknown over there simply takes the document's -- the same thing it
            // would have meant before lines could carry one, and better than refusing a whole
            // case over the room a single test was run in.
            $lineWarehouseId = NULL;
            if ($line['sourceWarehouseId'] !== NULL
                    && $line['sourceWarehouseId'] !== $mapped['sourceWarehouseId']) {
                $found2 = $this->warehouseId($line['sourceWarehouseId']);
                if (!$found2['ok']) {
                    return $this->rejected($found2['error'], $found2['retryable']);
                }
                $lineWarehouseId = $found2['id'];
                if ($lineWarehouseId === NULL) {
                    $note = trim($note . ' line-warehouse-not-synced: ' . $line['sourceWarehouseId']);
                }
            }

            $serviceItems[] = array(
                'assetServiceId' => (int) $found['id'],
                'quantity'       => $line['quantity'],
                'unitPrice'      => $line['unitPrice'],
                'subtotal'       => $line['subtotal'],
                'discount'       => $line['discount'],
                'vatType'        => $refs['vatType'],
                // ZERO, and it has to be said rather than left out.
                //
                // prepareAssetServiceItem sets tax ONLY inside `if (isVat && vatType == 1)`, so a
                // service outside VAT never gets one -- and the line after the loop does
                // `totalServiceTax += service.getTax()`, which unboxes it. Absent means a
                // NullPointerException, answered as system-error[0001.9999]. The screen does not
                // send it either, so the same document built by hand crashes the same way.
                //
                // Nothing overwrites a value that is already there, and a line outside VAT really
                // does carry no tax, so saying 0 is both true and sufficient.
                'tax'            => 0,
            );
            if ($line['lineId'] !== NULL) {
                $serviceItems[count($serviceItems) - 1]['code'] = (string) $line['lineId'];
            }
            if ($lineWarehouseId !== NULL) {
                $serviceItems[count($serviceItems) - 1]['warehouseId'] = (int) $lineWarehouseId;
            }
        }

        // The warehouse the case was treated in. Fifty-five of them are synced and carry the CMS
        // id as their code, so the document names the real place rather than one stand-in for
        // everything -- which is what somebody reading the register would otherwise see.
        if ($mapped['sourceWarehouseId'] !== NULL) {
            $warehouse = $this->warehouseId($mapped['sourceWarehouseId']);
            if (!$warehouse['ok']) {
                return $this->rejected($warehouse['error'], $warehouse['retryable']);
            }
            if ($warehouse['id'] !== NULL) {
                $mapped['waybill']['warehouseId'] = (int) $warehouse['id'];
            } elseif ($refs['warehouseId'] !== NULL) {
                // Said out loud rather than silently defaulted: a document on the wrong warehouse
                // looks exactly like a document on the right one.
                $note = trim($note . ' warehouse-not-synced-fell-back: ' . $mapped['sourceWarehouseId']);
            } else {
                return $this->rejected('warehouse-not-synced: ' . $mapped['sourceWarehouseId']
                    . ' -- and rilven_sale_warehouse_id is not set as a fallback', TRUE, TRUE);
            }
        } elseif ($refs['warehouseId'] === NULL) {
            return $this->rejected('case-has-no-warehouse', FALSE);
        }

        $payload = $mapped['waybill'];
        $payload['serviceItems'] = $serviceItems;

        // BOTH of these travel as empty arrays and neither may be omitted.
        //
        // WaybillController.insert does `dto.items().isEmpty()` on a list it never null-guards,
        // and `.isEmpty()` is evaluated BEFORE the `&& documentType != 13` that would have
        // excused it -- so a body without `items` is a NullPointerException, which the product
        // answers with system-error[0001.9999] and a stack trace. The screen never finds this
        // because Angular always sends `items: []`.
        //
        // A document of services carries no goods and no extra costs. Saying that with an empty
        // list is the same thing the screen says, and it is the caller's business to say it.
        $payload['items'] = array();
        $payload['additionalCosts'] = array();

        if ((int) $rilvenId > 0) {
            return $this->update($payload, (int) $rilvenId, $note, $mapped['post']);
        }

        // The outbox does not know an id, which is not the same as the case being new: the table
        // may simply have been cleared. Ask Rilven by the case's own code before creating
        // anything -- a second document would recognise the revenue twice.
        $found = $this->lookup($mapped['code']);
        if (!$found['ok']) {
            return $this->rejected($found['error'], $found['retryable']);
        }
        if ($found['id'] !== NULL) {
            // It is there and we had lost it. Update it, and the id travels back so the outbox
            // records it and never has to ask again. The lines it carries came with the lookup,
            // so they need not be fetched twice.
            return $this->update($payload, (int) $found['id'], $note, $mapped['post'],
                                 $found['lines']);
        }

        $answer = $this->sendDocument('POST', '/waybill/insert', $payload, $mapped['code']);
        if (!$answer['ok']) {
            return $this->rejected($answer['error'], $answer['retryable']);
        }
        $id = isset($answer['data']['id']) ? (int) $answer['data']['id'] : 0;

        $result = array('result' => 'created', 'id' => $id, 'error' => '', 'retryable' => FALSE,
                        'note' => $note, 'noteRetryable' => FALSE);

        return $this->maybePost($result, $mapped['post']);
    }

    /**
     * Bring an existing document up to date.
     *
     * Only a DRAFT can be changed. A confirmed document is read-only over there and its accrual is
     * already in the ledger, so an edited case cannot simply be overwritten -- somebody has to
     * decide whether the difference is a correction or a new fact. That is said plainly rather
     * than retried, because no number of attempts will change it.
     */
    private function update($payload, $rilvenId, $note = '', $post = FALSE, $knownLines = NULL)
    {
        $payload['id'] = $rilvenId;

        // What is on the document now, indexed by the code we put there. Comes free with a
        // lookup; otherwise the document has to be read for it.
        $index = $knownLines;
        if ($index === NULL) {
            $existing = $this->existingLines($rilvenId);
            if (!$existing['ok']) {
                return $this->rejected($existing['error'], $existing['retryable']);
            }
            $index = $existing['lines'];
        }

        // Match line to line by code, and delete only what really went.
        //
        // Every line used to be deleted and written again on every update, because `code` was the
        // only handle and nothing used it. The cost was not the requests: it was that the version
        // history of a case became unreadable -- correcting one price destroyed and recreated
        // every service on the document -- and that line ids changed under anything holding one.
        //
        // A line the payload still carries keeps its id and is UPDATED. Rilven costs it again
        // from the quantity and price sent, so an edited amount is a real amount and not the old
        // one wearing a new number.
        $keptIds = array();
        foreach ($payload['serviceItems'] as $i => $line) {
            $code = isset($line['code']) ? trim((string) $line['code']) : '';
            if ($code !== '' && isset($index['byCode'][$code])) {
                $payload['serviceItems'][$i]['id'] = (int) $index['byCode'][$code];
                $keptIds[] = (int) $index['byCode'][$code];
            }
        }

        // The goners: lines nothing in the payload claims, plus the ones that never had a code
        // to be claimed by. A line dropped from the case must not go on claiming money.
        $lineIds = array_values(array_diff($index['ids'], $keptIds));

        $cleared = $this->clearLines($rilvenId, $lineIds);

        // A confirmed document will not give up its lines. Take it back to a draft and try once.
        if (!$cleared['ok'] && $this->looksPosted($cleared['error'])
                && $this->client->cfg('rilven_sale_unpost', TRUE)) {
            $back = $this->unpost($rilvenId);
            if (!$back['ok']) {
                return $this->rejected('case-changed-but-' . $back['error'], $back['retryable']);
            }
            $cleared = $this->clearLines($rilvenId, $lineIds);
        }
        if (!$cleared['ok']) {
            return $this->rejected($cleared['error'], $cleared['retryable']);
        }

        $answer = $this->sendDocument('PUT', '/waybill/update', $payload,
                                      isset($payload['code']) ? $payload['code'] : $rilvenId);

        // A confirmed document is read-only, and a case that changed after it was posted is the
        // ordinary way that happens -- a service added to a stay, a corrected amount. Take it back
        // to a draft, write the change, and let the posting rule decide whether it goes back.
        //
        // ONCE. If the second attempt is refused too, something other than the status is wrong and
        // repeating it would only unconfirm a document for nothing.
        if (!$answer['ok'] && $this->looksPosted($answer['error'])
                && $this->client->cfg('rilven_sale_unpost', TRUE)) {
            $back = $this->unpost($rilvenId);
            if (!$back['ok']) {
                return $this->rejected('case-changed-but-' . $back['error'], $back['retryable']);
            }
            $answer = $this->sendDocument('PUT', '/waybill/update', $payload,
                                          isset($payload['code']) ? $payload['code'] : $rilvenId);
        }

        if (!$answer['ok']) {
            if ($this->looksPosted($answer['error'])) {
                // Refused BECAUSE it is posted, which is the one refusal that also tells us
                // something worth keeping: the document is confirmed. Recorded on the queue row so
                // the CMS can stop the case being edited again instead of letting the clinic write
                // a change that will be refused here, quietly, an hour later.
                $refusal = $this->rejected('case-changed-but-document-is-already-posted: ' . $rilvenId
                    . ' -- ' . $answer['error'], FALSE);
                $refusal['rilvenStatus'] = 2;
                return $refusal;
            }
            return $this->rejected($answer['error'], $answer['retryable']);
        }

        $result = array('result' => 'updated', 'id' => $rilvenId, 'error' => '', 'retryable' => FALSE,
                        'note' => $note, 'noteRetryable' => FALSE);

        return $this->maybePost($result, $post);
    }

    /**
     * Confirm the document, which is what writes the accrual.
     *
     * Off by default. The whole point of the split is that a first run can fill the register with
     * drafts somebody looks at before any of it reaches the ledger -- and a draft can be deleted,
     * where a posting into a closed period cannot.
     */
    private function maybePost($result, $post)
    {
        // What the document's status is when this call is done with it. The CMS reads it back
        // from the outbox to refuse editing a case whose document is already posted.
        $result['rilvenStatus'] = 1;

        if (!$post || $result['id'] <= 0) {
            return $result;
        }

        $answer = $this->client->put('/waybill/update-status', array(
            'ids'    => array((int) $result['id']),
            'status' => 2,
        ));

        if (!$answer['ok']) {
            // The document itself landed. Not posting it is a note and not a failure: the draft is
            // there to be confirmed later, by this library or by a person.
            $result['note'] = 'not-posted: ' . $answer['error'];
            $result['noteRetryable'] = $answer['retryable'];
        } else {
            $result['rilvenStatus'] = 2;
        }
        return $result;
    }

    /**
     * Confirm a document that was left as a draft, without re-sending what is in it.
     *
     * {@see maybePost} confirms at the moment the case travels, which is right when the case is
     * ripe by then. A case that was sent while it was still being written is not, so it lands as
     * a draft and stays one -- and so does a case whose confirmation call failed, because that
     * failure is recorded as a note rather than a refusal. Both are left for the period close.
     *
     * This is the SAME call `maybePost` makes. It is separate only because the close has no
     * payload in hand and must not build one: re-sending the case would hash it afresh, and a
     * case edited since it travelled would be quietly rewritten by an operation the clinic asked
     * to be a posting and nothing else.
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

        $answer = $this->client->put('/waybill/update-status', array('ids' => $ids, 'status' => 2));

        if (!$answer['ok']) {
            return array('ok' => FALSE, 'error' => $answer['error'],
                         'retryable' => $answer['retryable']);
        }
        return array('ok' => TRUE, 'error' => '', 'retryable' => FALSE);
    }

    /**
     * Take the document back to a draft, which DELETES ITS POSTING.
     *
     * Rilven allows 2 → 1 and unconfirming removes the ledger entries -- it is the product's own
     * reversal path, the one the status button on the register uses. So this is not a trick; it
     * is the supported way to undo an accrual.
     *
     * It is still the most consequential thing this library does. Revenue that was recognised
     * stops being recognised, and if anybody has reported on the period in between, the report
     * and the ledger no longer agree. A period that has been CLOSED refuses the change outright,
     * which is the protection that matters and is Rilven's, not ours.
     *
     * Calling it on a document that is already a draft is harmless: the far side sees from == to
     * and does nothing.
     *
     * @return array ok, error, retryable
     */
    public function unpost($rilvenId)
    {
        $answer = $this->client->put('/waybill/update-status', array(
            'ids'    => array((int) $rilvenId),
            'status' => 1,
        ));
        if (!$answer['ok']) {
            return array('ok' => FALSE, 'error' => 'unpost: ' . $answer['error'],
                         'retryable' => $answer['retryable']);
        }
        return array('ok' => TRUE, 'error' => '', 'retryable' => FALSE);
    }

    /**
     * Remove the document a case no longer justifies.
     *
     * **Voiding a case does NOT come through here.** That register has no delete: a case is killed
     * by REPLACING its lines with zero-valued ones, so the case goes on existing, keeps its kind,
     * and stays in this library's scope. What it needs is an UPDATE to zero — which is what it
     * gets, because the amounts moved, the hash moved with them, and {@see update} unconfirms
     * first. The ledger ends up with the accrual removed and a zero one in its place, which is
     * what the clinic meant by killing it.
     *
     * This path is for a case that genuinely leaves scope: one that falls outside the date window
     * or fails a configured test. Unconfirm first, then delete — a confirmed document cannot be
     * deleted, and its accrual would otherwise be left behind claiming money for a case nothing
     * points at any more. The unconfirm is skipped when {@code rilven_sale_unpost} is off, and a
     * posted document is then reported and left for a person.
     */
    public function remove($rilvenId)
    {
        if ($this->client->cfg('rilven_sale_unpost', TRUE)) {
            $back = $this->unpost($rilvenId);
            if (!$back['ok']) {
                return array('ok' => FALSE, 'error' => 'case-voided-but-' . $back['error'],
                             'retryable' => $back['retryable']);
            }
        }

        $answer = $this->client->request('DELETE', '/waybill/delete', array('ids' => array((int) $rilvenId)));
        if (!$answer['ok']) {
            if ($this->looksPosted($answer['error'])) {
                return array('ok' => FALSE, 'retryable' => FALSE,
                             'error' => 'case-voided-but-document-is-still-posted: ' . $rilvenId
                                      . ' -- ' . $answer['error']);
            }
            return array('ok' => FALSE, 'error' => $answer['error'], 'retryable' => $answer['retryable']);
        }
        return array('ok' => TRUE, 'error' => '', 'retryable' => FALSE);
    }

    /**
     * Whether a refusal is the far side saying "this document is no longer a draft".
     *
     * Matched on the key rather than on a status code because that is all the API gives, and the
     * distinction matters: it is the one refusal that means a person has to look, not that the
     * request was wrong.
     */
    private function looksPosted($error)
    {
        return strpos($error, 'status') !== FALSE || strpos($error, 'confirmed') !== FALSE
            || strpos($error, 'not-editable') !== FALSE;
    }

    // -----------------------------------------------------------------------
    // the reference ids
    // -----------------------------------------------------------------------

    /**
     * The four ids every document carries and the clinic's register cannot supply.
     *
     * All pinned in the config: a company branch, a warehouse, a currency and a VAT treatment are
     * decisions about this installation, not facts about a case. The warehouse is required by the
     * route even for a document that moves no goods.
     */
    public function references($refresh = FALSE)
    {
        if ($this->refs !== NULL && !$refresh) {
            return $this->refs;
        }

        $refs = array(
            'companyBranchId' => $this->intOrNull($this->client->cfg('rilven_sale_company_branch_id', NULL)),
            'companyBankAccountId' => $this->intOrNull($this->client->cfg('rilven_sale_company_bank_account_id', NULL)),
            'warehouseId'     => $this->intOrNull($this->client->cfg('rilven_sale_warehouse_id', NULL)),
            'currencyId'      => $this->intOrNull($this->client->cfg('rilven_sale_currency_id', NULL)),
            'vatType'         => $this->intOrNull($this->client->cfg('rilven_sale_vat_type', NULL)),
            'notes'           => array(),
        );

        if ($refs['vatType'] === NULL) {
            // The service register's answer, because a service sold cannot be taxed differently
            // from the service in the reference.
            $refs['vatType'] = $this->intOrNull($this->client->cfg('rilven_service_vat_type', NULL));
        }

        $this->refs = $refs;
        return $refs;
    }

    public function missingReferences($refs)
    {
        $missing = array();
        // warehouseId is NOT here: it is resolved per case from the CMS warehouse, and the
        // configured one is only a fallback for a case whose warehouse was never synced.
        foreach (array('companyBranchId'      => 'sale companyBranchId',
                       'companyBankAccountId' => 'sale companyBankAccountId',
                       'currencyId'           => 'sale currencyId',
                       'vatType'              => 'sale vatType') as $key => $name) {
            if ($refs[$key] === NULL) {
                $missing[] = $name;
            }
        }
        return $missing;
    }

    // -----------------------------------------------------------------------
    // small things
    // -----------------------------------------------------------------------

    public function val($row, $meaning)
    {
        $fields = $this->client->cfg('rilven_sale_fields', array());
        $column = isset($fields[$meaning]) ? $fields[$meaning] : $meaning;
        if ($column === '' || $column === NULL || !isset($row->$column) || $row->$column === NULL) {
            return '';
        }
        return trim((string) $row->$column);
    }

    private function clip($value, $max)
    {
        $value = (string) $value;
        if (function_exists('mb_substr')) {
            return mb_strlen($value, 'UTF-8') > $max ? mb_substr($value, 0, $max, 'UTF-8') : $value;
        }
        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }

    private function intOrNull($value)
    {
        if ($value === NULL || $value === '' || (int) $value <= 0) {
            return NULL;
        }
        return (int) $value;
    }

    /**
     * @param bool $dependency whether the row was refused because something it NEEDS has not
     *                         arrived yet, rather than because the far side is in trouble. The
     *                         run counts consecutive outside failures and stops at ten; a queue
     *                         waiting on its own dependencies must not trip that, or a day when
     *                         the patients are still catching up looks like an outage and the
     *                         other two hundred cases are never even looked at.
     */
    private function rejected($error, $retryable, $dependency = FALSE)
    {
        return array('result' => 'rejected', 'id' => 0, 'error' => $error,
                     'retryable' => (bool) $retryable, 'note' => '', 'noteRetryable' => FALSE,
                     'dependency' => (bool) $dependency);
    }
}
