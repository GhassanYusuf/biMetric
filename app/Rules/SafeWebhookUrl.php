<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A webhook URL must not let a tenant aim this server at itself.
 *
 * Webhooks make the SERVER issue requests to an address the CALLER chose, so an
 * unchecked URL is a way to reach `127.0.0.1`, or the cloud metadata service at
 * `169.254.169.254`, from inside the network. Loopback, link-local and other reserved
 * ranges are therefore refused, for literal IPs and for any name that RESOLVES to one.
 *
 * PRIVATE ranges (10/8, 172.16/12, 192.168/16) are deliberately ALLOWED: this product
 * runs on the employer's network and the customer's HR system is very often on the same
 * LAN. Refusing them would make the feature useless for exactly the deployment we sell.
 *
 * The same check runs again at DELIVERY time (DeliverWebhook): a name that resolved
 * somewhere harmless when it was saved can be repointed afterwards.
 */
class SafeWebhookUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($reason = self::violation((string) $value)) {
            $fail($reason);
        }
    }

    /** Null when the URL is acceptable, otherwise the sentence to show. */
    public static function violation(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'The webhook URL must be an absolute http(s) URL.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Credentials in the URL are not allowed. Verify the X-Webhook-Signature header instead.';
        }

        $host = trim($host, '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if ($ips === []) {
            return "The host '{$host}' does not resolve.";
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE)) {
                return "The webhook URL points at a reserved address ({$ip}).";
            }
        }

        return null;
    }
}
