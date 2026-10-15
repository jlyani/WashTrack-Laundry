<?php
// users.php - user management (admin). Gumagamit ng shared list system (ot-*)
// that Orders, Services and Transactions use: a toolbar with role tabs and
// a live search, one account per table row, at a modal for add + edit.
// Page for managing accounts (Admin lang). Uses the same list/tabs/search
// used by Orders and Services din; with a modal for add and edit, plus a list of password-reset requests.
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Admin') {
    header("Location: login.php?error=unauthorized");
    exit();
}
require_once __DIR__ . '/includes/db.php';

$users = [];
$res = $conn->query("SELECT user_id, full_name, username, role, is_active FROM users ORDER BY user_id ASC");
if ($res) while ($row = $res->fetch_assoc()) $users[] = $row;

$me = (int) $_SESSION['user_id'];
$user_counts = ['all' => count($users), 'Admin' => 0, 'Staff' => 0];
foreach ($users as $u) { if (isset($user_counts[$u['role']])) $user_counts[$u['role']]++; }

// Mga password-reset request raised from the sign-in page and not yet handled.
// "Forgot password?" requests na hindi resolved — shown here so the admin can set a new password.
$requests = [];
try {
    $req_res = $conn->query(
        "SELECT r.request_id, r.requested_at, u.full_name, u.username, u.role
           FROM password_reset_requests r
           JOIN users u ON u.user_id = r.user_id
          WHERE r.status = 'pending'
          ORDER BY r.requested_at ASC"
    );
    if ($req_res) while ($row = $req_res->fetch_assoc()) $requests[] = $row;
} catch (Throwable $e) {
    $requests = [];   // table missing on an older import: the page still works
}

$page_scripts = ['assets/js/users.js'];
include __DIR__ . '/components/header.php';
?>
<div class="container-fluid ot-page" x-data="userAdmin(<?php echo $me; ?>)">

    <div class="ot-toolbar">
        <div class="ot-toolbar-group">
            <nav class="ot-tabs" aria-label="Filter by role">
                <button type="button" class="ot-tab" :class="{ 'is-active': tab === 'all' }" @click="tab = 'all'">All users <span class="ot-tab-count"><?php echo (int) $user_counts['all']; ?></span></button>
                <button type="button" class="ot-tab" :class="{ 'is-active': tab === 'Admin' }" @click="tab = 'Admin'">Admins <span class="ot-tab-count"><?php echo (int) $user_counts['Admin']; ?></span></button>
                <button type="button" class="ot-tab" :class="{ 'is-active': tab === 'Staff' }" @click="tab = 'Staff'">Staff <span class="ot-tab-count"><?php echo (int) $user_counts['Staff']; ?></span></button>
            </nav>
            <a href="#" class="ot-clear" :class="{ 'is-off': tab === 'all' && q.trim() === '' }" @click.prevent="tab = 'all'; q = ''"><i class="bi bi-x-lg"></i> Clear</a>
        </div>
        <button type="button" class="ot-add-btn" @click="openAdd()"><i class="bi bi-person-plus"></i> Add User</button>
    </div>

    <?php if ($requests): ?>
    <div class="ot-requests" role="region" aria-label="Pending password reset requests">
        <div class="ot-req-head">
            <span class="ot-req-icon" aria-hidden="true"><i class="bi bi-key"></i></span>
            <div>
                <div class="ot-req-title">Password reset <?php echo count($requests) === 1 ? 'request' : 'requests'; ?></div>
                <div class="ot-req-sub"><?php echo count($requests); ?> waiting &mdash; set a new password, then pass it on to the person.</div>
            </div>
        </div>
        <ul class="ot-req-list">
            <?php foreach ($requests as $r):
                $rid  = (int) $r['request_id'];
                $name = $r['full_name'];
            ?>
            <li class="ot-req-item">
                <div class="ot-req-who">
                    <span class="ot-cust-name"><?php echo htmlspecialchars($name); ?></span>
                    <span class="ot-sub">@<?php echo htmlspecialchars($r['username']); ?> &middot; <?php echo htmlspecialchars($r['role']); ?> &middot; requested <?php echo htmlspecialchars(time_ago($r['requested_at'])); ?></span>
                </div>
                <div class="ot-actions">
                    <button type="button" class="ot-btn" @click="resolveRequest(<?php echo $rid; ?>, <?php echo htmlspecialchars(json_encode($name), ENT_QUOTES); ?>)"><i class="bi bi-shield-lock"></i> Set new password</button>
                    <button type="button" class="ot-icon-btn" title="Dismiss request" aria-label="Dismiss request" @click="dismissRequest(<?php echo $rid; ?>, <?php echo htmlspecialchars(json_encode($name), ENT_QUOTES); ?>)"><i class="bi bi-x-lg"></i></button>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="ot-searchbar">
        <div class="ot-search">
            <i class="bi bi-search"></i>
            <input type="text" x-model="q" placeholder="Search name or username..." aria-label="Search users">
        </div>
    </div>

    <div class="ot-hint">
        <i class="bi bi-info-circle"></i>
        <span><strong>Admins</strong> manage services, users, prices and reports. <strong>Staff</strong> handle orders and payments only. Admin accounts cannot be deleted; set an account to <strong>Inactive</strong> instead of deleting it once it has records.</span>
    </div>

    <div class="ot-table-card">
        <div class="table-responsive">
            <table class="ot-table">
                <thead>
                    <tr>
                        <th>Full name</th>
                        <th class="d-none d-sm-table-cell">Username</th>
                        <th>Role</th>
                        <th class="d-none d-sm-table-cell">Status</th>
                        <th class="is-center ot-col-action">Actions</th>
                    </tr>
                </thead>
                <tbody id="userRoster">
                    <?php if (!$users): ?>
                        <tr><td colspan="5" class="ot-empty"><i class="bi bi-people"></i>No users yet. Add the first account.</td></tr>
                    <?php else: foreach ($users as $u):
                        $self = (int) $u['user_id'] === $me;
                        $active = (int) $u['is_active'] === 1;
                        $json = htmlspecialchars(json_encode([
                            'id' => (int) $u['user_id'], 'name' => $u['full_name'], 'username' => $u['username'],
                            'role' => $u['role'], 'active' => $active ? '1' : '0', 'self' => $self,
                        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
                        $search_blob = htmlspecialchars(mb_strtolower($u['full_name'] . ' ' . $u['username'], 'UTF-8'), ENT_QUOTES, 'UTF-8');
                    ?>
                    <tr data-role="<?php echo htmlspecialchars($u['role']); ?>" data-search="<?php echo $search_blob; ?>" data-user="<?php echo $json; ?>" x-show="match($el)">
                        <td>
                            <div class="ot-cust-name"><?php echo htmlspecialchars($u['full_name']); ?><?php if ($self): ?> <span class="ot-badge is-compact ot-b-pending">You</span><?php endif; ?></div>
                            <span class="ot-sub d-sm-none wt-mono"><?php echo htmlspecialchars($u['username']); ?></span>
                        </td>
                        <td class="wt-mono d-none d-sm-table-cell"><?php echo htmlspecialchars($u['username']); ?></td>
                        <td><span class="ot-badge is-compact <?php echo $u['role'] === 'Admin' ? 'ot-b-progress' : 'ot-b-pending'; ?>"><?php echo htmlspecialchars($u['role']); ?></span></td>
                        <td class="d-none d-sm-table-cell"><span class="ot-badge is-compact <?php echo $active ? 'ot-b-done' : 'ot-b-cancel'; ?>"><?php echo $active ? 'Active' : 'Inactive'; ?></span></td>
                        <td class="is-center">
                            <div class="ot-actions">
                                <button type="button" class="ot-icon-btn" title="Edit user" aria-label="Edit user" @click="openEdit($el.closest('tr'))"><i class="bi bi-pencil-square"></i></button>
                                <?php if ($u['role'] === 'Admin'): ?>
                                    <span class="d-inline-flex" style="cursor:not-allowed" title="<?php echo $self ? 'You cannot delete your own account' : 'Admin accounts cannot be deleted'; ?>" data-bs-toggle="tooltip">
                                        <button type="button" class="ot-icon-btn" disabled style="pointer-events:none" aria-label="Admin accounts cannot be deleted"><i class="bi bi-trash"></i></button>
                                    </span>
                                <?php else: ?>
                                    <button type="button" class="ot-icon-btn" title="Delete user" aria-label="Delete user" @click="remove($el.closest('tr'))"><i class="bi bi-trash"></i></button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr x-show="shownCount() === 0"><td colspan="5" class="ot-empty"><i class="bi bi-search"></i><span x-text="emptyText()"></span></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="ot-foot">
            <span x-text="shownCount() + ' of <?php echo (int) $user_counts['all']; ?> accounts'"></span>
            <span>Deleting cannot be undone.</span>
        </div>
    </div>

    <!-- Add / Edit user modal -->
    <div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered wt-modal-md">
            <div class="modal-content wt-modal">
                <form @submit.prevent="submit" novalidate>
                    <div class="modal-header border-0 pb-2">
                        <div class="d-flex align-items-center gap-3 w-100 pe-3">
                            <div class="avatar-box"><i class="bi bi-person-gear fs-5"></i></div>
                            <div class="min-w-0">
                                <h5 class="modal-title fw-bold mb-0" x-text="f.id ? 'Edit user' : 'Add new user'"></h5>
                                <div class="small text-muted" x-text="f.id ? 'Update this account.' : 'Create a staff or admin account.'"></div>
                            </div>
                        </div>
                        <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger py-2 small" x-show="error" x-text="error" x-cloak></div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small" for="uName">Full name <span class="text-danger">*</span></label>
                            <input type="text" id="uName" class="form-control" maxlength="100" x-model="f.name" :class="{ 'is-invalid': tried && !f.name.trim() }">
                            <div class="invalid-feedback">Please enter the full name.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small" for="uUser">Username <span class="text-danger">*</span></label>
                            <input type="text" id="uUser" class="form-control" maxlength="50" autocomplete="off" x-model="f.username" :class="{ 'is-invalid': tried && !f.username.trim() }">
                            <div class="invalid-feedback">Please enter a username.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small" for="uPass"><span x-text="f.id ? 'New password' : 'Password'"></span> <span class="text-danger" x-show="!f.id">*</span><span class="fw-normal text-muted" x-show="f.id" x-cloak>(leave blank to keep current)</span></label>
                            <div class="position-relative" x-data="{ show: false }" x-init="document.getElementById('userModal').addEventListener('show.bs.modal', () => show = false)">
                                <input :type="show ? 'text' : 'password'" id="uPass" class="form-control" style="padding-right: 52px; background-image: none;" autocomplete="new-password" x-model="f.password" :class="{ 'is-invalid': tried && !f.id && !f.password }">
                                <button type="button" tabindex="-1" @click="show = !show" :aria-label="show ? 'Hide password' : 'Show password'" :aria-pressed="show" class="border-0 bg-transparent d-grid text-secondary rounded-3" style="position:absolute; right:5px; top:50%; transform:translateY(-50%); width:34px; height:34px; place-items:center; cursor:pointer; font-size:1rem;" onmouseover="this.style.background='#EAF1FE';this.style.color='#2563eb'" onmouseout="this.style.background='transparent';this.style.color=''"><i class="bi" :class="show ? 'bi-eye-slash' : 'bi-eye'"></i></button>
                            </div>
                            <div class="invalid-feedback" :class="{ 'd-block': tried && !f.id && !f.password }">Please enter a password.</div>
                        </div>
                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold small" for="uRole">Role</label>
                                <select id="uRole" class="form-select" x-model="f.role" :disabled="f.self">
                                    <option value="Staff">Staff (Cashier)</option>
                                    <option value="Admin">Admin</option>
                                </select>
                            </div>
                            <div class="col-6" x-show="f.id" x-cloak>
                                <label class="form-label fw-semibold small" for="uActive">Status</label>
                                <select id="uActive" class="form-select" x-model="f.active" :disabled="f.self">
                                    <option value="1">Active</option>
                                    <option value="0">Inactive</option>
                                </select>
                            </div>
                        </div>
                        <div class="small text-muted mt-2" x-show="f.self" x-cloak>You cannot change your own role or status.</div>
                    </div>
                    <div class="modal-footer border-0 pt-0 gap-2">
                        <button type="button" class="btn btn-light border flex-fill" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary flex-fill fw-semibold" :disabled="busy"><i class="bi bi-check-lg me-1"></i><span x-text="f.id ? 'Save changes' : 'Add user'"></span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/components/footer.php'; ?>