<?php

namespace App\Services\Zkteco;

/**
 * Some ZKTeco-hardware terminals ship with a reseller-branded firmware
 * (seen in the wild as "Secutime"/"CSL") that does NOT speak the modern
 * zkemkeeper-style binary protocol implemented in ZktecoConnection, nor
 * the older raw "CommPro" serial protocol. Instead it exposes a small
 * authenticated web UI (an embedded "ZK Web Server") with a data export
 * page at /csl/download that returns a plain tab-separated attendance
 * log over plain HTTP.
 *
 * This client drives that web UI directly: log in, discover the
 * enrolled user IDs, then request the export for a date range.
 *
 * Export line format (tab-separated, no header row):
 *   {device_user_id}\t{name}\t{yyyy-mm-dd HH:ii:ss}\t{verify_method}\t{status}
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
    ) {
        $this->curl = curl_init();
        curl_setopt_array($this->curl, [
            CURLOPT_COOKIEFILE => '', // enables curl's in-memory cookie engine for this handle
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) ceil($this->timeout),
            CURLOPT_FOLLOWLOCATION => false,
        ]);
    }

    public static function fromModel(\App\Models\Device $device): self
    {
        return new self($device->ip_address, (string) $device->web_username, (string) $device->web_password);
    }

    /** Logs into the device's web UI. Throws if the credentials are rejected. */
    public function login(): void
    {
        $this->request('GET', '/'); // bootstraps the session cookie the login check relies on

        $response = $this->request('POST', '/csl/check', [
            'username' => $this->username,
            'userpwd' => $this->password,
        ]);

        if (!stripos($response, 'FRAMESET')) {
            throw new ZktecoException('Device web login was rejected - check the web username/password.');
        }
    }

    /** @return int[] device-internal user IDs found on the Download page's user checklist. */
    public function fetchEnrolledUids(): array
    {
        $html = $this->request('GET', '/csl/download');

        preg_match_all('/name=uid\s+value=(\d+)/i', $html, $matches);

        return array_values(array_unique(array_map('intval', $matches[1])));
    }

    /**
     * The device's "User" administration page lists every enrolled person
     * with their actual payroll-facing ID Number and name (distinct from
     * the internal `uid` used elsewhere - ID Number is what shows up as
     * the user_id in attendance exports).
     *
     * @return array<int, array{uid:int, employee_code:string, name:string}>
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
     * Enrolls a new user through the device's own "Add User" web form -
     * the same path the device's admin UI itself uses, proven far more
     * reliable than the binary protocol's CMD_USER_WRQ (which has been
     * observed to silently drop writes on this hardware).
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

    /** Removes a user (by the device-internal uid found in fetchEmployeeDirectory) via the device's own web UI. */
    public function deleteUser(int $uid): void
    {
        $this->request('POST_RAW', '/csl/user?action=del', 'uid=' . $uid);
    }

    /** @return array<int, array{user_id:string,name:string,timestamp:\DateTimeImmutable,verify_method:int,status:int}> */
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

        $raw = $this->request('POST_RAW', '/form/Download', implode('&', $pairs));

        return self::parseAttendanceExport($raw);
    }

    public static function parseAttendanceExport(string $raw): array
    {
        $records = [];

        foreach (preg_split('/\r\n|\r|\n/', trim($raw)) as $line) {
            if ($line === '') {
                continue;
            }

            $columns = explode("\t", $line);
            if (count($columns) < 4) {
                continue;
            }

            // The device's export reports its own local wall-clock time, not UTC
            // (confirmed against real hardware - same fix as ZktecoConnection's
            // binary-protocol decoder, see its DEVICE_TIMEZONE doc comment).
            $timestamp = \DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                trim($columns[2]),
                new \DateTimeZone('+03:00')
            );
            if ($timestamp === false) {
                continue;
            }

            $records[] = [
                'user_id' => trim($columns[0]),
                'name' => trim($columns[1]),
                'timestamp' => $timestamp,
                'verify_method' => (int) trim($columns[3]),
                'status' => isset($columns[4]) ? (int) trim($columns[4]) : 0,
            ];
        }

        return $records;
    }

    private function request(string $method, string $path, array|string $data = []): string
    {
        $url = "http://{$this->host}:{$this->port}{$path}";
        curl_setopt($this->curl, CURLOPT_URL, $url);

        if ($method === 'GET') {
            curl_setopt($this->curl, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($this->curl, CURLOPT_POST, true);
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : $data);
        }

        $response = curl_exec($this->curl);

        if ($response === false) {
            throw new ZktecoException("HTTP request to device web UI failed: " . curl_error($this->curl));
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
