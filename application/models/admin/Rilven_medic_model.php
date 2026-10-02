<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Medicines used on a case, written off in Rilven as they are saved here.
 *
 * Rilven keeps the stock; this CMS keeps recording what was used (sales_medic / sale_items_medic).
 * The save, the edit and the delete of a medicine document go through here instead of straight to
 * Medical_costs_model, and each is ONE transaction across both systems:
 *
 *   1. open a transaction here and let Medical_costs_model write the document as it always has;
 *   2. send the document to Rilven (PUT /medic-consumption/post, or DELETE for a delete). Rilven
 *      checks every drug against the department warehouse: one short refuses the whole document;
 *      otherwise it posts the write-off at once (an edit reverses the old one and posts again);
 *   3. Rilven refused, or could not be reached: roll back -- nothing is saved here either -- and
 *      send the person back to the form with the reason. Rilven posted: commit.
 *
 * The key on Rilven's side is sales_medic.id, so a second press or a retry after a lost answer
 * changes nothing. A write-off Rilven posted whose answer never arrived is left for
 * admin/rilven_medic reconcile, which reverses write-offs whose document does not exist here.
 *
 * Off (rilven_medic_enabled FALSE): every call goes straight to Medical_costs_model, unchanged.
 */
class Rilven_medic_model extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
        $this->load->admin_model('medical_costs_model');
    }

    private function enabled()
    {
        return (bool) $this->config->item('rilven_medic_enabled');
    }

    /** The sync's own HTTP client and its warehouse lookup, loaded once. */
    private function client()
    {
        $this->load->library('rilven_client');
        return $this->rilven_client;
    }

    private function sales()
    {
        $this->load->library('rilven_client');
        $this->load->library('rilven_sale');
        return $this->rilven_sale;
    }

    public function addSale($data = array(), $items = array(), $page_number = null, $colomn = null,
                            $add_template = null, $template_name = null)
    {
        if (!$this->enabled()) {
            return $this->medical_costs_model->addSale($data, $items, $page_number, $colomn, $add_template, $template_name);
        }
        $this->db->trans_begin();
        $id = $this->medical_costs_model->addSale($data, $items, $page_number, $colomn, $add_template, $template_name);
        if (!$id) {
            $this->db->trans_rollback();
            return FALSE;
        }
        return $this->finish($this->post($id), $id);
    }

    public function updateSale($id, $data, $items = array())
    {
        if (!$this->enabled()) {
            return $this->medical_costs_model->updateSale($id, $data, $items);
        }
        $this->db->trans_begin();
        if (!$this->medical_costs_model->updateSale($id, $data, $items)) {
            $this->db->trans_rollback();
            return FALSE;
        }
        return $this->finish($this->post($id), TRUE);
    }

    public function deleteSale($id)
    {
        if (!$this->enabled()) {
            return $this->medical_costs_model->deleteSale($id);
        }
        $this->db->trans_begin();
        if (!$this->medical_costs_model->deleteSale($id)) {
            $this->db->trans_rollback();
            return FALSE;
        }
        $answer = $this->client()->request('DELETE', '/medic-consumption/delete', array('externalId' => (string) $id));
        return $this->finish($answer, TRUE);
    }

    /** Commit when Rilven posted, otherwise roll back and send the person back with the reason. */
    private function finish($answer, $result)
    {
        if ($answer['ok'] && $this->db->trans_status() !== FALSE) {
            $this->db->trans_commit();
            return $result;
        }
        $this->db->trans_rollback();
        $message = $this->explain($answer);
        log_message('error', 'rilven medic: refused: ' . $answer['error']);
        if ($this->input->is_ajax_request()) {
            $this->sma->send_json(array('error' => 1, 'msg' => $message));
        }
        $this->session->set_flashdata('error', $message);
        admin_redirect(isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'welcome');
        return FALSE;
    }

    /** The document as written here, sent to Rilven. */
    private function post($id)
    {
        $header = $this->db->get_where('sales_medic', array('id' => $id), 1)->row();
        $lines  = $this->db->select('i.product_id, i.product_code, i.product_name, i.unit_quantity, i.quantity')
            ->from('sale_items_medic i')->where('i.sale_id', $id)->get()->result();
        if (!$header) {
            return array('ok' => FALSE, 'error' => 'medic-document-not-found', 'data' => array());
        }

        $warehouse = $this->sales()->warehouseId($header->warehouse_id);
        if (!$warehouse['ok']) {
            return $warehouse;
        }
        if ($warehouse['id'] === NULL) {
            return array('ok' => FALSE, 'error' => 'medic-warehouse-not-synced', 'data' => array());
        }

        $payload = array(
            'externalId'  => (string) $id,
            'warehouseId' => (int) $warehouse['id'],
            'date'        => $header->date ? date('Y-m-d H:i:s', strtotime($header->date)) : NULL,
            'comment'     => 'CMS medic ' . $id . ($header->parent_id ? ' / case ' . $header->parent_id : ''),
            'lines'       => array(),
        );

        // the patient's case and the service line, when the case is already in Rilven
        if ((int) $header->parent_id > 0) {
            $case = $this->db->select('rilven_id')->from('rilven_outbox')
                ->where('entity', 'sale')->where('external_id', (string) $header->parent_id)->get()->row();
            if ($case && (int) $case->rilven_id > 0) {
                $payload['caseWaybillId'] = (int) $case->rilven_id;
                if ((int) $header->sale_items_id > 0) {
                    $payload['serviceLineCode'] = (string) $header->sale_items_id;
                }
            }
        }

        // who answers for it: the performer of the service line, else the configured default
        $taxCode = $this->performerTaxCode((int) $header->sale_items_id);
        if ($taxCode === '') {
            $taxCode = trim((string) $this->config->item('rilven_medic_default_employee_tax_code'));
        }
        if ($taxCode !== '') {
            $payload['employeeTaxCode'] = $taxCode;
        }

        foreach ($lines as $l) {
            // unit_quantity is the base unit the stock moves in (updateAVCO moves it), not the pack
            $quantity = $l->unit_quantity !== NULL ? $l->unit_quantity : $l->quantity;
            if ((float) $quantity == 0) {
                continue;
            }
            $payload['lines'][] = array(
                'productId'   => (string) $l->product_id,
                'productCode' => (string) $l->product_code,
                'productName' => (string) $l->product_name,
                'quantity'    => (string) $quantity,
            );
        }
        if (empty($payload['lines'])) {
            // nothing to write off: an empty document is not a refusal, and not a call
            return array('ok' => TRUE, 'error' => '', 'data' => array('status' => 'empty'));
        }
        return $this->client()->put('/medic-consumption/post', $payload);
    }

    private function performerTaxCode($saleItemId)
    {
        if ($saleItemId <= 0) {
            return '';
        }
        $row = $this->db->select('TRIM(c.vat_no) AS tax_code', FALSE)->from('sale_items i')
            ->join('companies c', 'c.id = i.serial_no', 'inner')->where('i.id', $saleItemId)->get()->row();
        if ($row && $row->tax_code !== '') {
            return (string) $row->tax_code;
        }
        $row = $this->db->select('TRIM(c.vat_no) AS tax_code', FALSE)->from('salary_action a')
            ->join('companies c', 'c.id = a.staff_id', 'inner')->where('a.sale_item_id', $saleItemId)
            ->where('a.staff_id >', 0)->order_by('a.salary', 'DESC')->limit(1)->get()->row();
        return $row && $row->tax_code !== '' ? (string) $row->tax_code : '';
    }

    /** Rilven's refusal in words a nurse can act on. */
    private function explain($answer)
    {
        $error = (string) $answer['error'];
        $meta = isset($answer['data']['meta']['products']) ? $answer['data']['meta']['products']
              : (isset($answer['meta']['products']) ? $answer['meta']['products'] : '');
        if (strpos($error, 'medic-insufficient-stock') === 0) {
            return 'ჩამოწერა შეჩერდა: ნაშთი არ არის საკმარისი (არის / საჭიროა) — ' . $meta;
        }
        if (strpos($error, 'medic-product-not-mapped') === 0) {
            return 'ჩამოწერა შეჩერდა: მედიკამენტი Rilven-ში დაკავშირებული არ არის — ' . $meta;
        }
        if (strpos($error, 'medic-quantity-not-whole') === 0) {
            return 'ჩამოწერა შეჩერდა: რაოდენობა ერთეულში მთელი არ გამოდის — ' . $meta;
        }
        if (strpos($error, 'medic-warehouse-not-synced') === 0) {
            return 'ჩამოწერა შეჩერდა: განყოფილების საწყობი Rilven-ში არ არის';
        }
        if (strpos($error, '[employeeId]-is-required') === 0) {
            return 'ჩამოწერა შეჩერდა: ვერ დადგინდა პასუხისმგებელი თანამშრომელი';
        }
        if (!empty($answer['retryable']) || (isset($answer['http']) && (int) $answer['http'] === 0)) {
            return 'Rilven მიუწვდომელია — ხარჯი არ შეინახა, სცადეთ ცოტა ხანში';
        }
        return 'ჩამოწერა შეჩერდა: ' . $error;
    }
}
