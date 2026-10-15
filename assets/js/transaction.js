/* assets/js/transaction.js - Transactions page: opens the transaction detail modal and reprints receipts. */
/* JS for the Transactions page. Opens the detail modal and reprints receipts. */
(function () {
    'use strict';
    // Helper to format money as ₱1,234.56.
    var money = function (n) { return '₱' + (Number(n) || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };

    document.addEventListener('alpine:init', function () {
        // txDetail = contents of the transaction detail modal (receipt, customer, payment, etc.).
        Alpine.data('txDetail', function () {
            return {
                d: { order_id: 0, receipt: '', order_no: '', customer: '', phone: '', service: '', weight: 0, loads: 0, price: 0, total: 0, paid: 0, method: 'Cash', ref: '', tendered: 0, change: 0, cashier: '', paid_at: '', completed: '' },
                money: money,
                open: function (d) { this.d = d; bootstrap.Modal.getOrCreateInstance(document.getElementById('transactionDetailModal')).show(); }
            };
        });
    });

    document.addEventListener('click', function (e) {
        // A single listener for [data-tx-do] buttons: 'print' = print receipt, otherwise = open the detail modal.
        var btn = e.target.closest('[data-tx-do]');
        if (!btn) return;
        var holder = btn.closest('[data-tx]');
        if (!holder) return;
        var d;
        try { d = JSON.parse(holder.dataset.tx); } catch (err) { return; }
        if (btn.dataset.txDo === 'print') wtPrintReceipt(d.order_id);
        else Alpine.$data(document.getElementById('txDetailBody')).open(d);
    });
})();
