<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * The CMS side of medicine write-offs in Rilven (see models/admin/Rilven_medic_model.php).
 *
 *   php index.php admin/rilven_medic reconcile [days]   write-offs Rilven posted for a document that
 *                                                       is not here (the save answered too late and
 *                                                       was rolled back): reversed
 *   php index.php admin/rilven_medic unmapped [days]    medicines used lately with no Rilven product in
 *                                                       sma_rilven_product_map: what to map first
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
        $this->say('rilven_medic_enabled: ' . ($this->config->item('rilven_medic_enabled') ? 'ON' : 'off'));
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

    /** Medicines used lately that have no row in sma_rilven_product_map: what to map first. */
    public function unmapped($days = '90')
    {
        $days = max(1, min(730, (int) $days));
        $rows = $this->db->select('i.product_id, MAX(p.code) AS code, MAX(p.name) AS name, MAX(i.product_unit_code) AS unit,'
                . ' COUNT(*) AS used', FALSE)
            ->from('sale_items_medic i')->join('products p', 'p.id = i.product_id', 'left')
            ->join('rilven_product_map m', 'm.product_id = i.product_id', 'left')
            ->where('i.post_date >=', date('Y-m-d', strtotime('-' . $days . ' days')))
            ->where('m.product_id IS NULL', NULL, FALSE)
            ->group_by('i.product_id')->order_by('used', 'DESC')->get()->result();
        foreach ($rows as $r) {
            $this->say(sprintf('NOT MAPPED  id=%s  code=%s  unit=%s  used=%d  %s', $r->product_id, $r->code, $r->unit, $r->used, $r->name));
        }
        $mapped = $this->db->count_all('rilven_product_map');
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
        $sql = 'INSERT INTO ' . $this->db->dbprefix('rilven_product_map') . ' (product_id, rilven_sku_id, factor, updated_by)'
             . ' VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE rilven_sku_id = VALUES(rilven_sku_id), factor = VALUES(factor),'
             . ' updated_by = VALUES(updated_by)';
        $this->db->query($sql, array((int) $productId, (int) $skuId, (float) $factor,
            is_cli() ? NULL : (int) $this->session->userdata('user_id')));
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
                ->join('rilven_product_map m', 'm.product_id = i.product_id', 'left')
                ->where('i.post_date >=', date('Y-m-d', strtotime('-' . $days . ' days')));
            if ($show === 'unmapped') {
                $this->db->where('m.product_id IS NULL', NULL, FALSE);
            } elseif ($show === 'mapped') {
                $this->db->where('m.product_id IS NOT NULL', NULL, FALSE);
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
                . ' COUNT(*) AS used, MAX(i.post_date) AS last_used,'
                . ' MAX(m.rilven_sku_id) AS rilven_sku_id, MAX(m.factor) AS factor, MAX(m.rilven_name) AS rilven_name,'
                . ' MAX(m.rilven_code) AS rilven_code, MAX(m.rilven_measure) AS rilven_measure, MAX(m.note) AS note', FALSE)
            ->order_by('used', 'DESC')->limit($per, ($page - 1) * $per)->get()->result();

        $this->data['rows'] = $rows;
        $this->data['total'] = $total;
        $this->data['page'] = $page;
        $this->data['pages'] = max(1, (int) ceil($total / $per));
        $this->data['show'] = $show;
        $this->data['days'] = $days;
        $this->data['q'] = $q;
        $this->data['mapped_total'] = $this->db->count_all('rilven_product_map');

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
        $sql = 'INSERT INTO ' . $this->db->dbprefix('rilven_product_map')
             . ' (product_id, rilven_sku_id, factor, rilven_name, rilven_code, rilven_measure, note, updated_by)'
             . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE rilven_sku_id = VALUES(rilven_sku_id),'
             . ' factor = VALUES(factor), rilven_name = VALUES(rilven_name), rilven_code = VALUES(rilven_code),'
             . ' rilven_measure = VALUES(rilven_measure), note = VALUES(note), updated_by = VALUES(updated_by)';
        $this->db->query($sql, array($productId, $skuId, (float) $factor,
            isset($sku['name']) ? $sku['name'] : NULL, isset($sku['code']) ? $sku['code'] : NULL,
            isset($sku['measure']) ? $sku['measure'] : NULL, 'screen', (int) $this->session->userdata('user_id')));
        return $this->json(array('ok' => TRUE, 'row' => array('rilven_sku_id' => $skuId, 'factor' => (float) $factor,
            'rilven_name' => isset($sku['name']) ? $sku['name'] : '', 'rilven_code' => isset($sku['code']) ? $sku['code'] : '',
            'rilven_measure' => isset($sku['measure']) ? $sku['measure'] : '')));
    }

    /** Forget one medicine's Rilven product (POST product_id). */
    public function delete_map()
    {
        if ($this->input->method() !== 'post') {
            show_404();
        }
        $this->db->delete('rilven_product_map', array('product_id' => (int) $this->input->post('product_id')));
        return $this->json(array('ok' => TRUE));
    }

    private function json($value)
    {
        $this->output->set_content_type('application/json')->set_output(json_encode($value));
    }
}
