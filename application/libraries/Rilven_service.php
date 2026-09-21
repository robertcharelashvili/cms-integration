<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * One service of this clinic, as a service of Rilven.
 *
 * The reference, and not the fact that a service was performed. A service PERFORMED is a row of
 * sma_sale_items with its own service_instance_id and its own date, patient and amount; this
 * file is about the catalogue entry it names -- the thing `si.product_code` points at. The
 * specification is explicit about the difference: "код услуги в справочнике не является
 * идентификатором факта её оказания".
 *
 * One route is involved:
 *
 *   /asset-service   the service itself -- `code`, which is this CMS's own id and the only link
 *                    between the two systems, its name, the category it belongs to, the account
 *                    CLASS it posts through, and its VAT treatment.
 *
 * The link is `code` = sma_products.id, looked up with GET /asset-service/get/{code}?by=code.
 *
 * **The id and not the source's own `code` column.** This is the one mistake this integration
 * has already made once: an earlier version wrote sma_products.code into
 * tb_asset_service_data.code, and 2 services out of 2105 agreed with it while 2103 did not,
 * because a person at the CMS may edit that column at any time. The row's id cannot be edited
 * and is therefore the identity. See {@see Rilven_category} for the same note.
 *
 * **A service is created only after its category is.** categoryId is Rilven's id for the
 * category, which this library knows only by asking -- so the run does the categories first and
 * a service whose category has not arrived yet is left in the queue rather than sent without
 * one. See Rilven::push(), which orders the registers.
 */
class Rilven_service
{
    /** @var CI_Controller */
    private $CI;

    /** @var Rilven_client */
    private $client;

    /** @var Rilven_category */
    private $category;

    /** Resolved once per run and remembered in sma_rilven_state. */
    private $refs = NULL;

    /** CMS category id => Rilven category id, for this run only. */
    private $categoryIds = array();

    public function __construct()
    {
        $this->CI = get_instance();
        $this->CI->load->library('rilven_client');
        $this->CI->load->library('rilven_category');
        $this->client   = $this->CI->rilven_client;
        $this->category = $this->CI->rilven_category;
    }

    public function client()
    {
        return $this->client;
    }

    /** The outbox `entity` this register writes under. */
    public function entity()
    {
        return 'service';
    }

    /** The outbox `type_code`. Informational; it is what somebody reading the queue sees. */
    public function typeCode()
    {
        return 'service';
    }

    public function enabled()
    {
        return $this->client->cfg('rilven_service_enabled', FALSE) ? TRUE : FALSE;
    }

    // -----------------------------------------------------------------------
    // the link
    // -----------------------------------------------------------------------

    /**
     * What this service is called over there.
     *
     * Service codes are unique per company among SERVICES only -- the check is scoped to the
     * table -- so a service and a category may both be "114" without colliding.
     */
    public function code($sourceId)
    {
        $code = trim((string) $this->client->cfg('rilven_service_code_prefix', '')) . trim((string) $sourceId);
        if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $code)) {
            return NULL;
        }
        return $code;
    }

    /**
     * Our service id for a CMS service, or NULL when Rilven has never seen it.
     *
     * The record itself comes back too: the update route replaces the whole service, so the
     * values this clinic does not manage have to be read before they can be handed back.
     *
     * @return array ok, id (NULL when absent), item, error, retryable
     */
    public function lookup($code)
    {
        $answer = $this->client->get('/asset-service/get/' . rawurlencode($code), array('by' => 'code'));

        if ($answer['ok']) {
            $item = isset($answer['data']['item']) ? $answer['data']['item'] : array();
            $id = isset($item['id']) ? (int) $item['id'] : 0;
            return array('ok' => TRUE, 'id' => $id > 0 ? $id : NULL, 'item' => $item,
                         'error' => '', 'retryable' => FALSE);
        }

        if (strpos($answer['error'], '[code]-not-found') === 0) {
            // Not a failure: this is the answer that means "create it".
            return array('ok' => TRUE, 'id' => NULL, 'item' => array(), 'error' => '', 'retryable' => FALSE);
        }

        return array('ok' => FALSE, 'id' => NULL, 'item' => array(),
                     'error' => $answer['error'], 'retryable' => $answer['retryable']);
    }

    /**
     * Rilven's id for the category this service sits in.
     *
     * Asked once per category per run and kept, because a clinic's two thousand services sit in
     * a few dozen categories and this would otherwise be two thousand lookups.
     *
     * @return array ok, id (NULL when the category has not been synced yet), error, retryable
     */
    public function categoryId($sourceCategoryId)
    {
        $key = (string) $sourceCategoryId;
        if ($key === '' || (int) $sourceCategoryId <= 0) {
            return array('ok' => TRUE, 'id' => NULL, 'error' => '', 'retryable' => FALSE);
        }
        if (array_key_exists($key, $this->categoryIds)) {
            return array('ok' => TRUE, 'id' => $this->categoryIds[$key], 'error' => '', 'retryable' => FALSE);
        }

        $code = $this->category->code($sourceCategoryId);
        if ($code === NULL) {
            return array('ok' => FALSE, 'id' => NULL, 'retryable' => FALSE,
                         'error' => 'category-code-format-is-invalid: ' . $sourceCategoryId);
        }

        $found = $this->category->lookup($code);
        if (!$found['ok']) {
            return array('ok' => FALSE, 'id' => NULL, 'error' => $found['error'],
                         'retryable' => $found['retryable']);
        }

        $this->categoryIds[$key] = $found['id'];
        return array('ok' => TRUE, 'id' => $found['id'], 'error' => '', 'retryable' => FALSE);
    }

    /** Forget the resolved categories, so the next run asks again. */
    public function forgetCategories()
    {
        $this->categoryIds = array();
    }

    // -----------------------------------------------------------------------
    // the mapping
    // -----------------------------------------------------------------------

    /**
     * A row of sma_products as /asset-service wants it.
     *
     * 'error' is filled in when the row cannot be sent at all. Those are refusals of the source
     * row and never worth retrying.
     */
    public function map($row, $refs)
    {
        $out = array('code' => NULL, 'service' => array(), 'sourceCategoryId' => NULL, 'error' => '');

        $sourceId = isset($row->id) ? $row->id : NULL;
        $code = $this->code($sourceId);
        if ($code === NULL) {
            $out['error'] = 'code-format-is-invalid: '
                . $this->client->cfg('rilven_service_code_prefix', '') . $sourceId;
            return $out;
        }
        $out['code'] = $code;

        $name = $this->val($row, 'name');
        if ($name === '') {
            $out['error'] = 'source-row-has-no-name';
            return $out;
        }

        $service = array(
            'code'             => $code,
            'name'             => $this->clip($name, 255),
            'accountPlanMapId' => $refs['accountPlanMapId'],
            'vatType'          => $refs['vatType'],
            'status'           => $this->status($row),
            'variablePrice'    => $this->client->cfg('rilven_service_variable_price', FALSE) ? TRUE : FALSE,
        );

        $settlementType = $this->intOrNull($this->client->cfg('rilven_service_settlement_type', NULL));
        if ($settlementType !== NULL) {
            $service['settlementType'] = $settlementType;
        }
        $measureId = $this->intOrNull($this->client->cfg('rilven_service_measure_id', NULL));
        if ($measureId !== NULL) {
            $service['measureId'] = $measureId;
        }

        $out['service'] = $service;
        $out['sourceCategoryId'] = $this->categoryColumn($row);
        return $out;
    }

    /** What is compared against the outbox's payload hash. */
    public function hash($mapped)
    {
        return hash('sha256', json_encode(array($mapped['service'], $mapped['sourceCategoryId'])));
    }

    /**
     * Whether the service is offered today.
     *
     * Two columns can answer, and they read OPPOSITE ways: `status` is truthy when the service is
     * active, `hide` is truthy when it is not. This register spells it the second way, and 416 of
     * its 2 103 services are hidden -- so reading `hide` as though it were a status marks every
     * one of them active, and a receptionist can still pick a service the clinic withdrew. They
     * are kept apart rather than joined by an "invert" flag for exactly that reason.
     *
     * An inactive service is still SENT. Last year's documents point at it, and a reference it
     * cannot resolve is worse than one carrying something withdrawn.
     *
     * A fork with neither column has every service active, which is the truthful answer for a
     * register that has no way of saying otherwise.
     */
    private function status($row)
    {
        $hidden = (string) $this->client->cfg('rilven_service_hidden_column', '');
        if ($hidden !== '' && isset($row->$hidden) && $row->$hidden !== NULL) {
            return $this->truthy(trim((string) $row->$hidden)) ? FALSE : TRUE;
        }

        $fields = $this->client->cfg('rilven_service_fields', array());
        if (isset($fields['status']) && $fields['status'] !== NULL && $fields['status'] !== '') {
            $raw = $this->val($row, 'status');
            if ($raw !== '') {
                return $this->truthy($raw);
            }
        }

        return $this->client->cfg('rilven_service_status', TRUE) ? TRUE : FALSE;
    }

    /** What this database's several spellings of yes and no come to. */
    private function truthy($raw)
    {
        return !($raw === '' || $raw === '0' || strcasecmp($raw, 'no') === 0
                 || strcasecmp($raw, 'false') === 0);
    }

    /** The CMS category this service belongs to, by whatever this fork calls the column. */
    private function categoryColumn($row)
    {
        $column = (string) $this->client->cfg('rilven_service_category_column', 'category_id');
        if ($column === '' || !isset($row->$column) || $row->$column === NULL) {
            return NULL;
        }
        $value = (int) $row->$column;
        return $value > 0 ? $value : NULL;
    }

    // -----------------------------------------------------------------------
    // sending
    // -----------------------------------------------------------------------

    /**
     * Put one service into Rilven.
     *
     * @return array result ('created'|'updated'|'rejected'), id, error, retryable, note
     */
    public function push($row, $refs)
    {
        $mapped = $this->map($row, $refs);
        if ($mapped['error'] !== '') {
            return $this->rejected($mapped['error'], FALSE);
        }

        $payload = $mapped['service'];

        // Filled in only when a service goes without the category it should have had. It rides
        // back on the result so the run records it and the cron prints it; it does NOT make the
        // row a failure, because the service itself landed.
        $note = '';

        // The category, if this service has one here.
        //
        // A category this CMS has but Rilven has not yet is RETRYABLE and not a refusal: the
        // categories go first in the same run, so this means the category's own entry failed or
        // has not been reached, and the next tick will very likely find it. Sending the service
        // without a category instead would put it in the reference uncategorised and nothing
        // would ever come back to fix it.
        if ($mapped['sourceCategoryId'] !== NULL) {
            $category = $this->categoryId($mapped['sourceCategoryId']);
            if (!$category['ok']) {
                return $this->rejected($category['error'], $category['retryable']);
            }
            if ($category['id'] === NULL) {
                if ($this->client->cfg('rilven_service_require_category', TRUE)) {
                    return $this->rejected('category-not-synced-yet: ' . $mapped['sourceCategoryId'], TRUE, TRUE);
                }
                // The installation has said it would rather have the service than the link. It
                // goes without one AND it is reported, because a register quietly filling with
                // uncategorised services is the failure this whole ordering exists to prevent.
                $note = 'sent-without-category: ' . $mapped['sourceCategoryId'];
            } else {
                $payload['categoryId'] = (int) $category['id'];
            }
        }

        $found = $this->lookup($mapped['code']);
        if (!$found['ok']) {
            return $this->rejected($found['error'], $found['retryable']);
        }

        if ($found['id'] === NULL) {
            $answer = $this->client->post('/asset-service/insert', $payload);
            if (!$answer['ok']) {
                return $this->rejected($answer['error'], $answer['retryable']);
            }
            return array('result' => 'created',
                         'id' => isset($answer['data']['id']) ? (int) $answer['data']['id'] : 0,
                         'error' => '', 'retryable' => FALSE, 'note' => $note, 'noteRetryable' => FALSE);
        }

        $payload['id'] = (int) $found['id'];

        // The update route replaces the whole service, so a value this clinic does not manage
        // has to be handed back or it is erased -- the same rule as the contractor's branch. A
        // measure or a settlement type an accountant chose over there is not ours to delete
        // because a service was renamed here.
        foreach (array('measureId', 'settlementType') as $keep) {
            if (!isset($payload[$keep]) && isset($found['item'][$keep])
                    && $found['item'][$keep] !== NULL && (int) $found['item'][$keep] > 0) {
                $payload[$keep] = (int) $found['item'][$keep];
            }
        }

        $answer = $this->client->put('/asset-service/update', $payload);
        if (!$answer['ok']) {
            return $this->rejected($answer['error'], $answer['retryable']);
        }
        return array('result' => 'updated', 'id' => (int) $found['id'],
                     'error' => '', 'retryable' => FALSE, 'note' => $note, 'noteRetryable' => FALSE);
    }

    // -----------------------------------------------------------------------
    // the reference ids Rilven wants, resolved once
    // -----------------------------------------------------------------------

    /**
     * The account class a service posts through and the VAT treatment it carries.
     *
     * The account class is resolved exactly as a category's is, and defaults to the same one:
     * the income account the accrual in the specification credits. VAT is configured and never
     * derived -- see the config, where the three values are named.
     */
    public function references($refresh = FALSE)
    {
        if ($this->refs !== NULL && !$refresh) {
            return $this->refs;
        }

        $refs = array(
            'accountPlanMapId' => $this->intOrNull($this->client->cfg('rilven_service_account_plan_map_id', NULL)),
            'vatType'          => $this->intOrNull($this->client->cfg('rilven_service_vat_type', NULL)),
            'notes'            => array(),
        );

        if ($refs['accountPlanMapId'] === NULL) {
            // The categories resolved the same thing a moment ago; a service posts through the
            // same class unless this installation says otherwise.
            $categoryRefs = $this->category->references($refresh);
            $refs['accountPlanMapId'] = $categoryRefs['accountPlanMapId'];
            foreach ($categoryRefs['notes'] as $note) {
                $refs['notes'][] = $note;
            }
        }

        if ($refs['vatType'] === NULL) {
            $refs['notes'][] = 'service VAT: rilven_service_vat_type is not set'
                             . ' (1 standard, 2 tax-free, 3 no VAT)';
        }

        $this->refs = $refs;
        return $refs;
    }

    /** What is missing before a single service can be sent. */
    public function missingReferences($refs)
    {
        $missing = array();
        if ($refs['accountPlanMapId'] === NULL) {
            $missing[] = 'service accountPlanMapId';
        }
        if ($refs['vatType'] === NULL) {
            // The route will not take a service without one, so every row would be refused for
            // the same reason. Said once instead of once per service.
            $missing[] = 'service vatType';
        }
        return $missing;
    }

    // -----------------------------------------------------------------------
    // small things
    // -----------------------------------------------------------------------

    /** A column this fork may not have at all. */
    public function val($row, $meaning)
    {
        $fields = $this->client->cfg('rilven_service_fields', array());
        if (!isset($fields[$meaning])) {
            return '';
        }
        $column = $fields[$meaning];
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

    /** @param bool $dependency see Rilven_sale::rejected -- it must not trip the outage guard. */
    private function rejected($error, $retryable, $dependency = FALSE)
    {
        return array('result' => 'rejected', 'id' => 0, 'error' => $error,
                     'retryable' => (bool) $retryable, 'note' => '', 'noteRetryable' => FALSE,
                     'dependency' => (bool) $dependency);
    }
}
