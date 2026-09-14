<?php

namespace App\Services\Devices\Csl;

use Promoseven\Biometric\Exception\ConnectionException;

/**
 * Drives the embedded web UI on reseller-branded ("Secutime"/"CSL") firmware.
 *
 * Some ZKTeco hardware ships with firmware that does NOT speak the binary protocol
 * on port 4370 at all. Instead it exposes a small authenticated web server with a
 * data-export page. There is no session, no capability negotiation and no realtime
 * stream — just forms.
 *
 * DOCUMENTED, NOT CONFIRMED-BY-TEST: every selector below was reverse-engineered
 * from one terminal's HTML. A firmware revision that renames a form field breaks
 * the matching regex, and the honest failure is an empty result rather than a
 * wrong one — see fetchEmployeeDirectory() and parseAttendanceExport().
 *
 * Export line format (tab-separated, no header row):
 *   {device_user_id}\t{name}\t{yyyy-mm-dd HH:ii:ss}\t{verify_method}\t{status}
 *
 * This is transport only. It knows nothing about Eloquent, tenancy or attendance
 * semantics — CslHttpDriver adapts it to the app, exactly as ZktecoDriver adapts
 * the protocol package.
 */
class CslWebPortalClient
{
    private \CurlHandle $curl;

    public function __construct(
        public readonly string $host,
        public readonly string $username,
        public readonly string $password,
        public readonly int $port = 80,
        private readonly float $timeout = 10.0,
        private readonly string $timezone = '+03:00',
    ) {
        $this->curl = curl_init();

        curl_setopt_array($this->curl, [
            CURLOPT_COOKIEFILE => '', // enables curl's in-memory cookie jar for this handle
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) ceil($this->timeout),
            CURLOPT_FOLLOWLOCATION => false,
        ]);
    }

    /** Logs into the device's web UI. Throws if the credentials are rejected. */
    public function login(): void
    {
        $this->request('GET', '/'); // bootstraps the cookie the login check relies on

        $response = $this->request('POST', '/csl/check', [
            'username' => $this->username,
            'userpwd' => $this->password,
        ]);

        // The UI answers a successful login with the frameset of its admin shell.
        if (! stripos($response, 'FRAMESET')) {
            throw new ConnectionException('Device web login was rejected — check the web username and password.');
        }
    }

    /** @return list<int> device-internal user ids from the Download page's checklist. */
    public function fetchEnrolledUids(): array
    {
        $html = $this->request('GET', '/csl/download');

        preg_match_all('/name=uid\s+value=(\d+)/i', $html, $matches);

        return array_values(array_unique(array_map('intval', $matches[1])));
    }

    /**
     * The administration page lists every enrolled person with their payroll-facing
     * ID Number and name. That ID Number — not the internal uid — is what appears
     * as user_id in an attendance export.
     *
     * @return list<array{uid:int, employee_code:string, name:string}>
     */
    public function fetchEmployeeDirectory(): array
    {
        $html = $this->request('GET', '/csl/user');

        $pattern = '/name=uid\s+value=(\d+)>\s*<\/td>\s*<td[^>]*>\s*<\/td>\s*'
            . '<td[^>]*>([^<]*)<\/td>\s*<td[^>]*>([^<]*)<\/td>/is';

        preg_match_all($pattern, $html, $matches, PREG_SET_ORDER);

        $employees = [];

        foreach ($matches as $match) {
            $employeeCode = trim($match[2]);
            $name = trim(html_entity_decode($match[3]));

            if ($employeeCode === '') {
                continue;
            }

            $employees[] = [
                'uid' => (int) $match[1],
                'employee_code' => $employeeCode,
                'name' => $name !== '' ? $name : $employeeCode,
            ];
        }

        return $employees;
    }

    /**
     * Enrolls a user through the device's own "Add User" form.
     *
     * CONFIRMED on this firmware: this path is markedly more reliable than the
     * binary protocol's USER_WRQ, which has been observed to acknowledge a write
     * and then drop it.
     */
    public function addUser(string $employeeCode, string $name, int $privilege = 0, int $cardNumber = 0): void
    {
        $this->request('POST', '/csl/user?action=save&id=add', [
            'upin' => 'NULL',
            'upin2' => $employeeCode,
            'uname' => $name,
            'udpm' => '',
            'uprivilege' => $privilege,
            'ugroup' => 1,
            'usegrouptz' => 0,
            'upwd' => '',
            'ucard' => $cardNumber,
        ]);
    }

    /** Removes a user by the device-internal uid from fetchEmployeeDirectory(). */
    public function deleteUser(int $uid): void
    {
        $this->request('POST_RAW', '/csl/user?action=del', 'uid=' . $uid);
    }

    /**
     * Downloads the attendance export for a date range.
     *
     * The form requires an explicit uid list — there is no "everyone" option — so
     * the enrolled set is fetched first. No uids means no export, not an error.
     *
     * @return list<array<string,mixed>>
     */
    public function downloadAttendance(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $uids = $this->fetchEnrolledUids();

        if ($uids === []) {
            return [];
        }

        $pairs = [
            'sdate=' . urlencode($from->format('Y-m-d')),
            'edate=' . urlencode($to->format('Y-m-d')),
            'period=0',
        ];

        foreach ($uids as $uid) {
            $pairs[] = 'uid=' . $uid;
        }

        return $this->parseAttendanceExport($this->request('POST_RAW', '/form/Download', implode('&', $pairs)));
    }

    /**
     * Parses the tab-separated export.
     *
     * Each returned record carries the ORIGINAL LINE as `raw`, so the archive holds
     * what the device actually said and a parser fix can be replayed over it. A line
     * that will not parse is returned WITHOUT a timestamp rather than skipped — the
     * caller counts it as undecodable. Dropping it here would understate attendance
     * and nobody would ever know.
     *
     * @return list<array<string,mixed>>
     */
    public function parseAttendanceExport(string $raw): array
    {
        $records = [];

        foreach (preg_split('/\r\n|\r|\n/', trim($raw)) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $columns = explode("\t", $line);

            if (count($columns) < 4) {
                $records[] = ['raw' => $line]; // no timestamp key = undecodable
                continue;
            }

            /*
             * The export reports the device's own LOCAL wall-clock time, not UTC.
             * The offset is supplied by the caller from the device record; assuming
             * one here is how a whole estate ends up shifted by hours.
             */
            $timestamp = \DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                trim($columns[2]),
                new \DateTimeZone($this->timezone),
            );

            if ($timestamp === false) {
                $records[] = ['raw' => $line];
                continue;
            }

            $records[] = [
                'user_id' => trim($columns[0]),
                'name' => trim($columns[1]),
                'timestamp' => $timestamp,
                'verify_method' => (int) trim($columns[3]),
                'status' => isset($columns[4]) ? (int) trim($columns[4]) : 0,
                'raw' => $line,
            ];
        }

        return $records;
    }

    private function request(string $method, string $path, array|string $data = []): string
    {
        curl_setopt($this->curl, CURLOPT_URL, "http://{$this->host}:{$this->port}{$path}");

        if ($method === 'GET') {
            curl_setopt($this->curl, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($this->curl, CURLOPT_POST, true);
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : $data);
        }

        $response = curl_exec($this->curl);

        if ($response === false) {
            throw new ConnectionException('HTTP request to the device web UI failed: ' . curl_error($this->curl));
        }

        return $response;
    }

    public function __destruct()
    {
        if (isset($this->curl)) {
            curl_close($this->curl);
        }
    }
}
