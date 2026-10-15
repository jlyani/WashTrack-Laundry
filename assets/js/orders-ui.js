/* assets/js/orders-ui.js - the buttons on each order row (Order Tracking and Dashboard).
   Every row/card carries its data in data-order='{json}' (built by ot_order_json() in includes/order_view.php);
   buttons only say what they do: data-ot="view|print|start|ready|release|edit|cancel".
   Load after app.js, before Alpine.
   Buttons on each order row (Order Tracking and Dashboard). The data-order='{json}' holds
   the row data; each button just declares its action (view/print/start/ready/release/edit/cancel). */
(function () {
  "use strict";

  // Helper to format money as ₱1,234.56.
  var money = function (n) {
    return (
      "₱" +
      (Number(n) || 0).toLocaleString("en-PH", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      })
    );
  };
  var STATUS_CLASS = {
    // Maps each status to a badge color class.
    Pending: "ot-b-pending",
    "In Progress": "ot-b-progress",
    "Ready for Pickup": "ot-b-ready",
    Released: "ot-b-done",
    Cancelled: "ot-b-cancel",
  };

  // ---------- Alpine components for the two read/collect modals ----------
  // Alpine components for the two modals - order details (view) and release/payment (collect).
  document.addEventListener("alpine:init", function () {
    // orderDetails = shows the full order details + payment history once paid.
    Alpine.data("orderDetails", function () {
      return {
        o: {
          id: 0,
          number: "",
          customer: "",
          phone: "",
          service: "",
          weight: 0,
          loads: 0,
          category: "",
          perKg: false,
          rate: 0,
          billed: 0,
          garments: "",
          supplies: "",
          suppliesUsed: false,
          machines: "",
          mixedNote: "",
          total: 0,
          paid: false,
          status: "",
          statusClass: "",
          created: "",
          promised: "",
          overdue: false,
        },
        money: money,
        open: function (o) {
          var mixed =
            (o.notes || "").indexOf("Mixed:") === 0
              ? o.notes
                  .replace(/(^| \| )Machines reserved .*$/, "")
                  .replace(/^Mixed:\s*/, "")
              : "";
          this.o = Object.assign({}, o, {
            statusClass: STATUS_CLASS[o.status] || "ot-b-pending",
            mixedNote: mixed,
          });
          bootstrap.Modal.getOrCreateInstance(
            document.getElementById("orderDetailsModal"),
          ).show();
          if (o.paid) {
            fetch(
              "get_payment_history.php?order_id=" + encodeURIComponent(o.id),
              { credentials: "same-origin" },
            )
              .then(function (r) {
                return r.text();
              })
              .then(function (html) {
                var b = document.getElementById("paymentHistoryTableBody");
                if (b) b.innerHTML = html;
              })
              .catch(function () {
                var b = document.getElementById("paymentHistoryTableBody");
                if (b)
                  b.innerHTML =
                    '<tr><td colspan="3" class="text-center text-danger">Error loading the payment record.</td></tr>';
              });
          }
        },
      };
    });

    Alpine.data("releaseForm", function () {
      return {
        // releaseForm = accepts payment and releases the order (Cash or GCash, with change/ref).
        o: { id: 0, short: "", customer: "", total: 0 },
        method: "Cash",
        tendered: "",
        ref: "",
        busy: false,
        money: money,
        get tenderedNum() {
          return this.tendered === ""
            ? this.o.total
            : parseFloat(this.tendered) || 0;
        },
        get short() {
          return (
            this.method === "Cash" &&
            Math.round(this.tenderedNum * 100) < Math.round(this.o.total * 100)
          );
        },
        get change() {
          return (
            Math.max(
              0,
              Math.round(this.tenderedNum * 100) -
                Math.round(this.o.total * 100),
            ) / 100
          );
        },
        open: function (o) {
          this.o = o;
          this.method = "Cash";
          this.tendered = "";
          this.ref = "";
          this.busy = false;
          bootstrap.Modal.getOrCreateInstance(
            document.getElementById("releaseModal"),
          ).show();
        },
        submit: function (ev) {
          if (this.short || this.busy) return;
          var self = this,
            o = this.o,
            fd = new FormData(ev.target);
          this.busy = true;
          wtPost("orders_process.php", fd).then(function (j) {
            self.busy = false;
            if (!j.ok) {
              wtError(j.message);
              return;
            }
            bootstrap.Modal.getOrCreateInstance(
              document.getElementById("releaseModal"),
            ).hide();
            // Payment collected and order released: the receipt prints itself, then the page reloads with the note.
            // Payment done and order released - the receipt prints itself, then the page reloads.
            wtPrintReceipt(o.id).then(function () {
              wtReloadWith(j.message);
            });
          });
        },
      };
    });
  });

  // ---------- row actions ----------
  // Helpers for the buttons and workflow actions on each row.
  var busyBtn = function (btn, on) {
    if (btn) {
      btn.disabled = on;
    }
  };

  // Sends one workflow action and reacts to the reply.
  // Sends a single workflow action (start/ready/cancel) and waits for the reply.
  function runAction(btn, o, actionType, extra) {
    busyBtn(btn, true);
    var data = Object.assign(
      { order_id: o.id, action_type: actionType },
      extra || {},
    );
    return wtPost("orders_process.php", data).then(function (j) {
      busyBtn(btn, false);
      return j;
    });
  }

  function onResult(j, fallback) {
    if (j.ok) {
      wtReloadWith(j.message || fallback);
      return;
    }
    if (j.type === "warning") {
      Swal.fire({
        icon: "warning",
        title: "Cannot start yet",
        text: j.message,
      });
    } else {
      wtError(j.message);
    }
  }

  var actions = {
    // view = opens the details modal; print = prints receipt/stub; edit = edit modal; release = payment modal.
    view: function (o) {
      Alpine.$data(document.getElementById("orderDetailsBody")).open(o);
    },
    print: function (o) {
      wtPrintReceipt(o.id);
    },
    edit: function (o) {
      Alpine.$data(document.getElementById("editOrderBody")).load(o);
    },
    release: function (o) {
      Alpine.$data(document.getElementById("releaseBody")).open(o);
    },

    // Start / Ready are one-way forward steps, not destructive: they act
    // immediately and report through the toast. Start follows the waiting line strictly
    // (oldest Pending order first, full machine set or nothing); when it can't, the server says why.
    // Only Cancel still asks first.
    // Start and Ready are forward steps, not destructive - they act at once and report via toast.
    // Start follows the queue (oldest Pending first, full machine set or nothing). Only Cancel asks first.
    start: function (o, btn) {
      runAction(btn, o, "start").then(function (j) {
        onResult(j, "Order started.");
      });
    },

    ready: function (o, btn) {
      runAction(btn, o, "mark_ready_or_dispatch").then(function (j) {
        onResult(j, "Order marked ready.");
      });
    },

    cancel: function (o, btn) {
      wtConfirm({
        title: "Cancel " + o.short + "?",
        text:
          "Order " +
          o.number +
          " will be kept in the history as Cancelled and leaves the waiting line. A started order can no longer be cancelled.",
        confirmText: "Cancel order",
        cancelText: "Keep order",
        tone: "danger",
      }).then(function (yes) {
        if (yes)
          runAction(btn, o, "cancel").then(function (j) {
            onResult(j, "Order cancelled.");
          });
      });
    },
  };

  document.addEventListener("click", function (e) {
    // A single listener for all [data-ot] buttons. Read the JSON from [data-order] and run the action.
    var btn = e.target.closest("[data-ot]");
    if (!btn) return;
    var holder = btn.closest("[data-order]");
    if (!holder || !actions[btn.dataset.ot]) return;
    var o;
    try {
      o = JSON.parse(holder.dataset.order);
    } catch (err) {
      return;
    }
    actions[btn.dataset.ot](o, btn);
  });
})();
