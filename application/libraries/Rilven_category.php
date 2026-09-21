<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * One service category of this CMS, as a category of Rilven.
 *
 * The clinic's `sma_categories` holds the categories of everything it sells; the ones with
 * `product_type = 2` are the SERVICE categories, and those are the whole scope of this file.
 * Goods categories are a different account class and are not this library's business.
 *
 * Only one route is involved:
 *
 *   /category   the category itself -- `code`, which is this CMS's own id and the only link
 *               between the two systems, its name, and the account CLASS its services post
 *               through.
 *
 * The link is `code` = sma_categories.id, stored on the category record over there
 * (tb_category_data.code) and looked up with GET /category/get/{code}?by=code, exactly as a
 * patient is. A code Rilven does not know answers `[code]-not-found`, which is what tells this
 * library to create rather than update.
 *
 * **The id and not the source's own `code` column.** sma_categories has a `code` of its own and
 * it is the obvious thing to send, which is precisely what an earlier version of this
 * integration did for services: the source's `code` went into tb_asset_service_data.code, 2
 * services out of 2105 agreed with it and 2103 did not. That column is editable by a person at
 * the CMS, so it is not an identity; the row's id is. The specification says the same thing --
 * "редактируемое пользователем поле code не использовать как единственный ключ синхронизации".
 *
 * **The account class is not a category's to guess.** A category over there is bound to a row of
 * tb_account_plan_map -- an account IN A CLASS -- and the class decides how the documents built
 * on it post. For a clinic's services that is the income class (type 3, Georgian 6110), which is
 * what the accrual in the specification credits. It is configured, not derived, because no
 * column of sma_categories carries it.
 */
class Rilven_category
{
    /** @var CI_Controller */
    private $CI;

    /** @var Rilven_client */
    private $client;

    /** Resolved once per run and remembered in sma_rilven_state. */
    private $refs = NULL;

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

    /** The outbox `entity` this register writes under. */
    public function entity()
    {
        return 'category';
    }

    /** The outbox `type_code`. Informational; it is what somebody reading the queue sees. */
    public function typeCode()
    {
        return 'service-category';
    }

    public function enabled()
    {
        return $this->client->cfg('rilven_category_enabled', FALSE) ? TRUE : FALSE;
    }

    // -----------------------------------------------------------------------
    // the link
    // -----------------------------------------------------------------------

    /**
     * What this category is called over there.
     *
     * Rilven refuses a code that does not match its shape rather than tidying it, on the grounds
     * that a code it altered is one the next lookup from here would not find. The same test is
     * applied on this side, where the value can still be explained.
     *
     * Category codes are unique per company among CATEGORIES only -- the check is scoped to the
     * table -- so a category and a patient may both be "114" without colliding. The prefix is
     * there for a Rilven company that takes categories from two source systems.
     */
    public function code($sourceId)
    {
        $code = trim((string) $this->client->cfg('rilven_category_code_prefix', '')) . trim((string) $sourceId);
        if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $code)) {
            return NULL;
        }
        return $code;
    }

    /**
     * Our category id for a CMS category, or NULL when Rilven has never seen it.
     *
     * The same call also brings back the SPECIFICATIONS bound to it, which the update route
     * would otherwise erase -- see {@see push}. They are returned here rather than fetched again
     * because /category/get answers with both in one response.
     *
     * @return array ok, id (NULL when absent), item, specifications, error, retryable
     */
    public function lookup($code)
    {
        $answer = $this->client->get('/category/get/' . rawurlencode($code), array('by' => 'code'));

        if ($answer['ok']) {
            $item = isset($answer['data']['item']) ? $answer['data']['item'] : array();
            $specs = isset($answer['data']['specifications']) && is_array($answer['data']['specifications'])
                ? $answer['data']['specifications'] : array();
            $id = isset($item['id']) ? (int) $item['id'] : 0;
            return array('ok' => TRUE, 'id' => $id > 0 ? $id : NULL, 'item' => $item,
                         'specifications' => $specs, 'error' => '', 'retryable' => FALSE);
        }

        if (strpos($answer['error'], '[code]-not-found') === 0) {
            // Not a failure: this is the answer that means "create it".
            return array('ok' => TRUE, 'id' => NULL, 'item' => array(), 'specifications' => array(),
                         'error' => '', 'retryable' => FALSE);
        }

        return array('ok' => FALSE, 'id' => NULL, 'item' => array(), 'specifications' => array(),
                     'error' => $answer['error'], 'retryable' => $answer['retryable']);
    }

    // -----------------------------------------------------------------------
    // the mapping
    // -----------------------------------------------------------------------

    /**
     * A row of sma_categories as /category wants it.
     *
     * 'error' is filled in when the row cannot be sent at all -- no name, an id that will not
     * make a legal code. Those are refusals of the source row and never worth retrying.
     */
    public function map($row, $refs)
    {
        $out = array('code' => NULL, 'category' => array(), 'error' => '');

        $sourceId = isset($row->id) ? $row->id : NULL;
        $code = $this->code($sourceId);
        if ($code === NULL) {
            $out['error'] = 'code-format-is-invalid: '
                . $this->client->cfg('rilven_category_code_prefix', '') . $sourceId;
            return $out;
        }
        $out['code'] = $code;

        $name = $this->val($row, 'name');
        if ($name === '') {
            // The route demands a name and there is nothing here to make one from. A category
            // with no name at all is a data-entry accident somebody can fix in a minute, and
            // silence would hide it for ever.
            $out['error'] = 'source-row-has-no-name';
            return $out;
        }

        $out['category'] = array(
            'code'             => $code,
            'name'             => $this->clip($name, 255),
            'accountPlanMapId' => $refs['accountPlanMapId'],
        );

        return $out;
    }

    /** What is compared against the outbox's payload hash. */
    public function hash($mapped)
    {
        return hash('sha256', json_encode($mapped['category']));
    }

    // -----------------------------------------------------------------------
    // sending
    // -----------------------------------------------------------------------

    /**
     * Put one category into Rilven.
     *
     * @return array result ('created'|'updated'|'rejected'), id, error, retryable, note
     */
    public function push($row, $refs)
    {
        $mapped = $this->map($row, $refs);
        if ($mapped['error'] !== '') {
            return $this->rejected($mapped['error'], FALSE);
        }

        $found = $this->lookup($mapped['code']);
        if (!$found['ok']) {
            return $this->rejected($found['error'], $found['retryable']);
        }

        if ($found['id'] === NULL) {
            $answer = $this->client->post('/category/insert', $mapped['category']);
            if (!$answer['ok']) {
                return $this->rejected($answer['error'], $answer['retryable']);
            }
            return array('result' => 'created',
                         'id' => isset($answer['data']['id']) ? (int) $answer['data']['id'] : 0,
                         'error' => '', 'retryable' => FALSE, 'note' => '', 'noteRetryable' => FALSE);
        }

        $payload = $mapped['category'];
        $payload['id'] = $found['id'];

        // The specifications a person bound to this category over there, handed straight back.
        //
        // NOT decoration: /category/update replaces them wholesale -- it deletes every binding of
        // the category BEFORE it looks at what was sent, so a payload without them is a payload
        // that erases them. The same is true of the contractor branch, for the same reason, and
        // is handled the same way there. A clinic that never uses specifications sends an empty
        // list and nothing changes.
        $payload['specifications'] = $this->keepSpecifications($found['specifications']);

        $answer = $this->client->put('/category/update', $payload);
        if (!$answer['ok']) {
            return $this->rejected($answer['error'], $answer['retryable']);
        }
        return array('result' => 'updated', 'id' => (int) $found['id'],
                     'error' => '', 'retryable' => FALSE, 'note' => '', 'noteRetryable' => FALSE);
    }

    /**
     * The specification bindings as the update route wants them back.
     *
     * Only the three fields it reads. The rest of what /get returns -- the specification's own
     * code, name and type -- is there to be displayed and is not part of the binding.
     */
    private function keepSpecifications($specifications)
    {
        $kept = array();
        foreach ((array) $specifications as $spec) {
            if (!isset($spec['specificationId']) || (int) $spec['specificationId'] <= 0) {
                continue;
            }
            $kept[] = array(
                'specificationId' => (int) $spec['specificationId'],
                'isRequired'      => isset($spec['isRequired']) && $spec['isRequired'] ? TRUE : FALSE,
                'sortOrder'       => isset($spec['sortOrder']) ? (int) $spec['sortOrder'] : 0,
            );
        }
        return $kept;
    }

    // -----------------------------------------------------------------------
    // the reference ids Rilven wants, resolved once
    // -----------------------------------------------------------------------

    /**
     * The account CLASS every service category is bound to.
     *
     * Pinned in the config when somebody has looked it up -- which is the normal case, because
     * an accountant decided it. Otherwise resolved through /account-plan/map/filter by the
     * account NUMBER and the class type, which is what makes this config file copyable to the
     * next clinic: the map id differs between installations and "6110, in the income class"
     * does not.
     *
     * @param bool $refresh ignore what was remembered and ask again
     */
    public function references($refresh = FALSE)
    {
        if ($this->refs !== NULL && !$refresh) {
            return $this->refs;
        }

        $refs = array(
            'accountPlanMapId' => $this->intOrNull($this->client->cfg('rilven_category_account_plan_map_id', NULL)),
            'notes'            => array(),
        );

        if (!$refresh && $refs['accountPlanMapId'] === NULL) {
            $refs['accountPlanMapId'] = $this->intOrNull($this->client->state('ref_category_account_map_id', NULL));
        }

        if ($refs['accountPlanMapId'] === NULL) {
            $refs['accountPlanMapId'] = $this->resolveAccountMap($refs['notes']);
        }

        if ($refs['accountPlanMapId'] !== NULL) {
            $this->client->setState('ref_category_account_map_id', $refs['accountPlanMapId']);
        }

        $this->refs = $refs;
        return $refs;
    }

    /**
     * The map row for the configured account number in the configured class.
     *
     * By the account NUMBER and not by name: the row says "Revenue from sales", a Georgian user
     * reads "შემოსავალი რეალიზაციიდან", and matching those is guesswork. The number is the same
     * on every chart that follows the Georgian plan.
     */
    private function resolveAccountMap(&$notes)
    {
        $wanted = trim((string) $this->client->cfg('rilven_category_account_code', ''));
        $type   = $this->intOrNull($this->client->cfg('rilven_category_account_type', NULL));

        if ($wanted === '' || $type === NULL) {
            $notes[] = 'category account: neither rilven_category_account_plan_map_id nor'
                     . ' rilven_category_account_code + rilven_category_account_type is set';
            return NULL;
        }

        $answer = $this->client->post('/account-plan/map/filter', array('type' => array($type)));
        if (!$answer['ok']) {
            $notes[] = 'category account: ' . $answer['error'];
            return NULL;
        }

        foreach ($this->items($answer) as $item) {
            if (isset($item['code']) && trim((string) $item['code']) === $wanted) {
                return (int) $item['id'];
            }
        }

        $notes[] = 'category account: this company\'s chart has no account ' . $wanted
                 . ' in class ' . $type . ' -- pin rilven_category_account_plan_map_id';
        return NULL;
    }

    /** What is missing before a single category can be sent. */
    public function missingReferences($refs)
    {
        $missing = array();
        if ($refs['accountPlanMapId'] === NULL) {
            $missing[] = 'category accountPlanMapId';
        }
        return $missing;
    }

    // -----------------------------------------------------------------------
    // small things
    // -----------------------------------------------------------------------

    /** A column this fork may not have at all. */
    public function val($row, $meaning)
    {
        $fields = $this->client->cfg('rilven_category_fields', array());
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

    private function items($answer)
    {
        if (isset($answer['data']['items']) && is_array($answer['data']['items'])) {
            return $answer['data']['items'];
        }
        if (isset($answer['data']['pagination']['items']) && is_array($answer['data']['pagination']['items'])) {
            return $answer['data']['pagination']['items'];
        }
        return array();
    }

    private function rejected($error, $retryable)
    {
        return array('result' => 'rejected', 'id' => 0, 'error' => $error,
                     'retryable' => (bool) $retryable, 'note' => '', 'noteRetryable' => FALSE);
    }
}
