-- ============================================================
-- Laundry Shop Management System - Database Schema (MySQL 8.0.16+)
-- Workflow: Pay-at-Pickup / Deferred Settlement
-- ============================================================

-- Database name matches includes/db.php ($dbname = "laundry_db").
-- WARNING: this script DROPS and recreates the database (same as the old laundry_db.sql).
DROP DATABASE IF EXISTS laundry_db;

CREATE DATABASE laundry_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE laundry_db;

-- ------------------------------------------------------------
-- USERS (mga account)
-- ------------------------------------------------------------
CREATE TABLE users (
  user_id       INT AUTO_INCREMENT PRIMARY KEY,
  full_name     VARCHAR(100) NOT NULL,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('Admin','Staff') NOT NULL DEFAULT 'Staff',
  is_active     BOOLEAN NOT NULL DEFAULT TRUE,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- PASSWORD RESET REQUESTS (mga reset password request)
-- Raised from the sign-in page ("Forgot password?"). An Admin sets a
-- new password from the Users page, which closes the request.
-- ------------------------------------------------------------
CREATE TABLE password_reset_requests (
  request_id   INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT NOT NULL,
  status       ENUM('pending','resolved','dismissed') NOT NULL DEFAULT 'pending',
  requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  handled_at   TIMESTAMP NULL DEFAULT NULL,
  handled_by   INT NULL DEFAULT NULL,
  CONSTRAINT fk_prr_user  FOREIGN KEY (user_id)    REFERENCES users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_prr_admin FOREIGN KEY (handled_by) REFERENCES users(user_id) ON DELETE SET NULL,
  INDEX idx_prr_status (status, requested_at)
);

-- ------------------------------------------------------------
-- CUSTOMERS (mga customer)
-- ------------------------------------------------------------
CREATE TABLE customers (
  customer_id INT AUTO_INCREMENT PRIMARY KEY,
  full_name   VARCHAR(100) NOT NULL,
  phone       VARCHAR(20) NULL,          -- walk-ins may not give one
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_customer_name (full_name),
  INDEX idx_customer_phone (phone)
);

-- ------------------------------------------------------------
-- SERVICES (standard services and bundled packages)
-- ------------------------------------------------------------
CREATE TABLE services (
  service_id     INT AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(100) NOT NULL UNIQUE,
  service_type   ENUM('Standard','Package') NOT NULL,
  includes_fold  BOOLEAN NOT NULL DEFAULT FALSE,
  price_per_load DECIMAL(10,2) NOT NULL,
  is_active      BOOLEAN NOT NULL DEFAULT TRUE
);

-- ------------------------------------------------------------
-- INVENTORY ITEMS (mga item, inventory)
-- ------------------------------------------------------------
CREATE TABLE inventory_items (
  item_id       INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100) NOT NULL UNIQUE,
  unit          VARCHAR(20) NOT NULL,     -- e.g. 'sachet', 'pc'
  stock_qty     DECIMAL(10,2) NOT NULL DEFAULT 0,
  reorder_level DECIMAL(10,2) NOT NULL DEFAULT 0
);

-- ------------------------------------------------------------
-- SERVICE CONSUMABLES (what each package includes)
-- ------------------------------------------------------------
CREATE TABLE service_consumables (
  service_id INT NOT NULL,
  item_id    INT NOT NULL,
  qty        DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (service_id, item_id),
  FOREIGN KEY (service_id) REFERENCES services(service_id),
  FOREIGN KEY (item_id)    REFERENCES inventory_items(item_id)
);

-- ------------------------------------------------------------
-- GARMENT TYPES (mga checkbox, New Order form)
-- Regular = up to 7 kg per load, Heavy = up to 6 kg per load. A type only decides the number of loads
-- (machines, promise time, supplies); the price is per kilo and never depends on it.
-- ------------------------------------------------------------
CREATE TABLE garment_types (
  garment_id INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(60) NOT NULL UNIQUE,
  category   ENUM('Regular','Heavy') NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active  BOOLEAN NOT NULL DEFAULT TRUE
);

-- ------------------------------------------------------------
-- ORDERS
-- Price, loads and total are snapshots: later price changes
-- must not alter existing orders.
-- ------------------------------------------------------------
CREATE TABLE orders (
  order_id       INT AUTO_INCREMENT PRIMARY KEY,
  order_number   VARCHAR(20) NOT NULL UNIQUE,   -- e.g. LDY-20261009-001
  customer_id    INT NOT NULL,
  service_id     INT NOT NULL,
  weight_kg      DECIMAL(6,2) NOT NULL,
  load_type      ENUM('Light','Heavy') NOT NULL,
  loads          DECIMAL(5,2) NOT NULL,         -- snapshot of computed loads
  price_per_load DECIMAL(10,2) NOT NULL,        -- snapshot
  total_amount   DECIMAL(10,2) NOT NULL,        -- snapshot
  order_status   ENUM('Pending','In Progress','Ready for Pickup','Released','Cancelled')
                 NOT NULL DEFAULT 'Pending',
  payment_status ENUM('Unpaid','Paid') NOT NULL DEFAULT 'Unpaid',
  notes          VARCHAR(255) NULL,
  created_by     INT NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  promised_at    TIMESTAMP NULL,                -- turnaround promise (Ready by); Overdue = still Pending/In Progress after this
  released_at    TIMESTAMP NULL,
  FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
  FOREIGN KEY (service_id)  REFERENCES services(service_id),
  FOREIGN KEY (created_by)  REFERENCES users(user_id),
  CHECK (weight_kg > 0),
  CHECK (order_status <> 'Released' OR payment_status = 'Paid'),  -- payment gate backstop
  CHECK (order_status <> 'Cancelled' OR payment_status = 'Unpaid'),  -- a paid order can never be cancelled
  INDEX idx_order_status (order_status),
  INDEX idx_payment_status (payment_status),
  INDEX idx_created_at (created_at)
);

-- ------------------------------------------------------------
-- ORDER GARMENTS (mga garment type, ticked for an order)
-- ------------------------------------------------------------
CREATE TABLE order_garments (
  order_id   INT NOT NULL,
  garment_id INT NOT NULL,
  PRIMARY KEY (order_id, garment_id),
  FOREIGN KEY (order_id)   REFERENCES orders(order_id),
  FOREIGN KEY (garment_id) REFERENCES garment_types(garment_id)
);

-- ------------------------------------------------------------
-- ORDER STATUS HISTORY (feeds Order Tracking)
-- ------------------------------------------------------------
CREATE TABLE order_status_history (
  history_id  INT AUTO_INCREMENT PRIMARY KEY,
  order_id    INT NOT NULL,
  from_status VARCHAR(20) NULL,
  to_status   VARCHAR(20) NOT NULL,
  changed_by  INT NOT NULL,
  changed_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id)   REFERENCES orders(order_id),
  FOREIGN KEY (changed_by) REFERENCES users(user_id)
);

-- ------------------------------------------------------------
-- PAYMENTS (recorded at release; one full payment per order)
-- ------------------------------------------------------------
CREATE TABLE payments (
  payment_id      INT AUTO_INCREMENT PRIMARY KEY,
  order_id        INT NOT NULL UNIQUE,
  receipt_no      VARCHAR(20) NOT NULL UNIQUE,
  method          ENUM('Cash','GCash') NOT NULL,
  amount_due      DECIMAL(10,2) NOT NULL,
  amount_tendered DECIMAL(10,2) NOT NULL,
  change_given    DECIMAL(10,2) NOT NULL DEFAULT 0,
  reference_no    VARCHAR(50) NULL,             -- GCash reference
  received_by     INT NOT NULL,
  paid_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id)    REFERENCES orders(order_id),
  FOREIGN KEY (received_by) REFERENCES users(user_id)
);

-- ------------------------------------------------------------
-- INVENTORY MOVEMENTS (audit trail of stock changes)
-- ------------------------------------------------------------
CREATE TABLE inventory_movements (
  movement_id INT AUTO_INCREMENT PRIMARY KEY,
  item_id     INT NOT NULL,
  change_qty  DECIMAL(10,2) NOT NULL,           -- negative = used
  reason      ENUM('Restock','Order Usage','Adjustment') NOT NULL,
  order_id    INT NULL,
  created_by  INT NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (item_id)    REFERENCES inventory_items(item_id),
  FOREIGN KEY (order_id)   REFERENCES orders(order_id),
  FOREIGN KEY (created_by) REFERENCES users(user_id)
);

-- ------------------------------------------------------------
-- LOGIN ATTEMPTS (failed sign-ins; 5 wrong passwords pause that username on that computer for 15 minutes)
-- ------------------------------------------------------------
CREATE TABLE login_attempts (
  attempt_id   INT AUTO_INCREMENT PRIMARY KEY,
  username     VARCHAR(50) NOT NULL,
  ip_address   VARCHAR(45) NOT NULL,
  attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_attempt (username, ip_address, attempted_at)
);

-- ------------------------------------------------------------
-- CODE COUNTERS (last order / receipt number issued per day, so a deleted order never frees its number for reuse)
-- ------------------------------------------------------------
CREATE TABLE code_counters (
  prefix  VARCHAR(10) NOT NULL,          -- 'LDY' (orders) or 'OR' (receipts)
  day     CHAR(8)     NOT NULL,          -- YYYYMMDD
  last_n  INT         NOT NULL,
  PRIMARY KEY (prefix, day)
);

-- ------------------------------------------------------------
-- SMS LOG (one row per text sent to a customer, e.g. "your laundry is ready"; see includes/sms.php)
-- ------------------------------------------------------------
CREATE TABLE sms_log (
  sms_id      INT AUTO_INCREMENT PRIMARY KEY,
  order_id    INT NOT NULL,
  phone       VARCHAR(20)  NOT NULL,
  message     VARCHAR(320) NOT NULL,
  status      ENUM('Sent','Failed') NOT NULL,
  provider_id VARCHAR(40)  NULL,                -- message id returned by the SMS service
  error       VARCHAR(255) NULL,                -- why it failed
  sent_by     INT NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
  FOREIGN KEY (sent_by)  REFERENCES users(user_id),
  INDEX idx_sms_order (order_id, sms_id)
);

-- ------------------------------------------------------------
-- MACHINES + RESERVATIONS (strict FIFO waiting line in includes/machines.php)
-- Pending orders wait in line and hold NO machines. Start Process gives the oldest Pending order its full set
-- (up to 2 washers + 2 dryers, see MACHINES_PER_ORDER in includes/config.php) or nothing. The set is held while the
-- order is In Progress and given back (released_at) at Mark Ready or Cancel.
-- ------------------------------------------------------------
CREATE TABLE machines (
  machine_id   INT AUTO_INCREMENT PRIMARY KEY,
  machine_type ENUM('Washer','Dryer') NOT NULL,
  label        VARCHAR(30) NOT NULL UNIQUE,
  is_active    BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE order_machines (
  order_id    INT NOT NULL,
  machine_id  INT NOT NULL,
  reserved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  released_at TIMESTAMP NULL,
  PRIMARY KEY (order_id, machine_id),
  FOREIGN KEY (order_id)   REFERENCES orders(order_id),
  FOREIGN KEY (machine_id) REFERENCES machines(machine_id),
  INDEX idx_om_open (machine_id, released_at)
);

-- ------------------------------------------------------------
-- PAYMENTS ARE PERMANENT: no edits, no deletes (financial record)
-- ------------------------------------------------------------
DELIMITER $$
CREATE TRIGGER payments_no_update BEFORE UPDATE ON payments
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payments are permanent and cannot be edited.';
END$$
CREATE TRIGGER payments_no_delete BEFORE DELETE ON payments
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payments are permanent and cannot be deleted.';
END$$
DELIMITER ;

-- ============================================================
-- SEED DATA (catalog only; no invented business rules)
-- ============================================================
INSERT INTO inventory_items (name, unit) VALUES
('Detergent', 'sachet'),
('Fabric Conditioner', 'sachet');

INSERT INTO machines (machine_type, label) VALUES
('Washer','Washer 1'),('Washer','Washer 2'),('Washer','Washer 3'),('Washer','Washer 4'),('Washer','Washer 5'),('Washer','Washer 6'),
('Dryer','Dryer 1'),('Dryer','Dryer 2'),('Dryer','Dryer 3'),('Dryer','Dryer 4'),('Dryer','Dryer 5'),('Dryer','Dryer 6');

-- price_per_load holds the rate PER KILO (the column name is kept from the old per-load pricing). Sample rates - set your own in Services.
-- The shop's three services. Supplies are listed per load below; the system multiplies them by the order's loads.
INSERT INTO services (name, service_type, includes_fold, price_per_load) VALUES
('Wash and Dry',              'Standard', FALSE, 45.00),
('Wash, Dry, and Fold',       'Standard', TRUE,  50.00),
('Wash, Dry, Fold, and Iron', 'Standard', TRUE,  55.00);

-- item_id 1 = Detergent, 2 = Fabric Conditioner. Dose PER LOAD (sample values - adjust to the shop's real dosing).
INSERT INTO service_consumables (service_id, item_id, qty) VALUES
(1, 1, 1), (1, 2, 1),   -- Wash and Dry
(2, 1, 1), (2, 2, 1),   -- Wash, Dry, and Fold
(3, 1, 1), (3, 2, 1);   -- Wash, Dry, Fold, and Iron

-- Garment types for the New Order checkboxes (Admin can change these; Heavy ones make the bag count at 6 kg per load).
INSERT INTO garment_types (name, category, sort_order) VALUES
('T-shirts & polos',      'Regular', 1),
('Shorts & light pants',  'Regular', 2),
('Dresses & blouses',     'Regular', 3),
('Underwear & socks',     'Regular', 4),
('Small towels',          'Regular', 5),
('Jeans',                 'Heavy',   6),
('Jackets & hoodies',     'Heavy',   7),
('Bed sheets & blankets', 'Heavy',   8),
('Curtains',              'Heavy',   9);

-- ============================================================
-- SAMPLE DATA (carried over from the old laundry_db.sql, adapted to this schema)
-- Login passwords are stored as bcrypt hashes of the old ones:
--   janessa (Admin) = admin123 | mark (Admin) = admin1234 | maria (Staff) = cashier01 | bernard (Staff) = cashier02
-- ============================================================
INSERT INTO users (full_name, username, password_hash, role) VALUES
('Janessa Reyes',  'janessa', '$2y$10$Xst07Vb4tMtONxih6xpAXeOyRjo5nrfQEwsY8VRj.Xg2z.Y3RNDUq', 'Admin'),
('Mark Santos',    'mark',    '$2y$10$gjttH1dIermJtNxhseneXuKvPmZEwgG5d6uMutv/MWjVJ1OBqU2xO', 'Admin'),
('Maria Cruz',     'maria',   '$2y$10$RdtPYE1T6BqZkuGJE0L.L.qlSz7Y2Eh/3fkj/Es7C9nwOpBOpgM9S', 'Staff'),
('Bernard Lopez',  'bernard', '$2y$10$okAQ/ys76DDAO62NpeECK.gbQxCAjyDHBIwmxsW7sFC/ID/tKI/dq', 'Staff');

INSERT INTO customers (full_name, phone) VALUES
('Jamelle Casandra Rustria', '09912404141'),
('Jillian Tabanganay',       '09912404147'),
('Jewel Segundo',            '09912404144'),
('Catherine Matillano',      '09171234567'),
('Trisha Panganiban',        '09281234567');

-- Pickup-only, pay-at-pickup: every active order is Unpaid; Released orders are Paid.
-- Price = rate per kg x billed kg (minimum charge 3 kg), rounded to the peso. Loads (Regular 7 kg, Heavy 6 kg per load, rounded up) only plan machines.
-- Insert order = order_id 1..8. created_by 2 = Maria.
INSERT INTO orders
  (order_number, customer_id, service_id, weight_kg, load_type, loads, price_per_load, total_amount,
   order_status, payment_status, created_by, created_at, released_at) VALUES
('LDY-20261009-001', 1, 1,  5.00, 'Light', 1, 45.00, 225.00, 'In Progress',      'Unpaid', 2, '2026-10-09 09:15:00', NULL),
('LDY-20261009-002', 2, 2,  6.00, 'Heavy', 2, 50.00, 300.00, 'Pending',          'Unpaid', 2, '2026-10-09 09:40:00', NULL),
('LDY-20261009-003', 3, 2,  4.00, 'Light', 1, 50.00, 200.00, 'Ready for Pickup', 'Unpaid', 2, '2026-10-09 08:30:00', NULL),
('LDY-20261006-001', 4, 1,  5.00, 'Light', 1, 45.00, 225.00, 'Released',         'Paid',   2, '2026-10-06 10:00:00', '2026-10-07 16:20:00'),
('LDY-20261009-004', 5, 2,  6.00, 'Light', 1, 50.00, 300.00, 'Pending',          'Unpaid', 2, '2026-10-09 10:05:00', NULL),
('LDY-20261007-001', 1, 3, 15.00, 'Heavy', 3, 55.00, 825.00, 'Released',         'Paid',   2, '2026-10-07 11:00:00', '2026-10-08 15:45:00'),
('LDY-20261008-001', 2, 1, 12.00, 'Heavy', 2, 45.00, 540.00, 'Ready for Pickup', 'Unpaid', 3, '2026-10-08 13:10:00', NULL),
('LDY-20261005-001', 3, 2,  7.00, 'Light', 1, 50.00, 350.00, 'Released',         'Paid',   3, '2026-10-05 09:00:00', '2026-10-05 17:30:00');

-- Garment types ticked on the sample orders (garment_id 1 = T-shirts, 6 = Jeans, 8 = Bed sheets, 3 = Dresses)
INSERT INTO order_garments (order_id, garment_id) VALUES
(1, 1), (2, 1), (2, 6), (3, 1), (3, 3), (4, 1), (5, 1), (6, 1), (6, 6), (7, 6), (7, 8), (8, 1);

-- Status trail for each order (Pending -> In Progress -> Ready for Pickup -> Released)
INSERT INTO order_status_history (order_id, from_status, to_status, changed_by, changed_at) VALUES
(1, NULL,               'Pending',          2, '2026-10-09 09:15:00'),
(1, 'Pending',          'In Progress',      2, '2026-10-09 09:50:00'),
(2, NULL,               'Pending',          2, '2026-10-09 09:40:00'),
(3, NULL,               'Pending',          2, '2026-10-09 08:30:00'),
(3, 'Pending',          'In Progress',      2, '2026-10-09 08:45:00'),
(3, 'In Progress',      'Ready for Pickup', 2, '2026-10-09 10:30:00'),
(4, NULL,               'Pending',          2, '2026-10-06 10:00:00'),
(4, 'Pending',          'In Progress',      2, '2026-10-06 10:30:00'),
(4, 'In Progress',      'Ready for Pickup', 2, '2026-10-06 14:00:00'),
(4, 'Ready for Pickup', 'Released',         2, '2026-10-07 16:20:00'),
(5, NULL,               'Pending',          2, '2026-10-09 10:05:00'),
(6, NULL,               'Pending',          2, '2026-10-07 11:00:00'),
(6, 'Pending',          'In Progress',      2, '2026-10-07 11:30:00'),
(6, 'In Progress',      'Ready for Pickup', 2, '2026-10-07 17:00:00'),
(6, 'Ready for Pickup', 'Released',         2, '2026-10-08 15:45:00'),
(7, NULL,               'Pending',          3, '2026-10-08 13:10:00'),
(7, 'Pending',          'In Progress',      3, '2026-10-08 13:40:00'),
(7, 'In Progress',      'Ready for Pickup', 3, '2026-10-08 18:00:00'),
(8, NULL,               'Pending',          3, '2026-10-05 09:00:00'),
(8, 'Pending',          'In Progress',      3, '2026-10-05 09:20:00'),
(8, 'In Progress',      'Ready for Pickup', 3, '2026-10-05 13:00:00'),
(8, 'Ready for Pickup', 'Released',         3, '2026-10-05 17:30:00');

-- One full payment per released order, taken at release (receipt_no = OR-<paid date>-NNN)
INSERT INTO payments
  (order_id, receipt_no, method, amount_due, amount_tendered, change_given, reference_no, received_by, paid_at) VALUES
(4, 'OR-20261007-001', 'Cash',  225.00, 250.00, 25.00, NULL,           2, '2026-10-07 16:20:00'),
(6, 'OR-20261008-001', 'GCash', 825.00, 825.00,  0.00, 'GC1234567890', 2, '2026-10-08 15:45:00'),
(8, 'OR-20261005-001', 'Cash',  350.00, 400.00, 50.00, NULL,           3, '2026-10-05 17:30:00');

-- Turnaround promise: one round (180 min) per 2 loads, rounded up (same rule as includes/sla.php; the live system also
-- adds waiting rounds at intake when an order cannot start at once, the sample data has none)
UPDATE orders SET promised_at = DATE_ADD(created_at, INTERVAL (180 * GREATEST(CEIL(CEIL(loads) / 2), 1)) MINUTE);

-- Machine history. Only an In Progress order holds machines (an open row, released_at NULL);
-- Ready for Pickup orders have already given theirs back at Mark Ready, and Pending orders hold none.
INSERT INTO order_machines (order_id, machine_id, reserved_at, released_at) VALUES
(1, 1, '2026-10-09 09:50:00', NULL),                  (1, 7,  '2026-10-09 09:50:00', NULL),
(3, 3, '2026-10-09 08:45:00', '2026-10-09 10:30:00'), (3, 9,  '2026-10-09 08:45:00', '2026-10-09 10:30:00'),
(7, 5, '2026-10-08 13:40:00', '2026-10-08 18:00:00'), (7, 6,  '2026-10-08 13:40:00', '2026-10-08 18:00:00'),
(7, 11,'2026-10-08 13:40:00', '2026-10-08 18:00:00'), (7, 12, '2026-10-08 13:40:00', '2026-10-08 18:00:00');