// Users page: the shared list system (ot-*) — same as Orders and Services.
// Role tabs plus a live search filter the roster (Alpine); one Bootstrap
// modal handles add + edit; SweetAlert2 asks before delete.
// Talks to users_process.php.
// JS for the Users page (same list system as Orders and Services).
// Role tabs + live search filter the list; one modal handles add/edit,
// SweetAlert2 asks before delete. It talks to users_process.php.
document.addEventListener('alpine:init', function () {
    Alpine.data('userAdmin', function (meId) {
        // A decent throwaway password the admin can accept or edit.
        // Generates a random temporary password (the admin may use or change it).
        var randomPassword = function () {
            var abc = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            var pick = (window.crypto && window.crypto.getRandomValues)
                ? function (n) { var a = new Uint32Array(1); window.crypto.getRandomValues(a); return a[0] % n; }
                : function (n) { return Math.floor(Math.random() * n); };
            var out = '';
            for (var i = 0; i < 8; i++) out += abc.charAt(pick(abc.length));
            return out;
        };
        var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };

        var blank = { id: 0, name: '', username: '', password: '', role: 'Staff', active: '1', self: false };
        return {
            tab: 'all',
            q: '',
            f: Object.assign({}, blank), tried: false, busy: false, error: '',
            modal: function () { return bootstrap.Modal.getOrCreateInstance(document.getElementById('userModal')); },
            // A roster row shows when it matches the active tab and the search box.
            // The row appears when it matches the active tab and the search term (search box).
            match: function (row) {
                if (!row) return true;
                if (this.tab !== 'all' && row.getAttribute('data-role') !== this.tab) return false;
                var needle = this.q.trim().toLowerCase();
                if (!needle) return true;
                return (row.getAttribute('data-search') || '').indexOf(needle) !== -1;
            },
            shownRows: function () {
                var self = this;
                return [].filter.call(document.querySelectorAll('#userRoster tr[data-role]'), function (r) { return self.match(r); });
            },
            shownCount: function () { return this.shownRows().length; },
            emptyText: function () {
                var q = this.q.trim();
                if (q) return 'No accounts match \u201C' + q + '\u201D.';
                if (this.tab === 'Admin') return 'No admin accounts right now.';
                if (this.tab === 'Staff') return 'No staff accounts right now.';
                return 'No accounts match this filter.';
            },
            openAdd: function () { this.f = Object.assign({}, blank); this.tried = false; this.error = ''; this.modal().show(); },
            openEdit: function (row) {
                var d = JSON.parse(row.dataset.user);
                this.f = { id: d.id, name: d.name, username: d.username, password: '', role: d.role, active: d.active, self: d.self };
                this.tried = false; this.error = ''; this.modal().show();
            },
            submit: function () {
                // Save the user. With an id -> update; without -> a new account (a password is required).
                var self = this, f = this.f;
                this.tried = true; this.error = '';
                if (!f.name.trim() || !f.username.trim() || (!f.id && !f.password)) return;
                var data = { action: f.id ? 'update' : 'add', full_name: f.name.trim(), username: f.username.trim(), password: f.password, role: f.role };
                if (f.id) { data.user_id = f.id; data.is_active = f.active; }
                this.busy = true;
                wtPost('users_process.php', data).then(function (j) {
                    self.busy = false;
                    if (j.ok) wtReloadWith(f.id ? 'User updated' : 'User added');
                    else self.error = j.message || 'Could not save this user.';
                });
            },
            remove: function (row) {
                // Confirm first before deleting. If the account has records, only Inactive is allowed.
                var d = JSON.parse(row.dataset.user);
                wtConfirm({ title: 'Delete ' + d.name + '?', text: 'This cannot be undone. If the account has records, set it to Inactive instead.', confirmText: 'Delete', tone: 'danger' })
                    .then(function (yes) {
                        if (!yes) return;
                        wtPost('users_process.php', { action: 'delete', user_id: d.id }).then(function (j) {
                            if (j.ok) wtReloadWith('User deleted');
                            else wtError(j.message);
                        });
                    });
            },
            // "Forgot password?" request from the sign-in page: set a new password.
            // "Forgot password?" request from the login page - the admin sets a new password here.
            resolveRequest: function (id, name) {
                Swal.fire({
                    title: 'Set a new password',
                    html: 'New password for <strong>' + esc(name) + '</strong>. Share it with them once it is saved.',
                    input: 'text',
                    inputValue: randomPassword(),
                    inputAttributes: { autocapitalize: 'off', autocorrect: 'off', spellcheck: 'false' },
                    showCancelButton: true,
                    confirmButtonText: 'Update password',
                    cancelButtonText: 'Cancel',
                    reverseButtons: true,
                    inputValidator: function (v) { return (!v || v.trim().length < 6) ? 'Use at least 6 characters.' : undefined; }
                }).then(function (r) {
                    if (!r.isConfirmed) return;
                    wtPost('users_process.php', { action: 'reset_resolve', request_id: id, password: r.value })
                        .then(function (j) {
                            if (j.ok) wtReloadWith('Password updated for ' + name, 'success');
                            else wtError(j.message);
                        });
                });
            },
            dismissRequest: function (id, name) {
                // Reject/dismiss the user's reset request.
                wtConfirm({ title: 'Dismiss ' + name + '\u2019s request?', text: 'They will need to ask again if they still need a reset.', confirmText: 'Dismiss', tone: 'warning' })
                    .then(function (yes) {
                        if (!yes) return;
                        wtPost('users_process.php', { action: 'reset_dismiss', request_id: id })
                            .then(function (j) { if (j.ok) wtReloadWith('Reset request dismissed.'); else wtError(j.message); });
                    });
            }
        };
    });
});
