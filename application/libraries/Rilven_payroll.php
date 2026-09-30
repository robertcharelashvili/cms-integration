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
     * 5 (duty shifts) and 6 (fixed pay) are sent too since 2026-09-30: LJ keeps no salary cards
     * in Rilven, so without them the fixed part was paid by nobody. Rilven refuses an accrual
     * for anybody who has BOTH a salary card and a type-6 line (salary-variable-fixed-pay-twice):
     * the month cards are introduced, 6 comes out of this list.
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
        $runs = $this->latestRuns($since);
        if (!$runs) {
            return array();
        }

        // Which months changed, from ONE aggregate over every run -- not by building each
        // month's 500 lines on every tick only to find the fingerprint unchanged.
        $fingerprints = $this->fingerprints(array_map(function ($r) { return (int) $r->id; }, $runs));

        // A cap per tick: a first catch-up of twenty months must not hold the cron for minutes.
        // The oldest first, so the ledger fills in order; the rest follow on the next ticks.
        $budget = max(1, (int) $this->client->cfg('rilven_payroll_months_per_tick', 2));
        $out = array();
        foreach ($runs as $run) {
            $fp = isset($fingerprints[(int) $run->id]) ? $fingerprints[(int) $run->id] : $run->id . ':0:0';
            if ($this->client->state('payroll_' . $run->month) === $fp) {
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
            return $result;
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
            }
            return $result;
        }
        $this->client->setState($stateKey, $fingerprint);
        return $result;
    }

    /** The run's variable-pay rows, as Rilven's lines. */
    private function lines($did)
    {
        $staff = $this->CI->db->dbprefix('daricxva_by_staff');
        $comp  = $this->CI->db->dbprefix('companies');
        $wh    = $this->CI->db->dbprefix('warehouses');
        $types = implode(',', array_map('intval', array_keys(self::COMPONENTS)));

        // the department by NAME: warehouse_id is not filled on these rows
        $sql = "SELECT s.id, s.salary_type, s.daricxuli_sul AS amount, s.warehouse_name,"
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
            );
            if (in_array($name, $admin, TRUE)) {
                $line['accountCode'] = $adminAccount;
            }
            $lines[] = $line;
        }
        return $lines;
    }
}
