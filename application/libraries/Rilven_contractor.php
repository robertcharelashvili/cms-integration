<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * One patient of this clinic, as a counterparty of Rilven.
 *
 * Two routes are involved and they own different halves of the same person:
 *
 *   /contractor        the counterparty itself -- who they are, their identification
 *                      number, their kind, their sex and date of birth, and `code`,
 *                      which is this CMS's own id and the only link between the two
 *                      systems.
 *   /contractor-branch their contact details -- phone, e-mail, address. Over there a
 *                      posting resolves a BRANCH, not a counterparty, so this is where
 *                      those live. A counterparty created through the first route is
 *                      given a default branch carrying whatever contact details came
 *                      with it; the counterparty's own update route cannot touch them
 *                      afterwards, which is why the second route exists at all.
 *
 * The link is `code` = sma_companies.id, stored on the counterparty record over there
 * (tb_contractor_data.code) and looked up with GET /contractor/get/{code}?by=code. There
 * is no map table on either side. A code Rilven does not know answers `[code]-not-found`,
 * which is what tells this library to create rather than update -- and is deliberately a
 * different sentence from "the call was wrong".
 */
class Rilven_contractor
{
    /** @var CI_Controller */
    protected $CI;

    /** @var Rilven_client */
    protected $client;

    /** Resolved once per run and remembered in sma_rilven_state. */
    protected $refs = NULL;

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

    /**
     * One config value, and the only door this register reads them through.
     *
     * It exists so that a register for a DIFFERENT kind of counterparty can be this class with
     * the keys redirected, rather than a second copy of eight hundred lines that would then have
     * to be kept in step. {@see Rilven_insurer}, which overrides this to try its own prefix first
     * and fall back here for everything the two kinds share.
     */
    protected function cfg($key, $default = NULL)
    {
        return $this->client->cfg($key, $default);
    }

    /**
     * The name this register remembers a resolved reference under.
     *
     * Plain for patients, so the values already in sma_rilven_state keep being found. A register
     * for another KIND of counterparty must not share them: it resolves a different contragent
     * type and a different legal form, and one cache would mean whichever register ran last
     * decided what the other one is. Stamping every patient as an insurance company is one save
     * away, and an update cannot unsay it -- the same accident, in the other direction, as the
     * one the person fields in map() were fenced off after.
     */
    protected function stateKey($name)
    {
        return $name;
    }

    /** The outbox `entity` this register writes under. */
    public function entity()
    {
        return 'contractor';
    }

    /** The outbox `type_code`. Informational; it is what somebody reading the queue sees. */
    public function typeCode()
    {
        return 'patient';
    }

    /**
     * Whether patients are sent at all.
     *
     * Defaults to TRUE, so an installation whose config predates the service registers goes on
     * doing exactly what it did before this switch existed.
     */
    public function enabled()
    {
        return $this->cfg('rilven_contractor_enabled', TRUE) ? TRUE : FALSE;
    }

    /**
     * What is compared against the outbox's payload hash.
     *
     * Both halves, because the branch is half of what is sent and a change to a phone number
     * that did not move the counterparty still has to travel.
     */
    public function hash($mapped)
    {
        return hash('sha256', json_encode(array($mapped['contractor'], $mapped['branch'])));
    }

    // -----------------------------------------------------------------------
    // the link
    // -----------------------------------------------------------------------

    /**
     * What this patient is called over there.
     *
     * Rilven refuses a code that does not match its shape rather than tidying it, on the
     * grounds that a code it altered is one the next lookup from here would not find. So
     * the same test is applied on this side, where the value can still be explained.
     */
    public function code($sourceId)
    {
        $code = trim((string) $this->cfg('rilven_code_prefix', '')) . trim((string) $sourceId);
        if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $code)) {
            return NULL;
        }
        return $code;
    }

    /**
     * Our counterparty id for a patient, or NULL when Rilven has never seen them.
     *
     * @return array ok, id (NULL when absent), error, retryable
     */
    public function lookup($code)
    {
        $answer = $this->client->get('/contractor/get/' . rawurlencode($code), array('by' => 'code'));

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
     * Both of Rilven's ids for a patient we know only by our own: the counterparty and its BRANCH.
     *
     * Needed because a posting resolves a BRANCH, and the outbox only ever captured one at
     * CREATION -- 379 patients of 70 045 on this installation, the rest having been sent before
     * that column existed or having read as unchanged and written NULL. Asking for them is the
     * only way to recover the other 69 666, and the caller writes what comes back into the outbox
     * so that each patient costs one lookup ONCE rather than one per run.
     *
     * The branch route is the weak link: at the time of writing it is not granted to the service
     * account, so this answers `[permission]-denied` and every case waits. That is the truth and
     * it is reported as such -- there is no way to invent a branch id, and a document sent without
     * one does not fail politely: the engine reads it unboxed.
     *
     * @return array ok, id, branchId, error, retryable
     */
    public function resolveIds($code)
    {
        $found = $this->lookup($code);
        if (!$found['ok']) {
            return array('ok' => FALSE, 'id' => 0, 'branchId' => 0,
                         'error' => $found['error'], 'retryable' => $found['retryable']);
        }
        if ($found['id'] === NULL) {
            // Rilven has never heard of this patient. Retryable: the contractor register runs
            // before whatever is asking, so the next tick very likely has them.
            return array('ok' => FALSE, 'id' => 0, 'branchId' => 0,
                         'error' => 'patient-not-synced-yet: ' . $code, 'retryable' => TRUE);
        }

        $list = $this->client->get('/contractor-branch/list', array('contractor-id' => (int) $found['id']));
        if (!$list['ok']) {
            return array('ok' => FALSE, 'id' => (int) $found['id'], 'branchId' => 0,
                         'error' => 'branch-lookup: ' . $list['error'], 'retryable' => $list['retryable']);
        }

        $items = array();
        if (isset($list['data']['pagination']['items']) && is_array($list['data']['pagination']['items'])) {
            $items = $list['data']['pagination']['items'];
        } elseif (isset($list['data']['items']) && is_array($list['data']['items'])) {
            $items = $list['data']['items'];
        }

        $existing = array();
        $branchId = $this->chooseBranch($items, $this->cfg('rilven_branch_code', TRUE) ? $code : NULL, $existing);

        if ($branchId === NULL || (int) $branchId <= 0) {
            // A counterparty always gets a default branch at creation, so none at all means
            // somebody removed it over there. Not retryable: a person has to put one back.
            return array('ok' => FALSE, 'id' => (int) $found['id'], 'branchId' => 0,
                         'error' => 'patient-has-no-branch-over-there: ' . $code, 'retryable' => FALSE);
        }

        return array('ok' => TRUE, 'id' => (int) $found['id'], 'branchId' => (int) $branchId,
                     'error' => '', 'retryable' => FALSE);
    }

    // -----------------------------------------------------------------------
    // the mapping
    // -----------------------------------------------------------------------

    /**
     * A row of sma_companies as the two routes want it.
     *
     * Returns 'error' filled in when the row cannot be sent at all -- a name that is
     * entirely blank, an id that will not make a legal code. Those are refusals of the
     * source row and never worth retrying, so they are reported as such and the entry is
     * closed rather than left to collect ten identical attempts.
     */
    public function map($row, $refs)
    {
        $out = array('code' => NULL, 'contractor' => array(), 'branch' => array(), 'error' => '');

        $sourceId = isset($row->id) ? $row->id : NULL;
        $code = $this->code($sourceId);
        if ($code === NULL) {
            $out['error'] = 'code-format-is-invalid: ' . $this->cfg('rilven_code_prefix', '') . $sourceId;
            return $out;
        }
        $out['code'] = $code;

        $legalName = $this->joinParts($row, $this->cfg('rilven_name_order', array('given_name', 'family_name')));
        $brandName = $this->joinParts($row, $this->cfg('rilven_brand_order', array('given_name', 'family_name')));
        if ($legalName === '') {
            $legalName = $brandName;
        }
        if ($brandName === '') {
            $brandName = $legalName;
        }
        if ($legalName === '') {
            // Both routes demand a name and there is nothing here to make one from. Worth
            // saying out loud: a patient with no name at all is a data-entry accident the
            // clinic can fix in a minute, and silence would hide it for ever.
            $out['error'] = 'source-row-has-no-name';
            return $out;
        }

        $taxCode = $this->taxCode($row, $sourceId);
        if ($taxCode === NULL) {
            $out['error'] = 'no-usable-tax-code';
            return $out;
        }

        $contractor = array(
            'code'      => $code,
            'taxCode'   => $this->clip($taxCode, 255),
            'legalName' => $this->clip($legalName, 255),
            'brandName' => $this->clip($brandName, 255),
            'countryId' => $refs['countryId'],
            'vat'       => $this->cfg('rilven_vat', FALSE) ? TRUE : FALSE,
        );

        if ($refs['legalFormId'] !== NULL) {
            $contractor['legalFormId'] = $refs['legalFormId'];
        }
        if ($refs['contragentTypeId'] !== NULL) {
            $contractor['contragentTypeId'] = $refs['contragentTypeId'];
        }
        $priceGroupId = $this->cfg('rilven_price_group_id', NULL);
        if ($priceGroupId !== NULL && (int) $priceGroupId > 0) {
            $contractor['priceGroupId'] = (int) $priceGroupId;
        }
        $isResident = $this->cfg('rilven_is_resident', NULL);
        if ($isResident !== NULL) {
            $contractor['isResident'] = $isResident ? TRUE : FALSE;
        }

        // Sex and date of birth are sent only because everything this library sends is a
        // PERSON. Pushing them for organisations once stamped a sex onto two hundred
        // insurance companies, and an update cannot unsay a value.
        if ($this->cfg('rilven_person_fields', TRUE)) {
            $gender = $this->val($row, 'gender');
            if ($gender === '1' || $gender === '2') {
                $contractor['gender'] = (int) $gender;
            }
            $birthday = $this->birthday($row);
            if ($birthday !== NULL) {
                $contractor['birthday'] = $birthday;
            }
        }

        // The contact details. They travel with the counterparty at creation (where they
        // furnish its default branch) and through the branch route afterwards.
        $phone = $this->digits($this->val($row, 'phone'));
        if ($phone === '') {
            $phone = $this->digits($this->val($row, 'phone_alt'));
        }
        $email   = $this->val($row, 'email');
        $address = trim($this->val($row, 'address') . ' ' . $this->val($row, 'address2'));

        if ($phone !== '') {
            $contractor['phone'] = $this->clip($phone, 255);
        }
        if ($email !== '') {
            $contractor['email'] = $this->clip($email, 255);
        }
        if ($address !== '') {
            $contractor['address'] = $this->clip($address, 255);
        }

        $branch = array(
            'name'    => $this->clip($brandName, 255),
            // The branch route will not take a blank address, and a good share of patients
            // have none; the placeholder is configured rather than invented here.
            'address' => $this->clip($address !== '' ? $address
                        : (string) $this->cfg('rilven_branch_address_fallback', '-'), 255),
            // NULL and not '' for a value this clinic does not have. The branch a
            // counterparty is created with stores a blank as NULL, so sending '' here would
            // differ from what is already there and write a new version of the record on
            // the first run -- and on every run, if the two are ever compared the other way
            // round. Nothing is gained by the empty string.
            'email'   => $email !== '' ? $this->clip($email, 255) : NULL,
            // digits only: the branch route refuses anything else outright
            'phone'   => $phone !== '' ? $this->clip($phone, 255) : NULL,
        );
        if ($this->cfg('rilven_branch_code', TRUE)) {
            $branch['code'] = $this->clip($code, 128);
        }
        $out['branch'] = $branch;

        $out['contractor'] = $contractor;
        return $out;
    }

    /**
     * The identification number, or the placeholder that stands in for it.
     *
     * This register keeps '-', '123' and four-digit internal numbers in the same column
     * as real personal numbers. Sent as they are, they are duplicates of each other and
     * all but the first patient is refused -- so a value that does not look like a real
     * number is replaced by one built from the id, which is unique by construction and
     * recognisable as a placeholder at a glance.
     *
     * @return string|NULL NULL when there is nothing usable and the installation has
     *                     chosen to skip such patients rather than invent a code
     */
    private function taxCode($row, $sourceId)
    {
        $raw = $this->val($row, 'tax_code');
        $pattern = $this->cfg('rilven_taxcode_pattern', NULL);

        if ($raw !== '' && ($pattern === NULL || $pattern === '' || preg_match($pattern, $raw))) {
            return $raw;
        }

        $fallback = (string) $this->cfg('rilven_taxcode_fallback', '');
        if (trim($fallback) === '') {
            return NULL;
        }
        return str_replace('{id}', (string) $sourceId, $fallback);
    }

    private function birthday($row)
    {
        $raw = $this->val($row, 'birthday');
        if ($raw === '' || strpos($raw, '0000-00-00') === 0) {
            return NULL;
        }
        $stamp = strtotime($raw);
        if ($stamp === FALSE) {
            return NULL;
        }
        $formatted = date('Y-m-d', $stamp);
        // A year outside living memory is a typo or a zero date that survived strtotime,
        // and a birthday is not worth refusing a patient over.
        if ($formatted < '1900-01-01' || $formatted > date('Y-m-d')) {
            return NULL;
        }
        return $formatted;
    }

    // -----------------------------------------------------------------------
    // sending
    // -----------------------------------------------------------------------

    /**
     * Put one patient into Rilven, counterparty first and branch after.
     *
     * @return array result ('created'|'updated'|'rejected'), id, branchId, error, retryable, note
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
            $answer = $this->client->post('/contractor/insert', $mapped['contractor']);
            if (!$answer['ok']) {
                return $this->rejected($answer['error'], $answer['retryable']);
            }
            $id       = isset($answer['data']['id']) ? (int) $answer['data']['id'] : 0;
            $branchId = isset($answer['data']['branchId']) ? (int) $answer['data']['branchId'] : 0;
            $result   = array('result' => 'created', 'id' => $id, 'branchId' => $branchId,
                              'error' => '', 'retryable' => FALSE, 'note' => '');
        } else {
            $payload = $mapped['contractor'];
            $payload['id'] = $found['id'];
            // The counterparty's own route carries none of the contact details -- they
            // belong to the branch -- so they are dropped rather than sent and ignored.
            unset($payload['phone'], $payload['email'], $payload['address']);

            $answer = $this->client->put('/contractor/update', $payload);
            if (!$answer['ok']) {
                return $this->rejected($answer['error'], $answer['retryable']);
            }
            $result = array('result' => 'updated', 'id' => $found['id'], 'branchId' => 0,
                            'error' => '', 'retryable' => FALSE, 'note' => '');
        }

        $result['noteRetryable'] = FALSE;

        if ($this->cfg('rilven_branch_enabled', FALSE)) {
            $branch = $this->pushBranch($result['id'], $result['branchId'], $mapped['branch'], $refs);
            $result['branchId'] = $branch['id'];
            // A branch that could not be brought up to date does NOT undo the counterparty:
            // the counterparty is what a posting needs, and a stale phone number is a note,
            // not a failure. It does decide whether this patient is finished with, though --
            // see the caller, which leaves the row in the queue when the branch could still
            // succeed later and closes it when it could not.
            $result['note'] = $branch['note'];
            $result['noteRetryable'] = $branch['retryable'];
        }

        return $result;
    }

    /**
     * Bring the patient's branch up to date, creating one only if they somehow have none.
     *
     * @return array id, note, retryable
     */
    private function pushBranch($contractorId, $knownBranchId, $branch, $refs)
    {
        if ($refs['stateId'] === NULL || $refs['cityId'] === NULL) {
            // The branch route demands both and the clinic's register holds neither, so
            // they are configured. Without them there is nothing to send -- said once per
            // row rather than attempted and refused once per row.
            return array('id' => $knownBranchId, 'retryable' => FALSE,
                         'note' => 'branch-skipped: state/city not configured');
        }

        $branchId = $knownBranchId > 0 ? $knownBranchId : NULL;
        $existing = array();

        if ($branchId === NULL) {
            $list = $this->client->get('/contractor-branch/list', array('contractor-id' => $contractorId));
            if (!$list['ok']) {
                return array('id' => 0, 'retryable' => $list['retryable'],
                             'note' => 'branch-skipped: ' . $list['error']);
            }
            $items = array();
            if (isset($list['data']['pagination']['items']) && is_array($list['data']['pagination']['items'])) {
                $items = $list['data']['pagination']['items'];
            }
            $branchId = $this->chooseBranch($items, isset($branch['code']) ? $branch['code'] : NULL, $existing);
        }

        if ($branchId === NULL) {
            $payload = $branch;
            $payload['contractorId'] = (int) $contractorId;
            $payload['countryId']    = $refs['countryId'];
            $payload['stateId']      = $refs['stateId'];
            $payload['cityId']       = $refs['cityId'];

            $answer = $this->client->post('/contractor-branch/insert', $payload);
            if (!$answer['ok']) {
                return array('id' => 0, 'retryable' => $answer['retryable'],
                             'note' => 'branch-failed: ' . $answer['error']);
            }
            return array('id' => isset($answer['data']['id']) ? (int) $answer['data']['id'] : 0,
                         'note' => '', 'retryable' => FALSE);
        }

        $payload = $branch;
        $payload['id'] = (int) $branchId;
        // The branch keeps its own country, state and city when it has them -- a place
        // somebody corrected over there is not this library's to overwrite with a default.
        $payload['countryId'] = $this->pick($existing, 'countryId', $refs['countryId']);
        $payload['stateId']   = $this->pick($existing, 'stateId', $refs['stateId']);
        $payload['cityId']    = $this->pick($existing, 'cityId', $refs['cityId']);

        // The update route replaces the whole branch, so a field this library does not
        // manage has to be handed back or it is erased. A contact person typed by an
        // accountant over there is not ours to delete because a patient's phone changed.
        foreach (array('fax', 'contactPerson', 'contactPersonPosition', 'contactPersonPhone') as $keep) {
            if (isset($existing[$keep]) && $existing[$keep] !== NULL && $existing[$keep] !== '') {
                $payload[$keep] = $existing[$keep];
            }
        }

        $answer = $this->client->put('/contractor-branch/update', $payload);
        if (!$answer['ok']) {
            return array('id' => (int) $branchId, 'retryable' => $answer['retryable'],
                         'note' => 'branch-failed: ' . $answer['error']);
        }
        return array('id' => (int) $branchId, 'note' => '', 'retryable' => FALSE);
    }

    /**
     * Which of a counterparty's branches is the one this CMS owns.
     *
     * By code when it carries ours. Otherwise the OLDEST, which is the default branch
     * created with the counterparty -- the list answers newest first, so "the first one"
     * would pick whichever branch somebody over there added last.
     */
    private function chooseBranch($items, $wantedCode, &$existing)
    {
        $fallbackId = NULL;
        foreach ($items as $item) {
            $id = isset($item['id']) ? (int) $item['id'] : 0;
            if ($id <= 0) {
                continue;
            }
            if ($wantedCode !== NULL && isset($item['code']) && (string) $item['code'] === (string) $wantedCode) {
                $existing = $item;
                return $id;
            }
            if ($fallbackId === NULL || $id < $fallbackId) {
                $fallbackId = $id;
                $existing = $item;
            }
        }
        return $fallbackId;
    }

    // -----------------------------------------------------------------------
    // the reference ids Rilven wants, resolved once
    // -----------------------------------------------------------------------

    /**
     * Country, legal form, kind, state and city -- as ids, which differ per installation.
     *
     * Pinned in the config when somebody has looked them up; otherwise resolved through
     * the reference routes by ISO code and by name, and remembered, so a clinic's config
     * file can be copied to the next clinic unchanged. A value that can be neither pinned
     * nor resolved comes back NULL and the caller says so in one place instead of once
     * per patient.
     *
     * @param bool $refresh ignore what was remembered and ask again
     */
    public function references($refresh = FALSE)
    {
        if ($this->refs !== NULL && !$refresh) {
            return $this->refs;
        }

        $refs = array(
            'countryId'        => $this->intOrNull($this->cfg('rilven_country_id', NULL)),
            'legalFormId'      => $this->intOrNull($this->cfg('rilven_legal_form_id', NULL)),
            'contragentTypeId' => $this->intOrNull($this->cfg('rilven_contragent_type_id', NULL)),
            'stateId'          => $this->intOrNull($this->cfg('rilven_branch_state_id', NULL)),
            'cityId'           => $this->intOrNull($this->cfg('rilven_branch_city_id', NULL)),
            'notes'            => array(),
        );

        $cached = array(
            'countryId'        => 'ref_country_id',
            'legalFormId'      => 'ref_legal_form_id',
            'contragentTypeId' => 'ref_contragent_type_id',
            'stateId'          => 'ref_state_id',
            'cityId'           => 'ref_city_id',
        );

        if (!$refresh) {
            foreach ($cached as $key => $stateName) {
                if ($refs[$key] === NULL) {
                    $refs[$key] = $this->intOrNull($this->client->state($this->stateKey($stateName), NULL));
                }
            }
        }

        if ($refs['countryId'] === NULL) {
            $refs['countryId'] = $this->resolveCountry($refs['notes']);
        }
        if ($refs['countryId'] !== NULL && $refs['legalFormId'] === NULL) {
            $refs['legalFormId'] = $this->resolveLegalForm($refs['countryId'], $refs['notes']);
        }
        if ($refs['contragentTypeId'] === NULL) {
            $refs['contragentTypeId'] = $this->resolveContragentType($refs['notes']);
        }
        if ($refs['countryId'] !== NULL && $refs['stateId'] === NULL) {
            $refs['stateId'] = $this->resolveState($refs['countryId'], $refs['notes']);
        }
        if ($refs['countryId'] !== NULL && $refs['stateId'] !== NULL && $refs['cityId'] === NULL) {
            $refs['cityId'] = $this->resolveCity($refs['countryId'], $refs['stateId'], $refs['notes']);
        }

        foreach ($cached as $key => $stateName) {
            if ($refs[$key] !== NULL) {
                $this->client->setState($this->stateKey($stateName), $refs[$key]);
            }
        }

        $this->refs = $refs;
        return $refs;
    }

    private function resolveCountry(&$notes)
    {
        $iso = trim((string) $this->cfg('rilven_country_iso', ''));
        if ($iso === '') {
            $notes[] = 'country: neither rilven_country_id nor rilven_country_iso is set';
            return NULL;
        }
        // By ISO and never by name: the row says "Georgia", a Georgian user types
        // "საქართველო", and matching those is guesswork.
        $answer = $this->client->post('/country/filter', new stdClass(), array('iso' => $iso));
        if (!$answer['ok']) {
            $notes[] = 'country: ' . $answer['error'];
            return NULL;
        }
        $items = $this->items($answer);
        if (empty($items)) {
            $notes[] = 'country: no country answers to ISO ' . $iso;
            return NULL;
        }
        return (int) $items[0]['id'];
    }

    private function resolveLegalForm($countryId, &$notes)
    {
        $wanted = trim((string) $this->cfg('rilven_legal_form_code', ''));
        if ($wanted === '') {
            return NULL;
        }
        $answer = $this->client->post('/legal-form/filter', array('countryId' => (int) $countryId));
        if (!$answer['ok']) {
            $notes[] = 'legal form: ' . $answer['error'];
            return NULL;
        }
        foreach ($this->items($answer) as $item) {
            $short = isset($item['short_code']) ? $item['short_code'] : (isset($item['shortCode']) ? $item['shortCode'] : '');
            if ($this->sameText($short, $wanted) || $this->sameText(isset($item['name']) ? $item['name'] : '', $wanted)) {
                return (int) $item['id'];
            }
        }
        $notes[] = 'legal form: no form of country ' . $countryId . ' is called ' . $wanted;
        return NULL;
    }

    private function resolveContragentType(&$notes)
    {
        $wanted = trim((string) $this->cfg('rilven_contragent_type_code', ''));
        if ($wanted === '') {
            return NULL;
        }
        $answer = $this->client->get('/contragent-type/list-all');
        if (!$answer['ok']) {
            $notes[] = 'counterparty kind: ' . $answer['error'];
            return NULL;
        }
        foreach ($this->items($answer) as $item) {
            if ($this->sameText(isset($item['code']) ? $item['code'] : '', $wanted)) {
                return (int) $item['id'];
            }
        }
        $notes[] = 'counterparty kind: this company has no kind with code ' . $wanted . ' -- create it there';
        return NULL;
    }

    private function resolveState($countryId, &$notes)
    {
        $wanted = trim((string) $this->cfg('rilven_branch_state_name', ''));
        if ($wanted === '') {
            return NULL;
        }
        $answer = $this->client->post('/state/filter', array('countryId' => (int) $countryId, 'key' => $wanted));
        if (!$answer['ok']) {
            $notes[] = 'state: ' . $answer['error'];
            return NULL;
        }
        $items = $this->items($answer);
        if (empty($items)) {
            $notes[] = 'state: nothing in country ' . $countryId . ' is called ' . $wanted;
            return NULL;
        }
        return (int) $items[0]['id'];
    }

    private function resolveCity($countryId, $stateId, &$notes)
    {
        $wanted = trim((string) $this->cfg('rilven_branch_city_name', ''));
        if ($wanted === '') {
            return NULL;
        }
        $answer = $this->client->post('/city/filter', array(
            'countryId' => (int) $countryId, 'stateId' => (int) $stateId, 'key' => $wanted,
        ));
        if (!$answer['ok']) {
            $notes[] = 'city: ' . $answer['error'];
            return NULL;
        }
        $items = $this->items($answer);
        if (empty($items)) {
            $notes[] = 'city: nothing in state ' . $stateId . ' is called ' . $wanted;
            return NULL;
        }
        return (int) $items[0]['id'];
    }

    /** What is missing before a single patient can be sent. */
    public function missingReferences($refs)
    {
        $missing = array();
        if ($refs['countryId'] === NULL) {
            $missing[] = 'countryId';
        }
        if ($refs['legalFormId'] === NULL && $this->cfg('rilven_is_resident', TRUE)) {
            // required for a resident counterparty, and a clinic's patients are residents
            $missing[] = 'legalFormId';
        }
        return $missing;
    }

    // -----------------------------------------------------------------------
    // small things
    // -----------------------------------------------------------------------

    /** A column this fork may not have at all. */
    public function val($row, $meaning)
    {
        $fields = $this->cfg('rilven_fields', array());
        if (!isset($fields[$meaning])) {
            return '';
        }
        $column = $fields[$meaning];
        if ($column === '' || !isset($row->$column) || $row->$column === NULL) {
            return '';
        }
        return trim((string) $row->$column);
    }

    private function joinParts($row, $order)
    {
        $parts = array();
        foreach ((array) $order as $meaning) {
            $value = $this->val($row, $meaning);
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        return trim(implode(' ', $parts));
    }

    private function digits($value)
    {
        return preg_replace('/[^0-9]/', '', (string) $value);
    }

    private function clip($value, $max)
    {
        $value = (string) $value;
        if (function_exists('mb_substr')) {
            return mb_strlen($value, 'UTF-8') > $max ? mb_substr($value, 0, $max, 'UTF-8') : $value;
        }
        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }

    private function sameText($a, $b)
    {
        $a = trim((string) $a);
        $b = trim((string) $b);
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($a, 'UTF-8') === mb_strtolower($b, 'UTF-8');
        }
        return strtolower($a) === strtolower($b);
    }

    private function intOrNull($value)
    {
        if ($value === NULL || $value === '' || (int) $value <= 0) {
            return NULL;
        }
        return (int) $value;
    }

    private function pick($row, $key, $default)
    {
        return isset($row[$key]) && $row[$key] !== NULL && (int) $row[$key] > 0 ? (int) $row[$key] : $default;
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
        return array('result' => 'rejected', 'id' => 0, 'branchId' => 0,
                     'error' => $error, 'retryable' => (bool) $retryable,
                     'note' => '', 'noteRetryable' => FALSE);
    }
}
