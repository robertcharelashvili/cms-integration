<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * The clinic's payroll as computed here, as Rilven's monthly salary-variable document.
 *
 * WHAT IS SENT. Every salary type of a payroll run: 1-4 the doctors' percentages of the services
 * they performed (outpatient, day hospital, inpatient, team output), 5 duty shifts, 6 fixed pay.
 * Rilven computes income tax and pension on it in the salary accrual; bonuses, vacation pay and
 * other one-off items are added there by hand. See COMPONENTS for when 6 must stop being sent.
 *
 * THE TYPES ARE THE KIND OF CASE, not a speciality. Companies_model groups a sale's items by
 * `sales.service` and names them in its own comments: service 1 -> type 1 outpatient, 3 -> type 2
 * day hospital, 4 -> type 3 inpatient; 4 is the team output. The by_staff column names say
 * otherwise (type 2 lands in `daricxuli_radiacliuli`, type 3 in `daricxuli_dstc`) and so does the
 * $salary_type label array in the same file (2 and 3 swapped); the data settles it -- in run 489
 * type 2 is discharged the same day, type 3 stays 3.1 days on average.
 *
 * WHICH RUN. Only a CONFIRMED one (sma_daricxva.rilven_status = 1, set from the salary register
 * by an owner or the accounting department), and only as it stood when confirmed. A month is
 * recalculated several times (August 2026: runs 483, 484, 487, 488, 489),
 * and only the LAST one counts. One Rilven document per month, code `{prefix}{YYYY-MM}`; a newer
 * run replaces its lines. Rilven refuses that once a salary accrual has taken the month
 * (`salary-variable-is-in-payroll`) -- the payroll has been taxed on the old figures, and it is
 * for a person to delete that accrual, not for a cron to change it underneath.
 *
 * WHAT A LINE IS. One sma_daricxva_by_staff row: staff x position x department, carrying one
 * salary type, its amount in `daricxuli_sul` (GROSS). The employee travels by personal number
 * (sma_companies.vat_no = Rilven's tax_code), the department by its CMS id and name --
 * `warehouse_id` is NULL on every row, so the id is looked up from the name. Administration
 * staff are expensed on 7410, everybody else on Rilven's default, 7320.
 *
 * WHAT RILVEN DOES WITH IT. Posted: Dt 7320/7410 department / Kt 3160 employee. The salary accrual
 * then takes it Dt 3160 / Kt 3130 and withholds tax on it with the rest of the person's pay.
 */
class Rilven_payroll
{
    /** @var CI_Controller */
    private $CI;

    /** @var Rilven_client */
    private $client;

    /**
     * CMS salary type -> Rilven component. The same numbers, kept apart on purpose.
     *
     * 5 (duty shifts) and 6 (fixed pay) are sent too since 2026-09-30. The salary cards this
     * library sends (syncCards) arrive in Rilven as EXTERNAL cards, which its accrual never pays,
     * so 6 stays here: the card says how a person is paid, the line is what the run paid.
     */
    const COMPONENTS = array(1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 6 => 6);

    public function __construct()
    {
        $this->CI = get_instance();
        $this->CI->load->library('rilven_client');
        $this->client = $this->CI->rilven_client;
        $this->CI->load->database();
    }

    /** OFF by default: an upgraded installation must not start sending payroll on its own. */
    public function enabled()
    {
        return $this->client->cfg('rilven_payroll_enabled', FALSE) ? TRUE : FALSE;
    }

    /**
     * The months whose latest run has not been sent as it now stands.
     *
     * Only the last `rilven_payroll_months_back` months: an old month is closed, and re-reading it
     * every tick would only ever find the refusal that payroll has taken it.
     *
     * @return array of result rows, one per month looked at
     */
    public function sweep()
    {
        // From a fixed first month when one is configured (LJ: everything since 2025-01),
        // otherwise the last `rilven_payroll_months_back` months.
        $since = trim((string) $this->client->cfg('rilven_payroll_since', ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            $back  = max(1, (int) $this->client->cfg('rilven_payroll_months_back', 3));
            $since = $this->client->clinicToday('-' . $back . ' months');
        }
        $out = array();
        // the cards first, so the months that follow can name the card each line was paid under
        if ($this->cardsEnabled()) {
            $cards = $this->syncCards(FALSE);
            if ($cards['sent'] || !$cards['ok']) {
                $out[] = $cards;
            }
        }
        $runs = $this->latestRuns($since);
        if (!$runs) {
            return $out;
        }

        // Which months changed, from ONE aggregate over every run -- not by building each
        // month's 500 lines on every tick only to find the fingerprint unchanged.
        $fingerprints = $this->fingerprints(array_map(function ($r) { return (int) $r->id; }, $runs));

        // A cap per tick: a first catch-up of twenty months must not hold the cron for minutes.
        // The oldest first, so the ledger fills in order; the rest follow on the next ticks.
        $budget = max(1, (int) $this->client->cfg('rilven_payroll_months_per_tick', 2));
        foreach ($runs as $run) {
            $fp = isset($fingerprints[(int) $run->id]) ? $fingerprints[(int) $run->id] : $run->id . ':0:0';
            if ($this->client->state('payroll_' . $run->month) === $fp
                && (!$this->casesEnabled() || $this->client->state('payroll_cases_' . $run->month) === $fp)) {
                continue;
            }
            if ((string) $run->confirmed_fingerprint !== $fp) {
                // edited after it was confirmed: not payroll until confirmed again. Said once per
                // change, not every minute.
                if ($this->client->state('payroll_changed_' . $run->month) !== $fp) {
                    $this->client->setState('payroll_changed_' . $run->month, $fp);
                    $out[] = array('month' => $run->month, 'run' => (int) $run->id, 'ok' => FALSE,
                        'sent' => FALSE, 'error' => 'changed after confirmation -- confirm the run again');
                }
                continue;
            }
            if ($budget-- <= 0) {
                break;
            }
            $out[] = $this->sendRun($run, FALSE);
        }
        return $out;
    }

    /**
     * did => "did:lines:total" exactly as sendRun() computes it, in SQL. The total is summed as
     * ROUND(amount x 10000) per line, which is what lines() sends, so the two never disagree.
     */
    private function fingerprints(array $dids)
    {
        if (!$dids) {
            return array();
        }
        $staff = $this->CI->db->dbprefix('daricxva_by_staff');
        $types = implode(',', array_map('intval', array_keys(self::COMPONENTS)));
        $in    = implode(',', array_map('intval', $dids));
        $sql = "SELECT did, COUNT(*) AS n, SUM(ROUND(daricxuli_sul * 10000)) AS total FROM {$staff}"
             . " WHERE did IN ({$in}) AND salary_type IN ({$types}) AND daricxuli_sul > 0 GROUP BY did";
        $out = array();
        foreach ($this->CI->db->query($sql)->result() as $r) {
            $out[(int) $r->did] = $r->did . ':' . $r->n . ':' . (int) $r->total;
        }
        return $out;
    }

    /**
     * One month, by hand: `php index.php admin/rilven_sync payroll 2026-08`.
     *
     * @param bool $force send even when the fingerprint says nothing changed
     */
    public function syncMonth($month, $force = FALSE)
    {
        if (!preg_match('/^\d{4}-\d{2}$/', (string) $month)) {
            return array('month' => $month, 'ok' => FALSE, 'error' => 'month must be YYYY-MM');
        }
        foreach ($this->latestRuns($month . '-01') as $run) {
            if ($run->month === $month) {
                if ((string) $run->confirmed_fingerprint !== $this->fingerprintOf($run->id)) {
                    return array('month' => $month, 'run' => (int) $run->id, 'ok' => FALSE,
                        'error' => 'run ' . $run->id . ' changed after confirmation -- confirm it again');
                }
                return $this->sendRun($run, $force);
            }
        }
        return array('month' => $month, 'ok' => FALSE, 'error' => 'no CONFIRMED payroll run for this month');
    }

    /**
     * The latest CONFIRMED run of every month from $since on, with the fingerprint it was
     * confirmed at.
     *
     * A run is recalculated and edited in place for days before it is right; only the one an
     * owner or the accounting department confirmed (sma_daricxva.rilven_status = 1) is payroll.
     * A newer run that is still being worked on does not hide the confirmed one -- the month keeps
     * what was confirmed until the new one is confirmed in its turn.
     */
    private function latestRuns($since)
    {
        $t = $this->CI->db->dbprefix('daricxva');
        $sql = "SELECT r.id, r.month, d.rilven_fingerprint AS confirmed_fingerprint"
             . " FROM (SELECT MAX(id) AS id, DATE_FORMAT(post_date, '%Y-%m') AS month FROM {$t}"
             . "        WHERE post_date >= ? AND rilven_status = 1 GROUP BY DATE_FORMAT(post_date, '%Y-%m')) r"
             . " JOIN {$t} d ON d.id = r.id ORDER BY r.month";
        return $this->CI->db->query($sql, array($since))->result();
    }

    /**
     * did:lines:total -- what a run is, as far as Rilven is concerned. Stored on the run when it is
     * confirmed, so an edit made after the confirmation is noticed instead of sent.
     */
    public function fingerprintOf($did)
    {
        $f = $this->fingerprints(array((int) $did));
        return isset($f[(int) $did]) ? $f[(int) $did] : ((int) $did) . ':0:0';
    }

    private function sendRun($run, $force)
    {
        $lines = $this->lines((int) $run->id);
        $total = 0;
        foreach ($lines as $l) {
            $total += $l['amount'];
        }
        // the run's id, what it sums to and how many lines: a new run or a changed one is sent
        $fingerprint = $run->id . ':' . count($lines) . ':' . $total;
        $stateKey = 'payroll_' . $run->month;
        $result = array('month' => $run->month, 'run' => (int) $run->id, 'lines' => count($lines),
                        'amount' => $total / 10000, 'ok' => TRUE, 'error' => '', 'sent' => FALSE);

        if (!$force && $this->client->state($stateKey) === $fingerprint) {
            return $this->sendCasesOf($run, $fingerprint, $force, $result);
        }

        $prefix = (string) $this->client->cfg('rilven_payroll_code_prefix', 'cms-');
        $body = array(
            'code'            => $prefix . $run->month,
            'period'          => $run->month,
            'companyBranchId' => $this->client->cfg('rilven_payroll_company_branch_id', NULL),
            'comment'         => 'CMS run ' . $run->id,
            'post'            => TRUE,
            'items'           => $lines,
        );
        $answer = $this->client->post('salary-variable/sync', $body);
        $result['sent'] = TRUE;
        if (!$answer['ok']) {
            $result['ok'] = FALSE;
            $result['error'] = $answer['error'];
            // payroll has taken the month: remembered, so the refusal is not asked for every tick
            // until somebody deletes that accrual or a newer run appears
            if (strpos($answer['error'], 'salary-variable-is-in-payroll') === 0) {
                $this->client->setState($stateKey, $fingerprint);
                // the month is frozen by payroll, but its breakdown only explains it: still sent
                return $this->sendCasesOf($run, $fingerprint, $force, $result);
            }
            return $result;
        }
        $this->client->setState($stateKey, $fingerprint);
        return $this->sendCasesOf($run, $fingerprint, $force, $result);
    }

    /**
     * One month's per-case breakdown alone, sent again now, the month's lines untouched:
     * `php index.php admin/rilven_sync payroll_cases 2026-03`. For a breakdown that needs resending
     * when the month itself does not (a month already in payroll, or a fix on this side).
     */
    public function syncCases($month)
    {
        if (!preg_match('/^\d{4}-\d{2}$/', (string) $month)) {
            return array('month' => $month, 'ok' => FALSE, 'error' => 'month must be YYYY-MM');
        }
        foreach ($this->latestRuns($month . '-01') as $run) {
            if ($run->month === $month) {
                $fingerprint = $this->fingerprintOf($run->id);
                $result = array('month' => $month, 'run' => (int) $run->id, 'ok' => TRUE, 'error' => '', 'sent' => FALSE);
                if (!$this->casesEnabled()) {
                    $result['ok'] = FALSE;
                    $result['error'] = 'rilven_payroll_cases_enabled is off';
                    return $result;
                }
                return $this->sendCasesOf($run, $fingerprint, TRUE, $result);
            }
        }
        return array('month' => $month, 'ok' => FALSE, 'error' => 'no CONFIRMED payroll run for this month');
    }

    public function cardsEnabled()
    {
        return $this->client->cfg('rilven_payroll_cards_enabled', FALSE) ? TRUE : FALSE;
    }

    public function casesEnabled()
    {
        return $this->client->cfg('rilven_payroll_cases_enabled', FALSE) ? TRUE : FALSE;
    }

    /**
     * The run's per-case breakdown (sma_daricxvebi_detall), after its month: one row per staff x
     * case line x salary type, types 1-4 only -- 5 and 6 are not case-based. Through the ordinary
     * register salary-variable-case: the month's breakdown deleted whole, then inserted in batches.
     * Proved on run 489: per staff and type it adds up to by_staff exactly.
     *
     * A subservice line (sub = 1) was never sent to Rilven as a line of its own; it goes with its
     * case and the flag, and Rilven counts it against the case only.
     */
    private function sendCasesOf($run, $fingerprint, $force, $result)
    {
        if (!$this->casesEnabled()) {
            return $result;
        }
        $stateKey = 'payroll_cases_' . $run->month;
        if (!$force && $this->client->state($stateKey) === $fingerprint) {
            return $result;
        }
        $rows = $this->cases((int) $run->id);
        $prefix = (string) $this->client->cfg('rilven_payroll_code_prefix', 'cms-');
        $chunk = max(100, (int) $this->client->cfg('rilven_payroll_cases_chunk', 2000));
        // the month's document by its code, then its breakdown replaced: deleted whole, inserted in batches
        $doc = $this->client->get('salary-variable/get/' . rawurlencode($prefix . $run->month), array('by' => 'code'));
        if (!$doc['ok'] || !isset($doc['data']['salaryVariable']['id'])) {
            $result['ok'] = FALSE;
            $result['error'] = 'cases: the month ' . $prefix . $run->month . ' is not in Rilven (' . $doc['error'] . ')';
            return $result;
        }
        $docId = (int) $doc['data']['salaryVariable']['id'];
        $gone = $this->client->request('DELETE', 'salary-variable-case/delete', array('salaryVariableId' => $docId));
        if (!$gone['ok']) {
            $result['ok'] = FALSE;
            $result['error'] = 'cases: ' . $gone['error'];
            return $result;
        }
        $written = 0;
        $unknown = 0;
        foreach ($rows ? array_chunk($rows, $chunk) : array() as $part) {
            $answer = $this->client->post('salary-variable-case/insert', array('salaryVariableId' => $docId, 'items' => $part));
            if (!$answer['ok']) {
                $result['ok'] = FALSE;
                $result['error'] = 'cases: ' . $answer['error'];
                return $result;
            }
            $data = isset($answer['data']) ? $answer['data'] : array();
            $written += isset($data['written']) ? (int) $data['written'] : 0;
            $unknown += isset($data['unknownEmployees']) ? count($data['unknownEmployees']) : 0;
        }
        $this->client->setState($stateKey, $fingerprint);
        $result['sent'] = TRUE;
        $result['cases'] = $written;
        $result['casesUnknownStaff'] = $unknown;
        return $result;
    }

    /** The run's case rows, summed per staff x case line x type x position, as Rilven's items. */
    private function cases($did)
    {
        $detall = $this->CI->db->dbprefix('daricxvebi_detall');
        $comp   = $this->CI->db->dbprefix('companies');
        // The name exactly as the month's lines send it (by_staff.staff_name), the card's name only
        // when the run has no line for the person: Rilven falls back to the name for staff with no
        // personal number, and two spellings of one person found the line and lost the cases
        // (2025-10 and 2026-03, one pair each).
        $staff  = $this->CI->db->dbprefix('daricxva_by_staff');
        $sql = "SELECT d.staff_id, TRIM(c.vat_no) AS tax_code, COALESCE(bs.staff_name, c.name) AS staff_name, d.salary_type,"
             . " d.sale_id, d.sale_item_id, MAX(d.sub) AS sub, d.position_id, d.warehouse_id,"
             . " MAX(d.ganyofileba) AS department_name, SUM(ROUND(d.salary * 10000)) AS amount"
             . " FROM {$detall} d LEFT JOIN {$comp} c ON c.id = d.staff_id"
             . " LEFT JOIN (SELECT staff_id, MAX(staff_name) AS staff_name FROM {$staff} WHERE did = ? GROUP BY staff_id) bs"
             . "        ON bs.staff_id = d.staff_id"
             . " WHERE d.did = ? AND d.salary_type BETWEEN 1 AND 4"
             . " GROUP BY d.staff_id, c.vat_no, bs.staff_name, c.name, d.salary_type, d.sale_id, d.sale_item_id, d.position_id, d.warehouse_id"
             . " HAVING SUM(ROUND(d.salary * 10000)) <> 0"
             . " ORDER BY d.staff_id, d.sale_id, d.sale_item_id";
        $items = array();
        foreach ($this->CI->db->query($sql, array($did, $did))->result() as $r) {
            $items[] = array(
                'employeeTaxCode' => (string) $r->tax_code,
                'employeeName'    => (string) $r->staff_name,
                'component'       => self::COMPONENTS[(int) $r->salary_type],
                'caseCode'        => $r->sale_id === NULL ? NULL : (string) $r->sale_id,
                'lineCode'        => $r->sale_item_id === NULL ? NULL : (string) $r->sale_item_id,
                'subservice'      => (int) $r->sub === 1,
                'positionCode'    => $r->position_id === NULL ? NULL : (string) $r->position_id,
                'departmentCode'  => $r->warehouse_id === NULL ? NULL : 'cms-' . $r->warehouse_id,
                'departmentName'  => trim((string) $r->department_name),
                'amount'          => (int) $r->amount,
            );
        }
        return $items;
    }

    /**
     * The salary cards (sma_staff_positions) as Rilven's external cards, all of them, when anything
     * about them changed since the last send. Rilven matches each by its code and reports back the
     * staff it does not know as employees.
     *
     * @param bool $force send even when nothing changed
     */
    public function syncCards($force = FALSE)
    {
        $sp   = $this->CI->db->dbprefix('staff_positions');
        $comp = $this->CI->db->dbprefix('companies');
        $pos  = $this->CI->db->dbprefix('positions');
        $wh   = $this->CI->db->dbprefix('warehouses');
        $sql = "SELECT sp.id, TRIM(c.vat_no) AS tax_code, c.name AS staff_name, sp.position_id, p.name AS position_name,"
             . " sp.warehouse, w.name AS warehouse_name, sp.salary_type, sp.type_of_accident, sp.ammount, sp.status,"
             . " sp.start_date, sp.end_date"
             . " FROM {$sp} sp LEFT JOIN {$comp} c ON c.id = sp.staff_id"
             . " LEFT JOIN {$pos} p ON p.id = sp.position_id LEFT JOIN {$wh} w ON w.id = sp.warehouse"
             . " WHERE sp.salary_type IN (1, 2, 3) ORDER BY sp.id";
        $cards = array();
        foreach ($this->CI->db->query($sql)->result() as $r) {
            $cards[] = array(
                'code'            => (string) $r->id,
                'employeeTaxCode' => (string) $r->tax_code,
                'employeeName'    => (string) $r->staff_name,
                'positionCode'    => $r->position_id === NULL ? NULL : (string) $r->position_id,
                'positionName'    => trim((string) $r->position_name),
                'departmentCode'  => $r->warehouse === NULL ? NULL : 'cms-' . $r->warehouse,
                'departmentName'  => trim((string) $r->warehouse_name),
                'kind'            => (int) $r->salary_type,
                'caseKind'        => $r->type_of_accident === NULL ? NULL : (int) $r->type_of_accident,
                'rate'            => trim((string) $r->ammount),
                'status'          => $r->status === NULL ? 1 : (int) $r->status,
                'dateStart'       => $this->date($r->start_date),
                'dateEnd'         => $this->date($r->end_date),
            );
        }
        $fingerprint = md5(json_encode($cards));
        $result = array('month' => 'cards', 'lines' => count($cards), 'ok' => TRUE, 'error' => '', 'sent' => FALSE);
        if (!$force && $this->client->state('payroll_cards') === $fingerprint) {
            return $result;
        }
        $counts = array('inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0);
        $error = $this->writeCards($cards, $counts);
        if ($error !== '') {
            $result['ok'] = FALSE;
            $result['error'] = 'cards: ' . $error;
            return $result;
        }
        $this->client->setState('payroll_cards', $fingerprint);
        $result['sent'] = TRUE;
        $result['error'] = sprintf('inserted %d, updated %d, unchanged %d, skipped %d',
            $counts['inserted'], $counts['updated'], $counts['unchanged'], $counts['skipped']);
        return $result;
    }

    /**
     * The cards written through Rilven's ordinary salary-card register, as a person at the screen
     * would: the employee found by personal number (or a unique name), the position and department by
     * their codes (created when Rilven has none), the card by its code -- inserted when new, updated
     * only when something the CMS owns changed. Rilven owns the expense account and the branch once
     * the card exists, so an update sends back what Rilven has for those.
     *
     * @return string the error that stopped it, '' when it went through
     */
    private function writeCards(array $cards, array &$counts)
    {
        // who: every employee once, by personal number and by name
        $byTax = array(); $byName = array(); $ambiguous = array();
        for ($page = 1; ; $page++) {
            $answer = $this->client->get('employee/list', array('page' => $page));
            if (!$answer['ok']) {
                return 'employee/list: ' . $answer['error'];
            }
            $items = isset($answer['data']['pagination']['items']) ? $answer['data']['pagination']['items'] : array();
            foreach ($items as $e) {
                $tax = trim((string) (isset($e['taxCode']) ? $e['taxCode'] : ''));
                if ($tax !== '' && !isset($byTax[$tax])) {
                    $byTax[$tax] = (int) $e['id'];
                }
                $given = mb_strtolower(trim((string) $e['givenName']));
                $family = mb_strtolower(trim((string) $e['familyName']));
                foreach (array_unique(array($family . ' ' . $given, $given . ' ' . $family)) as $key) {
                    if (trim($key) === '') { continue; }
                    if (isset($byName[$key]) && $byName[$key] !== (int) $e['id']) { $ambiguous[$key] = TRUE; }
                    $byName[$key] = (int) $e['id'];
                }
            }
            if (count($items) === 0 || $page * 25 >= (int) $answer['data']['pagination']['total']) {
                break;
            }
        }
        // what Rilven already holds, by code
        $existing = array();
        for ($page = 1; ; $page++) {
            $answer = $this->client->get('employee-salary/list', array('is-external' => 'true', 'page' => $page));
            if (!$answer['ok']) {
                return 'employee-salary/list: ' . $answer['error'];
            }
            $items = isset($answer['data']['pagination']['items']) ? $answer['data']['pagination']['items'] : array();
            foreach ($items as $c) {
                $existing[(string) $c['code']] = $c;
            }
            if (count($items) === 0 || $page * 25 >= (int) $answer['data']['pagination']['total']) {
                break;
            }
        }
        // what a new card needs from Rilven: the company's currency, the branch, the 7320 expense map
        $company = $this->client->get('company/get/' . (int) $this->client->companyId());
        $currencyId = isset($company['data']['item']['currencyId']) ? (int) $company['data']['item']['currencyId'] : 0;
        $branchId = (int) $this->client->cfg('rilven_payroll_company_branch_id', 0);
        $maps = $this->client->post('account-plan/map/filter', array('type' => array(27)));
        $mapId = 0;
        foreach (isset($maps['data']['items']) ? $maps['data']['items'] : array() as $m) {
            if ((string) $m['code'] === (string) $this->client->cfg('rilven_payroll_card_account', '7320')) {
                $mapId = (int) $m['id'];
                break;
            }
        }
        if (!$currencyId || !$branchId || !$mapId) {
            return sprintf('defaults missing (currency %d, branch %d, expense map %d)', $currencyId, $branchId, $mapId);
        }

        $positions = array(); $departments = array();
        foreach ($cards as $card) {
            $tax = trim((string) $card['employeeTaxCode']);
            $employeeId = isset($byTax[$tax]) ? $byTax[$tax] : NULL;
            if ($employeeId === NULL) {
                $key = mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $card['employeeName'])));
                if (isset($byName[$key]) && !isset($ambiguous[$key])) {
                    $employeeId = $byName[$key];
                }
            }
            if ($employeeId === NULL) {
                $counts['skipped']++;   // a CMS staff member who is not an employee in Rilven
                continue;
            }
            $positionId = $this->idByCode('position', $card['positionCode'], $card['positionName'], $positions);
            $departmentId = $this->idByCode('department', $card['departmentCode'], $card['departmentName'], $departments);
            $code = 'cms-sp-' . $card['code'];
            $kind = (int) $card['kind'];
            $body = array(
                'code'          => $code,
                'name'          => $card['positionName'] !== '' ? $card['positionName'] : '-',
                'positionId'    => $positionId,
                'departmentId'  => $departmentId,
                'salaryType'    => $kind === 2 ? 1 : ($kind === 3 ? 2 : 4),
                // a figure only where the CMS quotes a wage; a variable rate stays text ('30%', '10')
                'salary'        => $kind === 1 ? 0 : $this->amount($card['rate']),
                'status'        => (int) $card['status'],
                'dateStart'     => $card['dateStart'],
                'dateEnd'       => $card['dateEnd'],
                'isExternal'    => TRUE,
                'externalKind'  => $kind,
                'caseKind'      => $card['caseKind'],
                'rate'          => $card['rate'] === '' ? NULL : $card['rate'],
            );
            if (!isset($existing[$code])) {
                $answer = $this->client->post('employee-salary/insert', $body + array(
                    'employeeId' => $employeeId, 'companyBranchId' => $branchId,
                    'currencyId' => $currencyId, 'accountPlanMapId' => $mapId));
                if (!$answer['ok']) {
                    return 'employee-salary/insert ' . $code . ': ' . $answer['error'];
                }
                $counts['inserted']++;
                continue;
            }
            $had = $existing[$code];
            if ($this->sameCard($had, $body)) {
                $counts['unchanged']++;
                continue;
            }
            // what Rilven owns goes back as Rilven has it
            $answer = $this->client->put('employee-salary/update', $body + array(
                'id' => (int) $had['id'], 'companyBranchId' => $had['companyBranchId'], 'currencyId' => $had['currencyId'],
                'accountPlanMapId' => $had['accountPlanMapId'], 'workTimeGroupId' => $had['workTimeGroupId'],
                'workRateTypeId' => $had['workRateTypeId'], 'overtimeRate' => $had['overtimeRate'],
                'remote' => !empty($had['isRemote'])));
            if (!$answer['ok']) {
                return 'employee-salary/update ' . $code . ': ' . $answer['error'];
            }
            $counts['updated']++;
        }
        return '';
    }

    /** Whether nothing the CMS owns differs between Rilven's card and what the CMS would send. */
    private function sameCard(array $had, array $body)
    {
        $pairs = array('name' => 'name', 'positionId' => 'positionId', 'departmentId' => 'departmentId',
                       'salaryType' => 'salaryType', 'salary' => 'salary', 'status' => 'status',
                       'dateStart' => 'dateStart', 'dateEnd' => 'dateEnd', 'externalKind' => 'externalKind',
                       'caseKind' => 'caseKind', 'rate' => 'rate');
        foreach ($pairs as $theirs => $ours) {
            $a = isset($had[$theirs]) ? (string) $had[$theirs] : '';
            $b = isset($body[$ours]) && $body[$ours] !== NULL ? (string) $body[$ours] : '';
            if (in_array($theirs, array('dateStart', 'dateEnd'), TRUE)) {
                $a = substr($a, 0, 10);
            }
            if ($a !== $b) {
                return FALSE;
            }
        }
        return TRUE;
    }

    /**
     * Rilven's id of a position or department the CMS knows by its own code, through the register's
     * own ?by=code; created there when it has none (a name is needed for that). Cached per run.
     */
    private function idByCode($register, $code, $name, array &$cache)
    {
        if ($code === NULL || $code === '') {
            return NULL;
        }
        if (array_key_exists($code, $cache)) {
            return $cache[$code];
        }
        $answer = $this->client->get($register . '/get/' . rawurlencode($code), array('by' => 'code'));
        $id = NULL;
        if ($answer['ok'] && isset($answer['data']['item']['id'])) {
            $id = (int) $answer['data']['item']['id'];
        } elseif ($answer['error'] === '[code]-not-found' && $name !== NULL && $name !== '') {
            // Rilven may already have it under another code: LJ's departments predate this sync and
            // carry their own codes (1, 3, 5...), and the old sync matched them by name. Creating a
            // twin here split 592 cards off their departments (2026-10-07). The name first, exactly.
            // the oldest of the exact matches: the filter does not hide a soft-deleted twin
            $found = $this->client->post($register . '/filter', array('key' => $name));
            foreach (isset($found['data']['items']) ? $found['data']['items'] : array() as $row) {
                if (mb_strtolower(trim((string) $row['name'])) === mb_strtolower(trim((string) $name))
                    && ($id === NULL || (int) $row['id'] < $id)) {
                    $id = (int) $row['id'];
                }
            }
        }
        if ($id === NULL && $answer['error'] === '[code]-not-found' && $name !== NULL && $name !== '') {
            $made = $this->client->post($register . '/insert', array('code' => $code, 'name' => $name));
            $id = $made['ok'] && isset($made['data']['id']) ? (int) $made['data']['id'] : NULL;
        }
        $cache[$code] = $id;
        return $id;
    }

    /** "1875" or "1875.50" as Rilven money (x10000); a percentage or nothing is 0. */
    private function amount($rate)
    {
        $v = str_replace(',', '.', trim((string) $rate));
        return ($v === '' || strpos($v, '%') !== FALSE || !is_numeric($v)) ? 0 : (int) round((float) $v * 10000);
    }

    /** A MySQL date as yyyy-mm-dd, or NULL for none and for the zero date. */
    private function date($value)
    {
        $v = trim((string) $value);
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $v) && substr($v, 0, 4) !== '0000' ? substr($v, 0, 10) : NULL;
    }

    /** The run's variable-pay rows, as Rilven's lines. */
    private function lines($did)
    {
        $staff = $this->CI->db->dbprefix('daricxva_by_staff');
        $comp  = $this->CI->db->dbprefix('companies');
        $wh    = $this->CI->db->dbprefix('warehouses');
        $types = implode(',', array_map('intval', array_keys(self::COMPONENTS)));

        // the department by NAME: warehouse_id is not filled on these rows
        $sql = "SELECT s.id, s.salary_type, s.daricxuli_sul AS amount, s.warehouse_name, s.position_id, s.position_name,"
             . " TRIM(c.vat_no) AS tax_code, s.staff_name,"
             . " (SELECT MIN(w.id) FROM {$wh} w WHERE w.name = s.warehouse_name) AS warehouse_id"
             . " FROM {$staff} s LEFT JOIN {$comp} c ON c.id = s.staff_id"
             . " WHERE s.did = ? AND s.salary_type IN ({$types}) AND s.daricxuli_sul > 0"
             . " ORDER BY s.id";
        $rows = $this->CI->db->query($sql, array($did))->result();

        $admin = (array) $this->client->cfg('rilven_payroll_admin_departments', array('ადმინისტრაცია'));
        $adminAccount = (string) $this->client->cfg('rilven_payroll_admin_account', '7410');

        $lines = array();
        foreach ($rows as $r) {
            $name = trim((string) $r->warehouse_name);
            $line = array(
                'code'            => (string) $r->id,
                'employeeTaxCode' => (string) $r->tax_code,
                // for the few with no usable personal number (empty, or a surname typed into it);
                // Rilven uses it only when the number finds nobody and one employee has the name
                'employeeName'    => (string) $r->staff_name,
                'departmentCode'  => $r->warehouse_id === NULL ? NULL : 'cms-' . $r->warehouse_id,
                'departmentName'  => $name,
                'component'       => self::COMPONENTS[(int) $r->salary_type],
                // decimal GEL -> Rilven money, x10000; round() absorbs the float error of the cast
                'amount'          => (int) round((float) $r->amount * 10000),
                'comment'         => (string) $r->staff_name,
                // with the employee and the component it names the salary card the line was paid under
                'positionCode'    => $r->position_id === NULL ? NULL : (string) $r->position_id,
                'positionName'    => trim((string) $r->position_name),
            );
            if (in_array($name, $admin, TRUE)) {
                $line['accountCode'] = $adminAccount;
            }
            $lines[] = $line;
        }
        return $lines;
    }
}
