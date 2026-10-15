<?php
// print_receipt.php?id=ORDER_ID[&autoprint=1][&embed=1]   (ito yung usage)
//   embed=1: no toolbar / desk background, para sa preview inside the 'order created' popup and para sa silent printing from the pages.
//   autoprint=1: automatically opens the print dialog.
// 80 mm thermal print (this format):
//  - Released / paid -> OFFICIAL RECEIPT (classic monospaced layout, may OR number, tender, change + Code 39 barcode).
//                       Only produced once may payment; printed automatically at collect & release.
//  - Open order      -> CLAIM STUB: small slip na may order number, name, phone, service/weight at amount due.
//                       No QR / tracking page: the customer is also texted, and the stub is the backup kung lost or dead ang phone.
// Pricing on the paper: rate per kg x billed kg (the real weight, o ang minimum charge para sa light bag). Orders taken
// before per-kg pricing (total = price x loads) keep their old "N loads x price" line pa rin.
// Any logged-in user can print; the page only reads data.
// This generates the 80mm thermal print. If paid -> OFFICIAL RECEIPT (may OR number at barcode).
// If not paid yet -> CLAIM STUB (small slip, no QR, backup lang kasi the customer is texted).
// Read-only ito - any logged-in user can print.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?error=unauthorized");
    exit();
}
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/orders.php';   // order_is_per_kg() / order_billed_kg() (mga helper)

// ---------- Shop details printed sa header (edit these) ----------
// Shop details printed at the top of the receipt. Edit these dito.
const RCPT_SHOP_NAME    = 'WASHTRACK LAUNDRY';
const RCPT_SHOP_TAGLINE = 'Wash - Dry - Fold';
const RCPT_SHOP_ADDRESS = '';          // halimbawa: 'Brgy. Poblacion, Cabuyao, Laguna'
const RCPT_SHOP_PHONE   = '';          // halimbawa: '0917 000 0000'
const RCPT_W            = 42;          // characters per line sa 80 mm roll

// ---------- Text helpers (all output is monospaced, kaya alignment is done via padding) ----------
// Helpers to align text on the monospaced print (spaces lang, not CSS).
function r_len($s)  { return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s); }
function r_cut($s, $n) { return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n); }
function r_center($s) { $s = r_cut($s, RCPT_W); $pad = intdiv(RCPT_W - r_len($s), 2); return str_repeat(' ', $pad) . $s; }
function r_pair($l, $r) {                         // "Label ........ value" (this row)
    $r = r_cut($r, RCPT_W - 2);
    $l = r_cut($l, RCPT_W - r_len($r) - 1);
    return $l . str_repeat(' ', max(1, RCPT_W - r_len($l) - r_len($r))) . $r;
}
function r_wrap($s, $indent = '') {               // wrap long text sa paper width
    $out = [];
    foreach (explode("\n", wordwrap($s, RCPT_W - r_len($indent), "\n", true)) as $line) $out[] = $indent . $line;
    return $out;
}
function r_money($n) { return 'P' . number_format((float) $n, 2); }
function r_kg($n) { $s = rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.'); return ($s === '' ? '0' : $s) . ' kg'; }
function r_rule($c = '-') { return str_repeat($c, RCPT_W); }

// ---------- Code 39 barcode as inline SVG (wala nang library needed) ----------
// Generates a Code 39 barcode as SVG - wala nang library needed.
function code39_svg($text, $height = 54) {
    static $map = [
        '0'=>'nnnwwnwnn','1'=>'wnnwnnnnw','2'=>'nnwwnnnnw','3'=>'wnwwnnnnn','4'=>'nnnwwnnnw','5'=>'wnnwwnnnn','6'=>'nnwwwnnnn',
        '7'=>'nnnwnnwnw','8'=>'wnnwnnwnn','9'=>'nnwwnnwnn','A'=>'wnnnnwnnw','B'=>'nnwnnwnnw','C'=>'wnwnnwnnn','D'=>'nnnnwwnnw',
        'E'=>'wnnnwwnnn','F'=>'nnwnwwnnn','G'=>'nnnnnwwnw','H'=>'wnnnnwwnn','I'=>'nnwnnwwnn','J'=>'nnnnwwwnn','K'=>'wnnnnnnww',
        'L'=>'nnwnnnnww','M'=>'wnwnnnnwn','N'=>'nnnnwnnww','O'=>'wnnnwnnwn','P'=>'nnwnwnnwn','Q'=>'nnnnnnwww','R'=>'wnnnnnwwn',
        'S'=>'nnwnnnwwn','T'=>'nnnnwnwwn','U'=>'wwnnnnnnw','V'=>'nwwnnnnnw','W'=>'wwwnnnnnn','X'=>'nwnnwnnnw','Y'=>'wwnnwnnnn',
        'Z'=>'nwwnwnnnn','-'=>'nwnnnnwnw','.'=>'wwnnnnwnn',' '=>'nwwnnnwnn','*'=>'nwnnwnwnn',
    ];
    $text = '*' . strtoupper(preg_replace('/[^A-Za-z0-9\-. ]/', '', $text)) . '*';
    $n = 2; $w = 5; $x = 0; $bars = '';
    foreach (str_split($text) as $ci => $ch) {
        if (!isset($map[$ch])) continue;
        foreach (str_split($map[$ch]) as $i => $e) {
            $width = ($e === 'w') ? $w : $n;
            if ($i % 2 === 0) $bars .= '<rect x="' . $x . '" y="0" width="' . $width . '" height="' . $height . '"/>';   // bars sit sa even positions
            $x += $width;
        }
        $x += $n;   // small gap between characters
    }
    return '<svg class="barcode" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $x . ' ' . $height . '" preserveAspectRatio="none" role="img" aria-label="Barcode ' . htmlspecialchars(trim($text, '*')) . '" fill="#000">' . $bars . '</svg>';
}

// ---------- Load the order (kunin ang data) ----------
// Get the order, customer, service, payment at cashier na needed for the receipt.
$order_id = (int) ($_GET['id'] ?? 0);
$o = null; $consumables = [];
try {
    $stmt = $conn->prepare(
        "SELECT o.order_id, o.order_number, o.weight_kg, o.load_type, o.loads, o.price_per_load, o.total_amount, o.order_status, o.payment_status,
                o.notes, o.created_at, o.released_at,
                c.full_name AS customer_name, c.phone AS customer_phone,
                s.name AS service_name, s.service_id,
                cu.full_name AS intake_name,
                p.receipt_no, p.method, p.amount_due, p.amount_tendered, p.change_given, p.reference_no, p.paid_at, pu.full_name AS cashier_name
         FROM orders o
         JOIN customers c ON c.customer_id = o.customer_id
         JOIN services s  ON s.service_id = o.service_id
         JOIN users cu    ON cu.user_id = o.created_by
         LEFT JOIN payments p ON p.order_id = o.order_id
         LEFT JOIN users pu   ON pu.user_id = p.received_by
         WHERE o.order_id = ?"
    );
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $o = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($o) {
        $stmt = $conn->prepare("SELECT i.name, sc.qty FROM service_consumables sc JOIN inventory_items i ON i.item_id = sc.item_id WHERE sc.service_id = ? ORDER BY i.item_id");
        $stmt->bind_param("i", $o['service_id']);
        $stmt->execute();
        $consumables = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} catch (Throwable $e) {
    error_log('[print_receipt] ' . $e->getMessage());
}

// A receipt only exists kapag may payment na; everything before that prints as the claim stub.
// A receipt only when paid (may receipt_no). If not, the claim stub is printed.
$paid  = !empty($o) && $o['payment_status'] === 'Paid' && !empty($o['receipt_no']);
$short = !empty($o) ? order_short_code($o['order_number']) : '';

if (!$o || $o['order_status'] === 'Cancelled') {
    http_response_code($o ? 409 : 404);
    $problem = $o ? 'This order was cancelled, so there is nothing to print.' : 'Order not found.';
}

// Pricing figures para sa paper (per-kg orders only): real weight, billed kg, and whether the minimum charge applied.
// Per-kg pricing: real weight, billed kg, at kung the minimum charge applied.
$per_kg = !empty($o) && order_is_per_kg($o);
$billed = !empty($o) ? order_billed_kg($o) : 0.0;
$min_on = $per_kg && $billed > (float) $o['weight_kg'] + 0.004;

// ---------- Build the printout (paper content) ----------
// Here we build the paper content. $lines = receipt lines (paid), $stub = claim stub (unpaid).
$lines = [];     // receipt lines kapag paid: [text, css class]
$stub  = null;   // claim-stub values (open order, unpaid)

// Open order: a small claim stub, walang QR.
// If not paid yet -> small claim stub, walang QR.
if (empty($problem) && !$paid) {
    $stub = [
        'short'    => $short,
        'full'     => $o['order_number'],
        'customer' => $o['customer_name'],
        'phone'    => (string) ($o['customer_phone'] ?? ''),
        'service'  => $o['service_name'],
        'weight'   => number_format((float) $o['weight_kg'], 2),
        'rate'     => $per_kg ? r_money($o['price_per_load']) . '/kg' : '',
        'minnote'  => $min_on ? 'Min. charge: billed ' . r_kg($billed) : '',
        'total'    => r_money($o['total_amount']),
        'date'     => date('M j, Y g:i A', strtotime($o['created_at'])),
    ];
}

// Paid order naman: the classic monospaced OFFICIAL RECEIPT.
// Paid -> the classic OFFICIAL RECEIPT (monospaced, may OR number).
if (empty($problem) && $paid) {
    $loads    = (int) ceil((float) $o['loads']);
    $when     = $o['paid_at'];
    $cashier  = ($o['cashier_name'] ?: '-');

    $add = function ($t = '', $c = '') use (&$lines) { $lines[] = [$t, $c]; };

    $add(r_center(RCPT_SHOP_NAME), 'big');
    if (RCPT_SHOP_TAGLINE !== '') $add(r_center(RCPT_SHOP_TAGLINE));
    foreach (r_wrap(RCPT_SHOP_ADDRESS) as $l) if (trim($l) !== '') $add(r_center(trim($l)));
    if (RCPT_SHOP_PHONE !== '') $add(r_center('Tel ' . RCPT_SHOP_PHONE));
    $add(r_rule('='));
    $add(r_center($paid ? 'OFFICIAL RECEIPT' : 'CLAIM TICKET'), 'bold');
    if ($paid) $add(r_center($o['receipt_no']), 'bold');
    $add(r_rule('='));

    $add(r_pair('Order', $short . '  ' . $o['order_number']), 'bold');
    $add(r_pair($paid ? 'Paid' : 'Received', date('M j, Y g:i A', strtotime($when))));
    $add(r_pair($paid ? 'Cashier' : 'Taken by', $cashier));
    $add(r_pair('Customer', $o['customer_name']));
    if (!empty($o['customer_phone'])) $add(r_pair('Contact', $o['customer_phone']));
    $add(r_rule());

    $add($o['service_name'], 'bold');
    $add(r_pair('Weight', number_format((float) $o['weight_kg'], 2) . ' kg'));
    if ($per_kg) {
        // Per-kg order: rate x billed weight (minimum charge shown kapag applied).
        if ($min_on) $add(r_pair('Minimum charge', r_kg($billed) . ' billed'));
        $add(r_pair(r_kg($billed) . ' x ' . r_money($o['price_per_load']) . '/kg', r_money($o['total_amount'])));
    } else {
        // Order taken before per-kg pricing: load composition at "loads x price".
        // Mixed breakdown nasa order note ("Mixed: Light 8.00 kg = 2 loads ...")
        $note = preg_replace('/(^| \| )Machines reserved .*$/', '', (string) $o['notes']);
        if (stripos($note, 'Mixed:') === 0) {
            foreach (explode(';', substr($note, 6)) as $part) {
                foreach (r_wrap(trim(preg_replace('/\s*\(\d+ kg\/load\)/', '', $part)), '  ') as $l) $add($l);
            }
        } else {
            $add(r_pair('Category', $o['load_type']));
        }
        $add(r_pair($loads . ' load' . ($loads === 1 ? '' : 's') . ' x ' . r_money($o['price_per_load']), r_money($o['total_amount'])));
    }

    if ($consumables) {
        $add(r_rule());
        $add('Included supplies (' . $loads . ' load' . ($loads === 1 ? '' : 's') . ')');
        foreach ($consumables as $c) {
            $q = (float) $c['qty'] * $loads;
            $add(r_pair('  ' . $c['name'], 'x' . ($q == (int) $q ? (int) $q : $q)));
        }
    }

    $add(r_rule('='));
    $add(r_pair($paid ? 'TOTAL' : 'AMOUNT DUE AT PICKUP', r_money($o['total_amount'])), 'big');
    if ($paid) {
        $add(r_pair('Payment', $o['method']));
        if ($o['method'] === 'Cash') {
            $add(r_pair('Tendered', r_money($o['amount_tendered'])));
            $add(r_pair('Change', r_money($o['change_given'])));
        } elseif (!empty($o['reference_no'])) {
            $add(r_pair('Ref no.', $o['reference_no']));
        }
    } else {
        $add(r_pair('Payment', 'Cash / GCash at pickup'));
    }
    $add(r_rule('='));

    $add('CLAIM POLICY', 'bold');
    foreach ([
        'Present this ticket or scan the barcode to claim your laundry.',
        'Payment is collected at pickup.',
        'Please check your items before leaving the counter.',
        'Unclaimed laundry is kept for 30 days only.',
    ] as $p) foreach (r_wrap($p, '') as $i => $l) $add(($i === 0 ? '- ' : '  ') . $l);

    $add(r_rule());
    $add(r_center('Thank you for choosing us!'), 'bold');
    if (!empty($_SESSION['full_name'])) $add(r_center('Printed ' . date('M j, Y g:i A') . ' by ' . r_cut($_SESSION['full_name'], 14)));
}
$autoprint = !empty($_GET['autoprint']) && empty($problem);
$embed     = !empty($_GET['embed']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo empty($problem) ? htmlspecialchars(($paid ? 'Receipt ' : 'Claim stub ') . $short) : 'Receipt'; ?></title>
    <style>
        /* ---------- Screen preview: a paper strip sa grey desk ---------- */
        * { box-sizing: border-box; }
        body { margin: 0; background: #e5e7eb; font-family: system-ui, sans-serif; }
        .toolbar { position: sticky; top: 0; display: flex; gap: 10px; align-items: center; justify-content: center; padding: 12px; background: #fff; border-bottom: 1px solid #d1d5db; }
        .toolbar a, .toolbar button { font: 600 14px system-ui, sans-serif; padding: 9px 18px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; color: #334155; text-decoration: none; cursor: pointer; }
        .toolbar button { background: #2563eb; border-color: #2563eb; color: #fff; }
        .paper { width: 80mm; margin: 18px auto; padding: 4mm 3mm 6mm; background: #fff; box-shadow: 0 4px 18px rgba(0,0,0,.18); color: #000; }
        .rcpt { margin: 0; font-family: 'Courier New', Courier, monospace; font-size: 12px; line-height: 1.3; white-space: pre; }
        .rcpt .bold { font-weight: 700; }
        .rcpt .big  { font-weight: 800; line-height: 1.4; }   /* same size as the rest: a 42-character line must fit sa 80 mm paper */
        .barcode-wrap { margin: 8px 2mm 2px; text-align: center; }
        .barcode { display: block; width: 100%; height: 14mm; }
        .barcode-text { margin-top: 2px; font-family: 'Courier New', Courier, monospace; font-size: 12px; letter-spacing: .08em; }
        body.embed { background: #fff; }
        body.embed .toolbar { display: none; }
        body.embed .paper { margin: 0 auto; box-shadow: none; }
        .problem { max-width: 420px; margin: 60px auto; padding: 24px; background: #fff; border-radius: 12px; text-align: center; color: #334155; }

        /* ---------- Claim stub (open orders): small slip, essentials lang ---------- */
        .claim { text-align: center; font-family: 'Courier New', Courier, monospace; font-size: 12px; line-height: 1.4; }
        .claim .cl-shop { font-weight: 800; font-size: 13px; letter-spacing: .05em; }
        .claim .cl-tag { font-size: 10px; color: #444; }
        .claim .cl-rule { border-top: 2px dashed #999; margin: 7px 0; }
        .claim .cl-kind { font-weight: 800; font-size: 11px; letter-spacing: .2em; }
        .claim .cl-label { font-size: 9px; letter-spacing: .16em; color: #555; }
        .claim .cl-num { font-weight: 800; font-size: 34px; letter-spacing: .04em; line-height: 1.1; }
        .claim .cl-full { font-size: 10px; color: #333; }
        .claim .cl-name { font-weight: 700; font-size: 13px; margin-top: 6px; word-break: break-word; }
        .claim .cl-sub { font-size: 10.5px; color: #333; word-break: break-word; }
        .claim .cl-due { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
        .claim .cl-amount { font-weight: 800; font-size: 16px; }
        .claim .cl-foot { font-size: 10px; color: #333; }

        /* ---------- Printing: monochrome, 80 mm wide (receipt lang) ---------- */
        /* These are the print rules - 80mm wide, black and white lang, receipt lang. */
        @page { size: 80mm auto; margin: 0; }
        @media print {
            html, body { width: 80mm; background: #fff !important; margin: 0; }
            .no-print, .toolbar { display: none !important; }
            .paper { width: 80mm; margin: 0; padding: 3mm 3mm 8mm; box-shadow: none; }
            * { color: #000 !important; background: transparent !important; text-shadow: none !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .barcode rect { fill: #000 !important; }
        }
    </style>
</head>
<body class="<?php echo $embed ? 'embed' : ''; ?>">
<?php if (!empty($problem)): ?>
    <div class="problem no-print"><p><?php echo htmlspecialchars($problem); ?></p><a href="transaction.php">Back to Transactions</a></div>
<?php else: ?>
    <div class="toolbar no-print">
        <button type="button" onclick="window.print()"><?php echo $paid ? 'Print receipt' : 'Print claim stub'; ?></button>
        <a href="javascript:history.length > 1 ? history.back() : window.close()">Close</a>
    </div>
    <div class="paper">
<?php if (!empty($stub)): ?>
        <div class="claim">
            <div class="cl-shop"><?php echo htmlspecialchars(RCPT_SHOP_NAME); ?></div>
            <?php if (RCPT_SHOP_TAGLINE !== ''): ?><div class="cl-tag"><?php echo htmlspecialchars(RCPT_SHOP_TAGLINE); ?></div><?php endif; ?>
            <div class="cl-rule"></div>
            <div class="cl-kind">CLAIM STUB</div>
            <div class="cl-num"><?php echo htmlspecialchars($stub['short']); ?></div>
            <div class="cl-full"><?php echo htmlspecialchars($stub['full']); ?></div>
            <div class="cl-name"><?php echo htmlspecialchars($stub['customer']); ?></div>
            <?php if ($stub['phone'] !== ''): ?><div class="cl-sub"><?php echo htmlspecialchars($stub['phone']); ?></div><?php endif; ?>
            <div class="cl-sub"><?php echo htmlspecialchars($stub['service']); ?> &middot; <?php echo $stub['weight']; ?> kg<?php if ($stub['rate'] !== ''): ?> &middot; <?php echo htmlspecialchars($stub['rate']); ?><?php endif; ?></div>
            <?php if ($stub['minnote'] !== ''): ?><div class="cl-sub"><?php echo htmlspecialchars($stub['minnote']); ?></div><?php endif; ?>
            <div class="cl-sub"><?php echo htmlspecialchars($stub['date']); ?></div>
            <div class="cl-rule"></div>
            <div class="cl-due"><span class="cl-label">DUE AT PICKUP</span><span class="cl-amount"><?php echo $stub['total']; ?></span></div>
            <div class="cl-rule"></div>
            <div class="cl-foot">Keep this stub &middot; Pay at pickup</div>
        </div>
<?php else: ?>
        <pre class="rcpt"><?php
foreach ($lines as [$text, $cls]) {
    echo $cls !== '' ? '<span class="' . $cls . '">' . htmlspecialchars($text) . '</span>' : htmlspecialchars($text);
    echo "\n";
}
?></pre>
        <?php if (!empty($paid)): ?>
        <div class="barcode-wrap">
            <?php echo code39_svg($o['order_number']); ?>
            <div class="barcode-text"><?php echo htmlspecialchars($o['order_number']); ?></div>
        </div>
        <?php endif; ?>
<?php endif; ?>
    </div>
    <?php if ($autoprint): ?>
        <!-- If autoprint=1, it opens the print dialog automatically on load. -->
        <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });</script>
    <?php endif; ?>
<?php endif; ?>
</body>
</html>