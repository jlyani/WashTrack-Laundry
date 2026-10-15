<?php
// includes/config.php - environment detection and URL helpers (mga URL helper).
// This file holds the system "settings" (numbers like MACHINES_PER_ORDER, MIN_CHARGE_KG,
// bayad per load, etc.) plus the helpers that build proper URL/link and redirect.
// Include once, before anything that prints or redirects:   require_once __DIR__ . '/config.php';
//
//   BASE_URL            absolute URL of THIS folder, with trailing slash, e.g. https://shop.example.com/laundry_system/
//   APP_ENV             'local' or 'production'
//   app_url('x.php')    BASE_URL + path            -> links, redirects, fetch() endpoints
//   app_redirect('x')   header("Location: ...") + exit
//
// Production: set the APP_URL environment variable (or edit APP_URL_FIXED below) to pin the public address,
// e.g. APP_URL=https://yourdomain.com/laundry_system . Then the Host header sent by a visitor can never
// influence generated links. Kapag left empty, the URL is derived from the request.

if (defined('BASE_URL')) return;   // already loaded

const APP_URL_FIXED   = '';        // e.g. 'https://yourdomain.com/laundry_system'; '' = auto-detect (or use the APP_URL env var)
const APP_TRUST_PROXY = false;     // true lang if behind a reverse proxy / Cloudflare that sets X-Forwarded-Proto
                                   // (free tunnel hosts such as ngrok are recognised automatically, see app_is_tunnel_host)

// How many machines one order may use, and how long before it finishes (SLA/"Ready by").
// Machine queue and turnaround promise (printed on the claim slip as "Ready by").
//   MACHINES_PER_ORDER   one order runs on at most this many washers + the same number of dryers at a time.
//                        Loads beyond that run as extra rounds on the same machines, so a big order can never
//                        lock up the whole shop (and can always start).
//   SLA_ROUND_MINUTES    one round = one full wash + dry cycle (and fold, where the service includes it).
//   promised = order time + SLA_ROUND_MINUTES x (rounds + waiting rounds)
//   rounds   = ceil(loads / MACHINES_PER_ORDER); waiting rounds are added at intake when the order cannot start
//   at once (see sla_wait_rounds() in includes/sla.php).
// Orders still Pending / In Progress after the promised time are flagged Overdue. For a quick demo of the flag,
// set SLA_ROUND_MINUTES=2 (environment variable) or edit the number below.
const MACHINES_PER_ORDER       = 2;
const SLA_ROUND_MINUTES_DEFAULT = 180;

// Price = rate per kg x billed kg. The price stays the same no matter the load count —
// loads only decide how many machines are needed and when it finishes.
// Pricing: price = service rate per kg x billed kg, final at intake (the amount charged at pickup).
//   MIN_CHARGE_KG   minimum billed weight. A bag lighter than this is still accepted and weighed as it is,
//                   but billed as this many kg. Set to 0 to switch the minimum charge off.
//   ORDER_MAX_KG    heaviest bag one order accepts (guards against a mistyped weight).
// Loads never change the price; they only decide how many machines the order needs and when it is ready.
const MIN_CHARGE_KG_DEFAULT = 3;
const ORDER_MAX_KG          = 100;

// Called a "Profit guard" — a reminder lang on the Services page when the rate gets too low.
// It does not block saving a price.
// Profit guard shown on the Services page (it never blocks saving a rate):
//   COST_PER_LOAD      what one load really costs the shop: washer run + dryer run + water/power + detergent + fabric
//                      conditioner (+ labor if you count it). The rate per kg is flat, so it has to cover the worst bag:
//                      a Regular + Heavy mix of the minimum weight, which takes 2 loads.
//   PROFIT_MARGIN_PCT  margin wanted on top of cost.
// Both can be overridden with environment variables of the same name.
const COST_PER_LOAD_DEFAULT     = 50;
const PROFIT_MARGIN_PCT_DEFAULT = 30;

/** Host without port, lower-cased (mga letra). */
function app_host_only(string $host): string {
    $host = strtolower($host);
    if ($host !== '' && $host[0] === '[') return substr($host, 0, (int) strpos($host, ']') + 1);   // [::1]:80
    return explode(':', $host)[0];
}

/** True for localhost, loopback, *.local / *.test and private LAN addresses (10.x, 172.16-31.x, 192.168.x). */
function app_is_local_host(string $host): bool {
    $h = app_host_only($host);
    if ($h === 'localhost' || $h === '[::1]' || $h === '::1') return true;
    if (preg_match('/\.(local|test|localhost)$/', $h)) return true;
    if (filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return !filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
    return false;
}

/** True for free tunnel hosts used to demo the app on a phone (ngrok, localtunnel, Cloudflare quick tunnel). */
function app_is_tunnel_host(string $host): bool {
    return (bool) preg_match('/\.(ngrok-free\.app|ngrok-free\.dev|ngrok\.app|ngrok\.dev|ngrok\.io|loca\.lt|trycloudflare\.com)$/', app_host_only($host));
}

/** This project's folder relative to the web root: '' or '/laundry_system'. Independent of the running script. */
function app_base_path(): string {
    $norm = function ($p) { return rtrim(str_replace('\\', '/', (string) $p), '/'); };
    $root = $norm(realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
    $dir  = $norm(realpath(dirname(__DIR__)) ?: dirname(__DIR__));   // project root (this file lives in includes/)
    if ($root !== '' && stripos($dir, $root) === 0) return substr($dir, strlen($root));
    // Fallback (aliases / symlinks): folder of the running script.
    $d = $norm(dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return $d === '.' ? '' : $d;
}

// This works out the base URL (http or https, which host) and whether the app is local, tunnel, or production.
// Important so the generated links are correct.
(function () {
    $fixed = APP_URL_FIXED !== '' ? APP_URL_FIXED : (string) getenv('APP_URL');

    if ($fixed !== '') {
        $base  = rtrim($fixed, '/') . '/';
        $local = app_is_local_host((string) parse_url($base, PHP_URL_HOST));
    } else {
        // HTTP_HOST is client-supplied: accept only a plain host[:port], else fall back to the server's own name.
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if (!preg_match('/^(?:[a-z0-9.-]+|\[[0-9a-f:]+\])(?::\d{1,5})?$/i', $host)) {
            $host = (string) ($_SERVER['SERVER_NAME'] ?? 'localhost');
        }
        // A tunnel terminates HTTPS and talks plain HTTP to XAMPP, so trust its X-Forwarded-Proto (tunnel hosts only).
        $trust = APP_TRUST_PROXY || app_is_tunnel_host($host);
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['SERVER_PORT'] ?? '') === '443')
              || ($trust && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
              || (app_is_tunnel_host($host) && !isset($_SERVER['HTTP_X_FORWARDED_PROTO']));   // ngrok/localtunnel are always https
        $base  = ($https ? 'https://' : 'http://') . $host . rtrim(app_base_path(), '/') . '/';
        $local = app_is_local_host($host);
    }

    define('BASE_URL', $base);
    $tunnel = app_is_tunnel_host((string) parse_url($base, PHP_URL_HOST));
    define('APP_ENV', $tunnel ? 'tunnel' : ($local ? 'local' : 'production'));
})();

// SLA_BASE_MINUTES is still read as a fallback so an old demo setup keeps working.
define('COST_PER_LOAD', max(0.0, (float) (getenv('COST_PER_LOAD') !== false && getenv('COST_PER_LOAD') !== '' ? getenv('COST_PER_LOAD') : COST_PER_LOAD_DEFAULT)));
define('PROFIT_MARGIN_PCT', max(0.0, (float) (getenv('PROFIT_MARGIN_PCT') !== false && getenv('PROFIT_MARGIN_PCT') !== '' ? getenv('PROFIT_MARGIN_PCT') : PROFIT_MARGIN_PCT_DEFAULT)));
define('MIN_CHARGE_KG', max(0.0, (float) (getenv('MIN_CHARGE_KG') !== false && getenv('MIN_CHARGE_KG') !== '' ? getenv('MIN_CHARGE_KG') : MIN_CHARGE_KG_DEFAULT)));
define('SLA_ROUND_MINUTES', max(1, (int) (getenv('SLA_ROUND_MINUTES') ?: (getenv('SLA_BASE_MINUTES') ?: SLA_ROUND_MINUTES_DEFAULT))));

/** Absolute URL inside the app: app_url('view_order.php'). */
// Link for inside the app — use this for redirects and fetch().
function app_url(string $path = ''): string {
    return BASE_URL . ltrim($path, '/');
}

/** Redirect inside the app and stop (tapos exit). */
function app_redirect(string $path): void {
    header('Location: ' . app_url($path));
    exit();
}
