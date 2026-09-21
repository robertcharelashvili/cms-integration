<?php defined('BASEPATH') OR exit('No direct script access allowed');

require_once __DIR__ . '/Rilven_contractor.php';

/**
 * A financier of this clinic, as a counterparty of Rilven.
 *
 * The same two routes as a patient -- /contractor and /contractor-branch -- and the same link,
 * `code` = sma_companies.id. Which is why this is that register with its keys redirected rather
 * than a second copy of it: the branch handling, the reference resolution, the tax-code fallback
 * and the code shape are not different for an insurance company, and a copy would have to be
 * kept in step with the original for ever.
 *
 * WHAT IS different, and all of it lives in the config under `rilven_insurer_*`:
 *
 *   the scope     group 5 ('insurance') instead of group 3 ('customer').
 *   the kind      contragent type `insurance`, whose receivable account is 1410 -- the same
 *                 account as a patient's, which is what the accrual model requires: the
 *                 financier's share moves from the patient to the financier WITHIN 1410.
 *   the shape     a legal entity, not a natural person. `rilven_insurer_person_fields` must be
 *                 FALSE. Sending them once stamped a sex onto two hundred insurance companies,
 *                 and an update cannot unsay a value.
 *   the name      one company name, not a given name and a family name.
 *
 * WHAT THE DATA IS, measured against the live dump on 2026-09-21, because it decides what this
 * register can promise. Of 222 rows: 221 have a name, 76 have anything at all in the tax-code
 * column, and of those only FIVE are a real nine-digit number -- the rest are '-', '1', '123',
 * '1122'. So identity here is `code`, and the tax code is very largely the `cms-{id}` fallback.
 * Phone is filled in nine times and e-mail once; the branch will be mostly empty, and that is
 * the truth about this register rather than a fault in it.
 *
 * The group is also wider than its name: alongside the insurers it holds the state health
 * programme, municipalities, and placeholders like 'shida' and 'ფიზიკური პირი'. They are all
 * financiers in the sense that matters -- somebody other than the patient settles -- but if the
 * clinic wants only the ones that actually carry money, that is `rilven_insurer_where`, not code.
 */
class Rilven_insurer extends Rilven_contractor
{
    /** The outbox `entity` this register writes under. Its own, so the two never share a row. */
    public function entity()
    {
        return 'insurer';
    }

    /** The outbox `type_code`. Informational; it is what somebody reading the queue sees. */
    public function typeCode()
    {
        return 'insurance';
    }

    /**
     * Whether financiers are sent at all.
     *
     * Defaults to FALSE, unlike the patient register's TRUE. An installation that has not been
     * told about this register must not start writing a second kind of counterparty into Rilven
     * because it was upgraded.
     */
    public function enabled()
    {
        // Read straight from the client, not through cfg(): this key is already the
        // register's own, and the prefixing below would look for rilven_insurer_insurer_enabled.
        return $this->client->cfg('rilven_insurer_enabled', FALSE) ? TRUE : FALSE;
    }

    /**
     * A config value, this register's own if it has one and the shared one otherwise.
     *
     * `rilven_person_fields` is read here as `rilven_insurer_person_fields`, and falls through to
     * `rilven_person_fields` when that is not set. So the config says only what DIFFERS about a
     * financier, and everything the two kinds agree on -- the country, the code shape, the branch
     * defaults -- is written once.
     *
     * The sentinel, rather than a NULL test: a key deliberately set to NULL or FALSE is set, and
     * a fall-through that could not tell those from absent would ignore exactly the settings an
     * installation had bothered to turn off.
     */
    protected function cfg($key, $default = NULL)
    {
        if (strpos($key, 'rilven_') === 0) {
            $own = 'rilven_insurer_' . substr($key, strlen('rilven_'));
            $missing = "\0rilven-key-absent\0";
            $value = $this->client->cfg($own, $missing);
            if ($value !== $missing) {
                return $value;
            }
        }
        return $this->client->cfg($key, $default);
    }

    /**
     * This register's own corner of sma_rilven_state.
     *
     * Without it the two registers would share `ref_contragent_type_id` and `ref_legal_form_id`,
     * and whichever ran last would decide what the other one is -- every patient written as an
     * insurance company on the next run, or every insurer as a patient. There is no undo for
     * that: the update route overwrites the field, it cannot restore what was there.
     */
    protected function stateKey($name)
    {
        return 'insurer_' . $name;
    }
}
