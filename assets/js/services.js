// Services page (admin): one Bootstrap modal for add + edit (Alpine), SweetAlert2 for delete. Talks to services_process.php.
// JS for the Services page (admin). A single modal handles add and edit (Alpine),
// SweetAlert2 asks before delete. It talks to services_process.php.
document.addEventListener('alpine:init', function () {
    Alpine.data('serviceAdmin', function () {
        // blank = default/empty service form (new service).
        var blank = { id: 0, name: '', type: 'Standard', price: '', fold: false, active: '1' };
        return {
            f: Object.assign({}, blank), tried: false, busy: false, error: '',
            modal: function () { return bootstrap.Modal.getOrCreateInstance(document.getElementById('serviceModal')); },
            openAdd: function () { this.f = Object.assign({}, blank); this.tried = false; this.error = ''; this.modal().show(); },
            openEdit: function (tr) {
                // Open the modal pre-filled with the chosen service (from the data-service JSON).
                var d = JSON.parse(tr.dataset.service);
                this.f = { id: d.id, name: d.name, type: d.type, price: d.price, fold: d.fold, active: d.active };
                this.tried = false; this.error = ''; this.modal().show();
            },
            submit: function () {
                // Save the service. With an id -> update (including is_active); without -> a new service.
                var self = this, f = this.f, price = parseFloat(f.price);
                this.tried = true; this.error = '';
                if (!f.name.trim() || !(price > 0)) return;
                var data = { service_name: f.name.trim(), price_per_load: price.toFixed(2) };
                if (f.id) { data.update_service = 1; data.service_id = f.id; data.is_active = f.active; }
                else { data.add_service = 1; data.service_type = f.type; if (f.fold) data.includes_fold = 1; }
                this.busy = true;
                wtPost('services_process.php', data).then(function (j) {
                    self.busy = false;
                    if (j.ok) wtReloadWith(f.id ? 'Service updated' : 'Service added');
                    else self.error = j.message || 'Could not save this service.';
                });
            },
            remove: function (tr) {
                // Confirm first before deleting. If used by existing orders, it can only be set to Inactive.
                var d = JSON.parse(tr.dataset.service);
                wtConfirm({ title: 'Delete ' + d.name + '?', text: 'This cannot be undone. A service used by existing orders can only be set to Inactive.', confirmText: 'Delete', tone: 'danger' })
                    .then(function (yes) {
                        if (!yes) return;
                        wtPost('services_process.php', { delete_service: 1, service_id: d.id }).then(function (j) {
                            if (j.ok) wtReloadWith('Service deleted');
                            else wtError(j.message);
                        });
                    });
            }
        };
    });
});
