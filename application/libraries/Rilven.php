<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * The queue: what is waiting to go to Rilven, and the run that sends it.
 *
 * This is the only class the rest of the CMS touches. A save handler calls
 * {@see queue}; the cron calls {@see backfill} and {@see push}. The connection and the
 * mapping live in Rilven_client and Rilven_contractor, which this delegates to.
 *
 * A queue and not a direct call from the save handler, because the alternative is a
 * registration desk that waits on somebody else's server. A patient must be saveable
 * while Rilven is down, being deployed, or simply slow -- so {@see queue} writes one
 * row locally, never throws, and the cron does the travelling.
 */
class Rilven
{
    /**
     * How many rows may fail in a row for an outside reason before the run gives up.
     *
     * Small on purpose. The rows are not the problem when this trips -- the network, a
     * deploy or a restart is -- and every one of them is still queued with a single
     * attempt spent, so the next tick continues where this one stopped.
     */
    const GIVE_THE_SERVER_A_REST = 10;

    /** Outbox statuses. */
    const PENDING  = 0;
    const SENT     = 1;
    const FAILED   = 2;  // failed for a reason worth trying again
    const GIVEN_UP = 3;  // failed for a reason that will not change

    /** @var CI_Controller */
    private $CI;

    /** @var Rilven_client */
    private $client;

    /** @var Rilven_contractor */
    private $contractor;

    /** @var Rilven_insurer */
    private $insurer;

    /** @var Rilven_category */
    private $category;

    /** @var Rilven_service */
    private $service;

    /** @var Rilven_sale */
    private $sale;

    /** @var Rilven_cash */
    private $cash;

    /** @var Rilven_deposit */
    private $deposit;

    /** @var Rilven_financing */
    private $financing;

    /** Reasons rows were refused in this run, for the caller to print. */
    public $refusals = array();

    /** sma_companies.id => array(id, branchId), resolved once and kept for this run. */
    private $patientIds = array();

    public function __construct()
    {
        $this->CI = get_instance();
        $this->CI->load->library('rilven_client');
        $this->CI->load->library('rilven_contractor');
        $this->CI->load->library('rilven_insurer');
        $this->CI->load->library('rilven_category');
        $this->CI->load->library('rilven_service');
        $this->CI->load->library('rilven_sale');
        $this->CI->load->library('rilven_cash');
        $this->CI->load->library('rilven_deposit');
        $this->CI->load->library('rilven_financing');
        $this->client     = $this->CI->rilven_client;
        $this->contractor = $this->CI->rilven_contractor;
        $this->insurer    = $this->CI->rilven_insurer;
        $this->category   = $this->CI->rilven_category;
        $this->service    = $this->CI->rilven_service;
        $this->sale       = $this->CI->rilven_sale;
        $this->cash       = $this->CI->rilven_cash;
        $this->deposit    = $this->CI->rilven_deposit;
        $this->financing  = $this->CI->rilven_financing;
        $this->CI->load->database();
    }

    public function client()
    {
        return $this->client;
    }

    public function contractor()
    {
        return $this->contractor;
    }

    public function insurer()
    {
        return $this->insurer;
    }

    public function financing()
    {
        return $this->financing;
    }

    public function category()
    {
        return $this->category;
    }

    public function service()
    {
        return $this->service;
    }

    public function sale()
    {
        return $this->sale;
    }

    public function cash()
    {
        return $this->cash;
    }

    public function deposit()
    {
        return $this->deposit;
    }

    /**
     * The registers this library sends, IN THE ORDER THEY HAVE TO GO.
     *
     * Categories before services, because a service carries its category's Rilven id and this
     * side learns that id only by asking for the category. Contractors are independent of those
     * two.
     *
     * SALES GO LAST, and that is not a preference. A service sale names a patient AND every
     * service performed, and learns both ids by asking Rilven for them -- so a case sent before
     * its patient or its services have arrived waits, ten times, and is then given up on.
     * Everything it depends on is sent earlier in the same run.
     *
     * A register that is switched off is absent from this list, not skipped inside the loop --
     * so nothing about it is resolved, asked or reported either.
     */
    /**
     * The name of every register this library has, enabled or not.
     *
     * For the commands that take a register name. It is derived rather than written out because
     * the one place it was written out -- readEntity() in the controller -- was not updated when
     * a register was added, and `retry cash` answered "unknown register" about a register that
     * had been running for an hour. A list kept beside the thing it lists goes stale; this one
     * cannot.
     *
     * NOT filtered by enabled(): a disabled register's rows are still in the queue, and naming
     * it should say "nothing is sent" rather than "no such thing".
     */
    public function knownEntities()
    {
        $names = array();
        foreach (array($this->cash, $this->category, $this->service,
                       $this->contractor, $this->insurer, $this->sale,
                       $this->financing, $this->deposit) as $register) {
            $names[] = $register->entity();
        }
        return $names;
    }

    private function registers()
    {
        // Cash destinations come FIRST for the same reason categories precede services: a
        // payment names the till or account it went to, and this side learns that record's id
        // only by asking for it. A deposit whose destination has not arrived yet is refused and
        // left in the queue rather than sent to a default one.
        // Deposits go LAST: a payment names both the destination it went to and the patient it
        // came from, and it is refused until each has travelled.
        // Financiers sit beside patients and before the documents, for the same reason
        // patients do: a case that names one is refused until it has travelled.
        // Financing goes AFTER sale and before deposits: a settlement names the case's
        // document, and a case that has not travelled yet has no document to name.
        $all = array($this->cash, $this->category, $this->service, $this->contractor,
                     $this->insurer, $this->sale, $this->financing, $this->deposit);

        $enabled = array();
        foreach ($all as $register) {
            if ($register->enabled()) {
                $enabled[$register->entity()] = $register;
            }
        }
        return $enabled;
    }

    public function enabled()
    {
        return $this->client->enabled();
    }

    // -----------------------------------------------------------------------
    // queueing
    // -----------------------------------------------------------------------

    /**
     * Put one patient in the outbox.
     *
     * Called from the save handlers with sma_companies.id. Queues even when the
     * integration is switched off -- the point of an outbox is that nothing is lost while
     * somebody is still filling the config in -- and never throws: a failure to queue must
     * not become a failure to save the patient.
     *
     * @return bool whether anything was queued. FALSE for a row that is not a patient.
     */
    public function queue($sourceId)
    {
        try {
            $row = $this->sourceRow($sourceId);
            if ($row) {
                return $this->enqueue('contractor', $row->id, 'patient');
            }

            // Not a patient. It may still be a financier, and the CMS calls this one line
            // wherever a company is saved -- so the fall-through is what keeps the clinic's
            // own code from having to know there are two registers.
            $row = $this->insurerRow($sourceId);
            if ($row) {
                return $this->enqueue('insurer', $row->id, $this->insurer->typeCode());
            }
            return FALSE;
        } catch (Exception $e) {
            log_message('error', 'rilven: queue failed for ' . $sourceId . ': ' . $e->getMessage());
            return FALSE;
        }
    }

    /**
     * Put one service category in the outbox.
     *
     * Called from the save handlers of the category screen with sma_categories.id. Ignores a
     * row that is not a SERVICE category, so the same line is safe wherever categories are
     * saved. Like {@see queue} it never throws: a failure to queue must not become a failure to
     * save the category.
     *
     * @return bool whether anything was queued
     */
    /** Hook for the CMS: a payment was taken. */
    public function queueDeposit($sourceId)
    {
        try {
            $table = (string) $this->client->cfg('rilven_deposit_source_table', 'deposits');
            $this->CI->db->from($table . ' d')->select('d.id')->where('d.id', $sourceId);
            $this->applyDepositScope('d.');
            $this->applyMovementOnly('d.');
            $row = $this->CI->db->get()->row();
            if (!$row) {
                return FALSE;
            }
            return $this->enqueue('deposit', $row->id, $this->deposit->typeCode());
        } catch (Exception $e) {
            log_message('error', 'rilven: queueDeposit failed for ' . $sourceId . ': ' . $e->getMessage());
            return FALSE;
        }
    }

    /** Hook for the CMS: a money destination was saved. */
    public function queueCash($sourceId)
    {
        try {
            $row = $this->cashRow($sourceId);
            if (!$row) {
                return FALSE;
            }
            return $this->enqueue('cash', $row->id, $this->cash->typeCode());
        } catch (Exception $e) {
            log_message('error', 'rilven: queueCash failed for ' . $sourceId . ': ' . $e->getMessage());
            return FALSE;
        }
    }

    public function queueCategory($sourceId)
    {
        try {
            $row = $this->categoryRow($sourceId);
            if (!$row) {
                return FALSE;
            }
            return $this->enqueue('category', $row->id, $this->category->typeCode());
        } catch (Exception $e) {
            log_message('error', 'rilven: queueCategory failed for ' . $sourceId . ': ' . $e->getMessage());
            return FALSE;
        }
    }

    /**
     * Put one service in the outbox.
     *
     * Called from the save handlers of the product screen with sma_products.id. Ignores a row
     * that is not a service, so the same line is safe wherever products are saved.
     *
     * @return bool whether anything was queued
     */
    public function queueService($sourceId)
    {
        try {
            $row = $this->serviceRow($sourceId);
            if (!$row) {
                return FALSE;
            }
            return $this->enqueue('service', $row->id, $this->service->typeCode());
        } catch (Exception $e) {
            log_message('error', 'rilven: queueService failed for ' . $sourceId . ': ' . $e->getMessage());
            return FALSE;
        }
    }

    /**
     * Put one treatment case in the outbox.
     *
     * Called from the save handlers of the sale screen with sma_sales.id. Ignores a case outside
     * the configured window, so the same line is safe wherever a sale is saved.
     *
     * @return bool whether anything was queued
     */
    public function queueSale($sourceId)
    {
        try {
            $row = $this->saleRow($sourceId);
            if (!$row) {
                return FALSE;
            }
            return $this->enqueue('sale', $row->id, $this->sale->typeCode());
        } catch (Exception $e) {
            log_message('error', 'rilven: queueSale failed for ' . $sourceId . ': ' . $e->getMessage());
            return FALSE;
        }
    }

    /**
     * Is this case's document already posted in Rilven?
     *
     * The one question the CMS asks before it lets a case be edited. A confirmed document has
     * entries in the books behind it -- 1410 against the patient, 6110 as revenue -- and a clinic
     * that goes on editing the case underneath is writing a change the accounts will never see.
     *
     * Read from the queue row and NOT over the wire, deliberately. See recordDocumentStatus(): a
     * save in the CMS must not wait on this server, and must not fail when it is down. The cost of
     * that choice is that this answers from the last run, and the benefit is that registration
     * keeps working on a day Rilven does not.
     *
     * FALSE is the answer when nothing is known -- never queued, never sent, never confirmed. A
     * guard that refused what it could not verify would refuse every case the day it was switched
     * on, and there is nothing in the books yet to protect.
     *
     * @param  int|string $saleId sma_sales.id
     * @return bool TRUE when the case must not be edited.
     */
    public function isPostedInRilven($saleId)
    {
        if ($this->documentStatus($saleId) <= 1) {
            return FALSE;
        }
        return $this->pastEditWindow($saleId);
    }

    /**
     * Whether this case is past the days in which the CMS may still change it.
     *
     * A posted document is not, by itself, a reason to refuse an edit. `rilven_sale_post_after_days`
     * says a case goes on being written for that many days, and within them the sync already knows
     * what to do with a change to a posted document: unpost, write, let the posting rule decide
     * again. That path exists and is used. Refusing the edit here would only stop the doctor
     * finishing the case, and would not protect anything the sync cannot already handle.
     *
     * After the window it is the other way round. The case is closed, the accrual is what the
     * books say, and a silent change to it is exactly what this guard is for.
     *
     * Fails OPEN, like {@see documentStatus}: if the date cannot be read, the clinic goes on
     * working. A guard that locks the register when a query fails is worse than the edit it
     * was meant to catch.
     */
    private function pastEditWindow($saleId)
    {
        $days = (int) $this->client->cfg('rilven_sale_post_after_days', 0);
        if ($days <= 0) {
            // No window configured: posted means closed, which is what this did before.
            return TRUE;
        }

        $table  = (string) $this->client->cfg('rilven_sale_source_table', 'sales');
        $column = (string) $this->client->cfg('rilven_sale_date_column', 'date');
        if ($table === '' || $column === '') {
            return TRUE;
        }

        try {
            $row = $this->CI->db
                ->select($column . ' <= DATE_SUB(CURDATE(), INTERVAL ' . $days . ' DAY) AS closed', FALSE)
                ->from($table)
                ->where('id', $saleId)
                ->limit(1)
                ->get()->row();
        } catch (Exception $e) {
            log_message('error', 'rilven: pastEditWindow failed for ' . $saleId . ': ' . $e->getMessage());
            return FALSE;
        }

        return $row ? (bool) $row->closed : FALSE;
    }

    /**
     * What status Rilven's document for this case was last seen in: 1 draft, 2 confirmed,
     * 3 verified. 0 when there is no document, or nothing has been heard about it yet.
     *
     * @param  int|string $saleId sma_sales.id
     * @return int
     */
    public function documentStatus($saleId)
    {
        try {
            $row = $this->CI->db->select('rilven_status')
                                ->from('rilven_outbox')
                                ->where('entity', 'sale')
                                ->where('external_id', (string) $saleId)
                                ->limit(1)
                                ->get()->row();
        } catch (Exception $e) {
            // The guard failing shut would stop the clinic registering anybody, which is a worse
            // outcome than the edit it was meant to catch. It fails open and says so in the log.
            log_message('error', 'rilven: documentStatus failed for ' . $saleId . ': ' . $e->getMessage());
            return 0;
        }
        return $row ? (int) $row->rilven_status : 0;
    }

    /**
     * Insert or reopen an outbox entry.
     *
     * ON DUPLICATE KEY rather than select-then-insert: two receptionists saving the same
     * patient in the same second would otherwise both find nothing, both insert, and the
     * unique key would turn the second save into an error somebody sees. The payload hash
     * is deliberately KEPT -- an edit that changed nothing this library sends costs no
     * request at all.
     */
    private function enqueue($entity, $sourceId, $typeCode)
    {
        $sql = 'INSERT INTO ' . $this->CI->db->dbprefix('rilven_outbox')
             . ' (entity, external_id, type_code, status, attempts, created_at)'
             . ' VALUES (?, ?, ?, ?, 0, NOW())'
             . ' ON DUPLICATE KEY UPDATE status = ?, last_error = NULL, updated_at = NOW()';

        $this->CI->db->query($sql, array((string) $entity, (string) $sourceId, (string) $typeCode,
                                          self::PENDING, self::PENDING));
        return TRUE;
    }

    /**
     * Queue the patients with financial movement that the outbox has never heard of.
     *
     * The catch-up for a clinic that has been open for years. Bounded by a limit, so a
     * register of seventy thousand is not one query that never returns, and resumable
     * because the outbox itself is the record of what is already known -- NOT EXISTS
     * against the very (entity, external_id) pair its unique key enforces.
     *
     * THERE IS NO CURSOR, and that is deliberate. A high-water mark only works when one
     * sequence is being walked; a mark shared between kinds pushed past the top of one of
     * them silently put sixty-six thousand patients permanently out of reach.
     */
    public function backfill($limit = 2000)
    {
        $queued = 0;
        foreach ($this->registers() as $entity => $register) {
            if ($entity === 'deposit') {
                $queued += $this->backfillDeposits($limit);
            } elseif ($entity === 'cash') {
                $queued += $this->backfillCash($limit);
            } elseif ($entity === 'category') {
                $queued += $this->backfillCategories($limit);
            } elseif ($entity === 'service') {
                $queued += $this->backfillServices($limit);
            } elseif ($entity === 'insurer') {
                $queued += $this->backfillInsurers($limit);
            } elseif ($entity === 'financing') {
                $queued += $this->backfillFinancing($limit);
            } elseif ($entity === 'sale') {
                $queued += $this->backfillSales($limit);
                $queued += $this->refreshSales($limit);
                // Deliberately NOT given $limit -- see the method.
                $queued += $this->refreshSalesByFingerprint();
                $queued += $this->ripenSales($limit);
            } else {
                $queued += $this->backfillContractors($limit);
            }
        }
        return $queued;
    }

    /**
     * Queue the service categories the outbox has never heard of.
     *
     * No movement test: a category is a reference and there are a few dozen of them, so the
     * question "has anybody used it" is not worth asking. The NOT EXISTS is the same shape as
     * the patients', and for the same reason -- the outbox is the record of what is known, and
     * a cursor shared between kinds is what once put sixty-six thousand rows out of reach.
     */
    /**
     * Queue the money destinations the outbox has never heard of.
     *
     * No movement test, unlike the patients'. There are ten of these in one fork and a hundred
     * and sixty in another, and a destination with no payments yet is exactly the one a payment
     * is about to name -- sending it early costs one request and saves a refused deposit.
     */
    /** Queue the payments the outbox has never heard of, newest first. */
    public function backfillDeposits($limit = 2000)
    {
        $prefix = $this->CI->db->dbprefix;
        $table  = $prefix . (string) $this->client->cfg('rilven_deposit_source_table', 'deposits');

        $this->CI->db->select('d.id')->from($table . ' d')->order_by('d.id', 'ASC')->limit((int) $limit);
        $this->applyDepositScope('d.');
        $this->applyMovementOnly('d.');
        $this->CI->db->where('NOT EXISTS (SELECT 1 FROM ' . $prefix . 'rilven_outbox o'
            . " WHERE o.entity = 'deposit' AND o.external_id = CAST(d.id AS CHAR))", NULL, FALSE);

        $queued = 0;
        foreach ($this->CI->db->get()->result() as $row) {
            $this->enqueue('deposit', $row->id, $this->deposit->typeCode());
            $queued++;
        }
        return $queued;
    }

    public function backfillCash($limit = 2000)
    {
        $prefix = $this->CI->db->dbprefix;
        $table  = $prefix . (string) $this->client->cfg('rilven_cash_source_table', 'cash');

        $this->CI->db->select('c.id')->from($table . ' c')->order_by('c.id', 'ASC')->limit((int) $limit);
        $this->applyWhere($this->client->cfg('rilven_cash_where', array()), 'c.');
        $this->CI->db->where('NOT EXISTS (SELECT 1 FROM ' . $prefix . 'rilven_outbox o'
            . " WHERE o.entity = 'cash' AND o.external_id = CAST(c.id AS CHAR))", NULL, FALSE);

        $queued = 0;
        foreach ($this->CI->db->get()->result() as $row) {
            $this->enqueue('cash', $row->id, $this->cash->typeCode());
            $queued++;
        }
        return $queued;
    }

    public function backfillCategories($limit = 2000)
    {
        $prefix = $this->CI->db->dbprefix;
        $table  = $prefix . (string) $this->client->cfg('rilven_category_source_table', 'categories');

        $this->CI->db->select('c.id')->from($table . ' c')->order_by('c.id', 'ASC')->limit((int) $limit);
        $this->applyWhere($this->client->cfg('rilven_category_where', array()), 'c.');

        // CAST(c.id AS CHAR) for the same reason it is there in the patients' catch-up: comparing
        // an INT column to a VARCHAR one makes MySQL convert the COLUMN, the second half of the
        // unique key goes out of reach and the subquery turns DEPENDENT.
        $this->CI->db->where('NOT EXISTS (SELECT 1 FROM ' . $prefix . 'rilven_outbox o'
            . " WHERE o.entity = 'category' AND o.external_id = CAST(c.id AS CHAR))", NULL, FALSE);

        $queued = 0;
        foreach ($this->CI->db->get()->result() as $row) {
            $this->enqueue('category', $row->id, $this->category->typeCode());
            $queued++;
        }
        return $queued;
    }

    /**
     * Queue the services the outbox has never heard of.
     *
     * Same shape, and the scope is the products that sit in a SERVICE category -- which is what
     * makes "is this row a service" answerable without depending on a `type` column that the
     * forks spell differently. See {@see serviceScope}.
     */
    public function backfillServices($limit = 2000)
    {
        $prefix = $this->CI->db->dbprefix;
        $table  = $prefix . (string) $this->client->cfg('rilven_service_source_table', 'products');

        $this->CI->db->select('p.id')->from($table . ' p')->order_by('p.id', 'ASC')->limit((int) $limit);
        $this->serviceScope('p.');

        $this->CI->db->where('NOT EXISTS (SELECT 1 FROM ' . $prefix . 'rilven_outbox o'
            . " WHERE o.entity = 'service' AND o.external_id = CAST(p.id AS CHAR))", NULL, FALSE);

        $queued = 0;
        foreach ($this->CI->db->get()->result() as $row) {
            $this->enqueue('service', $row->id, $this->service->typeCode());
            $queued++;
        }
        return $queued;
    }

    /**
     * Queue the treatment cases the outbox has never heard of.
     *
     * Bounded by the configured window -- this register starts on a date somebody chose, not at
     * the beginning of the clinic's history, because everything before it belongs to periods that
     * are closed.
     */
    public function backfillSales($limit = 2000)
    {
        $prefix = $this->CI->db->dbprefix;
        $table  = $prefix . (string) $this->client->cfg('rilven_sale_source_table', 'sales');

        $this->CI->db->select('s.id')->from($table . ' s')->order_by('s.id', 'ASC')->limit((int) $limit);
        $this->applySaleScope('s.');

        $this->CI->db->where('NOT EXISTS (SELECT 1 FROM ' . $prefix . 'rilven_outbox o'
            . " WHERE o.entity = 'sale' AND o.external_id = CAST(s.id AS CHAR))", NULL, FALSE);

        $queued = 0;
        foreach ($this->CI->db->get()->result() as $row) {
            $this->enqueue('sale', $row->id, $this->sale->typeCode());
            $queued++;
        }
        return $queued;
    }

    /**
     * Re-open cases that CHANGED after this library last sent them.
     *
     * The catch-up only ever queues what the outbox has never heard of, which is right for a
     * reference and wrong for a document: an inpatient case is marked `completed` by a person
     * DAYS after it was first sent, and that is precisely the moment its accrual becomes
     * postable. Without this the row sits at "sent" for ever and the posting never happens.
     *
     * The signal is the case's own {@code updated_at} against the outbox row's. It catches any
     * change -- a completion, a corrected amount, an added service -- and needs nothing stored.
     *
     * Re-opening is cheap even when nothing material changed: {@see enqueue} KEEPS the payload
     * hash, so the run recomputes it, finds it identical and closes the row again without a
     * single request. Only a case that really moved costs anything.
     */
    public function refreshSales($limit = 2000)
    {
        $column = (string) $this->client->cfg('rilven_sale_updated_column', 'updated_at');
        if ($column === '') {
            return 0;
        }

        $prefix = $this->CI->db->dbprefix;
        $table  = $prefix . (string) $this->client->cfg('rilven_sale_source_table', 'sales');

        $this->CI->db->select('s.id')->from($table . ' s')
            ->join($prefix . 'rilven_outbox o',
                   "o.entity = 'sale' AND o.external_id = CAST(s.id AS CHAR)", 'inner', FALSE)
            ->where('o.status', self::SENT)
            ->where('s.' . $column . ' IS NOT NULL', NULL, FALSE)
            ->where('o.updated_at IS NOT NULL', NULL, FALSE)
            ->where('s.' . $column . ' > o.updated_at', NULL, FALSE)
            ->order_by('s.' . $column, 'ASC')
            ->limit((int) $limit);
        $this->applySaleScope('s.');

        $queued = 0;
        foreach ($this->CI->db->get()->result() as $row) {
            $this->enqueue('sale', $row->id, $this->sale->typeCode());
            $queued++;
        }
        return $queued;
    }

    /**
     * Queue the patients with financial movement that the outbox has never heard of.
     *
     * This was {@see backfill} before the service registers existed; that name now tops up
     * every register, and this one is the patients alone.
     */
    /**
     * Re-open the cases whose CONTENT changed, whatever the clinic's timestamp says.
     *
     * refreshSales() above watches `sma_sales.updated_at`, and that column cannot be trusted
     * here. It is a plain timestamp with no ON UPDATE clause, and of the twenty-seven handlers in
     * the fork's Sales.php that write the sales table, exactly three stamp it -- edit(), edit2()
     * and edit_diagnoses(). Everything else is invisible to a timestamp sweep, including:
     *
     *   - the five pdf_kalkulacia* handlers, which between them rewrite `unit_price`, `quantity`
     *     and `subtotal` on sale_items in fifteen places. The accrual would keep the old sum.
     *   - Sales_model::updateStatus(), which writes `sale_status` and nothing else. That is the
     *     transition an inpatient case posts on, so the document would stay a draft for ever.
     *
     * So this asks the only question that cannot be got wrong: is what is in the source now still
     * what we last sent? The fingerprint is computed BY THE DATABASE over the columns the payload
     * is actually built from, which makes it one grouped query and no round trip per case.
     *
     * A row that has never been fingerprinted ADOPTS its current value rather than being re-sent.
     * Switching this on would otherwise re-send every case in the register at once, and the rows
     * it would re-send are ones a run has just confirmed are correct. The count is returned
     * separately so that adoption is visible and not silent.
     *
     * Unlike every other sweep here it takes no limit by default, and the reason is in
     * saleFingerprints(): a limit on a grouped query buys nothing and hides rows for ever.
     *
     * @return int cases re-opened -- adoption is NOT counted, nothing was queued.
     */
    /**
     * Re-open the drafts that have now stood long enough to be posted.
     *
     * Without this the delay would be a one-way door. A case reaches Rilven on the day of the
     * visit, `rilven_sale_post_after_days` says not yet, and it lands as a draft -- correctly.
     * Two days later it is ripe, but nothing has changed about it, so no other sweep looks at
     * it again: the fingerprint is the same, `updated_at` was not touched, and the outbox says
     * SENT. It would stay a draft for ever. This is the sweep that goes and gets it.
     *
     * What it picks: a case that has LANDED, that Rilven still calls a draft, and whose date has
     * crossed the cutoff. The next run re-sends it, this time with `post` true, and once Rilven
     * answers with a confirmed status the row stops matching and the sweep forgets it.
     *
     * The hour in the last condition is what keeps that from becoming a treadmill. If a document
     * will not leave draft -- a posting rule missing over there, an account unmapped -- it goes
     * on matching for ever, and without the hour it would be re-queued sixty times an hour to
     * fail the same way. Once an hour is often enough to recover by itself and rare enough to
     * notice in the log.
     */
    public function ripenSales($limit = 0)
    {
        if (!$this->sale->enabled() || !$this->client->cfg('rilven_sale_post', FALSE)) {
            return 0;
        }
        $days = (int) $this->client->cfg('rilven_sale_post_after_days', 0);
        if ($days <= 0) {
            // Posting is immediate, so nothing is ever left waiting to ripen.
            return 0;
        }

        $prefix = $this->CI->db->dbprefix;
        $sales  = $prefix . (string) $this->client->cfg('rilven_sale_source_table', 'sales');
        $column = (string) $this->client->cfg('rilven_sale_date_column', 'date');
        if ($column === '') {
            return 0;
        }

        $this->CI->db
            ->select('s.id', FALSE)
            ->from($sales . ' s')
            ->join($prefix . 'rilven_outbox o',
                   "o.entity = 'sale' AND o.external_id = CAST(s.id AS CHAR)", 'inner', FALSE)
            ->where('o.status', self::SENT)
            // NULL as well as 1: rows that landed before the column existed have never been told
            // what Rilven thinks of them, and a draft is what they are until it says otherwise.
            ->group_start()
                ->where('o.rilven_status IS NULL', NULL, FALSE)
                ->or_where('o.rilven_status', 1)
            ->group_end()
            ->where('s.' . $column . ' <= DATE_SUB(CURDATE(), INTERVAL ' . $days . ' DAY)', NULL, FALSE)
            ->group_start()
                ->where('o.updated_at IS NULL', NULL, FALSE)
                ->or_where('o.updated_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)', NULL, FALSE)
            ->group_end();

        $from = trim((string) $this->client->cfg('rilven_sale_from', ''));
        if ($from !== '') {
            $this->CI->db->where('s.' . $column . ' >=', $from);
        }

        $this->CI->db->order_by('s.' . $column, 'ASC');
        if ((int) $limit > 0) {
            $this->CI->db->limit((int) $limit);
        }

        $rows = $this->CI->db->get()->result();

        $queued = 0;
        foreach ($rows as $row) {
            $this->enqueue('sale', $row->id, $this->sale->typeCode());
            $queued++;
        }
        return $queued;
    }

    public function refreshSalesByFingerprint($limit = 0)
    {
        $rows = $this->saleFingerprints($limit);
        if ($rows === NULL) {
            return 0;
        }

        $queued = 0;
        foreach ($rows as $row) {
            if ($row->source_fingerprint === NULL || $row->source_fingerprint === '') {
                // First sight of this case. Adopt, do not re-send.
                $this->CI->db->where('id', $row->outbox_id)
                             ->update('rilven_outbox', array('source_fingerprint' => $row->fingerprint));
                continue;
            }
            if ($row->source_fingerprint === $row->fingerprint) {
                continue;
            }
            $this->enqueue('sale', $row->id, $this->sale->typeCode());
            $queued++;
        }
        return $queued;
    }

    /**
     * What each in-scope case looks like right now, beside what was last sent.
     *
     * GROUP_CONCAT truncates at group_concat_max_len -- 1024 bytes by default, which a case with
     * thirty lines passes -- and a truncated digest is a digest that stops noticing changes at the
     * end of the list. The session value is raised first, and the whole thing is skipped rather
     * than trusted if that fails.
     */
    private function saleFingerprints($limit, $onlySaleId = NULL)
    {
        try {
            $this->CI->db->query('SET SESSION group_concat_max_len = 1000000');
        } catch (Exception $e) {
            log_message('error', 'rilven: cannot raise group_concat_max_len, '
                . 'skipping the fingerprint sweep: ' . $e->getMessage());
            return NULL;
        }

        $prefix = $this->CI->db->dbprefix;
        $sales  = $prefix . (string) $this->client->cfg('rilven_sale_source_table', 'sales');
        $items  = $prefix . (string) $this->client->cfg('rilven_sale_item_table', 'sale_items');

        // The header fields the document carries, then the lines. Anything the payload is built
        // from belongs here; anything else does not, or a clinical note would re-send an accrual.
        $digest = "MD5(CONCAT_WS('|',"
                . " COALESCE(s.sale_status,''), COALESCE(s.date,''), COALESCE(s.customer_id,0),"
                . " COALESCE(s.service,0), COALESCE(s.reference_no,''),"
                . " COALESCE(GROUP_CONCAT(CONCAT_WS(':', i.id, COALESCE(i.quantity,0),"
                . " COALESCE(i.unit_price,0), COALESCE(i.subtotal,0),"
                . " COALESCE(i.product_id,0), COALESCE(i.warehouse_id,0))"
                . " ORDER BY i.id SEPARATOR ','), '')"
                . "))";

        // Only cases recent enough to still change.
        //
        // The scope has a floor (`rilven_sale_from`) and no ceiling, so it grows for ever -- at
        // roughly six and a half thousand outpatient cases a month, a sweep that grouped the whole
        // register every minute would be grouping a hundred thousand of them inside a year, to
        // discover that cases from last spring are still what they were. The window is the ceiling
        // the scope does not have.
        //
        // What it gives up is a recalculation of a case older than the window, which is rare and
        // is NOT lost: the edit() path stamps `updated_at`, so refreshSales() still catches it.
        // This sweep is the net under the paths that do not stamp, and those are calculation
        // screens -- used while a case is current, not a year later.
        $days = (int) $this->client->cfg('rilven_sale_fingerprint_days', 90);
        if ($days > 0) {
            $column = (string) $this->client->cfg('rilven_sale_date_column', 'date');
            $this->CI->db->where('s.' . $column . ' >=',
                                 date('Y-m-d', strtotime('-' . $days . ' days')));
        }

        $this->CI->db
            ->select('s.id, o.id AS outbox_id, o.source_fingerprint, ' . $digest . ' AS fingerprint', FALSE)
            ->from($sales . ' s')
            ->join($prefix . 'rilven_outbox o',
                   "o.entity = 'sale' AND o.external_id = CAST(s.id AS CHAR)", 'inner', FALSE)
            // Only rows that have actually landed. A pending one is already going to be sent, and
            // a given-up one is waiting for a person, not for another attempt.
            ->where('o.status', self::SENT)
            ->join($items . ' i', 'i.sale_id = s.id', 'left', FALSE)
            ->group_by('s.id, o.id, o.source_fingerprint')
            ->order_by('s.id', 'ASC');
        // NO LIMIT, on purpose. A limit here would be a blind spot and not a saving: it applies
        // AFTER the grouping, so the work is already done, and with a stable ORDER BY the same
        // first N cases would be checked every run while the rest were never looked at at all.
        // One row of two short strings per case is not what makes this query cost anything.
        if ((int) $limit > 0) {
            $this->CI->db->limit((int) $limit);
        }
        if ($onlySaleId !== NULL) {
            $this->CI->db->where('s.id', $onlySaleId);
        }
        $this->applySaleScope('s.');

        try {
            return $this->CI->db->get()->result();
        } catch (Exception $e) {
            log_message('error', 'rilven: fingerprint sweep failed: ' . $e->getMessage());
            return NULL;
        }
    }

    public function backfillContractors($limit = 2000)
    {
        // The property, NOT dbprefix(''): that method exists to prefix a table name and
        // answers an empty one with display_error('db_table_required') -- "A table name is
        // required for that operation", which is a database error raised by asking for the
        // prefix. Nothing about the message says so.
        $prefix = $this->CI->db->dbprefix;
        $table  = $prefix . (string) $this->client->cfg('rilven_source_table', 'companies');

        $this->CI->db->select('c.id')->from($table . ' c')->order_by('c.id', 'ASC')->limit((int) $limit);
        $this->applyPatientWhere('c.');

        // Not already known to the outbox -- sent, pending or given up alike.
        //
        // CAST(c.id AS CHAR) is not decoration. external_id is VARCHAR because the next
        // system to be mapped will key on something that is not a number, and
        // sma_companies.id is INT. Comparing the two makes MySQL convert the COLUMN, which
        // puts the second half of the unique key out of reach: the subquery goes DEPENDENT
        // and examines every outbox row for every candidate. That is what a run doing
        // nothing at all once took fifteen minutes to discover.
        $this->CI->db->where('NOT EXISTS (SELECT 1 FROM ' . $prefix . 'rilven_outbox o'
            . " WHERE o.entity = 'contractor' AND o.external_id = CAST(c.id AS CHAR))", NULL, FALSE);

        // "Has movement": one EXISTS against the documents that make a patient matter to
        // accounting. The register holds everybody who ever walked in; the books want the
        // ones there are documents for, and the rest arrive on their first invoice.
        $movement = $this->client->cfg('rilven_movement', NULL);
        if (is_array($movement) && isset($movement['table']) && isset($movement['column'])) {
            $this->CI->db->where('EXISTS (SELECT 1 FROM ' . $prefix . $movement['table']
                . ' m WHERE m.' . $movement['column'] . ' = c.id)', NULL, FALSE);
        }

        $queued = 0;
        foreach ($this->CI->db->get()->result() as $row) {
            $this->enqueue('contractor', $row->id, 'patient');
            $queued++;
        }
        return $queued;
    }

    // -----------------------------------------------------------------------
    // sending
    // -----------------------------------------------------------------------

    /**
     * One cron run.
     *
     * @return array a summary for the log and the status page
     */
    public function push()
    {
        $summary = array('claimed' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0,
                         'rejected' => 0, 'retrying' => 0, 'skipped' => 0, 'stopped' => '',
                         'entities' => array());
        $this->refusals = array();

        if (!$this->enabled()) {
            $summary['skipped'] = 1;
            $summary['stopped'] = $this->client->cfg('rilven_enabled', FALSE)
                ? 'not configured: url, company id or credentials are missing'
                : 'switched off (rilven_enabled)';
            return $summary;
        }

        // Signing in before the loop rather than inside it, so a wrong password is one
        // sentence at the top of the log instead of two thousand identical ones.
        if (!$this->client->login()) {
            $summary['stopped'] = 'sign-in refused: ' . $this->client->lastError();
            return $summary;
        }

        // The categories' ids, learned last run, are not learned again here: a category deleted
        // over there between two ticks would otherwise go on being handed to every service.
        $this->service->forgetCategories();
        $this->sale->forgetServices();
        $this->patientIds = array();

        foreach ($this->registers() as $entity => $register) {
            $one = $this->pushEntity($entity, $register);

            $summary['entities'][$entity] = $one;
            foreach (array('claimed', 'created', 'updated', 'unchanged', 'rejected', 'retrying') as $key) {
                $summary[$key] += $one[$key];
            }

            if ($one['stopped'] !== '') {
                // A register that stopped for its own reason -- an unresolved account class --
                // must not stop the others. A refused credential or an unreachable server must,
                // because it is not this register that is the problem.
                $summary['stopped'] = trim($summary['stopped'] . ' ' . $entity . ': ' . $one['stopped']);
                if ($this->client->credentialRefused()) {
                    return $summary;
                }
            }
        }

        return $summary;
    }

    /**
     * One register's queue, sent.
     *
     * The loop is the same whichever register it is: claim a chunk, read the source row, map it,
     * skip it if Rilven already has exactly this, send it, and record what came back. What
     * differs -- which route, which payload, which reference ids -- is the register's own and is
     * reached only through the small set of methods every one of them has.
     *
     * @return array the same summary shape as {@see push}, for this register alone
     */
    private function pushEntity($entity, $register)
    {
        $summary = array('claimed' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0,
                         'rejected' => 0, 'retrying' => 0, 'skipped' => 0, 'stopped' => '');

        $refs = $register->references();
        $missing = $register->missingReferences($refs);
        if (!empty($missing)) {
            // Nothing can be sent without these, and every row would be refused for the
            // same reason. Said once, with what it could not resolve and why.
            $summary['stopped'] = 'cannot resolve ' . implode(', ', $missing)
                . (empty($refs['notes']) ? '' : ' -- ' . implode('; ', $refs['notes']));
            return $summary;
        }
        foreach ($refs['notes'] as $note) {
            $this->refuse('-', $entity . ' reference: ' . $note);
        }

        $batch    = (int) $this->client->cfg('rilven_batch', 200);
        $maxRows  = (int) $this->client->cfg('rilven_max_rows', 2000);
        $attempts = (int) $this->client->cfg('rilven_max_attempts', 10);

        // Where this run has got to in the queue.
        //
        // Without it a row bumped to "failed" is still a row this query matches, so the
        // very next chunk of the SAME run claims it again, and a patient that Rilven is
        // refusing today burns all ten of its attempts in one tick instead of over ten.
        // Rows queued while the run is going have higher ids and are still reached.
        $lastId = 0;

        // How many rows in a row have failed for a reason that was not their own. Rilven
        // being down is not two thousand separate facts, and a cron that discovers it two
        // thousand times a minute is how a clinic turns into a denial of service.
        $consecutive = 0;

        while ($maxRows === 0 || $summary['claimed'] < $maxRows) {
            $take = $batch;
            if ($maxRows > 0) {
                $take = min($batch, $maxRows - $summary['claimed']);
            }
            if ($take <= 0) {
                break;
            }

            $pending = $this->CI->db
                ->select('id, external_id, payload_hash, attempts, rilven_id')
                ->from('rilven_outbox')
                ->where('entity', $entity)
                ->where('id >', $lastId)
                ->where_in('status', array(self::PENDING, self::FAILED))
                ->where('attempts <', $attempts)
                ->order_by('id', 'ASC')
                ->limit($take)
                ->get()->result();

            if (empty($pending)) {
                break;
            }

            foreach ($pending as $entry) {
                $summary['claimed']++;
                $lastId = (int) $entry->id;

                $row = $this->sourceRowFor($entity, $entry->external_id);
                if (!$row) {
                    // Deleted in the CMS after being queued, or reclassified since -- a patient
                    // turned supplier, a service category turned goods category.
                    //
                    // For a REFERENCE that is the end of it: the record stays over there, because
                    // last year's documents still point at it. For a DOCUMENT it is not, because a
                    // case that no longer exists here must not go on claiming money there.
                    $gone = $this->withdraw($register, $entry);

                    if ($gone['retryable']) {
                        $this->bump($entry->id, $gone['error']);
                        $summary['retrying']++;
                        $this->refuse($entry->external_id, $gone['error']);
                        continue;
                    }
                    $this->finish($entry->id, self::GIVEN_UP, NULL,
                        $gone['error'] === '' ? 'source-row-no-longer-in-scope' : $gone['error'],
                        NULL, NULL);
                    $summary['rejected']++;
                    $this->refuse($entry->external_id, $gone['error'] === ''
                        ? 'source row no longer in scope for ' . $entity
                        : $gone['error']);
                    continue;
                }

                $mapped = $register->map($row, $refs);
                if ($mapped['error'] === '') {
                    $hash = $register->hash($mapped);
                    if ($entry->payload_hash !== NULL && $entry->payload_hash === $hash) {
                        // Queued again by a save that touched nothing this library sends.
                        $this->finish($entry->id, self::SENT, $hash, NULL, NULL, NULL);
                        // And the fingerprint with it, for the reason spelled out where the
                        // note is handled below: the fingerprint answers "is the source still
                        // what we last sent?", and left stale it answers "no" for ever.
                        // refreshSalesByFingerprint() then re-opens the case on the next run,
                        // this branch closes it again without stamping, and the two spin
                        // against each other once a minute for as long as the cron lives.
                        // Measured on the clinic 2026-09-20: three cases, 4 320 requests a day.
                        $this->stampFingerprint($entity, $entry->id, $entry->external_id);
                        $summary['unchanged']++;
                        continue;
                    }
                } else {
                    $hash = NULL;
                }

                // The document id this row is already known by, for a register that can update
                // what it sent. A reference finds its record by code and ignores the extra
                // argument; a document has no such lookup and needs it. See Rilven_sale.
                $result = $register->push($row, $refs, (int) $entry->rilven_id);

                // Written before the row is closed either way, because the refusal that matters
                // most here -- "already posted" -- is a refusal. See recordDocumentStatus().
                $this->recordDocumentStatus($entry->id, $result);

                if ($result['result'] === 'rejected') {
                    if ($result['retryable'] && $entry->attempts + 1 < $attempts) {
                        $this->bump($entry->id, $result['error']);
                        $summary['retrying']++;
                        // A row waiting on something that has not arrived yet -- its patient, its
                        // service, its warehouse -- is NOT the far side being in trouble, and must
                        // not count towards the outage guard. While the patients were catching up
                        // it stopped every run after ten cases and the other 293 were never looked
                        // at, which read as a failure of the sale register and was not one.
                        if (empty($result['dependency'])) {
                            $consecutive++;
                        }
                    } else {
                        $this->finish($entry->id, self::GIVEN_UP, NULL, $result['error'], NULL, NULL);
                        $summary['rejected']++;
                    }
                    $this->refuse($entry->external_id, $result['error']);

                    // A credential that has been refused refuses every later row too, and a
                    // cron that keeps trying is how a clinic becomes a denial of service.
                    if ($this->client->credentialRefused()) {
                        $summary['stopped'] = 'credential refused: ' . $this->client->lastError();
                        return $summary;
                    }
                    if ($consecutive >= self::GIVE_THE_SERVER_A_REST) {
                        // The rows are fine and the far side is not. Everything claimed so
                        // far is back in the queue with one attempt spent; the next tick
                        // picks it up.
                        $summary['stopped'] = 'stopped after ' . $consecutive
                            . ' rows in a row failed for the same outside reason: ' . $result['error'];
                        return $summary;
                    }
                    continue;
                }

                $consecutive = 0;

                // Only the counterparty has a second record behind it. A register with one route
                // says nothing about a branch, and NULL is what "there is none" is written as.
                $branchId = isset($result['branchId']) ? $result['branchId'] : NULL;

                if ($result['note'] === '') {
                    $this->finish($entry->id, self::SENT, $hash, NULL, $result['id'], $branchId);
                    $this->stampFingerprint($entity, $entry->id, $entry->external_id);
                } else {
                    // The counterparty landed and its branch did not. The hash must NOT be
                    // recorded: it stands for "what Rilven has accepted", and half of this
                    // was refused -- recorded, the next run would read the row as unchanged
                    // and the contact details would never arrive.
                    $this->refuse($entry->external_id, $result['note']);
                    if ($result['noteRetryable'] && $entry->attempts + 1 < $attempts) {
                        $this->bump($entry->id, $result['note']);
                        $this->finishIds($entry->id, $result['id'], $branchId);
                        $summary['retrying']++;
                        continue;
                    }
                    // Nothing about trying again would change the answer -- the route is not
                    // granted, or there is no state and city to send. The counterparty is in,
                    // so the row is closed rather than left to collect attempts, and the
                    // reason is in the run's refusals where somebody will read it.
                    $this->finish($entry->id, self::SENT, NULL, $result['note'], $result['id'], $branchId);

                    // The fingerprint IS recorded here, unlike the hash beside it, and the
                    // difference is not an oversight. The hash is withheld on purpose so the
                    // row is sent again and the missing half gets another chance. The
                    // fingerprint answers a different question -- "is the source still what we
                    // last sent?" -- and the document itself did land. Left stale it would
                    // answer "no" for ever, and refreshSalesByFingerprint() would re-open this
                    // case on every single run, for as long as the note kept coming back.
                    $this->stampFingerprint($entity, $entry->id, $entry->external_id);
                }
                $summary[$result['result']]++;
            }
        }

        return $summary;
    }

    // -----------------------------------------------------------------------
    // reading the source
    // -----------------------------------------------------------------------

    /**
     * Is there a row for this id in the counterparty table at all -- patient or not?
     *
     * Deliberately UNFILTERED by the patient scope. The question is whether the case points at
     * something real, and a case naming a supplier is a different fault from a case naming
     * nothing: the first is a scope decision somebody can argue about, the second is a broken
     * reference nobody can fix by waiting.
     */
    private function sourceRowExists($sourceId)
    {
        $table = (string) $this->client->cfg('rilven_source_table', 'companies');
        return (bool) $this->CI->db->from($table)->where('id', $sourceId)->count_all_results();
    }

    /**
     * One row of the counterparty table, but only if it is a patient.
     *
     * The patient test is applied on every read and not only at queue time: a row
     * reclassified after it was queued -- a patient turned supplier -- must stop being
     * sent, and the queue entry has no way of knowing that by itself.
     */
    /**
     * One financier, or nothing when this id is not one.
     *
     * Its own scope rather than a widened patient scope: the two registers write different kinds
     * of counterparty into Rilven, and a single scope would mean one queue entry could be read as
     * either. Group 5 here, group 3 there, and a row that changes group simply stops being in
     * scope for the register it left -- which the queue already knows how to report.
     */
    public function insurerRow($sourceId)
    {
        $table = (string) $this->client->cfg('rilven_source_table', 'companies');
        $this->CI->db->from($table)->where('id', $sourceId);
        $this->applyWhere($this->client->cfg('rilven_insurer_where', array()), '');
        return $this->CI->db->get()->row();
    }

    /**
     * Queue the financiers the outbox has never heard of.
     *
     * No movement test, unlike the patients'. There are two hundred of them against seventy
     * thousand patients, and a financier with no documents yet is exactly the one a case is
     * about to name -- refusing to send it until it has been used would hold up the first case
     * that used it.
     */
    public function backfillInsurers($limit = 2000)
    {
        $prefix = $this->CI->db->dbprefix;
        $table  = $prefix . (string) $this->client->cfg('rilven_source_table', 'companies');

        $this->CI->db->select('c.id')->from($table . ' c')->order_by('c.id', 'ASC')->limit((int) $limit);
        $this->applyWhere($this->client->cfg('rilven_insurer_where', array()), 'c.');

        // CAST for the reason spelled out in backfillContractors: comparing an INT id against a
        // VARCHAR external_id converts the COLUMN and puts the unique key out of reach.
        $this->CI->db->where('NOT EXISTS (SELECT 1 FROM ' . $prefix . 'rilven_outbox o'
            . " WHERE o.entity = 'insurer' AND o.external_id = CAST(c.id AS CHAR))", NULL, FALSE);

        $queued = 0;
        foreach ($this->CI->db->get()->result() as $row) {
            $this->enqueue('insurer', $row->id, $this->insurer->typeCode());
            $queued++;
        }
        return $queued;
    }

    public function sourceRow($sourceId)
    {
        $table = (string) $this->client->cfg('rilven_source_table', 'companies');
        $this->CI->db->from($table)->where('id', $sourceId);
        $this->applyPatientWhere('');
        return $this->CI->db->get()->row();
    }

    /** How many patients this installation has, by the configured test. */
    public function sourceCount($withMovement = FALSE)
    {
        // The property, NOT dbprefix(''): that method exists to prefix a table name and
        // answers an empty one with display_error('db_table_required') -- "A table name is
        // required for that operation", which is a database error raised by asking for the
        // prefix. Nothing about the message says so.
        $prefix = $this->CI->db->dbprefix;
        $table  = $prefix . (string) $this->client->cfg('rilven_source_table', 'companies');

        $this->CI->db->from($table . ' c');
        $this->applyPatientWhere('c.');

        if ($withMovement) {
            $movement = $this->client->cfg('rilven_movement', NULL);
            if (is_array($movement) && isset($movement['table']) && isset($movement['column'])) {
                $this->CI->db->where('EXISTS (SELECT 1 FROM ' . $prefix . $movement['table']
                    . ' m WHERE m.' . $movement['column'] . ' = c.id)', NULL, FALSE);
            }
        }

        return (int) $this->CI->db->count_all_results();
    }

    /**
     * What makes a row a patient here.
     *
     * Configured rather than written in, because this is the one thing every fork spells
     * differently, and because it is the entire scope of the integration: the suppliers,
     * insurers and billers that share this table are not this library's business.
     */
    private function applyPatientWhere($alias)
    {
        $this->applyWhere($this->client->cfg('rilven_patient_where', array()), $alias);
    }

    /**
     * A configured set of column/value tests, ANDed, as every register's scope is written.
     *
     * NULL means IS NULL and not `= NULL`, which matches nothing in MySQL and would silently
     * empty the register rather than say so.
     */
    private function applyWhere($where, $alias)
    {
        if (!is_array($where)) {
            return;
        }
        foreach ($where as $column => $value) {
            if ($value === NULL) {
                $this->CI->db->where($alias . $column . ' IS NULL', NULL, FALSE);
            } elseif (is_array($value)) {
                // A list means IN, and an EMPTY list means nothing matches rather than
                // everything -- `where_in` with no values is a filter that quietly disappears,
                // and a scope that disappears sends the whole table.
                if (empty($value)) {
                    $this->CI->db->where('1 = 0', NULL, FALSE);
                } else {
                    $this->CI->db->where_in($alias . $column, $value);
                }
            } else {
                $this->CI->db->where($alias . $column, $value);
            }
        }
    }

    /** One row of whichever register the entry belongs to, or nothing if it is out of scope. */
    private function sourceRowFor($entity, $sourceId)
    {
        if ($entity === 'insurer') {
            return $this->insurerRow($sourceId);
        }
        if ($entity === 'financing') {
            return $this->financingRow($sourceId);
        }
        if ($entity === 'category') {
            return $this->categoryRow($sourceId);
        }
        if ($entity === 'service') {
            return $this->serviceRow($sourceId);
        }
        if ($entity === 'sale') {
            return $this->saleRow($sourceId);
        }
        if ($entity === 'cash') {
            return $this->cashRow($sourceId);
        }
        if ($entity === 'deposit') {
            return $this->depositRow($sourceId);
        }
        return $this->sourceRow($sourceId);
    }

    /**
     * One row of sma_categories, but only if it is a SERVICE category.
     *
     * The test is applied on every read and not only at queue time: a category moved from
     * services to goods after it was queued must stop being sent, and the queue entry has no way
     * of knowing that by itself. The same rule as the patients'.
     */
    /**
     * One row of the money-destination table, still in scope.
     *
     * No kind test here: a row whose `paid_by` this library does not know is a REFUSAL and not an
     * absence -- see Rilven_cash. Filtering it out here would make it look deleted, and the
     * withdraw path would then remove the record over there, taking a real till out of the books
     * because somebody typed a new word in the clinic.
     */
    public function cashRow($sourceId)
    {
        $table = (string) $this->client->cfg('rilven_cash_source_table', 'cash');
        $this->CI->db->from($table . ' c')->select('c.*')->where('c.id', $sourceId);
        $this->applyWhere($this->client->cfg('rilven_cash_where', array()), 'c.');
        return $this->CI->db->get()->row();
    }

    /**
     * One payment row, with the patient's Rilven ids already attached.
     *
     * The scope here is DATES ONLY, deliberately. Which rows are money and which are the other
     * leg is decided by Rilven_deposit::directionOf(), not by a WHERE clause, because a row that
     * fell out of scope looks DELETED to the queue -- and for a document register that means
     * withdrawing what was sent. A payment_link row must read as "nothing to send", not as
     * "this payment was cancelled".
     */
    public function depositRow($sourceId)
    {
        $table = (string) $this->client->cfg('rilven_deposit_source_table', 'deposits');
        $this->CI->db->from($table . ' d')->select('d.*')->where('d.id', $sourceId);
        $this->applyDepositScope('d.');
        $row = $this->CI->db->get()->row();
        if (!$row) {
            return NULL;
        }
        // The PAYER, not the patient -- see Rilven_deposit. An insurer settling for a patient
        // is owed the advance back itself.
        $this->attachPatientIds($row, (string) $this->client->cfg('rilven_deposit_payer_column', 'company_id'));
        return $row;
    }

    /**
     * Only the rows that are money, for the two places that QUEUE.
     *
     * Half of `sma_deposits` is the other leg of an entry this library does not send -- 276 rows
     * of 550 in the first window. Queueing them costs a row each, a refusal each, and a permanent
     * place in the failures list: eighty thousand of them in a year, saying nothing.
     *
     * NOT applied by depositRow(), which reads a row the queue already holds. The distinction is
     * the one that made the sales register safe: a row that stops matching looks DELETED to the
     * push loop, and for a register that could withdraw what it sent that would be a document
     * silently removed from the books. This register has no remove() and withdraws nothing, so
     * the risk does not arise here -- but the shape stays the same, because the next register
     * copied from this one may not be so lucky.
     */
    private function applyMovementOnly($alias)
    {
        $kinds = $this->client->cfg('rilven_deposit_kinds', array());
        if (empty($kinds)) {
            return;
        }
        $fields = $this->client->cfg('rilven_deposit_fields', array());
        $column = isset($fields['kind']) && $fields['kind'] !== '' ? $fields['kind'] : 'paid_by';
        $this->CI->db->where_in($alias . $column, array_keys($kinds));
    }

    /** The window payments are taken from -- the same shape as the sales one, and usually the same date. */
    private function applyDepositScope($alias)
    {
        $this->applyWhere($this->client->cfg('rilven_deposit_where', array()), $alias);

        $from   = trim((string) $this->client->cfg('rilven_deposit_from', ''));
        $column = (string) $this->client->cfg('rilven_deposit_date_column', 'date');
        if ($from !== '' && $column !== '') {
            $this->CI->db->where($alias . $column . ' >=', $from);
        }
    }

    public function categoryRow($sourceId)
    {
        $table = (string) $this->client->cfg('rilven_category_source_table', 'categories');
        $this->CI->db->from($table)->where('id', $sourceId);
        $this->applyWhere($this->client->cfg('rilven_category_where', array()), '');
        return $this->CI->db->get()->row();
    }

    /** One row of sma_products, but only if it is a service. */
    public function serviceRow($sourceId)
    {
        $table = (string) $this->client->cfg('rilven_service_source_table', 'products');
        $this->CI->db->from($table . ' p')->where('p.id', $sourceId);
        $this->serviceScope('p.');
        return $this->CI->db->get()->row();
    }

    /**
     * What makes a product a service here.
     *
     * Two tests, and the FIRST is the one that matters: the product sits in a category that is a
     * service category. That is how the clinic itself tells them apart -- `sma_categories` is
     * typed and `product_type = 2` is the service side of it -- and it is the test that cannot
     * disagree with the categories this library syncs, which is what makes every service's
     * categoryId resolvable by construction. A `type` column on the product would be the obvious
     * thing to read instead, and the forks spell it differently.
     *
     * `rilven_service_where` is ANDed on top for an installation that has to narrow it further
     * (an inactive flag, a fork's own discriminator). Set `rilven_service_via_category` to FALSE
     * to drop the category test and rely on that alone -- at which point a service in no synced
     * category is refused with `category-not-synced-yet` rather than sent uncategorised.
     */
    private function serviceScope($alias)
    {
        $this->applyWhere($this->client->cfg('rilven_service_where', array()), $alias);

        if (!$this->client->cfg('rilven_service_via_category', TRUE)) {
            return;
        }

        $prefix   = $this->CI->db->dbprefix;
        $catTable = $prefix . (string) $this->client->cfg('rilven_category_source_table', 'categories');
        $column   = (string) $this->client->cfg('rilven_service_category_column', 'category_id');

        $conditions = array();
        foreach ((array) $this->client->cfg('rilven_category_where', array()) as $col => $value) {
            // Built from the same config the categories' own scope is built from, so the two
            // cannot drift apart: a clinic that re-types its service categories re-types both.
            $conditions[] = $value === NULL
                ? 'rc.' . $col . ' IS NULL'
                : 'rc.' . $col . ' = ' . $this->CI->db->escape($value);
        }

        $this->CI->db->where('EXISTS (SELECT 1 FROM ' . $catTable . ' rc WHERE rc.id = ' . $alias . $column
            . (empty($conditions) ? '' : ' AND ' . implode(' AND ', $conditions)) . ')', NULL, FALSE);
    }

    /**
     * One treatment case, with everything the document needs hung off it.
     *
     * The register itself does no SQL -- it maps and it talks to Rilven -- so the two things a
     * case needs from this database travel attached to the row: its service LINES, and the ids
     * Rilven gave the patient when the contractor register sent them.
     *
     * Those ids come from this library's own outbox and not from a lookup over there, because
     * the route that answers "which branches has this contractor" is not granted to the service
     * account. The outbox recorded them at creation for exactly this kind of reason.
     */
    /**
     * One case, with the shares somebody OTHER than the patient is paying.
     *
     * The case itself comes from the same scope the sale register uses -- so a settlement can
     * never exist for a case that was never sent -- and the shares are the accruing payment rows
     * naming a company in the financier group.
     *
     * Answers NULL when there are no shares. That is not an error and not a gap: most cases are
     * the patient's alone, and a settlement for one of those would be a document that moves
     * nothing. It is also what closes the queue row cleanly if the shares are removed later.
     */
    public function financingRow($sourceId)
    {
        $table = (string) $this->client->cfg('rilven_sale_source_table', 'sales');
        $this->CI->db->from($table . ' s')->select('s.*')->where('s.id', $sourceId);
        $this->applySaleScope('s.');
        $row = $this->CI->db->get()->row();
        if (!$row) {
            return NULL;
        }

        $row->shares = $this->financierShares($row->id);
        if (empty($row->shares)) {
            return NULL;
        }

        $this->attachPatientIds($row);
        return $row;
    }

    /**
     * The accruing rows of one case that name a financier.
     *
     * `company_id` into the financier group, NOT `insurance_group_id` -- that column is NULL in
     * every row of this database, and reading it was the first wrong turn taken here. The
     * financier's name comes along for the comment, so a person reading the settlement in Rilven
     * sees who it is about without another lookup.
     */
    private function financierShares($saleId)
    {
        $payments = (string) $this->client->cfg('rilven_financing_source_table', 'payments');
        $source   = (string) $this->client->cfg('rilven_source_table', 'companies');

        $this->CI->db
            ->select('p.*, c.name AS financier_name')
            ->from($payments . ' p')
            ->join($source . ' c', 'c.id = p.company_id', 'inner')
            ->where('p.sale_id', $saleId)
            ->where('p.type', (string) $this->client->cfg('rilven_financing_payment_type', 'accruing'))
            ->where('p.amount_credit <>', 0)
            ->order_by('p.id', 'ASC');
        $this->applyWhere($this->client->cfg('rilven_insurer_where', array()), 'c.');

        return $this->CI->db->get()->result();
    }

    /**
     * Queue the cases with a financier share that the outbox has never heard of.
     *
     * Bounded by the sale scope, so this can never run ahead of the register whose documents it
     * depends on: a case outside that scope has no document in Rilven, and a settlement naming
     * one would be refused anyway.
     */
    public function backfillFinancing($limit = 2000)
    {
        $prefix   = $this->CI->db->dbprefix;
        $sales    = $prefix . (string) $this->client->cfg('rilven_sale_source_table', 'sales');
        $payments = $prefix . (string) $this->client->cfg('rilven_financing_source_table', 'payments');
        $source   = $prefix . (string) $this->client->cfg('rilven_source_table', 'companies');
        $type     = (string) $this->client->cfg('rilven_financing_payment_type', 'accruing');

        $this->CI->db->select('s.id')->from($sales . ' s')->order_by('s.id', 'ASC')->limit((int) $limit);
        $this->applySaleScope('s.');

        // CAST for the reason spelled out in backfillContractors: comparing an INT id against a
        // VARCHAR external_id converts the COLUMN and puts the unique key out of reach.
        $this->CI->db->where('NOT EXISTS (SELECT 1 FROM ' . $prefix . 'rilven_outbox o'
            . " WHERE o.entity = 'financing' AND o.external_id = CAST(s.id AS CHAR))", NULL, FALSE);

        // Only cases that actually have a share to move.
        $this->CI->db->where('EXISTS (SELECT 1 FROM ' . $payments . ' p'
            . ' JOIN ' . $source . ' c ON c.id = p.company_id'
            . ' WHERE p.sale_id = s.id AND p.type = ' . $this->CI->db->escape($type)
            . ' AND p.amount_credit <> 0'
            . ' AND ' . $this->whereSql($this->client->cfg('rilven_insurer_where', array()), 'c.') . ')',
            NULL, FALSE);

        $queued = 0;
        foreach ($this->CI->db->get()->result() as $row) {
            $this->enqueue('financing', $row->id, $this->financing->typeCode());
            $queued++;
        }
        return $queued;
    }

    /**
     * A config `where` map as a raw SQL fragment, for use inside an EXISTS.
     *
     * The query builder cannot reach into a subquery, and building the fragment by hand is how
     * a scope written once in the config stays written once. Values go through escape(); an
     * empty map answers `1 = 1` rather than nothing, because an empty condition inside an AND
     * is a syntax error and an empty SCOPE means "everything".
     */
    private function whereSql($conditions, $alias)
    {
        if (!is_array($conditions) || empty($conditions)) {
            return '1 = 1';
        }
        $parts = array();
        foreach ($conditions as $column => $value) {
            if (is_array($value)) {
                if (empty($value)) {
                    return '1 = 0';
                }
                $escaped = array();
                foreach ($value as $one) {
                    $escaped[] = $this->CI->db->escape($one);
                }
                $parts[] = $alias . $column . ' IN (' . implode(', ', $escaped) . ')';
            } else {
                $parts[] = $alias . $column . ' = ' . $this->CI->db->escape($value);
            }
        }
        return implode(' AND ', $parts);
    }

    /**
     * Hook for the CMS: a payment was taken or changed, so the case's shares may have moved.
     *
     * Called with sma_payments.sale_id. Never throws: a failure to queue must not become a
     * failure to save the payment.
     */
    public function queueFinancing($saleId)
    {
        try {
            $row = $this->financingRow($saleId);
            if (!$row) {
                return FALSE;
            }
            return $this->enqueue('financing', $row->id, $this->financing->typeCode());
        } catch (Exception $e) {
            log_message('error', 'rilven: queueFinancing failed for ' . $saleId . ': ' . $e->getMessage());
            return FALSE;
        }
    }

    public function saleRow($sourceId)
    {
        $table = (string) $this->client->cfg('rilven_sale_source_table', 'sales');
        $this->CI->db->from($table . ' s')->select('s.*')->where('s.id', $sourceId);
        $this->applySaleScope('s.');
        $row = $this->CI->db->get()->row();
        if (!$row) {
            return NULL;
        }

        $row->items = $this->saleItems($row->id);
        $this->attachPatientIds($row);
        return $row;
    }

    /** The service lines of one case, subservices left out. */
    private function saleItems($saleId)
    {
        $table = (string) $this->client->cfg('rilven_sale_item_table', 'sale_items');

        $this->CI->db->from($table . ' i')->select('i.*')->where('i.sale_id', $saleId)
            ->order_by('i.id', 'ASC');
        $this->applyWhere($this->client->cfg('rilven_sale_item_where', array()), 'i.');

        return $this->CI->db->get()->result();
    }

    /**
     * What Rilven called this case's patient, read out of our own outbox.
     *
     * A case whose patient has never been sent gets nothing, and the register refuses it with
     * `patient-branch-unknown` -- which is the truth, and is fixed by the contractor register
     * catching up rather than by trying again harder.
     */
    private function attachPatientIds($row, $column = NULL)
    {
        $row->rilven_contractor_id = 0;
        $row->rilven_contractor_branch_id = 0;
        $row->patient_id_error = '';
        $row->patient_id_retryable = FALSE;

        if ($column === NULL) {
            $column = (string) $this->client->cfg('rilven_sale_patient_column', 'customer_id');
        }
        if (!isset($row->$column) || (int) $row->$column <= 0) {
            $row->patient_id_error = 'row-has-no-patient';
            return;
        }
        $customerId = (int) $row->$column;

        // 1. What the outbox already knows.
        $known = $this->CI->db->select('rilven_id, rilven_branch_id')
            ->from('rilven_outbox')
            ->where('entity', 'contractor')
            ->where('external_id', (string) $customerId)
            ->get()->row();

        if ($known && (int) $known->rilven_id > 0 && (int) $known->rilven_branch_id > 0) {
            $row->rilven_contractor_id = (int) $known->rilven_id;
            $row->rilven_contractor_branch_id = (int) $known->rilven_branch_id;
            return;
        }

        // 2. What this run has already had to ask for. A day's cases name the same patients
        //    repeatedly, and a patient with no outbox row would otherwise be asked for every time.
        if (isset($this->patientIds[$customerId])) {
            $cached = $this->patientIds[$customerId];
            $row->rilven_contractor_id = $cached['id'];
            $row->rilven_contractor_branch_id = $cached['branchId'];
            $row->patient_id_error = $cached['error'];
            $row->patient_id_retryable = $cached['retryable'];
            return;
        }

        // 3. Ask Rilven, and WRITE THE ANSWER BACK.
        //
        //    The outbox captured these ids only when it CREATED a patient -- 379 of 70 045 here.
        //    Recovering the rest by asking is the only route, and persisting what comes back is
        //    what stops it being 69 666 lookups every single run.
        $code = $this->contractor->code($customerId);
        if ($code === NULL) {
            $row->patient_id_error = 'patient-code-format-is-invalid: ' . $customerId;
            return;
        }

        $resolved = $this->contractor->resolveIds($code);

        // "Not over there yet" and "does not exist here either" wear the same words and must not.
        //
        // A patient the contractor register has simply not reached is a DEPENDENCY: it waits, and
        // the wait is free -- see the push loop, which does not count a dependency towards the
        // outage guard. A customer_id that names no row in this database at all is not waiting
        // for anything. Case 198642 points at company 1, which does not exist: it spent nine
        // attempts, one a minute, being told to be patient about a row nobody will ever write.
        //
        // Asked only when the answer was "not synced yet", so the ordinary case costs no query.
        if (!$resolved['ok'] && $resolved['retryable']
                && strpos($resolved['error'], 'patient-not-synced-yet') === 0
                && !$this->sourceRowExists($customerId)) {
            $resolved['error'] = 'patient-does-not-exist: ' . $customerId;
            $resolved['retryable'] = FALSE;
        }

        $this->patientIds[$customerId] = array(
            'id'        => $resolved['ok'] ? (int) $resolved['id'] : 0,
            'branchId'  => $resolved['ok'] ? (int) $resolved['branchId'] : 0,
            'error'     => $resolved['ok'] ? '' : $resolved['error'],
            'retryable' => $resolved['ok'] ? FALSE : (bool) $resolved['retryable'],
        );

        if (!$resolved['ok']) {
            $row->patient_id_error = $resolved['error'];
            $row->patient_id_retryable = (bool) $resolved['retryable'];
            return;
        }

        $row->rilven_contractor_id = (int) $resolved['id'];
        $row->rilven_contractor_branch_id = (int) $resolved['branchId'];

        if ($known) {
            // Only onto a row that exists. A patient with no movement has never been queued, and
            // inventing an outbox entry for them here would make the catch-up think they were sent.
            $this->CI->db->where('entity', 'contractor')
                ->where('external_id', (string) $customerId)
                ->update('rilven_outbox', array(
                    'rilven_id'        => (int) $resolved['id'],
                    'rilven_branch_id' => (int) $resolved['branchId'],
                ));
        }
    }

    /**
     * Which cases this register sends.
     *
     * A window (`rilven_sale_from`) and whatever else the installation adds. The window is the
     * important half: an accrual dated inside a closed period cannot be undone from here.
     */
    private function applySaleScope($alias)
    {
        $this->applyWhere($this->client->cfg('rilven_sale_where', array()), $alias);

        $from   = trim((string) $this->client->cfg('rilven_sale_from', ''));
        $column = (string) $this->client->cfg('rilven_sale_date_column', 'date');
        if ($from !== '' && $column !== '') {
            $this->CI->db->where($alias . $column . ' >=', $from);
        }
    }

    /**
     * How many cases in scope name a patient this outbox cannot give a BRANCH for.
     *
     * The number that decides whether this register can run at all. The accrual's 1410 leg is
     * keyed on the patient's branch and the engine reads it unboxed, so a case without one is not
     * a document with a blank field -- it is a refusal. Counted here rather than discovered one
     * case at a time.
     */
    public function salesWithoutPatientBranch()
    {
        $prefix = $this->CI->db->dbprefix;
        $table  = $prefix . (string) $this->client->cfg('rilven_sale_source_table', 'sales');
        $column = (string) $this->client->cfg('rilven_sale_patient_column', 'customer_id');

        $this->CI->db->from($table . ' s');
        $this->applySaleScope('s.');
        $this->CI->db->where('NOT EXISTS (SELECT 1 FROM ' . $prefix . 'rilven_outbox o'
            . " WHERE o.entity = 'contractor' AND o.status = " . (int) self::SENT
            . ' AND o.rilven_branch_id IS NOT NULL AND o.rilven_branch_id > 0'
            . ' AND o.external_id = CAST(s.' . $column . ' AS CHAR))', NULL, FALSE);

        return (int) $this->CI->db->count_all_results();
    }

    public function saleCount()
    {
        $table = (string) $this->client->cfg('rilven_sale_source_table', 'sales');
        $this->CI->db->from($table . ' s');
        $this->applySaleScope('s.');
        return (int) $this->CI->db->count_all_results();
    }

    /** How many rows each register would send, by the configured tests. */
    /** How many payments are in scope, and how many of them are real movements. */
    public function depositCount()
    {
        $table = (string) $this->client->cfg('rilven_deposit_source_table', 'deposits');
        $this->CI->db->from($table . ' d')->select('d.*');
        $this->applyDepositScope('d.');

        $counts = array('total' => 0, 'in' => 0, 'out' => 0, 'other-leg' => 0, 'unknown' => array());
        foreach ($this->CI->db->get()->result() as $row) {
            $counts['total']++;
            $direction = $this->deposit->directionOf($row);
            if ($direction === NULL) {
                $seen = $this->deposit->val($row, 'kind');
                $seen = $seen === '' ? '(empty)' : $seen;
                // The other leg is EXPECTED and not a problem -- see Rilven_deposit. Counted
                // apart from the kinds nobody has decided about.
                if (in_array($seen, (array) $this->client->cfg('rilven_deposit_other_leg', array()), TRUE)) {
                    $counts['other-leg']++;
                    continue;
                }
                if (!isset($counts['unknown'][$seen])) {
                    $counts['unknown'][$seen] = 0;
                }
                $counts['unknown'][$seen]++;
                continue;
            }
            $counts[$direction]++;
        }
        return $counts;
    }

    /** How many destinations are in scope, and how they break down by kind. */
    public function cashCount()
    {
        $table = (string) $this->client->cfg('rilven_cash_source_table', 'cash');
        $this->CI->db->from($table . ' c')->select('c.*');
        $this->applyWhere($this->client->cfg('rilven_cash_where', array()), 'c.');

        $counts = array('total' => 0, 'cash-machine' => 0, 'bank-account' => 0, 'unknown' => array());
        foreach ($this->CI->db->get()->result() as $row) {
            $counts['total']++;
            $kind = $this->cash->kindOf($row);
            if ($kind === NULL) {
                $seen = $this->cash->val($row, 'kind');
                $seen = $seen === '' ? '(empty)' : $seen;
                if (!isset($counts['unknown'][$seen])) {
                    $counts['unknown'][$seen] = 0;
                }
                $counts['unknown'][$seen]++;
                continue;
            }
            $counts[$kind]++;
        }
        return $counts;
    }

    public function categoryCount()
    {
        $table = (string) $this->client->cfg('rilven_category_source_table', 'categories');
        $this->CI->db->from($table);
        $this->applyWhere($this->client->cfg('rilven_category_where', array()), '');
        return (int) $this->CI->db->count_all_results();
    }

    public function serviceCount()
    {
        $table = (string) $this->client->cfg('rilven_service_source_table', 'products');
        $this->CI->db->from($table . ' p');
        $this->serviceScope('p.');
        return (int) $this->CI->db->count_all_results();
    }

    /**
     * How many services point at a category this library does NOT sync.
     *
     * The number to read before switching the services register on. A service carries its
     * category's Rilven id and waits for it; a service whose category is not a SERVICE category
     * waits for one that is never coming, and burns its ten attempts finding that out. With
     * `rilven_service_where` and `rilven_category_where` set independently -- `service = 1` here,
     * `product_type = 2` there -- nothing guarantees the two agree, and this is what measures it
     * instead of assuming.
     *
     * A service with no category at all is not counted: it is sent without one, which is a
     * different thing from being held for one.
     */
    public function servicesOutsideSyncedCategories()
    {
        $prefix   = $this->CI->db->dbprefix;
        $table    = $prefix . (string) $this->client->cfg('rilven_service_source_table', 'products');
        $catTable = $prefix . (string) $this->client->cfg('rilven_category_source_table', 'categories');
        $column   = (string) $this->client->cfg('rilven_service_category_column', 'category_id');

        $conditions = array();
        foreach ((array) $this->client->cfg('rilven_category_where', array()) as $col => $value) {
            $conditions[] = $value === NULL
                ? 'rc.' . $col . ' IS NULL'
                : 'rc.' . $col . ' = ' . $this->CI->db->escape($value);
        }

        $this->CI->db->from($table . ' p');
        $this->serviceScope('p.');
        $this->CI->db->where('p.' . $column . ' IS NOT NULL', NULL, FALSE);
        $this->CI->db->where('p.' . $column . ' > 0', NULL, FALSE);
        $this->CI->db->where('NOT EXISTS (SELECT 1 FROM ' . $catTable . ' rc WHERE rc.id = p.' . $column
            . (empty($conditions) ? '' : ' AND ' . implode(' AND ', $conditions)) . ')', NULL, FALSE);

        return (int) $this->CI->db->count_all_results();
    }

    /**
     * Take back what was sent for a source row that is no longer in scope.
     *
     * Only a register that can do it -- one that knows how to remove a document over there -- and
     * only when this outbox knows which document. A reference register has no {@code remove} and
     * nothing happens, which is right: a service withdrawn from the catalogue is still named by
     * every document that used it.
     *
     * @return array error (empty when there was nothing to do), retryable
     */
    private function withdraw($register, $entry)
    {
        if (!method_exists($register, 'remove') || (int) $entry->rilven_id <= 0) {
            return array('error' => '', 'retryable' => FALSE);
        }

        $gone = $register->remove((int) $entry->rilven_id);
        if ($gone['ok']) {
            return array('error' => 'source-row-gone: document ' . $entry->rilven_id . ' removed',
                         'retryable' => FALSE);
        }
        return array('error' => $gone['error'], 'retryable' => $gone['retryable']);
    }

    // -----------------------------------------------------------------------
    // outbox bookkeeping
    // -----------------------------------------------------------------------

    private function finish($id, $status, $hash, $error, $rilvenId, $branchId)
    {
        // payload_hash stands for "the payload Rilven last ACCEPTED", so it is written on
        // every close, NULL included. Leaving the old value behind on a failure is what
        // would let a row that never arrived read as unchanged on the next run.
        $set = array(
            'status'       => $status,
            'payload_hash' => $hash,
            'last_error'   => $error === NULL ? NULL : $this->clip($error, 500),
            'updated_at'   => date('Y-m-d H:i:s'),
        );
        if ($rilvenId !== NULL && (int) $rilvenId > 0) {
            $set['rilven_id'] = (int) $rilvenId;
        }
        if ($branchId !== NULL && (int) $branchId > 0) {
            $set['rilven_branch_id'] = (int) $branchId;
        }
        $this->CI->db->where('id', $id)->update('rilven_outbox', $set);
    }

    /**
     * Remember what status Rilven's document is in.
     *
     * Kept on the queue row rather than asked for when it is wanted, because the thing that wants
     * it is the CMS saving a case -- and a save must not wait on, or fail with, somebody else's
     * server. That is the whole reason this is a queue and not a pair of synchronous calls.
     *
     * The value is therefore as fresh as the last run, no fresher. That is honest in the direction
     * that matters: it only ever refuses what it positively saw confirmed, and a document confirmed
     * by hand in Rilven since the last tick is caught on the next push instead -- by the same
     * refusal that sets this, so the guard closes one run later rather than never.
     *
     * @see Rilven::documentStatus()
     */
    private function recordDocumentStatus($id, $result)
    {
        if (!isset($result['rilvenStatus'])) {
            return;
        }
        $this->CI->db->where('id', $id)
                     ->update('rilven_outbox', array('rilven_status' => (int) $result['rilvenStatus']));
    }

    /**
     * Record what the case looked like at the moment it was accepted.
     *
     * Written on the SEND and not by the sweep, deliberately. If the sweep stored what it saw
     * when it queued the row, a second edit arriving between the sweep and the push would be
     * recorded as sent when it never was, and that change would be lost for good.
     *
     * Best effort: the document is already in Rilven and failing here must not undo that. A row
     * left without a fingerprint is re-adopted by the next sweep, which costs nothing.
     */
    private function stampFingerprint($entity, $outboxId, $saleId)
    {
        if ($entity !== 'sale') {
            return;
        }
        try {
            $rows = $this->saleFingerprints(1, (string) $saleId);
            if (!empty($rows)) {
                $this->CI->db->where('id', $outboxId)
                     ->update('rilven_outbox', array('source_fingerprint' => $rows[0]->fingerprint));
            }
        } catch (Exception $e) {
            log_message('error', 'rilven: could not fingerprint sale ' . $saleId . ': ' . $e->getMessage());
        }
    }

    /** Keep what Rilven answered with, on a row that is staying in the queue. */
    private function finishIds($id, $rilvenId, $branchId)
    {
        $set = array();
        if ($rilvenId !== NULL && (int) $rilvenId > 0) {
            $set['rilven_id'] = (int) $rilvenId;
        }
        if ($branchId !== NULL && (int) $branchId > 0) {
            $set['rilven_branch_id'] = (int) $branchId;
        }
        if (!empty($set)) {
            $this->CI->db->where('id', $id)->update('rilven_outbox', $set);
        }
    }

    private function bump($id, $error)
    {
        $sql = 'UPDATE ' . $this->CI->db->dbprefix('rilven_outbox')
             . ' SET status = ?, attempts = attempts + 1, last_error = ?, updated_at = NOW()'
             . ' WHERE id = ?';
        $this->CI->db->query($sql, array(self::FAILED, $this->clip($error, 500), $id));
    }

    /**
     * Put a row's refusal back in front of somebody.
     *
     * CI logging is switched off on these installations, so log_message() goes nowhere.
     * Refusals are collected and printed by the cron instead -- that is the log that
     * exists. Before this, fifty-five rows refused for one reason looked exactly like a
     * quiet day.
     */
    private function refuse($externalId, $reason)
    {
        $key = $this->clip($reason, 120);
        if (!isset($this->refusals[$key])) {
            $this->refusals[$key] = array('count' => 0, 'first' => $externalId);
        }
        $this->refusals[$key]['count']++;
    }

    /**
     * Re-open rows that were given up on, so a fixed cause can be re-tried.
     *
     * @param string|NULL $entity one register, or NULL for every one of them
     */
    public function retry($includeGivenUp = TRUE, $entity = NULL)
    {
        $statuses = $includeGivenUp ? array(self::FAILED, self::GIVEN_UP) : array(self::FAILED);
        if ($entity !== NULL) {
            $this->CI->db->where('entity', $entity);
        }
        $this->CI->db->where_in('status', $statuses)
            ->update('rilven_outbox', array(
                'status'     => self::PENDING,
                'attempts'   => 0,
                'last_error' => NULL,
                'updated_at' => date('Y-m-d H:i:s'),
            ));
        return $this->CI->db->affected_rows();
    }

    /**
     * Re-open every patient, including the ones already recorded as delivered.
     *
     * For one situation only: Rilven no longer has what this outbox says it has. That is not
     * hypothetical -- on 2026-09-16 the counterparties of this company were deleted over
     * there while the outbox went on reporting seventy thousand rows as sent, so the sync
     * was inert and looked healthy. `retry()` does not cover it, because those rows never
     * failed.
     *
     * The payload hash goes too. It means "what Rilven has accepted", and the answer is now
     * nothing; left in place it would make every row read as unchanged and send none of them.
     *
     * Deliberately not reachable from the cron: this queues the clinic's whole register.
     */
    public function resend($entity = NULL)
    {
        if ($entity !== NULL) {
            $this->CI->db->where('entity', $entity);
        }
        $this->CI->db
            ->update('rilven_outbox', array(
                'status'       => self::PENDING,
                'attempts'     => 0,
                'payload_hash' => NULL,
                'last_error'   => NULL,
                'updated_at'   => date('Y-m-d H:i:s'),
            ));
        return $this->CI->db->affected_rows();
    }

    /** Where the queue stands: a count per status and the age of the oldest waiting row. */
    public function counts()
    {
        return $this->CI->db
            ->select('entity, status, COUNT(*) AS rows_count, MIN(created_at) AS oldest, MAX(updated_at) AS newest')
            ->from('rilven_outbox')
            ->group_by('entity, status')
            ->order_by('entity, status', 'ASC')
            ->get()->result();
    }

    /**
     * How many cases the edit guard is currently holding, by document status.
     *
     * Worth its own line on the status page because the guard is invisible otherwise: a column
     * that silently stayed NULL and a guard that correctly refuses nothing look exactly alike
     * from outside, and the difference is whether the accruals are protected.
     *
     * @see isPostedInRilven()
     */
    public function documentStatusCounts()
    {
        return $this->CI->db
            ->select('rilven_status, COUNT(*) AS rows_count')
            ->from('rilven_outbox')
            ->where('entity', 'sale')
            ->group_by('rilven_status')
            ->order_by('rilven_status', 'ASC')
            ->get()->result();
    }

    public function failures($limit = 50)
    {
        return $this->CI->db
            ->select('entity, external_id, status, attempts, last_error, updated_at')
            ->from('rilven_outbox')
            ->where_in('status', array(self::FAILED, self::GIVEN_UP))
            ->order_by('updated_at', 'DESC')
            ->limit($limit)
            ->get()->result();
    }

    private function clip($value, $max)
    {
        $value = (string) $value;
        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }
}
