<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * The CMS side of medicine write-offs in Rilven (see models/admin/Rilven_medic_model.php).
 *
 *   php index.php admin/rilven_medic reconcile [days]   write-offs Rilven posted for a document that
 *                                                       is not here (the save answered too late and
 *                                                       was rolled back): reversed
 *   php index.php admin/rilven_medic products [days]    the medicines used lately that Rilven cannot
 *                                                       match to its catalogue: what to map first
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

    public function products($days = '90')
    {
        $days = max(1, min(730, (int) $days));
        $rows = $this->db->select('i.product_id, MAX(p.code) AS code, MAX(p.name) AS name, COUNT(*) AS used', FALSE)
            ->from('sale_items_medic i')->join('products p', 'p.id = i.product_id', 'left')
            ->where('i.post_date >=', date('Y-m-d', strtotime('-' . $days . ' days')))
            ->group_by('i.product_id')->order_by('used', 'DESC')->get()->result();
        $unmatched = 0;
        $byVia = array('map' => 0, 'code' => 0);
        foreach (array_chunk($rows, 500) as $chunk) {
            $products = array();
            foreach ($chunk as $r) {
                $products[] = array('productId' => (string) $r->product_id, 'productCode' => (string) $r->code,
                                    'productName' => (string) $r->name);
            }
            $answer = $this->rilven_client->post('/medic-consumption/check-products', array('products' => $products));
            if (!$answer['ok']) {
                $this->say('STOPPED: ' . $answer['error']);
                return;
            }
            foreach ($answer['data']['items'] as $k => $m) {
                if ($m['matched']) {
                    $byVia[$m['via']] = isset($byVia[$m['via']]) ? $byVia[$m['via']] + 1 : 1;
                    continue;
                }
                $unmatched++;
                $this->say(sprintf('NOT MATCHED  id=%s  code=%s  used=%d  %s', $m['productId'], $m['productCode'],
                    $chunk[$k]->used, $m['productName']));
            }
        }
        $this->say(sprintf('[%s] %d products used in %d days: %d matched by map, %d by barcode, %d not matched',
            date('Y-m-d H:i:s'), count($rows), $days, $byVia['map'], $byVia['code'], $unmatched));
    }
}
