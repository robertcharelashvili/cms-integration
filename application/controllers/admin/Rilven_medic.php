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
            if (!$this->Owner) {
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
}
