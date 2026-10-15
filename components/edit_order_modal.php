<?php
// components/edit_order_modal.php - Edit Order (admin, Pending orders only). Only the customer's contact details
// can be changed: the weight and amount are already final, so the package, weight and price
// are shown read-only. orders_process.php (edit_order) saves just the name and phone.
// Alpine: Alpine.data('editOrder') in assets/js/app.js.
// Edit order (Admin only, Pending lang). Only the name and phone can be changed —
// the package, weight and price are read-only because they are final.
?>
<div class="modal fade" id="globalEditOrderModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="editModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable wt-modal-sm">
    <div class="modal-content wt-modal" id="editOrderBody" x-data="editOrder()">
      <form id="editOrderForm" action="orders_process.php" method="POST" novalidate :class="{ 'was-validated': tried }" @submit.prevent="submit($event)">
        <input type="hidden" name="edit_order" value="1">
        <input type="hidden" name="order_id" :value="id">
        <div class="modal-header border-0 pb-2">
          <div class="d-flex align-items-center gap-3 w-100 pe-3">
            <div class="avatar-box"><i class="bi bi-pencil-square fs-5"></i></div>
            <div>
              <h5 class="modal-title fw-bold mb-0" id="editModalTitle">Edit Order <span class="font-monospace" x-text="label"></span></h5>
              <div class="text-muted small">Customer details only &middot; Pending orders</div>
            </div>
          </div>
          <button type="button" class="btn-close shadow-none" aria-label="Close" @click="requestClose()"></button>
        </div>

        <div class="modal-body pt-1">
          <div class="mb-2">
            <label class="form-label small fw-semibold text-muted mb-1" for="edit_customer_name">Full name *</label>
            <input type="text" id="edit_customer_name" name="customer_name" class="form-control" maxlength="100" required x-model="name">
            <div class="invalid-feedback">Please enter the customer's full name.</div>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold text-muted mb-1" for="edit_contact_number">Contact number *</label>
            <input type="tel" id="edit_contact_number" name="contact_number" class="form-control" maxlength="11" pattern="^09\d{9}$" inputmode="tel" required x-model="phone">
            <div class="invalid-feedback">Enter 11 digits starting with 09.</div>
          </div>

          <div class="wt-box">
            <dl class="wt-kv mb-0">
              <dt>Package</dt><dd x-text="service"></dd>
              <dt>Weight</dt><dd><span x-text="Number(weight).toFixed(2)"></span> kg</dd>
              <dt>Amount</dt><dd class="fw-bold" x-text="money(total)"></dd>
            </dl>
          </div>
          <p class="small text-muted mt-3 mb-0">The weight and amount are final once the order is taken, so they can't be changed. To correct them, cancel this order and take a new one.</p>
        </div>

        <div class="modal-footer border-0 pt-0 gap-2">
          <button type="button" class="btn btn-light border flex-fill" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn btn-primary flex-fill fw-semibold" :disabled="busy"><i class="bi bi-check-circle me-1"></i> Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>