<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * The connection to Rilven: credentials, session, transport, and what an answer means.
 *
 * Nothing in here knows what a patient is. It knows how to prove who this clinic is,
 * how to make a request survive a session that expired between two cron ticks, and how
 * to tell a refusal worth retrying from one that will say the same thing for ever.
 * Everything above it -- the mapping, the queue -- is written against the small result
 * shape returned by {@see request}.
 *
 * Two things about Rilven's answers drive the whole design:
 *
 *   1. It answers HTTP 200 for business failures. `{"status":"fail","data":{"error":...}}`
 *      is a refusal; the HTTP code is not. Code that switches on the HTTP code reads
 *      every refusal as a success.
 *   2. A session is a PAIR of cookies, user_id and token, and it lasts a year. So the
 *      CMS signs in once and keeps them, rather than logging in on every tick and
 *      leaving a device row behind each time.
 *
 * Written for PHP 5.6 and plain CodeIgniter 3 on purpose: these installations are forks
 * that have drifted for years and some of the hosts will not be upgraded for this. No
 * short closures, no null coalescing, no type declarations.
 */
class Rilven_client
{
    /** Where the session is kept between runs. */
    const STATE_USER_ID   = 'session_user_id';
    const STATE_TOKEN     = 'session_token';
    const STATE_SIGNED_AT = 'session_signed_at';

    /** @var CI_Controller */
    private $CI;

    private $cfg = array();

    /** The session, once loaded or established. */
    private $userId = NULL;
    private $token  = NULL;
    private $sessionLoaded = FALSE;

    /** Why the last call failed, for a caller that only wants to print it. */
    private $lastError = '';

    /**
     * What time it is FOR THE CLINIC -- not for whoever ran this, and not for the database.
     *
     * There are three clocks on this installation and no two of them agree:
     *
     *   Asia/Tbilisi     what `index.php` sets, so the cron and every page write dates in it
     *   UTC              what PHP falls back to when the CLI is invoked without index.php
     *   America/New_York what the SERVER and therefore MySQL are set to, eight hours behind
     *
     * Every date in this schema is written through PHP, so the DATA is in the clinic's zone.
     * The comparisons were not, and the damage was the quiet kind. `CURDATE()` answered New
     * York's date, so between midnight and eight in the morning the ripening cutoff sat a whole
     * day behind. And `o.updated_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)` measured a Tbilisi
     * stamp against a New York threshold eight hours in its past, so what should have been a
     * one-hour guard became a nine-hour one -- measured 2026-09-21, it withheld 20 of the 306
     * ripe cases, and WHICH 20 moves with the time of day. Not broken enough to notice; wrong
     * all day long.
     *
     * An EXPLICIT zone rather than the database's, which was the previous fix. Taking both
     * sides off MySQL's clock made them consistent; it did not make them right, because the
     * data was never on that clock. This is the one the clinic keeps, and it reads the same
     * whoever runs the script.
     *
     * @param  string $modify optional strtotime-style shift, e.g. '-2 days'
     * @return string 'Y-m-d H:i:s' in the clinic's zone
     */
    public function clinicNow($modify = '')
    {
        $zone = trim((string) $this->cfg('rilven_source_timezone', 'Asia/Tbilisi'));
        try {
            $now = new DateTime('now', new DateTimeZone($zone === '' ? 'Asia/Tbilisi' : $zone));
        } catch (Exception $e) {
            // A zone name that PHP does not know must not stop the sync. It falls back to the
            // ambient clock and says so, which is the old behaviour and no worse than it.
            log_message('error', 'rilven: unknown timezone ' . $zone . ', using the ambient clock');
            return $modify === '' ? date('Y-m-d H:i:s') : date('Y-m-d H:i:s', strtotime($modify));
        }
        if ($modify !== '') {
            $now->modify($modify);
        }
        return $now->format('Y-m-d H:i:s');
    }

    /** The clinic's date alone, for comparing against a date column. */
    public function clinicToday($modify = '')
    {
        return substr($this->clinicNow($modify), 0, 10);
    }

    /** Set once a credential has been refused, so a run stops instead of hammering. */
    private $credentialRefused = FALSE;

    public function __construct()
    {
        $this->CI = get_instance();
        $this->CI->load->config('rilven', TRUE);
        $this->cfg = $this->CI->config->item('rilven');
        if (!is_array($this->cfg)) {
            // config('rilven', TRUE) namespaces the file. A fork that loaded it flat would
            // otherwise behave exactly as if nothing were configured -- silently.
            $this->cfg = array();
        }
        $this->CI->load->database();
    }

    // -----------------------------------------------------------------------
    // configuration
    // -----------------------------------------------------------------------

    public function cfg($key, $default = NULL)
    {
        if (isset($this->cfg[$key])) {
            return $this->cfg[$key];
        }
        $flat = $this->CI->config->item($key);
        return ($flat === NULL || $flat === FALSE) ? $default : $flat;
    }

    /** Whether this installation is configured well enough to send anything at all. */
    public function enabled()
    {
        if (!$this->cfg('rilven_enabled', FALSE)) {
            return FALSE;
        }
        return $this->credentialsPresent();
    }

    public function credentialsPresent()
    {
        if (trim((string) $this->cfg('rilven_url', '')) === '') {
            return FALSE;
        }
        if ((int) $this->cfg('rilven_company_id', 0) <= 0) {
            return FALSE;
        }
        if ($this->mode() === 'key') {
            return trim((string) $this->cfg('rilven_api_key', '')) !== '';
        }
        return trim((string) $this->cfg('rilven_client_id', '')) !== ''
            && trim((string) $this->cfg('rilven_secret_key', '')) !== '';
    }

    public function mode()
    {
        return strtolower(trim((string) $this->cfg('rilven_auth_mode', 'user'))) === 'key' ? 'key' : 'user';
    }

    public function companyId()
    {
        return (int) $this->cfg('rilven_company_id', 0);
    }

    /** The absolute URL of a business route, e.g. '/contractor/insert'. */
    public function url($path)
    {
        $base = rtrim((string) $this->cfg('rilven_url', ''), '/');
        $api  = (string) $this->cfg('rilven_api_base', '/api/v1');
        if ($api !== '' && substr($api, 0, 1) !== '/') {
            $api = '/' . $api;
        }
        if (substr($path, 0, 1) !== '/') {
            $path = '/' . $path;
        }
        return $base . rtrim($api, '/') . $path;
    }

    public function lastError()
    {
        return $this->lastError;
    }

    /** True once a credential has been refused: the caller should stop, not retry. */
    public function credentialRefused()
    {
        return $this->credentialRefused;
    }

    // -----------------------------------------------------------------------
    // the session
    // -----------------------------------------------------------------------

    /**
     * Sign in and keep the cookies.
     *
     * Not called per request -- {@see request} calls it when there is no session yet, and
     * again if a call comes back saying the session is dead. A successful sign-in is good
     * for a year and each one inserts a device row over there, so logging in per tick
     * would leave half a million of them behind in a year of minutes.
     */
    public function login($force = FALSE)
    {
        if ($this->mode() === 'key') {
            // nothing to sign in with; the key IS the credential
            return TRUE;
        }

        if (!$force && $this->haveSession()) {
            return TRUE;
        }

        // id.rilven.com is the only door. /user/login and /portal/login were deleted when the
        // portal and the console stopped having a login each, so there is nothing to fall back
        // to: a Rilven old enough to answer /user/login is older than the API this library is
        // written against, and pretending otherwise only spends a second request on every
        // refusal to reach the same failure.
        $credentials = array(
            'email'    => trim((string) $this->cfg('rilven_client_id', '')),
            'password' => (string) $this->cfg('rilven_secret_key', ''),
        );

        $answer = $this->send('POST', $this->url('/id/sign-in'), $credentials, FALSE);

        if (!$answer['ok']) {
            $this->credentialRefused = TRUE;
            $this->lastError = $answer['error'] !== '' ? $answer['error'] : 'login-failed';
            return FALSE;
        }

        $userId = isset($answer['cookies']['user_id']) ? $answer['cookies']['user_id'] : NULL;
        $token  = isset($answer['cookies']['token']) ? $answer['cookies']['token'] : NULL;

        if ($userId === NULL || $token === NULL || $userId === '' || $token === '') {
            // An "ok" with no cookies is not a session. Treated as a refusal rather than
            // carried forward, because every later call would fail for a reason that no
            // longer names the login.
            $this->credentialRefused = TRUE;
            $this->lastError = 'login-returned-no-session';
            return FALSE;
        }

        // Signing in opens a NEW device every time -- it never reuses one -- and a device is born
        // unverified. Auth then refuses an unverified device every endpoint but five, so the
        // cookies below would be a session that can do nothing, and the next tick would open
        // another one. The way out is not a retry: it is tb_user.is_service on the account, which
        // is what makes a device born verified, and which is set by hand in the database and
        // never through the API. So this is reported as a refusal of the credential, naming the
        // one thing that fixes it, rather than carried forward as a session.
        $verified = isset($answer['data']['deviceVerified']) ? $answer['data']['deviceVerified'] : TRUE;
        if ($verified === FALSE || $verified === 0 || $verified === '0' || $verified === 'false') {
            $this->credentialRefused = TRUE;
            $this->lastError = 'login-device-not-verified'
                . ' -- set tb_user.is_service = true for ' . $credentials['email'];
            return FALSE;
        }

        $this->userId = $userId;
        $this->token  = $token;
        $this->sessionLoaded = TRUE;
        $this->setState(self::STATE_USER_ID, $userId);
        $this->setState(self::STATE_TOKEN, $token);
        $this->setState(self::STATE_SIGNED_AT, date('Y-m-d H:i:s'));

        return TRUE;
    }

    /** Throw the stored session away, so the next call signs in again. */
    public function forgetSession()
    {
        $this->userId = NULL;
        $this->token  = NULL;
        $this->sessionLoaded = TRUE;
        $this->setState(self::STATE_USER_ID, NULL);
        $this->setState(self::STATE_TOKEN, NULL);
    }

    public function signedInAt()
    {
        return $this->state(self::STATE_SIGNED_AT, NULL);
    }

    private function haveSession()
    {
        if (!$this->sessionLoaded) {
            $this->userId = $this->state(self::STATE_USER_ID, NULL);
            $this->token  = $this->state(self::STATE_TOKEN, NULL);
            $this->sessionLoaded = TRUE;
        }
        return $this->userId !== NULL && $this->token !== NULL
            && $this->userId !== '' && $this->token !== '';
    }

    // -----------------------------------------------------------------------
    // requests
    // -----------------------------------------------------------------------

    public function get($path, $query = array())
    {
        return $this->request('GET', $path, NULL, $query);
    }

    public function post($path, $body = NULL, $query = array())
    {
        return $this->request('POST', $path, $body, $query);
    }

    public function put($path, $body = NULL, $query = array())
    {
        return $this->request('PUT', $path, $body, $query);
    }

    /**
     * One business call, with the session established and re-established as needed.
     *
     * The result is always an array and this never throws: a failure to reach Rilven must
     * not be able to take a save handler or a cron run with it.
     *
     *   ok        bool    the server accepted it
     *   http      int     the HTTP code, for the log only -- never for a decision
     *   status    string  'ok' | 'fail' | ''
     *   data      array   the data envelope, {} on a failure
     *   error     string  the error key, '' when there is none
     *   retryable bool    whether trying again later could give a different answer
     */
    public function request($method, $path, $body = NULL, $query = array(), $allowRelogin = TRUE)
    {
        $this->lastError = '';

        if (!$this->credentialsPresent()) {
            return $this->failure('not-configured', FALSE, 0);
        }
        if ($this->credentialRefused) {
            return $this->failure('credential-refused', FALSE, 0);
        }

        if ($this->mode() === 'user' && !$this->haveSession()) {
            if (!$this->login()) {
                return $this->failure($this->lastError, FALSE, 0);
            }
        }

        $url = $this->url($path);
        if (!empty($query)) {
            $url .= (strpos($url, '?') === FALSE ? '?' : '&') . http_build_query($query);
        }

        $answer = $this->send($method, $url, $body, TRUE);

        // A dead session between two cron ticks is ordinary -- the password was changed,
        // the device was cleared -- and is the one failure worth repeating immediately,
        // once, with a fresh sign-in behind it.
        if ($allowRelogin && $this->mode() === 'user' && $this->isDeadSession($answer)) {
            $this->forgetSession();
            if ($this->login(TRUE)) {
                $answer = $this->send($method, $url, $body, TRUE);
            } else {
                return $this->failure($this->lastError, FALSE, 0);
            }
        }

        if (!$answer['ok']) {
            $this->lastError = $answer['error'];
        }

        $throttle = (int) $this->cfg('rilven_throttle_ms', 0);
        if ($throttle > 0) {
            usleep($throttle * 1000);
        }

        return $answer;
    }

    // -----------------------------------------------------------------------
    // the wire
    // -----------------------------------------------------------------------

    /**
     * One HTTP exchange, with no notion of sessions or retries.
     *
     * @param bool $authenticated send the session and the company; false for the login itself
     */
    private function send($method, $url, $body, $authenticated)
    {
        $headers = array('Accept: application/json');
        if ($body !== NULL) {
            $headers[] = 'Content-Type: application/json';
        }

        // The key travels in a header and never in the URL: a URL is written into the
        // access log of every hop between here and there.
        $apiKey = trim((string) $this->cfg('rilven_api_key', ''));
        if ($apiKey !== '') {
            $headers[] = trim((string) $this->cfg('rilven_api_key_header', 'X-Rilven-Api-Key')) . ': ' . $apiKey;
        }
        if ($this->mode() === 'key') {
            $clientId = trim((string) $this->cfg('rilven_client_id', ''));
            if ($clientId !== '') {
                $headers[] = trim((string) $this->cfg('rilven_client_id_header', 'X-Rilven-Client-Id')) . ': ' . $clientId;
            }
            $secret = trim((string) $this->cfg('rilven_secret_key', ''));
            if ($secret !== '') {
                $headers[] = 'Authorization: Bearer ' . $secret;
            }
        }
        if ($authenticated) {
            $headers[] = 'Company-Id: ' . $this->companyId();
        }

        $ch = curl_init($url);
        $options = array(
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_CONNECTTIMEOUT => (int) $this->cfg('rilven_connect_timeout', 10),
            CURLOPT_TIMEOUT        => (int) $this->cfg('rilven_timeout', 60),
            CURLOPT_SSL_VERIFYPEER => $this->cfg('rilven_verify_ssl', TRUE) ? TRUE : FALSE,
            CURLOPT_SSL_VERIFYHOST => $this->cfg('rilven_verify_ssl', TRUE) ? 2 : 0,
            CURLOPT_HTTPHEADER     => $headers,
        );

        if ($body !== NULL) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        }

        // Auth resolves the PAIR. A token without a user_id is not a session, so both go
        // or neither does.
        if ($authenticated && $this->mode() === 'user' && $this->haveSession()) {
            $options[CURLOPT_COOKIE] = 'user_id=' . rawurlencode($this->userId)
                                     . '; token=' . rawurlencode($this->token);
        }

        // Cookies are read off the response rather than through a cookie jar file: a jar
        // is a path that has to be writable by both the web user and the cron user, and on
        // these hosts it very often is not.
        $cookies = array();
        $options[CURLOPT_HEADERFUNCTION] = function ($curl, $header) use (&$cookies) {
            $length = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2 && strtolower(trim($parts[0])) === 'set-cookie') {
                $pair = explode(';', trim($parts[1]), 2);
                $kv = explode('=', $pair[0], 2);
                if (count($kv) === 2) {
                    $cookies[trim($kv[0])] = rawurldecode(trim($kv[1]));
                }
            }
            return $length;
        };

        curl_setopt_array($ch, $options);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === FALSE) {
            // The far side was not reached at all. Always worth trying again: this is a
            // network, a restart, a deploy -- never a judgement on the payload.
            return $this->failure('transport: ' . $err, TRUE, 0);
        }

        $decoded = json_decode($raw, TRUE);
        if (!is_array($decoded)) {
            // HTML from a proxy, a maintenance page, a truncated answer. Not a refusal of
            // the row, so it is retryable; the first 200 characters go in the message
            // because that is usually enough to recognise which box answered.
            return $this->failure('unreadable-answer[' . $code . ']: ' . substr((string) $raw, 0, 200), TRUE, $code);
        }

        $status = isset($decoded['status']) ? (string) $decoded['status'] : '';
        $data   = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : array();

        if ($status === 'ok') {
            return array(
                'ok'        => TRUE,
                'http'      => $code,
                'status'    => 'ok',
                'data'      => $data,
                'error'     => '',
                'retryable' => FALSE,
                'cookies'   => $cookies,
            );
        }

        $error = '';
        if (isset($data['error'])) {
            $error = is_array($data['error']) ? json_encode($data['error']) : (string) $data['error'];
        } elseif (isset($decoded['error'])) {
            $error = (string) $decoded['error'];
        } elseif (isset($decoded['message'])) {
            $error = (string) $decoded['message'];
        }
        if ($error === '') {
            $error = 'rejected[' . $code . ']';
        }

        $result = $this->failure($error, $this->retryable($error, $code), $code);
        $result['status']  = $status === '' ? 'fail' : $status;
        $result['data']    = $data;
        $result['cookies'] = $cookies;
        return $result;
    }


    private function failure($error, $retryable, $code)
    {
        return array(
            'ok'        => FALSE,
            'http'      => (int) $code,
            'status'    => 'fail',
            'data'      => array(),
            'error'     => (string) $error,
            'retryable' => (bool) $retryable,
            'cookies'   => array(),
        );
    }

    /**
     * Whether the same request, sent again in ten minutes, could be answered differently.
     *
     * The distinction is the whole value of the queue. A row refused because a field is
     * missing will be refused identically for ever, and putting it back in the queue only
     * buys nine more identical refusals and hides it behind them; a row refused because
     * the far side was restarting wants exactly that.
     */
    private function retryable($error, $code)
    {
        if ($code >= 500) {
            return TRUE;
        }

        // an internal fault over there, as opposed to a judgement on the payload
        if (strpos($error, 'system-error[0001.0001]') === 0) {
            return TRUE;
        }
        // An unverified device (0002.0002) is NOT retryable, and it used to be swept up by the
        // prefix below. Signing in again cannot fix it -- each sign-in opens another device, also
        // unverified -- so every row would be requeued for ever against a cause no retry reaches.
        // It is a missing tb_user.is_service, and somebody has to set it.
        if (strpos($error, 'system-error[0002.0002]') === 0) {
            return FALSE;
        }
        // no session: recoverable by signing in again
        if (strpos($error, 'system-error[0001.0000]') === 0 || strpos($error, 'system-error[0002.') === 0) {
            return TRUE;
        }

        // A right that has not been granted, a missing field, a duplicate, a bad shape.
        // Every one of them is a decision somebody has to take, here or over there.
        return FALSE;
    }

    /**
     * The answer that means "you have no session", whatever else it says.
     *
     * Two codes, because the far side distinguishes between arriving with no cookie at
     * all (0002.0001) and arriving with one no device answers to (0001.0000) -- the first
     * run after an install, and a session that has since been cleared. Both are fixed by
     * signing in, and reading only one of them leaves the other looking like a permanent
     * refusal of every row.
     */
    private function isDeadSession($answer)
    {
        if ($answer['ok']) {
            return FALSE;
        }
        return strpos($answer['error'], 'system-error[0001.0000]') === 0
            || strpos($answer['error'], 'system-error[0002.0001]') === 0
            || $answer['http'] === 401;
    }

    // -----------------------------------------------------------------------
    // state -- the little key/value table this library keeps for itself
    // -----------------------------------------------------------------------

    public function state($name, $default = NULL)
    {
        $row = $this->CI->db->select('value')->from('rilven_state')->where('name', $name)->get()->row();
        if (!$row || $row->value === NULL || $row->value === '') {
            return $default;
        }
        return $row->value;
    }

    public function setState($name, $value)
    {
        $sql = 'INSERT INTO ' . $this->CI->db->dbprefix('rilven_state')
             . ' (name, value, updated_at) VALUES (?, ?, NOW())'
             . ' ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()';
        $this->CI->db->query($sql, array($name, $value === NULL ? NULL : (string) $value));
    }
}
