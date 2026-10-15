<?php
// components/release_modal.php - "Collect payment & release". Payment is taken in full when an order that is Ready for Pickup
// is handed over; orders_process.php (action finalize) records it in the payments ledger and marks the order Paid + Released
// in one transaction. Opened with openRelease(order) sa assets/js/orders-ui.js. Stays a Bootstrap modal (two fields + a toggle).
// Modal for payment and release. Full payment is taken when the order is Ready for Pickup —
// recorded in payments and marked Paid + Released in one transaction.
?>
<div class="modal fade" id="releaseModal" data-bs-backdrop="static" tabindex="-1" aria-labelledby="rmTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered wt-modal-sm">
    <div class="modal-content wt-modal" id="releaseBody" x-data="releaseForm()">
      <form id="releaseForm" @submit.prevent="submit($event)">
        <input type="hidden" name="action_type" value="finalize">
        <input type="hidden" name="order_id" :value="o.id">
        <div class="modal-header border-0 pb-2">
          <div class="d-flex align-items-center gap-3 w-100 pe-3">
            <div class="avatar-box"><i class="bi bi-wallet2 fs-5"></i></div>
            <div class="min-w-0">
              <h5 class="modal-title fw-bold mb-0" id="rmTitle">Collect payment</h5>
              <div class="text-muted small text-truncate"><span x-text="o.short"></span> &middot; <span x-text="o.customer"></span></div>
            </div>
          </div>
          <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body pt-1">
          <div class="wt-box mb-3">
            <dl class="wt-kv mb-0">
              <dt>Customer</dt><dd class="text-break" x-text="o.customer"></dd>
              <dt>Phone</dt><dd class="wt-mono" x-text="o.phone || 'No phone on file'"></dd>
            </dl>
            <div class="small text-muted mt-2">Ask the person collecting for their name and phone number, and check they match before handing over the laundry.</div>
          </div>

          <div class="wt-box d-flex justify-content-between align-items-center mb-3">
            <span class="small fw-semibold text-muted">Amount to collect</span>
            <span class="wt-total fs-4 text-danger" x-text="money(o.total)"></span>
          </div>

          <div class="btn-group w-100 mb-3" role="group" aria-label="Payment method">
            <input type="radio" class="btn-check" name="payment_method" id="rmCash" value="Cash" autocomplete="off" x-model="method">
            <label class="btn btn-outline-primary fw-semibold" for="rmCash"><i class="bi bi-cash-coin me-1"></i> Cash</label>
            <input type="radio" class="btn-check" name="payment_method" id="rmGcash" value="GCash" autocomplete="off" x-model="method">
            <label class="btn btn-outline-primary fw-semibold" for="rmGcash"><i class="bi bi-phone me-1"></i> GCash</label>
          </div>

          <div x-show="method === 'Cash'">
            <label class="form-label small fw-semibold text-muted mb-1" for="rmTendered">Cash received</label>
            <div class="input-group">
              <span class="input-group-text">&#8369;</span>
              <input type="number" step="0.01" min="0" class="form-control" id="rmTendered" name="amount_tendered" inputmode="decimal" x-model="tendered" :disabled="method !== 'Cash'" :placeholder="Number(o.total).toFixed(2)">
            </div>
            <div class="small mt-2" :class="short ? 'text-danger' : 'text-muted'">
              <span x-show="short" x-cloak>Not enough: short by <strong x-text="money(o.total - tenderedNum)"></strong></span>
              <span x-show="!short">Change: <strong x-text="money(change)"></strong></span>
            </div>
          </div>

          <div x-show="method === 'GCash'" x-cloak>
            <label class="form-label small fw-semibold text-muted mb-1" for="rmRef">GCash reference no. <span class="fw-normal">(optional)</span></label>
            <input type="text" class="form-control" id="rmRef" name="reference_no" maxlength="50" autocomplete="off" x-model="ref" :disabled="method !== 'GCash'">
          </div>
          <p class="small text-muted mt-3 mb-0">One full payment. The order is released as soon as you confirm.</p>
        </div>

        <div class="modal-footer border-0 pt-0 gap-2">
          <button type="button" class="btn btn-light border flex-fill" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary flex-fill fw-semibold" :disabled="busy || short">
            <i class="bi bi-check-circle me-1"></i> Collect &amp; release
          </button>
        </div>
      </form>
    </div>
  </div>
</div>