<?php
// components/order_details_modal.php - read-only order summary (opened by the eye button, data-ot="view").
// Filled by orders-ui.js from the row's data-order JSON; the payment record (if any) is fetched from get_payment_history.php.
// Read-only order summary (opened by the eye button). Filled by orders-ui.js from the
// row's data-order JSON; ang payment record is fetched from get_payment_history.php.
// Per-kg orders show "weight - rate/kg" (and the billed weight when the minimum charge applied); orders taken before
// per-kg pricing keep the older "n loads (category)" wording.
?>
<div class="modal fade" id="orderDetailsModal" tabindex="-1" aria-labelledby="odTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable wt-modal-sm">
    <div class="modal-content wt-modal" id="orderDetailsBody" x-data="orderDetails()">
      <div class="modal-header border-0 pb-2">
        <div class="d-flex align-items-center gap-3 w-100 pe-3">
          <div class="avatar-box"><i class="bi bi-receipt fs-5"></i></div>
          <div class="min-w-0">
            <h5 class="modal-title fw-bold mb-0 text-break" id="odTitle" x-text="o.customer || 'Order'"></h5>
            <div class="text-muted small"><span class="font-monospace" x-text="o.number"></span> &middot; <span x-text="o.created"></span></div>
          </div>
        </div>
        <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body pt-1">
        <div class="d-flex align-items-center flex-wrap gap-2 mb-3">
          <span class="ot-badge is-compact" :class="o.statusClass" x-text="o.status"></span>
        </div>

        <dl class="wt-kv mb-3">
          <dt>Contact</dt><dd x-text="o.phone || 'N/A'"></dd>
          <dt>Package</dt><dd x-text="o.service"></dd>
          <template x-if="o.perKg">
            <dt>Weight</dt>
          </template>
          <template x-if="o.perKg">
            <dd><span x-text="Number(o.weight).toFixed(2)"></span> kg &middot; <span x-text="money(o.rate)"></span>/kg<span x-show="o.billed > o.weight + 0.004" x-cloak> &middot; billed <span x-text="Number(o.billed).toFixed(2)"></span> kg (minimum)</span> &middot; <span x-text="o.loads"></span> <span x-text="o.loads == 1 ? 'load' : 'loads'"></span></dd>
          </template>
          <template x-if="!o.perKg">
            <dt>Weight</dt>
          </template>
          <template x-if="!o.perKg">
            <dd><span x-text="Number(o.weight).toFixed(1)"></span> kg &middot; <span x-text="o.loads"></span> <span x-text="o.loads == 1 ? 'load' : 'loads'"></span> (<span x-text="o.category"></span>)</dd>
          </template>
          <dt x-show="o.garments" x-cloak>Garments</dt><dd x-show="o.garments" x-cloak class="small" x-text="o.garments"></dd>
          <dt x-show="o.supplies" x-cloak>Supplies</dt><dd x-show="o.supplies" x-cloak class="small"><span x-text="o.supplies"></span> <span class="text-muted" x-text="o.suppliesUsed ? '(used)' : '(taken at start)'"></span></dd>
          <dt x-show="o.machines" x-cloak>Machines</dt><dd x-show="o.machines" x-cloak x-text="o.machines"></dd>
          <dt x-show="o.mixedNote" x-cloak>Breakdown</dt><dd x-show="o.mixedNote" x-cloak class="small" x-text="o.mixedNote"></dd>
        </dl>

        <div class="wt-box d-flex justify-content-between align-items-center mb-3">
          <div>
            <div class="small text-muted fw-semibold">Amount due</div>
            <div class="wt-total fs-4" x-text="money(o.total)"></div>
          </div>
          <span class="badge rounded-pill px-3 py-2" :class="o.paid ? 'text-bg-success' : 'text-bg-secondary'" x-text="o.paid ? 'Paid' : 'Unpaid'"></span>
        </div>

        <div x-show="o.paid" x-cloak>
          <div class="small fw-bold text-uppercase text-muted mb-1" style="letter-spacing:.08em">Payment</div>
          <div class="table-responsive border rounded-3">
            <table class="table table-sm align-middle mb-0 small">
              <thead class="table-light"><tr><th>Date &amp; time</th><th>Method</th><th class="text-end">Amount</th></tr></thead>
              <tbody id="paymentHistoryTableBody"><tr><td colspan="3" class="text-center text-muted py-2">Loading...</td></tr></tbody>
            </table>
          </div>
        </div>
        <p class="small text-muted mb-0" x-show="!o.paid" x-cloak>Payment is collected in full when the order is released.</p>
      </div>
    </div>
  </div>
</div>