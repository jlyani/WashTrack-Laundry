<?php
// components/create_order_modal.php - "New Laundry Order" intake (Bootstrap modal, Alpine for the live price).
// Modal for a new order: it collects the customer name/phone, the service, the
// garment types in the bag, and ONE weight for the whole bag. The total appears live while typing (Alpine).
// Intake only: staff record the customer, service, garment types sa bag, and ONE scale weight (the whole bag).
// Garment types (checkboxes) only decide the number of loads (Heavy = 6 kg per load, Regular = 7 kg); supplies are
// worked out and taken by the server when the order starts, so they are deliberately not on this form. The total shown is the final
// amount - rate per kg x billed kg, with the minimum charge (MIN_CHARGE_KG) for light bags - and this is the amount charged; orders_process.php works it out again on the server with the same rule (price_order()).
// Expects $services (service_id, service_name, price_per_load = the service's rate PER KG).
// Garment types come from garment_types (includes/orders.php); kapag walang garment tables, the garment step is hidden.
// Alpine component: assets/js/app.js -> Alpine.data('orderForm').
require_once __DIR__ . '/../includes/machines.php';
// Free washer/dryer count right now - shown in the form and used for the "pwede agad mag-start?" logic.
$free_machines = isset($conn) ? load_free_machines($conn) : ['Washer' => [], 'Dryer' => []];
$free_washers  = count($free_machines['Washer']);
$free_dryers   = count($free_machines['Dryer']);

// Count of Pending orders in the queue. When it is 0, a new order can start right away.
// Orders already waiting in line (Pending): a new order joins behind them, so it can only start right away when this is 0.
$ahead_pending = 0;
if (isset($conn)) {
    try {
        $r = $conn->query("SELECT COUNT(*) AS n FROM orders WHERE order_status = 'Pending'");
        $ahead_pending = $r ? (int) $r->fetch_assoc()['n'] : 0;
    } catch (Throwable $e) {
        // If unavailable, treat it as 0 (do not block the intake).
        error_log('[create_order_modal] waiting-line count unavailable: ' . $e->getMessage());
    }
}

// Garment types for the checkboxes, and their category for the live load count.
// Garment types for the checkboxes; the category (Regular/Heavy) sets the load size.
require_once __DIR__ . '/../includes/orders.php';
$garment_types = isset($conn) ? load_garment_types($conn) : [];
$garment_map   = [];
foreach ($garment_types as $g) $garment_map[(int) $g['garment_id']] = $g['category'];

// Rates per kg for the live total (service_id => rate). The saved total is recalculated by orders_process.php.
// Rate per kg for the live total in the form (service_id => rate). The real total is recalculated by the server.
$pkg_prices = [];
foreach ($services as $srv) {
    if (!empty($srv['price_per_load'])) {
        $pkg_prices[(int) $srv['service_id']] = (float) $srv['price_per_load'];
    }
}
?>
<style>
/* New Order: the selected service is marked by a border, not a solid fill; garment types are tick chips. Scoped to .wt-intake. */
.wt-intake .btn-check:checked + .wt-pkg {
  background: #fff; transform: none;
  border: 2px solid var(--it-brand); padding: 10px 12px;
  box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
}
.wt-intake .btn-check:checked + .wt-pkg .wt-pkg-name { color: var(--it-ink) !important; }
.wt-intake .btn-check:checked + .wt-pkg .wt-pkg-price { color: var(--it-brand-2) !important; }
.wt-intake .btn-check:checked + .wt-pkg .wt-pkg-price small { color: var(--it-mute) !important; }
.wt-intake .wt-gt-group { font-size: 0.72rem; font-weight: 700; color: var(--it-ink-2); margin: 2px 0 6px; }
.wt-intake .wt-gt-group small { font-weight: 500; color: var(--it-mute); }
.wt-intake .wt-gt-row { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.wt-intake .wt-gt {
  display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 10px; cursor: pointer; user-select: none;
  border: 1px solid var(--it-line); background: #fff; color: var(--it-ink-2); font-size: 0.8rem; font-weight: 600;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05); transition: border-color .15s ease, background .15s ease, color .15s ease;
}
.wt-intake .wt-gt:hover { border-color: #cfdcef; }
.wt-intake .wt-gt::before { content: "\F584"; font-family: "bootstrap-icons"; font-size: 0.95rem; line-height: 1; color: #94a3b8; }   /* empty square */
.wt-intake .btn-check:checked + .wt-gt { background: #e8f0fe; border-color: #93b4f5; color: var(--it-brand-2); }
.wt-intake .btn-check:checked + .wt-gt::before { content: "\F26C"; color: var(--it-brand-2); }                                          /* ticked square */
.wt-intake .btn-check:checked + .wt-gt.is-heavy { background: #fdf1d6; border-color: #f0c56a; color: #9a6207; }
.wt-intake .btn-check:checked + .wt-gt.is-heavy::before { color: #9a6207; }
.wt-intake .btn-check:focus-visible + .wt-gt { outline: 3px solid #3b82f6; outline-offset: 2px; }
</style>
<div class="modal fade" id="addOrderModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="addOrderTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable wt-modal-md">
    <div class="modal-content wt-modal wt-intake"
         x-data="orderForm({ washersFree: <?php echo (int) $free_washers; ?>, dryersFree: <?php echo (int) $free_dryers; ?>, perOrder: <?php echo (int) MACHINES_PER_ORDER; ?>, ahead: <?php echo (int) $ahead_pending; ?>, minKg: <?php echo json_encode((float) MIN_CHARGE_KG); ?>, garments: <?php echo htmlspecialchars(json_encode($garment_map, JSON_FORCE_OBJECT), ENT_QUOTES); ?>, prices: <?php echo htmlspecialchars(json_encode($pkg_prices, JSON_FORCE_OBJECT), ENT_QUOTES); ?> })">
      <!-- This x-data passes the settings (free machines, perOrder, minKg, garments, prices) to app.js. -->
      <form action="orders_process.php" method="POST" id="addOrderForm" novalidate
            :class="{ 'was-validated': tried }" @submit.prevent="submit($event)">
        <input type="hidden" name="create_order" value="1">

        <div class="modal-header">
          <div class="d-flex align-items-center gap-3 w-100 pe-3">
            <div class="avatar-box"><i class="bi bi-basket3 fs-5"></i></div>
            <div>
              <h5 class="modal-title fw-bold mb-0" id="addOrderTitle">New Laundry Order</h5>
              <div class="text-muted small">Store pickup &middot; payment is collected at release</div>
            </div>
          </div>
          <button type="button" class="btn-close shadow-none" aria-label="Close" @click="requestClose()"></button>
        </div>

        <div class="modal-body">
          <!-- 1. Customer details -->
          <div class="wt-step"><span class="wt-step-num">1</span> Customer</div>
          <div class="row g-2 mb-4">
            <div class="col-sm-7">
              <label class="form-label small fw-semibold text-muted mb-1" for="co_name">Full name <span class="text-danger">*</span></label>
              <input type="text" id="co_name" name="customer_name" class="form-control" placeholder="Enter full name" maxlength="100" autocomplete="off" required x-model="name">
              <div class="invalid-feedback">Please enter the customer's full name.</div>
            </div>
            <div class="col-sm-5">
              <label class="form-label small fw-semibold text-muted mb-1" for="co_phone">Contact number <span class="text-danger">*</span></label>
              <input type="tel" id="co_phone" name="contact_number" class="form-control" placeholder="09xxxxxxxxx" maxlength="11" pattern="^09\d{9}$" inputmode="tel" autocomplete="off" required x-model="phone">
              <div class="invalid-feedback">Enter 11 digits starting with 09.</div>
            </div>
          </div>

          <!-- 2. Service (pick one) -->
          <div class="wt-step"><span class="wt-step-num">2</span> Service</div>
          <?php if (!$services): ?>
            <p class="small text-muted">No active services. Ask an administrator to add one under Services.</p>
          <?php endif; ?>
          <div class="row g-2 mb-4" role="radiogroup" aria-label="Service">
            <?php foreach ($services as $srv):
                $sid   = (int) $srv['service_id'];
                $price = (float) $srv['price_per_load'];
            ?>
              <div class="col-12 col-sm-4">
                <input type="radio" class="btn-check" name="service_id" id="co_pkg_<?php echo $sid; ?>" value="<?php echo $sid; ?>" autocomplete="off" required x-model="pkg">
                <label class="wt-pkg" for="co_pkg_<?php echo $sid; ?>" title="<?php echo htmlspecialchars($srv['service_name']); ?>">
                  <span class="wt-pkg-top">
                    <span class="wt-pkg-name"><?php echo htmlspecialchars($srv['service_name']); ?></span>
                    <span class="wt-pkg-price">&#8369;<?php echo number_format($price, fmod($price, 1) == 0.0 ? 0 : 2); ?><small class="fw-normal text-muted">/kg</small></span>
                  </span>
                </label>
              </div>
            <?php endforeach; ?>
          </div>
          <p class="small text-danger mb-3" x-show="tried && !pkg" x-cloak>Please select a service.</p>

          <?php if ($garment_types): ?>
          <!-- 3. Garment types: they only decide the loads, never the price -->
          <div class="wt-step"><span class="wt-step-num">3</span> Garment types</div>
          <?php foreach (['Regular', 'Heavy'] as $cat): ?>
            <?php $in_cat = array_filter($garment_types, fn($g) => $g['category'] === $cat); if (!$in_cat) continue; ?>
            <div class="wt-gt-group"><?php echo $cat; ?></div>
            <div class="wt-gt-row">
              <?php foreach ($in_cat as $g): $gid = (int) $g['garment_id']; ?>
                <input type="checkbox" class="btn-check" name="garments[]" id="co_g_<?php echo $gid; ?>" value="<?php echo $gid; ?>" autocomplete="off" x-model="picked">
                <label class="wt-gt <?php echo $cat === 'Heavy' ? 'is-heavy' : ''; ?>" for="co_g_<?php echo $gid; ?>"><?php echo htmlspecialchars($g['name']); ?></label>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
          <p class="small text-danger mb-2" x-show="tried && !picked.length" x-cloak>Tick at least one garment type.</p>
          <div class="mb-4"></div>
          <?php endif; ?>

          <!-- Scale weight: the whole bag, weighed once -->
          <div class="wt-step"><span class="wt-step-num" x-text="needGarments ? 4 : 3">4</span> Scale weight
            <span class="wt-step-note"><?php echo (int) $free_washers; ?> washers &middot; <?php echo (int) $free_dryers; ?> dryers free</span>
          </div>
          <div class="wt-scale">
            <label class="fw-bold small" for="co_weight">Whole bag</label>
            <div class="text-muted wt-scale-hint">Weigh everything once<?php if (MIN_CHARGE_KG > 0): ?> &middot; minimum charge <?php echo rtrim(rtrim(number_format(MIN_CHARGE_KG, 2, '.', ''), '0'), '.'); ?> kg<?php endif; ?></div>
            <div class="d-flex align-items-baseline gap-1">
              <input type="text" id="co_weight" name="weight_kg" class="wt-scale-input" placeholder="0.0" inputmode="decimal" autocomplete="off" required x-model="weightRaw" @input="weightRaw = clean(weightRaw)">
              <span class="fw-bold text-muted">kg</span>
            </div>
          </div>
          <p class="small text-danger mt-2 mb-0" x-show="tried && !(weight > 0)" x-cloak>Enter the weight shown on the scale.</p>

          <!-- Total = rate per kg x billed kg (this is final) -->
          <div class="wt-loads mt-3" :class="{ 'is-empty': total === null }" aria-live="polite">
            <div class="wt-loads-figure">
              <span class="wt-loads-num" x-text="total === null ? '—' : money(total)"></span>
              <span class="fw-bold text-secondary">Total</span>
            </div>
            <div class="wt-loads-detail">
              <div class="fw-bold" x-text="priceLine"></div>
              <div class="small text-muted" x-show="loadsLine" x-text="loadsLine" x-cloak></div>
              <span class="badge rounded-pill border mt-1" :class="machineTone" x-text="machineText"></span>
            </div>
          </div>
        </div>

        <div class="modal-footer gap-2">
          <button type="button" class="btn btn-light border flex-fill" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn btn-primary flex-fill fw-semibold" :disabled="busy"><i class="bi bi-check-circle me-1"></i> Create Order</button>
        </div>
      </form>
    </div>
  </div>
</div>
