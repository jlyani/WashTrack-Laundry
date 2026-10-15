/* assets/js/app.js - shared behaviour for every signed-in page.
   Bootstrap draws the boxes, Alpine holds data inside them, SweetAlert2 asks the questions.
   Shared JS for every signed-in page: Bootstrap = boxes/UI,
   Alpine = data inside the boxes, SweetAlert2 = the confirm/popup dialogs. */
(function () {
  "use strict";

  // ---------- sidebar (side menu) ----------
  // When the hamburger is clicked, toggle the sidebar (mobile = overlay, desktop = collapse).
  document.addEventListener("DOMContentLoaded", function () {
    var btn = document.getElementById("sidebarCollapse"),
      sb = document.getElementById("sidebar");
    if (btn && sb) {
      btn.addEventListener("click", function () {
        if (window.innerWidth <= 768) {
          sb.classList.toggle("mobile-show");
        } else {
          sb.classList.toggle("collapsed");
          document.body.classList.toggle("sidebar-collapsed");
        }
      });
    }
    document
      .querySelectorAll('[data-bs-toggle="tooltip"]')
      .forEach(function (el) {
        new bootstrap.Tooltip(el);
      });
    document.querySelectorAll(".toast-container .toast").forEach(function (el) {
      new bootstrap.Toast(el, {
        delay: parseInt(el.dataset.delay, 10) || 4000,
      }).show();
    });
    // A message saved before a reload (see wtReloadWith) shows as a toast on the fresh page.
    // If a message was saved before a reload, it shows as a toast on the fresh page.
    try {
      var f = sessionStorage.getItem("wtFlash");
      if (f) {
        sessionStorage.removeItem("wtFlash");
        var j = JSON.parse(f);
        wtToast(j.message, j.type);
      }
    } catch (e) {
      /* storage blocked: the message is simply skipped */
    }
  });

  // ---------- toast (notification popup) ----------
  // Builds and shows a toast notification (success/warning/error).
  window.wtToast = function (message, type) {
    var box = document.querySelector(".toast-container");
    if (!box || !message) return;
    var cls =
      {
        success: "text-bg-success",
        warning: "text-bg-warning",
        error: "text-bg-danger",
      }[type || "success"] || "text-bg-success";
    var icon =
      type === "success" || !type
        ? "bi-check-circle-fill"
        : "bi-exclamation-triangle-fill";
    var el = document.createElement("div");
    el.className = "toast align-items-center border-0 shadow " + cls;
    el.setAttribute("role", "alert");
    el.innerHTML =
      '<div class="d-flex"><div class="toast-body d-flex align-items-center gap-2"><i class="bi ' +
      icon +
      ' fs-5"></i><div></div></div>' +
      '<button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>';
    el.querySelector(".toast-body div").textContent = message;
    box.appendChild(el);
    el.addEventListener("hidden.bs.toast", function () {
      el.remove();
    });
    new bootstrap.Toast(el, {
      delay: type === "error" || type === "warning" ? 7000 : 4000,
    }).show();
  };

  // Reload the page and show a toast on the fresh page.
  // Reload the page and show a toast on the fresh page (sessionStorage keeps it across the reload).
  window.wtReloadWith = function (message, type) {
    try {
      sessionStorage.setItem(
        "wtFlash",
        JSON.stringify({ message: message, type: type || "success" }),
      );
    } catch (e) {}
    window.location.reload();
  };

  // ---------- confirmations (SweetAlert2) ----------
  // wtConfirm({ title, text, confirmText, tone: 'primary' | 'danger' | 'warning' }) -> Promise<boolean> (true when confirmed)
  // Shared "Are you sure?" dialog; resolves true when the confirm button is pressed.
  window.wtConfirm = function (o) {
    var tone = o.tone || "primary";
    // Button looks come from the shared .swal2-* rules in app.css (house modal
    // chrome); the danger tone only needs a marker class on the confirm button.
    var cls = {};
    if (tone === "danger") cls.confirmButton = "is-danger";
    return Swal.fire({
      title: o.title || "Are you sure?",
      text: o.text || "",
      icon: o.icon || (tone === "primary" ? "question" : "warning"),
      showCancelButton: true,
      confirmButtonText: o.confirmText || "Confirm",
      cancelButtonText: o.cancelText || "Cancel",
      customClass: cls,
      reverseButtons: true,
      focusCancel: tone === "danger",
      returnFocus: false,
    }).then(function (r) {
      return r.isConfirmed;
    });
  };

  window.wtError = function (message) {
    return Swal.fire({
      icon: "error",
      title: "Could not do that",
      text: message,
    });
  };

  // ---------- talking to orders_process.php ----------
  // Sends an action in the background and returns the JSON reply ({ ok, message, type }).
  // Sends the action in the background (AJAX/fetch) and returns the JSON reply.
  window.wtPost = function (url, data) {
    var body = data instanceof FormData ? data : new URLSearchParams(data);
    return fetch(url, {
      method: "POST",
      headers: { "X-Requested-With": "XMLHttpRequest" },
      body: body,
      credentials: "same-origin",
    })
      .then(function (r) {
        return r.json();
      })
      .catch(function () {
        return {
          ok: false,
          message: "Could not reach the server. Please try again.",
        };
      });
  };

  // ---------- printing ----------
  // Prints the 80 mm receipt / claim stub through a hidden iframe (no new tab).
  // Prints the receipt/claim stub through a hidden iframe (no new tab opens).
  window.wtPrintReceipt = function (orderId) {
    return new Promise(function (resolve) {
      var old = document.getElementById("wtPrintFrame");
      if (old) old.remove();
      var f = document.createElement("iframe");
      f.id = "wtPrintFrame";
      f.style.cssText =
        "position:fixed;right:0;bottom:0;width:0;height:0;border:0;";
      f.src =
        "print_receipt.php?id=" + encodeURIComponent(orderId) + "&embed=1";
      f.onload = function () {
        try {
          f.contentWindow.focus();
          f.contentWindow.print();
        } catch (e) {
          // blocks until the print dialog closes
          window.open(
            "print_receipt.php?id=" +
              encodeURIComponent(orderId) +
              "&autoprint=1",
            "_blank",
          );
        }
        setTimeout(resolve, 300);
      };
      document.body.appendChild(f);
    });
  };

  // ---------- Alpine components ----------
  // Alpine components are registered here - this is the "brains" of the forms.
  document.addEventListener("alpine:init", function () {
    var LOAD_KG = 7,
      LOAD_KG_HEAVY = 6,
      MAX_KG = 100; // kg per load: Regular 7, Heavy 6; heaviest bag one order accepts (server: ORDER_MAX_KG)
    var loadsFor = function (kg, max) {
      var w = Math.round((parseFloat(kg) || 0) * 100);
      return w > 0 ? Math.ceil(w / Math.round(max * 100)) : 0;
    };
    // Keeps a weight box to digits: 3 integer digits, 1 decimal (scale precision), never above MAX_KG.
    var cleanWeight = function (v) {
      v = String(v).replace(/[^0-9.]/g, "");
      var dot = v.indexOf(".");
      if (dot !== -1)
        v =
          v.slice(0, dot + 1) +
          v
            .slice(dot + 1)
            .replace(/\./g, "")
            .slice(0, 1);
      var intPart = (dot === -1 ? v : v.slice(0, dot)).slice(0, 3);
      v = dot === -1 ? intPart : intPart + v.slice(dot);
      if (parseFloat(v) > MAX_KG) v = String(MAX_KG);
      return v;
    };
    var plural = function (n, one, many) {
      return n + " " + (n === 1 ? one : many);
    };

    // New Laundry Order. The bag is weighed once. Price = rate per kg x billed kg (the real weight, or the minimum
    // charge when the bag is lighter), rounded to a whole peso - same rule as price_order() in includes/orders.php,
    // so the total shown here is the amount the customer is texted and pays at pickup. Loads only size the machine set.
    // Garment types (checkboxes) only decide the loads: any Heavy type ticked -> 6 kg per load, otherwise 7 kg.
    // They never touch the price. Supplies are worked out by the server from the loads (not shown on this form).
    // cfg: washersFree / dryersFree (free machines now), perOrder (machines one order may use at a time),
    // ahead (orders already waiting in line), prices (service_id => rate per kg), minKg (minimum charge in kg),
    // garments (garment_id => 'Regular' | 'Heavy'; empty = no garment step).
    // This is the New Order form. The bag is weighed once; Price = rate/kg x billed kg
    // (real weight, or the minimum charge when light), matching price_order() in includes/orders.php.
    // Garment checkboxes only affect the loads (Heavy -> 6kg/load, otherwise -> 7kg), never the price.
    Alpine.data("orderForm", function (cfg) {
      return {
        name: "",
        phone: "",
        pkg: "",
        weightRaw: "",
        picked: [],
        tried: false,
        busy: false,
        prices: cfg.prices || {},
        garments: cfg.garments || {},
        minKg: Number(cfg.minKg) || 0,
        money: function (n) {
          return (
            "₱" +
            (Number(n) || 0).toLocaleString("en-PH", {
              minimumFractionDigits: 0,
              maximumFractionDigits: 2,
            })
          );
        },
        kgText: function (n) {
          return (Math.round(n * 100) / 100).toString();
        },
        clean: cleanWeight,
        get chosenPrice() {
          var p = this.prices[this.pkg];
          return p === undefined || p === null ? null : Number(p);
        },
        get weight() {
          return parseFloat(this.weightRaw) || 0;
        },
        get needGarments() {
          return Object.keys(this.garments).length > 0;
        },
        get heavy() {
          var g = this.garments;
          return this.picked.some(function (id) {
            return g[id] === "Heavy";
          });
        },
        // Regular and Heavy ticked together can't share a load: at least 2 loads (same rule as order_loads() in orders.php).
        // Regular and Heavy ticked together can't share a load, so a minimum of 2 loads.
        get mixed() {
          var g = this.garments;
          return (
            this.heavy &&
            this.picked.some(function (id) {
              return g[id] === "Regular";
            })
          );
        },
        get loads() {
          var n = loadsFor(this.weight, this.heavy ? LOAD_KG_HEAVY : LOAD_KG);
          return this.mixed && n > 0 ? Math.max(2, n) : n;
        },
        get minApplied() {
          return this.weight > 0 && this.weight < this.minKg;
        },
        get billedKg() {
          return Math.max(this.weight, this.minKg);
        },
        // whole pesos, half up, in integer cents / hundredths of a kg (no floating-point drift)
        // Whole pesos, half up; integer cents avoid floating-point errors.
        get total() {
          var p = this.chosenPrice;
          if (p === null || !this.weight) return null;
          return Math.floor(
            (Math.round(p * 100) * Math.round(this.billedKg * 100) + 5000) /
              10000,
          );
        },
        get priceLine() {
          if (!this.weight) return "Enter the scale weight to see the price.";
          if (this.chosenPrice === null)
            return "Choose a package to see the price.";
          var rate = this.money(this.chosenPrice) + "/kg";
          return this.minApplied
            ? "Minimum charge: " + this.kgText(this.minKg) + " kg × " + rate
            : this.kgText(this.weight) + " kg × " + rate;
        },
        get loadsLine() {
          if (!this.weight || (this.needGarments && !this.picked.length))
            return "";
          return (
            plural(this.loads, "load", "loads") +
            (this.mixed
              ? " · regular and heavy washed separately"
              : this.heavy
                ? " · heavy items"
                : "")
          );
        },
        // Machines are taken when the order is started, not at intake. An order uses at most cfg.perOrder
        // washers + dryers at a time; the rest of its loads run as extra rounds.
        // Machines are only taken at "start", not at intake. Up to cfg.perOrder washers + dryers
        // run at once; the remaining loads become extra rounds.
        get machinesNeeded() {
          return Math.max(1, Math.min(this.loads, cfg.perOrder || 2));
        },
        get canStartNow() {
          var need = this.machinesNeeded;
          return (
            !(cfg.ahead > 0) &&
            cfg.washersFree >= need &&
            cfg.dryersFree >= need
          );
        },
        get machineTone() {
          if (!this.weight) return "text-bg-light text-secondary";
          return this.canStartNow ? "text-bg-success" : "text-bg-warning";
        },
        get machineText() {
          if (!this.weight)
            return "Machines are assigned when the order starts";
          if (this.canStartNow)
            return (
              "Can start right away · " +
              plural(this.machinesNeeded, "Washer", "Washers") +
              " & " +
              plural(this.machinesNeeded, "Dryer", "Dryers")
            );
          return cfg.ahead > 0
            ? "Will wait in line · " + cfg.ahead + " ahead"
            : "Will wait for machines to free up";
        },
        // Only real customer data counts as unsaved work - picking a package
        // just to peek at the price closes it silently.
        // "dirty" = real customer data was typed; a package-only peek can be closed freely.
        get dirty() {
          return !!(
            this.name ||
            this.phone ||
            this.weightRaw ||
            this.picked.length
          );
        },
        modal: function () {
          return bootstrap.Modal.getOrCreateInstance(
            document.getElementById("addOrderModal"),
          );
        },
        reset: function () {
          this.name = this.phone = this.pkg = this.weightRaw = "";
          this.picked = [];
          this.tried = false;
          this.busy = false;
        },
        requestClose: function () {
          var self = this;
          if (!this.dirty) {
            this.modal().hide();
            this.reset();
            return;
          }
          wtConfirm({
            title: "Discard your entries?",
            text: "The customer details you typed will be lost.",
            confirmText: "Discard",
            cancelText: "Keep editing",
          }).then(function (yes) {
            if (yes) {
              self.modal().hide();
              self.reset();
            }
          });
        },
        submit: function (ev) {
          var form = ev.target;
          this.tried = true;
          if (
            this.busy ||
            !form.checkValidity() ||
            !this.pkg ||
            !(this.weight > 0) ||
            (this.needGarments && !this.picked.length)
          )
            return;
          // The modal (with its live total) is the confirmation - no second dialog.
          // The modal itself (with its live total) is the confirmation - no second popup.
          this.busy = true;
          var fd = new FormData(form);
          wtPost("orders_process.php", fd)
            .then(
              function (res) {
                if (res.ok) {
                  var modal = bootstrap.Modal.getOrCreateInstance(
                    document.getElementById("addOrderModal"),
                  );
                  modal.hide();
                  var self = this;
                  self.reset();
                  // Print the claim stub in the background, then reload to show the new order
                  // The claim stub prints in the background, then the page reloads to show the new order.
                  if (res.order_id) {
                    wtPrintReceipt(res.order_id).then(function () {
                      wtReloadWith(res.message, "success");
                    });
                  } else {
                    wtReloadWith(res.message, "success");
                  }
                } else {
                  this.busy = false;
                  wtError(res.message || "Could not create order.");
                }
              }.bind(this),
            )
            .catch(
              function () {
                this.busy = false;
                wtError("Could not reach the server. Please try again.");
              }.bind(this),
            );
        },
      };
    });

    // Edit Order (admin, Pending orders only): contact details only. Fields are filled by openEdit() in orders-ui.js.
    // Edit Order form (admin, Pending only): only the contact details can be changed.
    Alpine.data("editOrder", function () {
      return {
        id: "",
        label: "",
        name: "",
        phone: "",
        service: "",
        weight: 0,
        total: 0,
        start: "",
        tried: false,
        busy: false,
        money: function (n) {
          return (
            "₱" +
            (Number(n) || 0).toLocaleString("en-PH", {
              minimumFractionDigits: 0,
              maximumFractionDigits: 2,
            })
          );
        },
        get snapshot() {
          return [this.name, this.phone].join("|");
        },
        get dirty() {
          return this.start !== "" && this.snapshot !== this.start;
        },
        modal: function () {
          return bootstrap.Modal.getOrCreateInstance(
            document.getElementById("globalEditOrderModal"),
          );
        },
        load: function (o) {
          this.id = o.id;
          this.label = o.number;
          this.name = o.customer;
          this.phone = o.phone || "";
          this.service = o.service;
          this.weight = o.weight;
          this.total = o.total;
          this.tried = false;
          this.busy = false;
          this.start = this.snapshot;
          this.modal().show();
        },
        requestClose: function () {
          var self = this;
          if (!this.dirty) {
            this.modal().hide();
            return;
          }
          wtConfirm({
            title: "Discard changes?",
            text: "Your edits to " + this.label + " will be lost.",
            confirmText: "Discard",
            cancelText: "Keep editing",
          }).then(function (yes) {
            if (yes) self.modal().hide();
          });
        },
        submit: function (ev) {
          var form = ev.target;
          this.tried = true;
          if (!form.checkValidity()) return;
          if (!this.dirty) {
            this.modal().hide();
            return;
          }
          // Saving from the open modal is confirmation enough - no second dialog.
          // Saving from the open modal is confirmation enough.
          this.busy = true;
          form.submit();
        },
      };
    });
  });
})();
