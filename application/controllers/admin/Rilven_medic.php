<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * The CMS side of medicine write-offs in Rilven (see models/admin/Rilven_medic_model.php).
 *
 *   php index.php admin/rilven_medic reconcile [days]   write-offs Rilven posted for a document that
 *                                                       is not here (the save answered too late and
 *                                                       was rolled back): reversed
 *   php index.php admin/rilven_medic unmapped [days]    medicines used lately with no Rilven product in
 *                                                       sma_rilven_product_link: what to map first
 *   php index.php admin/rilven_medic map <id> <sku> [f] map one medicine to a Rilven product
 *
 * In the browser, admin/rilven_medic/mapping is the screen for the same map (owner and admin).
 *   php index.php admin/rilven_medic status
 *
 * The same pages open in a browser for the Owner.
 */
class Rilven_medic extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!is_cli()) {
            if (!$this->loggedIn) {
                $this->session->set_userdata('requested_page', $this->uri->uri_string());
                $this->sma->md('login');
            }
            if (!$this->Owner && !$this->Admin) {
                $this->session->set_flashdata('warning', lang('access_denied'));
                admin_redirect('welcome');
            }
        }
        $this->load->library('rilven_client');
    }

    private function say($line)
    {
        echo is_cli() ? $line . "\n" : htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . "<br>\n";
    }

    public function index()
    {
        if (!is_cli()) {
            admin_redirect('rilven_medic/mapping');
        }
        $this->status();
    }

    public function status()
    {
        $this->say('rilven_medic_enabled: ' . ($this->rilven_client->cfg('rilven_medic_enabled', FALSE) ? 'ON' : 'off'));
        $today = date('Y-m-d');
        $answer = $this->rilven_client->get('/medic-consumption/list',
            array('date-from' => date('Y-m-d', strtotime('-7 days')), 'date-to' => $today));
        $this->say($answer['ok'] ? 'Rilven: ' . count(isset($answer['data']['items']) ? $answer['data']['items'] : array())
            . ' write-offs in the last 7 days' : 'Rilven: ' . $answer['error']);
    }

    public function reconcile($days = '7')
    {
        $days = max(1, min(90, (int) $days));
        $answer = $this->rilven_client->get('/medic-consumption/list',
            array('date-from' => date('Y-m-d', strtotime('-' . $days . ' days')), 'date-to' => date('Y-m-d')));
        if (!$answer['ok']) {
            $this->say('STOPPED: ' . $answer['error']);
            return;
        }
        $items = isset($answer['data']['items']) ? $answer['data']['items'] : array();
        $ids = array();
        foreach ($items as $i) {
            if (ctype_digit((string) $i['externalId'])) {
                $ids[] = (int) $i['externalId'];
            }
        }
        $here = array();
        if (!empty($ids)) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                foreach ($this->db->select('id')->from('sales_medic')->where_in('id', $chunk)->get()->result() as $r) {
                    $here[(int) $r->id] = TRUE;
                }
            }
        }
        $reversed = 0;
        foreach ($ids as $id) {
            if (isset($here[$id])) {
                continue;
            }
            $r = $this->rilven_client->request('DELETE', '/medic-consumption/delete', array('externalId' => (string) $id));
            $this->say(($r['ok'] ? 'reversed ' : 'FAILED ') . $id . ($r['ok'] ? '' : ': ' . $r['error']));
            $reversed += $r['ok'] ? 1 : 0;
        }
        $this->say(sprintf('[%s] rilven medic reconcile: %d in Rilven, %d without a document here, %d reversed',
            date('Y-m-d H:i:s'), count($ids), count($ids) - count($here), $reversed));
    }

    /** Medicines used lately that have no link in sma_rilven_product_link: what to map first. */
    public function unmapped($days = '90')
    {
        $days = max(1, min(730, (int) $days));
        $rows = $this->db->select('i.product_id, MAX(p.code) AS code, MAX(p.name) AS name, MAX(i.product_unit_code) AS unit,'
                . ' COUNT(*) AS used', FALSE)
            ->from('sale_items_medic i')->join('products p', 'p.id = i.product_id', 'left')
            ->where('i.post_date >=', date('Y-m-d', strtotime('-' . $days . ' days')))
            ->where('NOT EXISTS (SELECT 1 FROM ' . $this->db->dbprefix('rilven_product_link') . ' l WHERE l.product_id = i.product_id)', NULL, FALSE)
            ->group_by('i.product_id')->order_by('used', 'DESC')->get()->result();
        foreach ($rows as $r) {
            $this->say(sprintf('NOT MAPPED  id=%s  code=%s  unit=%s  used=%d  %s', $r->product_id, $r->code, $r->unit, $r->used, $r->name));
        }
        $mapped = (int) $this->db->query('SELECT COUNT(DISTINCT product_id) n FROM ' . $this->db->dbprefix('rilven_product_link'))->row()->n;
        $this->say(sprintf('[%s] %d medicines used in %d days have no Rilven product; %d are mapped',
            date('Y-m-d H:i:s'), count($rows), $days, $mapped));
    }

    /** Map one medicine: php index.php admin/rilven_medic map <product_id> <rilven_sku_id> [factor] */
    public function map($productId = '', $skuId = '', $factor = '1')
    {
        if (!is_cli()) {
            show_404();
        }
        if (!ctype_digit((string) $productId) || !ctype_digit((string) $skuId) || !is_numeric($factor) || (float) $factor <= 0) {
            $this->say('usage: admin/rilven_medic map <product_id> <rilven_sku_id> [factor]');
            return;
        }
        $this->saveLink((int) $productId, (int) $skuId, (float) $factor);
        $this->say('mapped ' . $productId . ' -> Rilven product ' . $skuId . ' x ' . $factor);
    }

    // ------------------------------------------------------------------ the mapping screen

    /** Medicines used here, each with the Rilven product it is written off as. */
    public function mapping()
    {
        $show = in_array($this->input->get('show'), array('unmapped', 'mapped', 'all'), TRUE) ? $this->input->get('show') : 'unmapped';
        $days = max(1, min(3650, (int) ($this->input->get('days') ?: 90)));
        $q = trim((string) $this->input->get('q'));
        $page = max(1, (int) $this->input->get('page'));
        $per = 50;

        $build = function () use ($show, $days, $q) {
            $this->db->from('sale_items_medic i')
                ->join('products p', 'p.id = i.product_id', 'left')
                ->join('units u', 'u.id = p.unit', 'left')
                ->where('i.post_date >=', date('Y-m-d', strtotime('-' . $days . ' days')));
            $linked = 'EXISTS (SELECT 1 FROM ' . $this->db->dbprefix('rilven_product_link') . ' l WHERE l.product_id = i.product_id)';
            if ($show === 'unmapped') {
                $this->db->where('NOT ' . $linked, NULL, FALSE);
            } elseif ($show === 'mapped') {
                $this->db->where($linked, NULL, FALSE);
            }
            if ($q !== '') {
                $this->db->group_start()->like('p.name', $q)->or_like('p.code', $q);
                if (ctype_digit($q)) {
                    $this->db->or_where('i.product_id', (int) $q);
                }
                $this->db->group_end();
            }
            $this->db->group_by('i.product_id');
        };

        $build();
        $this->db->select('i.product_id');
        $total = $this->db->count_all_results();

        $build();
        $rows = $this->db->select('i.product_id, MAX(p.code) AS code, MAX(p.name) AS name, MAX(u.name) AS unit,'
                . ' COUNT(*) AS used, MAX(i.post_date) AS last_used', FALSE)
            ->order_by('used', 'DESC')->limit($per, ($page - 1) * $per)->get()->result();

        // every link of the page's medicines, with Rilven's names for them (one call for the page)
        $links = array();
        $skuIds = array();
        if (!empty($rows)) {
            $pids = array();
            foreach ($rows as $r) {
                $pids[] = (int) $r->product_id;
            }
            foreach ($this->db->where_in('product_id', $pids)->order_by('product_id')->get('rilven_product_link')->result() as $l) {
                $links[(int) $l->product_id][] = $l;
                $skuIds[(int) $l->rilven_sku_id] = TRUE;
            }
        }
        $skus = array();
        if (!empty($skuIds)) {
            $answer = $this->rilven_client->get('/medic-consumption/products', array('ids' => implode(',', array_keys($skuIds)), 'limit' => 500));
            if ($answer['ok']) {
                foreach ($answer['data']['items'] as $it) {
                    $skus[(int) $it['assetSkuId']] = $it;
                }
            }
        }
        $this->data['links'] = $links;
        $this->data['skus'] = $skus;

        $this->data['rows'] = $rows;
        $this->data['total'] = $total;
        $this->data['page'] = $page;
        $this->data['pages'] = max(1, (int) ceil($total / $per));
        $this->data['show'] = $show;
        $this->data['days'] = $days;
        $this->data['q'] = $q;
        $this->data['mapped_total'] = (int) $this->db->query('SELECT COUNT(DISTINCT product_id) n FROM ' . $this->db->dbprefix('rilven_product_link'))->row()->n;

        $bc = array(array('link' => base_url(), 'page' => lang('home')), array('link' => '#', 'page' => 'Rilven: მედიკამენტების დაკავშირება'));
        $meta = array('page_title' => 'Rilven: მედიკამენტების დაკავშირება', 'bc' => $bc);
        $this->page_construct('rilven_medic/mapping', $meta, $this->data);
    }

    /** Rilven's products by name or code, for the screen's picker (JSON). */
    public function search()
    {
        $key = trim((string) $this->input->get('key'));
        if (mb_strlen($key) < 2) {
            return $this->json(array('ok' => TRUE, 'items' => array()));
        }
        $answer = $this->rilven_client->get('/medic-consumption/products', array('key' => $key, 'limit' => 30));
        if (!$answer['ok']) {
            return $this->json(array('ok' => FALSE, 'error' => 'Rilven: ' . $answer['error']));
        }
        return $this->json(array('ok' => TRUE, 'items' => isset($answer['data']['items']) ? $answer['data']['items'] : array()));
    }

    /** Save one medicine's Rilven product (POST product_id, rilven_sku_id, factor). Checked against Rilven first. */
    public function save_map()
    {
        if ($this->input->method() !== 'post') {
            show_404();
        }
        $productId = (int) $this->input->post('product_id');
        $skuId = (int) $this->input->post('rilven_sku_id');
        $factor = str_replace(',', '.', trim((string) $this->input->post('factor')));
        if ($productId <= 0 || $skuId <= 0) {
            return $this->json(array('ok' => FALSE, 'error' => 'აირჩიეთ Rilven-ის პროდუქტი'));
        }
        if (!is_numeric($factor) || (float) $factor <= 0) {
            return $this->json(array('ok' => FALSE, 'error' => 'კოეფიციენტი უნდა იყოს დადებითი რიცხვი'));
        }
        // the product must still exist in Rilven, and its name is kept with the row
        $answer = $this->rilven_client->get('/medic-consumption/products', array('ids' => (string) $skuId));
        if (!$answer['ok']) {
            return $this->json(array('ok' => FALSE, 'error' => 'Rilven: ' . $answer['error']));
        }
        $items = isset($answer['data']['items']) ? $answer['data']['items'] : array();
        if (empty($items)) {
            return $this->json(array('ok' => FALSE, 'error' => 'ეს პროდუქტი Rilven-ში აღარ არსებობს'));
        }
        $sku = $items[0];
        $this->saveLink($productId, $skuId, (float) $factor);
        return $this->json(array('ok' => TRUE, 'row' => array('rilven_sku_id' => $skuId, 'factor' => (float) $factor,
            'rilven_name' => isset($sku['name']) ? $sku['name'] : '', 'rilven_code' => isset($sku['code']) ? $sku['code'] : '',
            'rilven_measure' => isset($sku['measure']) ? $sku['measure'] : '', 'pack_size' => isset($sku['packSize']) ? $sku['packSize'] : 1)));
    }

    /** Forget one link of a medicine (POST product_id, rilven_sku_id). */
    public function delete_map()
    {
        if ($this->input->method() !== 'post') {
            show_404();
        }
        $this->db->delete('rilven_product_link', array('product_id' => (int) $this->input->post('product_id'),
                                                       'rilven_sku_id' => (int) $this->input->post('rilven_sku_id')));
        return $this->json(array('ok' => TRUE));
    }

    /** A link a person chose: used before the learned ones; the same product again only changes its factor. */
    private function saveLink($productId, $skuId, $factor)
    {
        $this->db->query('INSERT INTO ' . $this->db->dbprefix('rilven_product_link') . ' (product_id, rilven_sku_id, factor, source)'
            . " VALUES (?, ?, ?, 'screen') ON DUPLICATE KEY UPDATE factor = VALUES(factor), source = 'screen'",
            array((int) $productId, (int) $skuId, (float) $factor));
    }

    private function json($value)
    {
        $this->output->set_content_type('application/json')->set_output(json_encode($value));
    }

    // ------------------------------------------------------------------ 2025-2026 history
    //
    // Past consumption brought over to Rilven as one write-off per month from the pharmacy, at the
    // stock Rilven has from the RS purchases, the rest received there against 1620 at our cost.
    // Products are Rilven's, through sma_rilven_product_link (several per medicine, each with how
    // many of its pieces one of our units is). Run in this order, months oldest first:
    //   admin/rilven_medic history_products
    //   admin/rilven_medic history_opening
    //   admin/rilven_medic history_month 2025-01 <employee tax code>

    /** Rilven products for the medicines the history needs and has none for. */
    public function history_products()
    {
        $this->cliOnly();
        $this->rilven_client->override('rilven_timeout', 900);
        $prefix = $this->db->dbprefix;
        $rows = $this->db->query("SELECT p.id, p.name FROM {$prefix}products p
              WHERE p.id IN (SELECT a.product_id FROM {$prefix}store_action a
                              WHERE (a.posted_date < '2025-01-01')
                                 OR (a.acount_id IN (3, 4) AND a.posted_date >= '2025-01-01' AND a.posted_date < ?)
                              GROUP BY a.product_id
                             HAVING SUM(CASE WHEN a.posted_date < '2025-01-01' THEN a.debet_count - a.credit_count ELSE 0 END) > 0
                                 OR SUM(CASE WHEN a.posted_date >= '2025-01-01' THEN a.credit_count - a.debet_count ELSE 0 END) > 0)
                AND p.id NOT IN (SELECT product_id FROM {$prefix}rilven_product_link)", array($this->historyEnd()))->result();
        $this->say(count($rows) . ' medicines have no Rilven product');
        $created = 0;
        foreach (array_chunk($rows, 200) as $chunk) {
            $items = array();
            foreach ($chunk as $r) {
                $items[] = array('code' => 'cms-' . $r->id, 'name' => $this->cleanName($r->name, $r->id));
            }
            $answer = $this->rilven_client->post('/medic-consumption/history/products', array(
                'accountPlanMapId' => (int) $this->rilven_client->cfg('rilven_medic_history_account_plan_map_id'),
                'categoryId'       => (int) $this->rilven_client->cfg('rilven_medic_history_category_id'),
                'measureId'        => (int) $this->rilven_client->cfg('rilven_medic_history_measure_id'),
                'vatType'          => (int) $this->rilven_client->cfg('rilven_medic_history_vat_type'),
                'items'            => $items,
            ));
            if (!$answer['ok']) {
                $this->say('FAILED: ' . $answer['error']);
                return;
            }
            foreach ($answer['data']['items'] as $it) {
                $this->db->query('INSERT IGNORE INTO ' . $prefix . 'rilven_product_link (product_id, rilven_sku_id, factor, source)'
                    . ' VALUES (?, ?, 1, ?)', array((int) substr($it['code'], 4), (int) $it['assetSkuId'], 'created'));
                $created += $it['created'] ? 1 : 0;
            }
            $this->say('  ' . count($items) . ' sent');
        }
        $this->say($created . ' created in Rilven, links saved');
    }

    /** Our stock on 2024-12-31, all warehouses, onto the Rilven pharmacy on 2025-01-01, Kt 1620. */
    public function history_opening($dry = '')
    {
        $this->cliOnly();
        $this->rilven_client->override('rilven_timeout', 1800);
        $prefix = $this->db->dbprefix;
        $rows = $this->db->query("SELECT a.product_id, SUM(a.debet_count - a.credit_count) qty, SUM(a.debet_amount - a.credit_amount) amt
              FROM {$prefix}store_action a WHERE a.posted_date < '2025-01-01'
             GROUP BY a.product_id HAVING qty > 0 AND amt > 0")->result();
        // an opening valued at more than 3x what the unit was BOUGHT for is a valuation error in
        // our books (a plaster counted in cm and valued per roll) and comes down to the purchase
        // price; the difference stays on 1620. Only down: a stock worth less than its purchase
        // price is more often a unit kept in ml and bought by the bottle, and raising it would make
        // value out of nothing. Those, and the medicines never bought, keep their value and are
        // listed for the auditor in /root/rilven-history-opening-review.csv.
        $reference = $this->purchasePrices();
        $links = $this->links();
        $excluded = $this->excludedProducts();
        $skipped = 0;
        $review = array();
        $lines = array();
        $missing = 0;
        $total = 0;
        $revalued = 0;
        $delta = 0;
        foreach ($rows as $r) {
            if (isset($excluded[(int) $r->product_id])) {
                $skipped++;
                continue;
            }
            if (empty($links[(int) $r->product_id])) {
                $missing++;
                continue;
            }
            $amount = (float) $r->amt;
            $ref = isset($reference[(int) $r->product_id]) ? $reference[(int) $r->product_id] : NULL;
            if ($ref === NULL && $amount / $r->qty > 0) {
                $unbought = isset($unbought) ? $unbought + 1 : 1;
                $review[] = array($r->product_id, 'never bought, kept', round($amount, 2), '');
            }
            if ($ref !== NULL) {
                $ratio = ($amount / $r->qty) / $ref;
                if ($ratio < 1 / 3) {
                    $review[] = array($r->product_id, 'below purchase price, kept', round($amount, 2), round($r->qty * $ref, 2));
                }
                if ($ratio > 3) {
                    $fixed = round($r->qty * $ref, 2);
                    $this->say(sprintf('  revalued %d: %.2f -> %.2f (unit %.4f, real %.4f)', $r->product_id, $amount, $fixed, $amount / $r->qty, $ref));
                    $delta += $fixed - $amount;
                    $amount = $fixed;
                    $revalued++;
                }
            }
            $first = $links[(int) $r->product_id][0];
            $lines[] = array('assetSkuId' => $first['assetSkuId'], 'factor' => $first['factor'],
                             'quantity' => $this->dec($r->qty), 'amount' => (int) round($amount * 10000));
            $total += $amount;
        }
        $this->say(sprintf('%d products, %.2f GEL (%d brought down to the purchase price, %+.2f; %d never bought, kept as booked); %d without a Rilven product',
            count($lines), $total, $revalued, $delta, isset($unbought) ? $unbought : 0, $missing));
        $this->say($skipped . ' left out: never from a real supplier (own oxygen, state agency, test supplier)');
        $f = fopen('/root/rilven-history-opening-review.csv', 'w');
        fputcsv($f, array('product_id', 'note', 'opening_value', 'at_purchase_price'));
        foreach ($review as $row) {
            fputcsv($f, $row);
        }
        fclose($f);
        if ($missing > 0) {
            $this->say('run history_products first');
            return;
        }
        if ($dry === 'dry') {
            return;
        }
        foreach (array_chunk($lines, 500) as $i => $chunk) {
            $answer = $this->rilven_client->put('/medic-consumption/history/opening', array(
                'externalId'         => 'history-opening-2025-01-01-' . ($i + 1),
                'warehouseId'        => (int) $this->rilven_client->cfg('rilven_medic_history_warehouse_id'),
                'date'               => '2025-01-01 06:00:00',
                'counterAccountCode' => '1620',
                'lines'              => $chunk,
            ));
            $this->say('  part ' . ($i + 1) . ': ' . ($answer['ok']
                ? $answer['data']['status'] . ' document ' . $answer['data']['waybillId'] . ', ' . $answer['data']['lines'] . ' lines'
                : 'FAILED ' . $answer['error'] . ' ' . json_encode(isset($answer['data']['meta']) ? $answer['data']['meta'] : array())));
            if (!$answer['ok']) {
                return;
            }
        }
    }

    /**
     * One month written off from the Rilven pharmacy: what was used in treatment (type 4, and the
     * departments' bulk write-offs, type 3), and apart from it the expired (warehouse 81) and the
     * inventory shortage (112), each its own document and reason.
     */
    public function history_month($month = '', $employeeTaxCode = '', $dry = '')
    {
        $this->cliOnly();
        if (!preg_match('/^\d{4}-\d{2}$/', $month) || $employeeTaxCode === '') {
            $this->say('usage: admin/rilven_medic history_month <YYYY-MM> <employee tax code> [dry]');
            return;
        }
        $this->rilven_client->override('rilven_timeout', 1800);
        $from = $month . '-01';
        $to = date('Y-m-d', strtotime($from . ' +1 month'));
        $last = date('Y-m-d', strtotime($to . ' -1 day'));
        $prefix = $this->db->dbprefix;
        $groups = array(
            'treatment' => array(7, "(a.acount_id = 4 OR (a.acount_id = 3 AND a.warehouse_id NOT IN (81, 112)))"),
            'expired'   => array(2, "(a.acount_id = 3 AND a.warehouse_id = 81)"),
            'inventory' => array(5, "(a.acount_id = 3 AND a.warehouse_id = 112)"),
        );
        $links = $this->links();
        $reference = $this->purchasePrices();
        $excluded = $this->excludedProducts();
        foreach ($groups as $key => $g) {
            $rows = $this->db->query("SELECT a.product_id, MAX(p.name) name, SUM(a.credit_count - a.debet_count) qty,
                       SUM(a.credit_amount - a.debet_amount) amt
                  FROM {$prefix}store_action a LEFT JOIN {$prefix}products p ON p.id = a.product_id
                 WHERE a.posted_date >= ? AND a.posted_date < ? AND {$g[1]}
                 GROUP BY a.product_id HAVING qty > 0", array($from, $to))->result();
            if (empty($rows)) {
                $this->say("$month $key: nothing");
                continue;
            }
            $lines = array();
            $missing = array();
            $total = 0;
            $left = 0;
            foreach ($rows as $r) {
                if (isset($excluded[(int) $r->product_id])) {
                    $left += $r->amt;
                    continue;
                }
                $l = isset($links[(int) $r->product_id]) ? $links[(int) $r->product_id] : array();
                if (empty($l)) {
                    $missing[] = $r->product_id;
                    continue;
                }
                // what a shortfall is received at: our cost, unless it is more than 3x the purchase
                // price -- our write-off cost carries the same errors as the stock
                $amount = max(0.0, (float) $r->amt);
                $ref = isset($reference[(int) $r->product_id]) ? $reference[(int) $r->product_id] : NULL;
                if ($ref !== NULL && $r->qty > 0) {
                    // only down, for the reason the opening is only brought down
                    if ($amount > 0 && ($amount / $r->qty) / $ref > 3) {
                        $amount = round($r->qty * $ref, 2);
                    }
                }
                $lines[] = array('label' => mb_substr((string) $r->name, 0, 200), 'quantity' => $this->dec($r->qty),
                                 'amount' => (int) round($amount * 10000), 'skus' => $l);
                $total += $r->amt;
            }
            $this->say(sprintf('%s %s: %d lines, %.2f GEL (%.2f left out)%s', $month, $key, count($lines), $total, $left,
                $missing ? '; NO Rilven product for ' . implode(',', array_slice($missing, 0, 10)) : ''));
            if ($missing || $dry === 'dry') {
                continue;
            }
            $answer = $this->rilven_client->put('/medic-consumption/history/month', array(
                'externalId'         => 'history-' . $month . '-' . $key,
                'warehouseId'        => (int) $this->rilven_client->cfg('rilven_medic_history_warehouse_id'),
                'date'               => $last . ' 12:00:00',
                'receiptDate'        => $from . ' 12:00:00',
                'counterAccountCode' => '1620',
                'reason'             => $g[0],
                'employeeTaxCode'    => $employeeTaxCode,
                'comment'            => 'CMS ' . $month . ' ' . $key,
                'lines'              => $lines,
            ));
            if (!$answer['ok']) {
                $this->say('  FAILED ' . $answer['error'] . ' ' . json_encode(isset($answer['data']['meta']) ? $answer['data']['meta'] : array(), JSON_UNESCAPED_UNICODE));
                return;
            }
            $d = $answer['data'];
            $this->say(sprintf('  %s: write-off %s (%.2f from stock and receipt), receipt %s (%d lines, %.2f at our cost)',
                $d['status'], $d['waybillId'], $d['writtenOff'] / 10000, $d['receiptWaybillId'] ? $d['receiptWaybillId'] : '-',
                $d['receivedLines'], $d['received'] / 10000));
        }
    }

    /** Take a posted history document back in Rilven (newest first): admin/rilven_medic history_undo <externalId> */
    public function history_undo($externalId = '')
    {
        $this->cliOnly();
        if (!preg_match('/^history-[0-9a-z-]+$/', $externalId)) {
            $this->say('usage: admin/rilven_medic history_undo <history-...>');
            return;
        }
        $this->rilven_client->override('rilven_timeout', 1800);
        $answer = $this->rilven_client->request('DELETE', '/medic-consumption/delete', array('externalId' => $externalId));
        $this->say($externalId . ': ' . ($answer['ok'] ? $answer['data']['status'] : 'FAILED ' . $answer['error']));
    }

    /**
     * Medicines that never came from a real supplier: the clinic's own oxygen, what the state
     * agency gives free of charge, and the test supplier -- every purchase since 2024 from one of
     * rilven_medic_history_exclude_suppliers. They are not material written off: their cost is
     * elsewhere in the books or there is none. Medicines also bought for real stay in.
     */
    private function excludedProducts()
    {
        $ids = array_map('intval', (array) $this->rilven_client->cfg('rilven_medic_history_exclude_suppliers', array()));
        if (empty($ids)) {
            return array();
        }
        $in = implode(',', $ids);
        $out = array();
        foreach ($this->db->query('SELECT i.product_id FROM ' . $this->db->dbprefix('purchase_items') . ' i JOIN '
                . $this->db->dbprefix('purchases') . " pu ON pu.id = i.purchase_id WHERE pu.date >= '2024-01-01'"
                . " GROUP BY i.product_id HAVING SUM(pu.supplier_id IN ($in)) > 0 AND SUM(pu.supplier_id NOT IN ($in)) = 0")->result() as $r) {
            $out[(int) $r->product_id] = TRUE;
        }
        return $out;
    }

    /** product_id => the unit price it was bought at since 2024 (purchases, which are the RS invoices). */
    private function purchasePrices()
    {
        $out = array();
        foreach ($this->db->query('SELECT product_id, SUM(debet_amount) a, SUM(debet_count) q FROM ' . $this->db->dbprefix('store_action')
                . " WHERE acount_id = 1 AND posted_date >= '2024-01-01' GROUP BY product_id HAVING q > 0 AND a > 0")->result() as $x) {
            $out[(int) $x->product_id] = $x->a / $x->q;
        }
        return $out;
    }

    /** product_id => [{assetSkuId, factor}], purchase-learned first, the most bought first. */
    private function links()
    {
        $out = array();
        foreach ($this->db->query('SELECT product_id, rilven_sku_id, factor FROM ' . $this->db->dbprefix('rilven_product_link')
                . " ORDER BY product_id, FIELD(source, 'screen', 'purchase', 'name', 'created'), purchases DESC, rilven_sku_id")->result() as $r) {
            $out[(int) $r->product_id][] = array('assetSkuId' => (int) $r->rilven_sku_id, 'factor' => $this->dec($r->factor));
        }
        return $out;
    }

    /** The history covers up to the end of the last whole month. */
    private function historyEnd()
    {
        return date('Y-m-01');
    }

    private function dec($v)
    {
        return rtrim(rtrim(number_format((float) $v, 6, '.', ''), '0'), '.');
    }

    /** Our names carry the unit twice and underscores: "ანალგინი_ ამპულა ამპულა" becomes "ანალგინი ამპულა". */
    private function cleanName($name, $id)
    {
        $n = trim(preg_replace('/\s+/u', ' ', str_replace('_', ' ', (string) $name)));
        $words = explode(' ', $n);
        $k = count($words);
        if ($k > 2 && $words[$k - 1] === $words[$k - 2]) {
            array_pop($words);
        }
        $n = trim(implode(' ', $words));
        return $n === '' ? 'CMS product ' . $id : mb_substr($n, 0, 250);
    }

    private function cliOnly()
    {
        if (!is_cli()) {
            show_404();
        }
    }
}
