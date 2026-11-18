-- =====================================================================
--  G-SMaRT Coop Cable System - complete database, CLEAN DATA (set-up, roles, one user per role, Super Admin)
--  Generated 2026-11-02 00:20:23 +08:00
--  Company database `gsmart_coop_cable`: 205 tables (147 accounting / cooperative, 58 cable / internet),
--  2,929 rows (0 ledger documents, 0 subscriber accounts)
--  Master database `gsmart_coop_cable_master` (Super Admin, companies, Company Codes): 10 tables, 32 rows
--
--  Import:  phpMyAdmin > Import > choose this file > Go
--      or:  mysql -u root -p < gsmart_coop_cable_clean.sql
--  Works on MySQL 5.5.3 or newer (5.5, 5.6, 5.7, 8.x) and MariaDB 5.5 or newer.
--  Import as root (or a user with CREATE, INSERT, REFERENCES and TRIGGER privileges).
--  WARNING: importing drops and recreates every table of the database(s) below.
--  Two databases: `gsmart_coop_cable` (Company 1) and `gsmart_coop_cable_master` (multi-company platform).
--  Keep storage/platform/multi_company.json in the program folder (it switches the platform on).
--  Super Admin: superadmin / SuperAdmin@2027 (a new password is asked at the first log-in)
--  Company users: admin / Admin@12345, the others Demo@12345 (each must choose a new password at the first log-in).
--  See docs/USER_ACCOUNTS.md for every account.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+08:00';

CREATE DATABASE IF NOT EXISTS `gsmart_coop_cable` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `gsmart_coop_cable`;

-- ---------------------------------------------------------------------
--  1. TABLES (database/schema.sql)
-- ---------------------------------------------------------------------
-- =====================================================================
--  G-SMaRT Coop Cable System - database schema
--  MySQL 5.5.3+ / 5.6 / 5.7 / 8.x and MariaDB 5.5+ (XAMPP / WAMP / Laragon)
--  MySQL 5.5 rules followed here: only TIMESTAMP columns default to
--  CURRENT_TIMESTAMP (one per table), indexed text columns stay within
--  767 bytes (utf8mb4), and foreign key columns match exactly.
-- =====================================================================


-- ---------------------------------------------------------------------
--  SYSTEM ADMINISTRATION AND SECURITY
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS settings;
CREATE TABLE settings (
  setting_key   VARCHAR(60)  NOT NULL PRIMARY KEY,
  setting_value TEXT         NULL,
  updated_at    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS users;
CREATE TABLE users (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username             VARCHAR(50)  NOT NULL,
  password_hash        VARCHAR(255) NOT NULL,
  full_name            VARCHAR(120) NOT NULL,
  email                VARCHAR(120) NULL,
  employee_no          VARCHAR(30)  NULL,
  pos_default_site_id  INT UNSIGNED NULL,                 -- the site this user's POS Register opens on
  pos_default_class_id INT UNSIGNED NULL,                 -- the class every new sale of theirs starts with
  coop_branch_id       INT UNSIGNED NULL,                 -- the cooperative branch the user works in (NULL = all)
  coop_cash_account_id INT UNSIGNED NULL,                 -- the teller's cash drawer account
  status               ENUM('active','inactive','locked') NOT NULL DEFAULT 'active',
  approval_status      VARCHAR(10)  NOT NULL DEFAULT 'approved',  -- approved / pending / rejected (joined with the Company Code)
  created_via          VARCHAR(12)  NOT NULL DEFAULT 'admin',     -- admin / registration / provisioning / setup / platform
  approved_by          INT UNSIGNED NULL,                         -- who approved or rejected the registration
  approved_at          DATETIME     NULL,
  rejected_reason      VARCHAR(255) NULL,
  is_platform          TINYINT(1)   NOT NULL DEFAULT 0,           -- the account a Super Admin works through inside the company
  must_change_password TINYINT(1)   NOT NULL DEFAULT 1,
  password_changed_at  DATETIME     NULL,
  failed_attempts      INT          NOT NULL DEFAULT 0,
  last_login_at        DATETIME     NULL,
  last_login_ip        VARCHAR(45)  NULL,
  created_by           INT UNSIGNED NULL,
  created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_users_username (username),
  KEY ix_users_approval (approval_status),
  KEY ix_users_pos_site (pos_default_site_id),
  KEY ix_users_pos_class (pos_default_class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS roles;
CREATE TABLE roles (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(60)  NOT NULL,
  description VARCHAR(255) NULL,
  is_admin    TINYINT(1)   NOT NULL DEFAULT 0,   -- full access to everything
  is_system   TINYINT(1)   NOT NULL DEFAULT 0,   -- standard role shipped with the system
  all_sites   TINYINT(1)   NOT NULL DEFAULT 1,   -- 0 = limited to role_sites
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL,                  -- set by the application (DB::update)
  coop_cash_account_id INT UNSIGNED NULL,         -- profile cash account: the cash of its tellers who have none of their own
  UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS user_roles;
CREATE TABLE user_roles (
  user_id INT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, role_id),
  CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS modules;
CREATE TABLE modules (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code       VARCHAR(20)  NOT NULL,
  name       VARCHAR(80)  NOT NULL,
  icon       VARCHAR(40)  NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_modules_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS pages;
CREATE TABLE pages (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  page_key     VARCHAR(60)  NOT NULL,
  module_id    INT UNSIGNED NOT NULL,
  name         VARCHAR(120) NOT NULL,
  page_type    ENUM('center','transaction','list','report','setup','admin') NOT NULL DEFAULT 'transaction',
  menu_group   VARCHAR(40)  NULL,
  sort_order   INT          NOT NULL DEFAULT 0,
  show_in_menu TINYINT(1)   NOT NULL DEFAULT 1,
  description  VARCHAR(255) NULL,
  UNIQUE KEY uq_pages_key (page_key),
  CONSTRAINT fk_pages_module FOREIGN KEY (module_id) REFERENCES modules(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Page access + action permissions per role (View = allowed)
DROP TABLE IF EXISTS role_page_access;
CREATE TABLE role_page_access (
  role_id     INT UNSIGNED NOT NULL,
  page_id     INT UNSIGNED NOT NULL,
  allowed     TINYINT(1)   NOT NULL DEFAULT 0,
  can_create  TINYINT(1)   NOT NULL DEFAULT 0,
  can_edit    TINYINT(1)   NOT NULL DEFAULT 0,
  can_delete  TINYINT(1)   NOT NULL DEFAULT 0,
  can_print   TINYINT(1)   NOT NULL DEFAULT 0,
  can_approve TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (role_id, page_id),
  CONSTRAINT fk_rpa_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rpa_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User-level overrides always take precedence over roles
DROP TABLE IF EXISTS user_page_overrides;
CREATE TABLE user_page_overrides (
  user_id     INT UNSIGNED NOT NULL,
  page_id     INT UNSIGNED NOT NULL,
  allowed     TINYINT(1)   NOT NULL DEFAULT 0,
  can_create  TINYINT(1)   NOT NULL DEFAULT 0,
  can_edit    TINYINT(1)   NOT NULL DEFAULT 0,
  can_delete  TINYINT(1)   NOT NULL DEFAULT 0,
  can_print   TINYINT(1)   NOT NULL DEFAULT 0,
  can_approve TINYINT(1)   NOT NULL DEFAULT 0,
  reason      VARCHAR(255) NULL,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, page_id),
  CONSTRAINT fk_upo_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_upo_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sensitive permissions set separately from the grid
--  view_costs, view_bank_balances, credit_limit_override, coa_changes,
--  closed_period_changes (0/1); max_discount_pct, max_price_override_pct,
--  max_amount (number, blank = unlimited)
DROP TABLE IF EXISTS role_special_permissions;
CREATE TABLE role_special_permissions (
  role_id    INT UNSIGNED NOT NULL,
  perm_key   VARCHAR(40)  NOT NULL,
  perm_value VARCHAR(40)  NULL,
  PRIMARY KEY (role_id, perm_key),
  CONSTRAINT fk_rsp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permanent, read-only audit trail (see triggers at the end of the file)
DROP TABLE IF EXISTS audit_trail;
CREATE TABLE audit_trail (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id     INT UNSIGNED NULL,
  username    VARCHAR(50)  NULL,
  user_roles  VARCHAR(255) NULL,
  ip_address  VARCHAR(45)  NULL,
  workstation VARCHAR(255) NULL,
  site_id     INT UNSIGNED NULL,
  terminal_id INT UNSIGNED NULL,
  module      VARCHAR(20)  NOT NULL,
  page_key    VARCHAR(60)  NULL,
  action      VARCHAR(30)  NOT NULL,
  record_type VARCHAR(40)  NULL,
  record_id   BIGINT       NULL,
  record_ref  VARCHAR(160) NULL,
  amount      DECIMAL(15,2) NULL,
  changes     LONGTEXT     NULL,   -- JSON [{field, old, new}]
  snapshot    LONGTEXT     NULL,   -- JSON copy of the record
  reason      VARCHAR(255) NULL,
  approved_by VARCHAR(50)  NULL,
  KEY ix_audit_date (created_at),
  KEY ix_audit_user (user_id, created_at),
  KEY ix_audit_record (record_type, record_id),
  KEY ix_audit_action (action),
  KEY ix_audit_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS memorized_reports;
CREATE TABLE memorized_reports (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  group_name  VARCHAR(60)  NULL,
  report_key  VARCHAR(60)  NOT NULL,
  query_string TEXT        NULL,
  is_shared   TINYINT(1)   NOT NULL DEFAULT 0,
  schedule    VARCHAR(10)  NULL,          -- daily, weekly, monthly (tools/send_scheduled_reports.php)
  email_to    VARCHAR(255) NULL,
  last_sent_at DATETIME    NULL,
  created_by  INT UNSIGNED NOT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mr_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS sequences;
CREATE TABLE sequences (
  seq_key VARCHAR(30) NOT NULL PRIMARY KEY,
  prefix  VARCHAR(10) NOT NULL DEFAULT '',
  next_no INT UNSIGNED NOT NULL DEFAULT 1,
  pad     TINYINT      NOT NULL DEFAULT 5
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  CHART OF ACCOUNTS AND LISTS
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS accounts;
CREATE TABLE accounts (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(20)  NOT NULL,
  name            VARCHAR(120) NOT NULL,
  account_type    ENUM('Bank','Accounts Receivable','Other Current Asset','Fixed Asset','Other Asset',
                       'Accounts Payable','Credit Card','Other Current Liability','Long Term Liability',
                       'Equity','Income','Other Income','Cost of Goods Sold','Expense','Other Expense') NOT NULL,
  type_id         INT UNSIGNED NULL,                    -- the account type chosen (account_types); NULL = the built-in type of account_type
  category        ENUM('Asset','Liability','Equity','Income','Expense') NOT NULL,
  normal_balance  ENUM('Debit','Credit') NOT NULL,
  parent_id       INT UNSIGNED NULL,
  description     VARCHAR(255) NULL,
  bank_account_no VARCHAR(40)  NULL,
  with_sl         TINYINT(1)   NOT NULL DEFAULT 0,    -- keeps a subsidiary ledger: every entry needs a name
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  is_system       TINYINT(1)   NOT NULL DEFAULT 0,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL,                  -- set by the application (DB::update)
  is_interunit    TINYINT(1)   NOT NULL DEFAULT 0,    -- due to / due from another business unit (eliminated when consolidating)
  UNIQUE KEY uq_accounts_code (code),
  KEY ix_acc_with_sl (with_sl),
  KEY ix_accounts_type (type_id),
  CONSTRAINT fk_accounts_parent FOREIGN KEY (parent_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Account types: the fifteen built-in ones (written by the 2027_01_24 migration) and the user's own, each behaving
-- as one built-in type (base_type, which accounts.account_type keeps) and linked to the Statement of Cash Flows
DROP TABLE IF EXISTS account_types;
CREATE TABLE account_types (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(60)  NOT NULL,
  base_type   ENUM('Bank','Accounts Receivable','Other Current Asset','Fixed Asset','Other Asset',
                   'Accounts Payable','Credit Card','Other Current Liability','Long Term Liability',
                   'Equity','Income','Other Income','Cost of Goods Sold','Expense','Other Expense') NOT NULL,
  cash_flow   VARCHAR(10)  NOT NULL DEFAULT '',     -- cash / operating / investing / financing ('' = income and expense)
  sort_order  INT          NOT NULL DEFAULT 0,
  description VARCHAR(255) NULL,
  is_system   TINYINT(1)   NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL,
  UNIQUE KEY uq_account_types_name (name),
  KEY ix_account_types_base (base_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Module default accounts (ar, ap, undeposited_funds, output_tax, ...)
DROP TABLE IF EXISTS default_accounts;
CREATE TABLE default_accounts (
  setting_key VARCHAR(40)  NOT NULL PRIMARY KEY,
  label       VARCHAR(120) NOT NULL,
  module      VARCHAR(20)  NOT NULL,
  account_id  INT UNSIGNED NULL,
  CONSTRAINT fk_da_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS terms;
CREATE TABLE terms (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(40)  NOT NULL,
  net_days      INT          NOT NULL DEFAULT 0,
  discount_pct  DECIMAL(5,2) NOT NULL DEFAULT 0,
  discount_days INT          NOT NULL DEFAULT 0,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS tax_codes;
CREATE TABLE tax_codes (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                VARCHAR(20)  NOT NULL,
  name                VARCHAR(80)  NOT NULL,
  rate                DECIMAL(6,3) NOT NULL DEFAULT 0,
  sales_account_id    INT UNSIGNED NULL,   -- Output Tax
  purchase_account_id INT UNSIGNED NULL,   -- Input Tax
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_tax_code (code),
  CONSTRAINT fk_tax_sales FOREIGN KEY (sales_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_tax_purch FOREIGN KEY (purchase_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS payment_methods;
CREATE TABLE payment_methods (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name                VARCHAR(40)  NOT NULL,
  method_type         ENUM('cash','check','card','ewallet','bank_transfer','voucher','other') NOT NULL DEFAULT 'other',
  clearing_account_id INT UNSIGNED NULL,   -- POS clearing account for card / e-wallet / voucher / bank
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  show_in_pos         TINYINT(1)   NOT NULL DEFAULT 1,   -- offered as a payment option at the POS register
  CONSTRAINT fk_pm_account FOREIGN KEY (clearing_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS price_levels;
CREATE TABLE price_levels (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(40)  NOT NULL,
  adjust_pct DECIMAL(6,2) NOT NULL DEFAULT 0,  -- e.g. -10 = 10% below standard price
  valid_from DATE         NULL,                -- a promotion: applies to documents dated within these dates
  valid_to   DATE         NULL,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS sales_reps;
CREATE TABLE sales_reps (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(80)  NOT NULL,
  initials  VARCHAR(10)  NULL,
  user_id   INT UNSIGNED NULL,
  commission_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1)   NOT NULL DEFAULT 1,
  CONSTRAINT fk_rep_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS customer_types;
CREATE TABLE customer_types (
  id   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS billing_groups;
CREATE TABLE billing_groups (
  id   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Class List: your own way of splitting the books (branches, departments, projects ...).
-- Every transaction can carry a class; the ledger keeps it, so financial reports can filter by it.
DROP TABLE IF EXISTS classes;
CREATE TABLE classes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(80)  NOT NULL,
  description VARCHAR(255) NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order  INT          NOT NULL DEFAULT 0,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_classes_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Employees and other names (payees and subsidiary ledgers that are neither customers nor vendors)
DROP TABLE IF EXISTS other_names;
CREATE TABLE other_names (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  party_id    INT UNSIGNED NULL,                     -- the Party Master record
  name_type   VARCHAR(10)  NOT NULL DEFAULT 'other',  -- employee, other
  name        VARCHAR(120) NOT NULL,
  employee_no VARCHAR(30)  NULL,
  member_id   INT UNSIGNED NULL,                     -- cooperative member this name stands for (name_type 'member')
  position    VARCHAR(80)  NULL,
  phone       VARCHAR(40)  NULL,
  address     VARCHAR(255) NULL,
  tin         VARCHAR(30)  NULL,
  notes       VARCHAR(255) NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL,                  -- set by the application (DB::update)
  KEY ix_other_names_name (name),
  KEY ix_other_names_member (member_id),
  KEY ix_other_names_party (party_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  CUSTOMERS AND VENDORS
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS customers;
CREATE TABLE customers (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  party_id            INT UNSIGNED NULL,          -- the Party Master record (a job takes its customer's)
  parent_id           INT UNSIGNED NULL,          -- job / project under a customer
  name                VARCHAR(120) NOT NULL,
  company_name        VARCHAR(120) NULL,
  contact_person      VARCHAR(120) NULL,
  phone               VARCHAR(40)  NULL,
  mobile              VARCHAR(40)  NULL,
  email               VARCHAR(120) NULL,
  billing_address     VARCHAR(255) NULL,
  shipping_address    VARCHAR(255) NULL,
  tin                 VARCHAR(30)  NULL,
  wht_ids             VARCHAR(100) NULL,          -- withholding taxes it usually withholds from its payments
  terms_id            INT UNSIGNED NULL,
  credit_limit        DECIMAL(15,2) NOT NULL DEFAULT 0,  -- 0 = no limit
  price_level_id      INT UNSIGNED NULL,
  tax_code_id         INT UNSIGNED NULL,
  payment_method_id   INT UNSIGNED NULL,
  customer_type_id    INT UNSIGNED NULL,
  sales_rep_id        INT UNSIGNED NULL,
  billing_group_id    INT UNSIGNED NULL,
  allow_pos_charge    TINYINT(1)   NOT NULL DEFAULT 0,  -- approved account customer at the POS
  notes               TEXT         NULL,
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NULL,                  -- set by the application (DB::update)
  KEY ix_customers_name (name),
  KEY ix_customers_party (party_id),
  CONSTRAINT fk_cust_parent FOREIGN KEY (parent_id) REFERENCES customers(id),
  CONSTRAINT fk_cust_terms  FOREIGN KEY (terms_id) REFERENCES terms(id),
  CONSTRAINT fk_cust_pl     FOREIGN KEY (price_level_id) REFERENCES price_levels(id),
  CONSTRAINT fk_cust_tax    FOREIGN KEY (tax_code_id) REFERENCES tax_codes(id),
  CONSTRAINT fk_cust_pm     FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id),
  CONSTRAINT fk_cust_type   FOREIGN KEY (customer_type_id) REFERENCES customer_types(id),
  CONSTRAINT fk_cust_rep    FOREIGN KEY (sales_rep_id) REFERENCES sales_reps(id),
  CONSTRAINT fk_cust_bg     FOREIGN KEY (billing_group_id) REFERENCES billing_groups(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS vendors;
CREATE TABLE vendors (
  id                         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  party_id                   INT UNSIGNED NULL,   -- the Party Master record
  name                       VARCHAR(120) NOT NULL,
  company_name               VARCHAR(120) NULL,
  contact_person             VARCHAR(120) NULL,
  phone                      VARCHAR(40)  NULL,
  fax                        VARCHAR(40)  NULL,
  email                      VARCHAR(120) NULL,
  address                    VARCHAR(255) NULL,
  tin                        VARCHAR(30)  NULL,
  terms_id                   INT UNSIGNED NULL,
  credit_limit               DECIMAL(15,2) NOT NULL DEFAULT 0,
  default_expense_account_id INT UNSIGNED NULL,
  tax_code_id                INT UNSIGNED NULL,
  account_no                 VARCHAR(40)  NULL,   -- our account number with the vendor
  wht_ids                    VARCHAR(100) NULL,   -- withholding taxes usually withheld from it (withholding_taxes ids, "1,3")
  notes                      TEXT         NULL,
  is_active                  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at                 TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                 DATETIME     NULL,                  -- set by the application (DB::update)
  KEY ix_vendors_name (name),
  KEY ix_vendors_party (party_id),
  CONSTRAINT fk_vend_terms FOREIGN KEY (terms_id) REFERENCES terms(id),
  CONSTRAINT fk_vend_exp   FOREIGN KEY (default_expense_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_vend_tax   FOREIGN KEY (tax_code_id) REFERENCES tax_codes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS entity_notes;
CREATE TABLE entity_notes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  entity_type ENUM('customer','vendor','item') NOT NULL,
  entity_id   INT UNSIGNED NOT NULL,
  note        TEXT         NOT NULL,
  is_todo     TINYINT(1)   NOT NULL DEFAULT 0,
  due_date    DATE         NULL,
  is_done     TINYINT(1)   NOT NULL DEFAULT 0,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_notes_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  INVENTORY
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS sites;
CREATE TABLE sites (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code      VARCHAR(20)  NOT NULL,
  name      VARCHAR(80)  NOT NULL,
  site_type ENUM('warehouse','store','stockroom','van','damaged','other') NOT NULL DEFAULT 'warehouse',
  address   VARCHAR(255) NULL,
  is_active TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_sites_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS item_categories;
CREATE TABLE item_categories (
  id   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Units of measure: a set holds the base unit (stock and costs are kept in it)
-- plus the larger or smaller units a document may be entered in
DROP TABLE IF EXISTS uom_sets;
CREATE TABLE uom_sets (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(60)  NOT NULL,
  uom_kind   VARCHAR(10)  NOT NULL DEFAULT 'count',  -- count, weight, volume, length, time, other
  base_unit  VARCHAR(20)  NOT NULL,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_uom_sets_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS uom_units;
CREATE TABLE uom_units (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  set_id              INT UNSIGNED NOT NULL,
  code                VARCHAR(20)  NOT NULL,
  name                VARCHAR(60)  NOT NULL,
  factor              DECIMAL(15,6) NOT NULL DEFAULT 1,  -- base units in one of this unit
  is_base             TINYINT(1)   NOT NULL DEFAULT 0,
  is_sales_default    TINYINT(1)   NOT NULL DEFAULT 0,
  is_purchase_default TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order          INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_uom_units (set_id, code),
  CONSTRAINT fk_uom_units_set FOREIGN KEY (set_id) REFERENCES uom_sets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS items;
CREATE TABLE items (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  item_type            ENUM('service','inventory','non_inventory','assembly','other_charge','subtotal','group','discount','payment') NOT NULL,
  code                 VARCHAR(40)  NOT NULL,
  barcode              VARCHAR(60)  NULL,
  name                 VARCHAR(120) NOT NULL,
  description          VARCHAR(255) NULL,
  purchase_description VARCHAR(255) NULL,
  category_id          INT UNSIGNED NULL,
  unit                 VARCHAR(20)  NULL,
  uom_set_id           INT UNSIGNED NULL,                  -- U/M set (blank = single unit)
  sales_uom_id         INT UNSIGNED NULL,                  -- unit offered first on sales forms
  purchase_uom_id      INT UNSIGNED NULL,                  -- unit offered first on purchase forms
  cost                 DECIMAL(15,4) NOT NULL DEFAULT 0,  -- standard purchase cost
  avg_cost             DECIMAL(15,4) NOT NULL DEFAULT 0,  -- weighted average cost
  sales_price          DECIMAL(15,4) NOT NULL DEFAULT 0,
  discount_pct         DECIMAL(6,2)  NOT NULL DEFAULT 0,  -- discount items: percent (0 = use sales_price as amount)
  income_account_id    INT UNSIGNED NULL,
  cogs_account_id      INT UNSIGNED NULL,
  asset_account_id     INT UNSIGNED NULL,
  expense_account_id   INT UNSIGNED NULL,
  tax_code_id          INT UNSIGNED NULL,
  preferred_vendor_id  INT UNSIGNED NULL,
  reorder_point        DECIMAL(15,4) NOT NULL DEFAULT 0,
  max_stock            DECIMAL(15,4) NOT NULL DEFAULT 0,
  build_point          DECIMAL(15,4) NOT NULL DEFAULT 0,
  is_active            TINYINT(1)   NOT NULL DEFAULT 1,
  created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME     NULL,                  -- set by the application (DB::update)
  patronage_eligible   TINYINT(1)   NOT NULL DEFAULT 0,    -- member purchases or deliveries of it count for patronage refund
  UNIQUE KEY uq_items_code (code),
  KEY ix_items_barcode (barcode),
  CONSTRAINT fk_items_cat    FOREIGN KEY (category_id) REFERENCES item_categories(id),
  CONSTRAINT fk_items_inc    FOREIGN KEY (income_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_items_cogs   FOREIGN KEY (cogs_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_items_asset  FOREIGN KEY (asset_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_items_exp    FOREIGN KEY (expense_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_items_tax    FOREIGN KEY (tax_code_id) REFERENCES tax_codes(id),
  CONSTRAINT fk_items_vendor FOREIGN KEY (preferred_vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_items_uom    FOREIGN KEY (uom_set_id) REFERENCES uom_sets(id),
  CONSTRAINT fk_items_suom   FOREIGN KEY (sales_uom_id) REFERENCES uom_units(id),
  CONSTRAINT fk_items_puom   FOREIGN KEY (purchase_uom_id) REFERENCES uom_units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bill of materials for assemblies, and members of group items
DROP TABLE IF EXISTS item_components;
CREATE TABLE item_components (
  parent_item_id    INT UNSIGNED NOT NULL,
  component_item_id INT UNSIGNED NOT NULL,
  qty               DECIMAL(15,4) NOT NULL DEFAULT 1,
  PRIMARY KEY (parent_item_id, component_item_id),
  CONSTRAINT fk_ic_parent FOREIGN KEY (parent_item_id) REFERENCES items(id) ON DELETE CASCADE,
  CONSTRAINT fk_ic_comp   FOREIGN KEY (component_item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Processing formulas (multi-output bills of materials) for disassembly,
-- de-kitting / break-bulk and co-product / by-product processing
DROP TABLE IF EXISTS process_formulas;
CREATE TABLE process_formulas (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(30)  NOT NULL,
  name         VARCHAR(120) NOT NULL,
  process_type VARCHAR(20)  NOT NULL DEFAULT 'co_product',  -- disassembly, dekit, co_product
  alloc_method VARCHAR(20)  NOT NULL DEFAULT 'sales_value', -- sales_value, standard_cost, quantity, weight, percent, average_cost, manual
  site_id      INT UNSIGNED NULL,                           -- usual processing site
  description  VARCHAR(255) NULL,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NULL,                           -- set by the application (DB::update)
  UNIQUE KEY uq_pf_code (code),
  CONSTRAINT fk_pf_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Formula lines, quantities per batch. input = consumed, output = produced, cost = labor / overhead absorbed
DROP TABLE IF EXISTS process_formula_lines;
CREATE TABLE process_formula_lines (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  formula_id  INT UNSIGNED NOT NULL,
  line_no     INT          NOT NULL DEFAULT 0,
  line_type   VARCHAR(10)  NOT NULL,                  -- input, output, cost
  item_id     INT UNSIGNED NULL,
  account_id  INT UNSIGNED NULL,                      -- cost lines: account credited
  description VARCHAR(255) NULL,
  qty         DECIMAL(15,4) NOT NULL DEFAULT 0,
  output_role VARCHAR(12)  NULL,                      -- co_product, by_product, scrap
  alloc_basis DECIMAL(15,4) NOT NULL DEFAULT 0,       -- price / weight / percent per unit, by-product value per unit, manual amount per batch
  amount      DECIMAL(15,2) NOT NULL DEFAULT 0,       -- cost lines: amount per batch
  KEY ix_pfl_formula (formula_id),
  KEY ix_pfl_item (item_id),
  CONSTRAINT fk_pfl_formula FOREIGN KEY (formula_id) REFERENCES process_formulas(id) ON DELETE CASCADE,
  CONSTRAINT fk_pfl_item    FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT fk_pfl_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS price_level_items;
CREATE TABLE price_level_items (
  price_level_id INT UNSIGNED NOT NULL,
  item_id        INT UNSIGNED NOT NULL,
  custom_price   DECIMAL(15,4) NOT NULL,
  PRIMARY KEY (price_level_id, item_id),
  CONSTRAINT fk_pli_level FOREIGN KEY (price_level_id) REFERENCES price_levels(id) ON DELETE CASCADE,
  CONSTRAINT fk_pli_item  FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS item_site_stock;
CREATE TABLE item_site_stock (
  item_id     INT UNSIGNED NOT NULL,
  site_id     INT UNSIGNED NOT NULL,
  qty_on_hand DECIMAL(15,4) NOT NULL DEFAULT 0,
  PRIMARY KEY (item_id, site_id),
  CONSTRAINT fk_iss_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
  CONSTRAINT fk_iss_site FOREIGN KEY (site_id) REFERENCES sites(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  TRANSACTIONS (all modules)
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS transactions;
CREATE TABLE transactions (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_type           VARCHAR(30)  NOT NULL,
  -- estimate, sales_order, invoice, sales_receipt, statement_charge, finance_charge,
  -- payment, credit_memo, refund, purchase_order, bill, vendor_credit, item_receipt,
  -- bill_payment, check, deposit, transfer, journal, inventory_adjustment,
  -- inventory_transfer, build_assembly, pos_sale, pos_return, depreciation,
  -- asset_disposal, bank_charge, bank_interest, item_processing, cash_voucher, vendor_advance
  txn_no             VARCHAR(30)  NULL,
  txn_date           DATE         NOT NULL,
  due_date           DATE         NULL,
  ship_date          DATE         NULL,
  ref_no             VARCHAR(60)  NULL,
  customer_id        INT UNSIGNED NULL,
  vendor_id          INT UNSIGNED NULL,
  payee_name         VARCHAR(120) NULL,
  account_id         INT UNSIGNED NULL,     -- AR / AP / bank / deposit-to / from account
  account2_id        INT UNSIGNED NULL,     -- transfer-to / cash-back / adjustment account
  site_id            INT UNSIGNED NULL,
  site2_id           INT UNSIGNED NULL,     -- transfer-to site
  terms_id           INT UNSIGNED NULL,
  sales_rep_id       INT UNSIGNED NULL,
  payment_method_id  INT UNSIGNED NULL,
  billing_address    VARCHAR(255) NULL,
  shipping_address   VARCHAR(255) NULL,
  memo               VARCHAR(255) NULL,
  customer_message   VARCHAR(255) NULL,
  subtotal           DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_total          DECIMAL(15,2) NOT NULL DEFAULT 0,
  discount_amount    DECIMAL(15,2) NOT NULL DEFAULT 0,
  withholding_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  cash_back_amount   DECIMAL(15,2) NOT NULL DEFAULT 0,
  total              DECIMAL(15,2) NOT NULL DEFAULT 0,
  status             VARCHAR(20)  NOT NULL DEFAULT 'open',
  -- open, closed, paid, partial, pending, accepted, suspended, completed, void
  is_pending         TINYINT(1)   NOT NULL DEFAULT 0,
  is_voided          TINYINT(1)   NOT NULL DEFAULT 0,
  void_reason        VARCHAR(255) NULL,
  voided_by          INT UNSIGNED NULL,
  voided_at          DATETIME     NULL,
  to_be_printed      TINYINT(1)   NOT NULL DEFAULT 0,
  is_printed         TINYINT(1)   NOT NULL DEFAULT 0,
  restock            TINYINT(1)   NOT NULL DEFAULT 1,     -- credit memos / POS returns
  credit_disposition VARCHAR(10)  NULL,                   -- keep, apply, refund
  progress_pct       DECIMAL(6,2) NULL,                   -- progress invoicing
  source_txn_id      BIGINT UNSIGNED NULL,                -- converted from / refund of
  terminal_id        INT UNSIGNED NULL,
  pos_shift_id       INT UNSIGNED NULL,
  receipt_no         VARCHAR(30)  NULL,
  amount_tendered    DECIMAL(15,2) NOT NULL DEFAULT 0,
  change_given       DECIMAL(15,2) NOT NULL DEFAULT 0,
  approved_by        INT UNSIGNED NULL,
  batch_ref          VARCHAR(40)  NULL,
  process_type       VARCHAR(20)  NULL,                   -- item processing: disassembly, dekit, co_product
  alloc_method       VARCHAR(20)  NULL,                   -- item processing: cost allocation method
  process_formula_id INT UNSIGNED NULL,                   -- item processing: formula used
  process_batches    DECIMAL(15,4) NULL,                  -- item processing: formula batches run
  class_id           INT UNSIGNED NULL,                   -- class of the whole transaction (lines may override it)
  other_name_id      INT UNSIGNED NULL,                   -- payee from Employees & Other Names
  party_id           INT UNSIGNED NULL,                   -- the Party Master record of the counterparty
  business_unit_id   INT UNSIGNED NULL,                   -- line of business (business_units)
  cp_role            VARCHAR(10)  NULL,                   -- counterparty snapshot: customer / supplier / payee / payer / name
  cp_name            VARCHAR(160) NULL,                   -- name printed on the document, taken when it was issued
  cp_address         VARCHAR(255) NULL,
  cp_tin             VARCHAR(30)  NULL,
  cp_member_no       VARCHAR(30)  NULL,
  cp_source          VARCHAR(8)   NULL,                   -- save / upgrade / reissue
  cp_issued_at       DATETIME     NULL,
  cp_reissue_no      INT          NOT NULL DEFAULT 0,     -- authorized corrections of the printed name
  created_by         INT UNSIGNED NULL,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by         INT UNSIGNED NULL,
  updated_at         DATETIME     NULL,                  -- set by the application (DB::update)
  KEY ix_txn_type_date (txn_type, txn_date),
  KEY ix_txn_customer (customer_id, txn_date),
  KEY ix_txn_vendor (vendor_id, txn_date),
  KEY ix_txn_status (status),
  KEY ix_txn_source (source_txn_id),
  KEY ix_txn_shift (pos_shift_id),
  KEY ix_txn_formula (process_formula_id),
  KEY ix_txn_class (class_id),
  KEY ix_txn_other_name (other_name_id),
  KEY ix_transactions_party (party_id, txn_date),
  KEY ix_transactions_bu (business_unit_id),
  CONSTRAINT fk_txn_class    FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_txn_other_name FOREIGN KEY (other_name_id) REFERENCES other_names(id),
  CONSTRAINT fk_txn_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_txn_vendor   FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_txn_account  FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_txn_formula  FOREIGN KEY (process_formula_id) REFERENCES process_formulas(id) ON DELETE SET NULL,
  CONSTRAINT fk_txn_account2 FOREIGN KEY (account2_id) REFERENCES accounts(id),
  CONSTRAINT fk_txn_site     FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_txn_site2    FOREIGN KEY (site2_id) REFERENCES sites(id),
  CONSTRAINT fk_txn_terms    FOREIGN KEY (terms_id) REFERENCES terms(id),
  CONSTRAINT fk_txn_rep      FOREIGN KEY (sales_rep_id) REFERENCES sales_reps(id),
  CONSTRAINT fk_txn_pm       FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS transaction_lines;
CREATE TABLE transaction_lines (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id          BIGINT UNSIGNED NOT NULL,
  line_no         INT          NOT NULL DEFAULT 0,
  line_kind       VARCHAR(10)  NOT NULL DEFAULT 'item',   -- item, expense, journal, deposit, component, input, output, cost
  item_id         INT UNSIGNED NULL,
  account_id      INT UNSIGNED NULL,
  description     VARCHAR(255) NULL,
  qty             DECIMAL(15,4) NOT NULL DEFAULT 0,
  unit            VARCHAR(20)  NULL,
  uom_id          INT UNSIGNED NULL,                      -- unit the line was entered in
  uom_factor      DECIMAL(15,6) NOT NULL DEFAULT 1,       -- base units in one entered unit
  uom_qty         DECIMAL(15,4) NOT NULL DEFAULT 0,       -- quantity as entered
  uom_rate        DECIMAL(15,4) NOT NULL DEFAULT 0,       -- price as entered
  rate            DECIMAL(15,4) NOT NULL DEFAULT 0,
  discount_kind   VARCHAR(3)    NOT NULL DEFAULT 'pct',   -- pct = percent of the line, amt = amount off
  discount_pct    DECIMAL(6,2)  NOT NULL DEFAULT 0,
  discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  amount          DECIMAL(15,2) NOT NULL DEFAULT 0,       -- journal: + debit / - credit
  tax_code_id     INT UNSIGNED NULL,
  tax_amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
  site_id         INT UNSIGNED NULL,
  customer_id     INT UNSIGNED NULL,                      -- customer:job for billable costs / journal
  vendor_id       INT UNSIGNED NULL,
  is_billable     TINYINT(1)   NOT NULL DEFAULT 0,
  billed_txn_id   BIGINT UNSIGNED NULL,                   -- invoice that billed this cost
  ref_txn_id      BIGINT UNSIGNED NULL,                   -- deposit: payment / receipt being deposited
  pos_shift_id    INT UNSIGNED NULL,                      -- deposit: the POS shift whose cash the line deposits
  source_line_id  BIGINT UNSIGNED NULL,                   -- estimate / SO / PO line
  qty_fulfilled   DECIMAL(15,4) NOT NULL DEFAULT 0,       -- SO invoiced / PO received
  amount_fulfilled DECIMAL(15,2) NOT NULL DEFAULT 0,      -- estimate progress-invoiced
  is_closed       TINYINT(1)   NOT NULL DEFAULT 0,
  cost_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,       -- COGS captured at posting
  value_change    DECIMAL(15,2) NOT NULL DEFAULT 0,       -- inventory value adjustment
  output_role     VARCHAR(12)  NULL,                      -- item processing output: co_product, by_product, scrap
  alloc_basis     DECIMAL(15,4) NOT NULL DEFAULT 0,       -- item processing: price / weight / percent / value entered
  alloc_pct       DECIMAL(9,4)  NOT NULL DEFAULT 0,       -- item processing: share of the total cost
  planned_qty     DECIMAL(15,4) NULL,                     -- item processing: quantity expected by the formula
  class_id        INT UNSIGNED NULL,                      -- class of this line (blank = the transaction's class)
  other_name_id   INT UNSIGNED NULL,                      -- subsidiary ledger: employee / other name
  KEY ix_tl_txn (txn_id),
  KEY ix_tl_item (item_id),
  KEY ix_tl_ref (ref_txn_id),
  KEY ix_tl_shift (pos_shift_id),
  KEY ix_tl_source (source_line_id),
  KEY ix_tl_class (class_id),
  KEY ix_tl_other_name (other_name_id),
  CONSTRAINT fk_tl_class   FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_tl_other_name FOREIGN KEY (other_name_id) REFERENCES other_names(id),
  CONSTRAINT fk_tl_txn     FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_tl_item    FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT fk_tl_account FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_tl_tax     FOREIGN KEY (tax_code_id) REFERENCES tax_codes(id),
  CONSTRAINT fk_tl_site    FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_tl_cust    FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_tl_uom     FOREIGN KEY (uom_id) REFERENCES uom_units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payments / credits applied to invoices and bills
DROP TABLE IF EXISTS payment_applications;
CREATE TABLE payment_applications (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payment_txn_id BIGINT UNSIGNED NOT NULL,   -- payment, credit memo, bill payment, vendor credit
  target_txn_id  BIGINT UNSIGNED NOT NULL,   -- invoice, statement/finance charge, bill
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  discount       DECIMAL(15,2) NOT NULL DEFAULT 0,
  withholding    DECIMAL(15,2) NOT NULL DEFAULT 0,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_pa_payment (payment_txn_id),
  KEY ix_pa_target (target_txn_id),
  CONSTRAINT fk_pa_payment FOREIGN KEY (payment_txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_pa_target  FOREIGN KEY (target_txn_id) REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Withholding Tax Table (BIR Reports): the taxes a payment can have withheld
DROP TABLE IF EXISTS withholding_taxes;
CREATE TABLE withholding_taxes (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                  VARCHAR(20)  NOT NULL,
  atc                   VARCHAR(10)  NULL,                   -- BIR alphanumeric tax code (WC158, WC160, WV010 ...)
  name                  VARCHAR(100) NOT NULL,
  tax_kind              ENUM('income','vat') NOT NULL DEFAULT 'income',   -- expanded withholding of income tax, or VAT / business tax withheld
  rate                  DECIMAL(9,4) NOT NULL DEFAULT 0,     -- percent
  base_type             ENUM('net_of_vat','gross') NOT NULL DEFAULT 'net_of_vat',
  applies_to            ENUM('purchases','sales','both') NOT NULL DEFAULT 'both',
  payable_account_id    INT UNSIGNED NULL,                   -- withheld from suppliers (blank = module default)
  receivable_account_id INT UNSIGNED NULL,                   -- withheld by customers (blank = module default)
  description           VARCHAR(255) NULL,
  sort_order            INT          NOT NULL DEFAULT 0,
  is_active             TINYINT(1)   NOT NULL DEFAULT 1,
  created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME     NULL,
  UNIQUE KEY uq_wht_code (code),
  CONSTRAINT fk_wht_payable FOREIGN KEY (payable_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_wht_receivable FOREIGN KEY (receivable_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Taxes withheld by a payment (bill payment or customer payment), by document paid and type: BIR Form 2307 and the BIR Reports
DROP TABLE IF EXISTS payment_withholdings;
CREATE TABLE payment_withholdings (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payment_txn_id BIGINT UNSIGNED NOT NULL,
  target_txn_id  BIGINT UNSIGNED NULL,        -- the bill / invoice it was withheld on
  wht_id         INT UNSIGNED NOT NULL,
  tax_kind       ENUM('income','vat') NOT NULL DEFAULT 'income',
  atc            VARCHAR(10)  NULL,
  rate           DECIMAL(9,4) NOT NULL DEFAULT 0,
  base           DECIMAL(15,2) NOT NULL DEFAULT 0,
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  account_id     INT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_pw_payment (payment_txn_id),
  KEY ix_pw_target (target_txn_id),
  KEY ix_pw_wht (wht_id),
  CONSTRAINT fk_pw_payment FOREIGN KEY (payment_txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_pw_target  FOREIGN KEY (target_txn_id) REFERENCES transactions(id) ON DELETE SET NULL,
  CONSTRAINT fk_pw_wht     FOREIGN KEY (wht_id) REFERENCES withholding_taxes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- General ledger (generated automatically by the posting engine)
DROP TABLE IF EXISTS journal_lines;
CREATE TABLE journal_lines (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id            BIGINT UNSIGNED NOT NULL,
  txn_date          DATE         NOT NULL,
  account_id        INT UNSIGNED NOT NULL,
  debit             DECIMAL(15,2) NOT NULL DEFAULT 0,
  credit            DECIMAL(15,2) NOT NULL DEFAULT 0,
  customer_id       INT UNSIGNED NULL,
  vendor_id         INT UNSIGNED NULL,
  item_id           INT UNSIGNED NULL,
  site_id           INT UNSIGNED NULL,
  class_id          INT UNSIGNED NULL,
  business_unit_id  INT UNSIGNED NULL,     -- line of business, copied from the document
  other_name_id     INT UNSIGNED NULL,
  memo              VARCHAR(255) NULL,
  cleared           TINYINT(1)   NOT NULL DEFAULT 0,
  reconciliation_id INT UNSIGNED NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_jl_account_date (account_id, txn_date),
  KEY ix_jl_txn (txn_id),
  KEY ix_jl_customer (customer_id),
  KEY ix_jl_vendor (vendor_id),
  KEY ix_jl_class (class_id, txn_date),
  KEY ix_jl_other_name (other_name_id),
  KEY ix_journal_lines_bu (business_unit_id, account_id),
  CONSTRAINT fk_jl_class   FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_jl_other_name FOREIGN KEY (other_name_id) REFERENCES other_names(id),
  CONSTRAINT fk_jl_txn     FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_jl_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Changes to saved journal entries: made only when a second, authorized user approves them, with the explanation
-- of the change, the entry as it was and as proposed (Chart of Accounts > Journal Entry Changes)
DROP TABLE IF EXISTS journal_edit_requests;
CREATE TABLE journal_edit_requests (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id           BIGINT UNSIGNED NOT NULL,            -- the journal entry (transactions.id)
  status           VARCHAR(12)  NOT NULL DEFAULT 'pending',  -- pending, approved, rejected, withdrawn, superseded
  explanation      VARCHAR(1000) NOT NULL,              -- why the entry is changed (required)
  before_json      MEDIUMTEXT   NOT NULL,               -- the entry as it was when the change was asked
  proposed_json    MEDIUMTEXT   NOT NULL,               -- the entry as proposed
  before_hash      CHAR(32)     NOT NULL,               -- refuses the approval when the entry changed meanwhile
  requested_by     INT UNSIGNED NOT NULL,
  requested_at     DATETIME     NOT NULL,
  decided_by       INT UNSIGNED NULL,
  decided_at       DATETIME     NULL,
  decision_remarks VARCHAR(255) NULL,
  KEY ix_jer_txn (txn_id, status),
  KEY ix_jer_status (status, id),
  KEY ix_jer_requester (requested_by, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Perpetual inventory movements (generated automatically)
DROP TABLE IF EXISTS inventory_movements;
CREATE TABLE inventory_movements (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id      BIGINT UNSIGNED NOT NULL,
  txn_line_id BIGINT UNSIGNED NULL,
  txn_date    DATE         NOT NULL,
  item_id     INT UNSIGNED NOT NULL,
  site_id     INT UNSIGNED NOT NULL,
  qty         DECIMAL(15,4) NOT NULL DEFAULT 0,
  unit_cost   DECIMAL(15,4) NOT NULL DEFAULT 0,
  total_cost  DECIMAL(15,2) NOT NULL DEFAULT 0,
  qty_after   DECIMAL(15,4) NOT NULL DEFAULT 0,    -- total on hand (all sites) after the movement
  avg_after   DECIMAL(15,4) NOT NULL DEFAULT 0,    -- average cost after the movement
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_im_item_date (item_id, txn_date),
  KEY ix_im_txn (txn_id),
  CONSTRAINT fk_im_txn  FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_im_item FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT fk_im_site FOREIGN KEY (site_id) REFERENCES sites(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  VENDORS - RECEIPT MANAGEMENT
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS receipt_documents;
CREATE TABLE receipt_documents (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  file_path     VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NULL,
  mime_type     VARCHAR(80)  NULL,
  source        ENUM('upload','email','mobile') NOT NULL DEFAULT 'upload',
  vendor_id     INT UNSIGNED NULL,
  receipt_date  DATE         NULL,
  amount        DECIMAL(15,2) NULL,
  account_id    INT UNSIGNED NULL,
  memo          VARCHAR(255) NULL,
  status        ENUM('for_review','matched','created','rejected') NOT NULL DEFAULT 'for_review',
  linked_txn_id BIGINT UNSIGNED NULL,
  uploaded_by   INT UNSIGNED NULL,
  uploaded_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by   INT UNSIGNED NULL,
  reviewed_at   DATETIME     NULL,
  CONSTRAINT fk_rd_vendor  FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_rd_account FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_rd_txn     FOREIGN KEY (linked_txn_id) REFERENCES transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  FIXED ASSETS
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS asset_categories;
CREATE TABLE asset_categories (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name                   VARCHAR(80)  NOT NULL,
  asset_account_id       INT UNSIGNED NOT NULL,
  accum_dep_account_id   INT UNSIGNED NOT NULL,
  dep_expense_account_id INT UNSIGNED NOT NULL,
  default_method         ENUM('straight_line','declining_balance','units_of_production','syd') NOT NULL DEFAULT 'straight_line',
  default_life_years     DECIMAL(5,2) NOT NULL DEFAULT 5,
  is_active              TINYINT(1)   NOT NULL DEFAULT 1,
  CONSTRAINT fk_ac_asset FOREIGN KEY (asset_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_ac_accum FOREIGN KEY (accum_dep_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_ac_exp   FOREIGN KEY (dep_expense_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_locations;
CREATE TABLE asset_locations (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL,
  UNIQUE KEY uq_asset_locations_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS fixed_assets;
CREATE TABLE fixed_assets (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  asset_no            VARCHAR(30)  NOT NULL,
  description         VARCHAR(160) NOT NULL,
  category_id         INT UNSIGNED NOT NULL,
  location            VARCHAR(120) NULL,
  location_id         INT UNSIGNED NULL,
  site_id             INT UNSIGNED NULL,
  class_id            INT UNSIGNED NULL,      -- default class (depreciation entry when no class is chosen)
  serial_no           VARCHAR(60)  NULL,
  vendor_id           INT UNSIGNED NULL,
  acquisition_date    DATE         NOT NULL,
  cost                DECIMAL(15,2) NOT NULL,
  salvage_value       DECIMAL(15,2) NOT NULL DEFAULT 0,
  useful_life_years   DECIMAL(5,2) NOT NULL,
  method              ENUM('straight_line','declining_balance','units_of_production','syd') NOT NULL,
  db_multiplier       DECIMAL(4,2) NOT NULL DEFAULT 2.00,
  total_units         DECIMAL(15,2) NULL,      -- units of production
  unit_label          VARCHAR(30)  NULL,
  -- change in estimate: new basis applied from basis_date forward
  basis_date          DATE         NULL,
  basis_nbv           DECIMAL(15,2) NULL,
  basis_life_months   INT          NULL,
  -- existing assets: depreciation recorded before the system was used
  opening_accum_dep   DECIMAL(15,2) NOT NULL DEFAULT 0,
  opening_through     DATE         NULL,
  acquisition_txn_id  BIGINT UNSIGNED NULL,
  status              ENUM('active','fully_depreciated','disposed') NOT NULL DEFAULT 'active',
  disposal_date       DATE         NULL,
  disposal_proceeds   DECIMAL(15,2) NULL,
  disposal_txn_id     BIGINT UNSIGNED NULL,
  notes               TEXT         NULL,
  current_meter       DECIMAL(15,2) NULL,     -- odometer / running hours
  meter_unit          VARCHAR(20)  NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_fa_no (asset_no),
  KEY ix_fa_location (location_id),
  KEY ix_fa_class (class_id),
  CONSTRAINT fk_fa_location FOREIGN KEY (location_id) REFERENCES asset_locations(id),
  CONSTRAINT fk_fa_class  FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_fa_cat    FOREIGN KEY (category_id) REFERENCES asset_categories(id),
  CONSTRAINT fk_fa_site   FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_fa_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_fa_acq    FOREIGN KEY (acquisition_txn_id) REFERENCES transactions(id) ON DELETE SET NULL,
  CONSTRAINT fk_fa_disp   FOREIGN KEY (disposal_txn_id) REFERENCES transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_depreciation;
CREATE TABLE asset_depreciation (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  asset_id     INT UNSIGNED NOT NULL,
  period_year  SMALLINT     NOT NULL,
  period_month TINYINT      NOT NULL,
  units_used   DECIMAL(15,2) NULL,
  amount       DECIMAL(15,2) NOT NULL,
  txn_id       BIGINT UNSIGNED NOT NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ad_period (asset_id, period_year, period_month),
  CONSTRAINT fk_ad_asset FOREIGN KEY (asset_id) REFERENCES fixed_assets(id) ON DELETE CASCADE,
  CONSTRAINT fk_ad_txn   FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_meter_readings;
CREATE TABLE asset_meter_readings (
  asset_id     INT UNSIGNED NOT NULL,
  period_year  SMALLINT     NOT NULL,
  period_month TINYINT      NOT NULL,
  units        DECIMAL(15,2) NOT NULL,
  entered_by   INT UNSIGNED NULL,
  entered_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (asset_id, period_year, period_month),
  CONSTRAINT fk_amr_asset FOREIGN KEY (asset_id) REFERENCES fixed_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fixed asset maintenance and preventive maintenance
DROP TABLE IF EXISTS asset_pm_schedules;
CREATE TABLE asset_pm_schedules (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  asset_id         INT UNSIGNED NOT NULL,
  task_name        VARCHAR(160) NOT NULL,
  task_type        VARCHAR(30)  NOT NULL DEFAULT 'other',  -- registration, change_oil, cleaning, inspection, insurance, other
  interval_value   INT          NULL,                      -- repeat every N ... (NULL = one time only)
  interval_unit    VARCHAR(10)  NULL,                      -- days, weeks, months, years
  meter_interval   DECIMAL(15,2) NULL,                     -- or every N km / hours
  next_due_date    DATE         NULL,
  next_due_meter   DECIMAL(15,2) NULL,
  remind_days      INT          NOT NULL DEFAULT 14,       -- start reminding this many days before the due date
  last_done_date   DATE         NULL,
  last_done_meter  DECIMAL(15,2) NULL,
  assigned_to      VARCHAR(120) NULL,
  notes            VARCHAR(255) NULL,
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NULL,
  KEY ix_pm_asset (asset_id),
  KEY ix_pm_due (is_active, next_due_date),
  CONSTRAINT fk_pm_asset FOREIGN KEY (asset_id) REFERENCES fixed_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_maintenance;
CREATE TABLE asset_maintenance (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  maint_no            VARCHAR(30)  NOT NULL,
  asset_id            INT UNSIGNED NOT NULL,
  maint_date          DATE         NOT NULL,
  maint_type          VARCHAR(20)  NOT NULL DEFAULT 'repair',     -- repair, preventive, inspection, upgrade
  status              VARCHAR(20)  NOT NULL DEFAULT 'completed',  -- in_progress, completed, void
  problem             VARCHAR(255) NULL,                          -- what was reported / why
  description         TEXT         NULL,                          -- maintenance description: the work done
  vendor_id           INT UNSIGNED NULL,                          -- supplier of the parts / service
  performed_by        VARCHAR(120) NULL,                          -- mechanic, technician or shop
  ref_no              VARCHAR(60)  NULL,                          -- supplier invoice / job order no.
  meter_reading       DECIMAL(15,2) NULL,
  downtime_days       DECIMAL(7,2) NULL,
  parts_total         DECIMAL(15,2) NOT NULL DEFAULT 0,
  labor_total         DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_total           DECIMAL(15,2) NOT NULL DEFAULT 0,
  total               DECIMAL(15,2) NOT NULL DEFAULT 0,
  with_vat            TINYINT(1)   NOT NULL DEFAULT 0,
  payment_mode        VARCHAR(20)  NOT NULL DEFAULT 'none',       -- none, bill, check, cash_voucher
  pay_account_id      INT UNSIGNED NULL,                          -- bank (check) or cash account (cash voucher)
  parts_account_id    INT UNSIGNED NULL,
  labor_account_id    INT UNSIGNED NULL,
  class_id            INT UNSIGNED NULL,
  txn_id              BIGINT UNSIGNED NULL,                       -- the bill, check or cash voucher created for it
  schedule_id         INT UNSIGNED NULL,                          -- preventive maintenance task completed by this record
  next_service_date   DATE         NULL,
  notes               TEXT         NULL,
  void_reason         VARCHAR(255) NULL,
  created_by          INT UNSIGNED NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NULL,
  UNIQUE KEY uq_maint_no (maint_no),
  KEY ix_maint_asset (asset_id, maint_date),
  KEY ix_maint_vendor (vendor_id),
  KEY ix_maint_txn (txn_id),
  KEY ix_maint_class (class_id),
  KEY ix_maint_schedule (schedule_id),
  CONSTRAINT fk_maint_asset    FOREIGN KEY (asset_id) REFERENCES fixed_assets(id),
  CONSTRAINT fk_maint_vendor   FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_maint_class    FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_maint_txn      FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE SET NULL,
  CONSTRAINT fk_maint_schedule FOREIGN KEY (schedule_id) REFERENCES asset_pm_schedules(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_maintenance_parts;
CREATE TABLE asset_maintenance_parts (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  maintenance_id  INT UNSIGNED NOT NULL,
  line_no         INT          NOT NULL DEFAULT 0,
  part_no         VARCHAR(60)  NULL,
  description     VARCHAR(200) NOT NULL,
  serial_no       VARCHAR(60)  NULL,
  model_no        VARCHAR(60)  NULL,
  size_spec       VARCHAR(60)  NULL,
  brand           VARCHAR(60)  NULL,
  qty             DECIMAL(15,4) NOT NULL DEFAULT 1,
  unit            VARCHAR(20)  NULL,
  unit_cost       DECIMAL(15,4) NOT NULL DEFAULT 0,
  amount          DECIMAL(15,2) NOT NULL DEFAULT 0,
  warranty_until  DATE         NULL,
  KEY ix_mparts_maint (maintenance_id),
  KEY ix_mparts_partno (part_no),
  CONSTRAINT fk_mparts_maint FOREIGN KEY (maintenance_id) REFERENCES asset_maintenance(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_maintenance_labor;
CREATE TABLE asset_maintenance_labor (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  maintenance_id  INT UNSIGNED NOT NULL,
  line_no         INT          NOT NULL DEFAULT 0,
  description     VARCHAR(200) NOT NULL,
  worker          VARCHAR(120) NULL,
  charge_type     VARCHAR(10)  NOT NULL DEFAULT 'hourly',   -- hourly (man-hours x rate) or bulk (one charge)
  hours           DECIMAL(9,2) NOT NULL DEFAULT 0,
  rate            DECIMAL(15,4) NOT NULL DEFAULT 0,
  amount          DECIMAL(15,2) NOT NULL DEFAULT 0,
  KEY ix_mlabor_maint (maintenance_id),
  CONSTRAINT fk_mlabor_maint FOREIGN KEY (maintenance_id) REFERENCES asset_maintenance(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_maintenance_photos;
CREATE TABLE asset_maintenance_photos (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  maintenance_id  INT UNSIGNED NOT NULL,
  file_name       VARCHAR(120) NOT NULL,      -- name inside storage/maintenance/
  original_name   VARCHAR(190) NULL,
  mime_type       VARCHAR(60)  NULL,
  file_size       INT UNSIGNED NOT NULL DEFAULT 0,
  caption         VARCHAR(190) NULL,
  uploaded_by     INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_mphotos_maint (maintenance_id),
  CONSTRAINT fk_mphotos_maint FOREIGN KEY (maintenance_id) REFERENCES asset_maintenance(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_pm_log;
CREATE TABLE asset_pm_log (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  schedule_id     INT UNSIGNED NOT NULL,
  asset_id        INT UNSIGNED NOT NULL,
  task_name       VARCHAR(160) NOT NULL,
  due_date        DATE         NULL,
  done_date       DATE         NOT NULL,
  done_meter      DECIMAL(15,2) NULL,
  maintenance_id  INT UNSIGNED NULL,
  remarks         VARCHAR(255) NULL,
  done_by         INT UNSIGNED NULL,          -- the user who acknowledged that the task is complete
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_pmlog_schedule (schedule_id),
  KEY ix_pmlog_asset (asset_id, done_date),
  CONSTRAINT fk_pmlog_schedule FOREIGN KEY (schedule_id) REFERENCES asset_pm_schedules(id) ON DELETE CASCADE,
  CONSTRAINT fk_pmlog_asset    FOREIGN KEY (asset_id) REFERENCES fixed_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  POINT OF SALE
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS pos_terminals;
CREATE TABLE pos_terminals (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(20)  NOT NULL,
  name            VARCHAR(80)  NOT NULL,
  site_id         INT UNSIGNED NOT NULL,
  cash_account_id INT UNSIGNED NOT NULL,
  receipt_prefix  VARCHAR(10)  NOT NULL DEFAULT 'OR',
  next_receipt_no INT UNSIGNED NOT NULL DEFAULT 1,
  next_z_no       INT UNSIGNED NOT NULL DEFAULT 1,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  class_id        INT UNSIGNED NULL,                      -- default class of sales made at this terminal
  UNIQUE KEY uq_term_code (code),
  CONSTRAINT fk_term_class FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_term_site FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_term_cash FOREIGN KEY (cash_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS pos_shifts;
CREATE TABLE pos_shifts (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  terminal_id    INT UNSIGNED NOT NULL,
  cashier_id     INT UNSIGNED NOT NULL,
  business_date  DATE         NOT NULL,
  opened_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  opening_float  DECIMAL(15,2) NOT NULL DEFAULT 0,
  closed_at      DATETIME     NULL,
  expected_cash  DECIMAL(15,2) NULL,
  counted_cash   DECIMAL(15,2) NULL,
  over_short     DECIMAL(15,2) NULL,
  over_short_txn_id BIGINT UNSIGNED NULL,
  deposit_txn_id BIGINT UNSIGNED NULL,
  status         ENUM('open','closed') NOT NULL DEFAULT 'open',
  closed_by      INT UNSIGNED NULL,
  corrected_by   INT UNSIGNED NULL,          -- who last corrected the closing entries (float, drops, count)
  corrected_at   DATETIME     NULL,
  KEY ix_shift_term (terminal_id, status),
  CONSTRAINT fk_shift_term FOREIGN KEY (terminal_id) REFERENCES pos_terminals(id),
  CONSTRAINT fk_shift_user FOREIGN KEY (cashier_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS pos_tenders;
CREATE TABLE pos_tenders (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id            BIGINT UNSIGNED NOT NULL,
  payment_method_id INT UNSIGNED NOT NULL,
  amount            DECIMAL(15,2) NOT NULL,     -- net amount applied to the sale (after change)
  reference         VARCHAR(60)  NULL,
  KEY ix_pt_txn (txn_id),
  CONSTRAINT fk_pt_txn FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_pt_pm  FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS pos_cash_drops;
CREATE TABLE pos_cash_drops (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  shift_id    INT UNSIGNED NOT NULL,
  amount      DECIMAL(15,2) NOT NULL,
  reason      VARCHAR(255) NULL,
  created_by  INT UNSIGNED NOT NULL,
  approved_by INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cd_shift FOREIGN KEY (shift_id) REFERENCES pos_shifts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS pos_readings;
CREATE TABLE pos_readings (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reading_type   ENUM('X','Z') NOT NULL,
  terminal_id    INT UNSIGNED NOT NULL,
  shift_id       INT UNSIGNED NOT NULL,
  z_no           INT UNSIGNED NULL,
  business_date  DATE         NOT NULL,
  first_receipt  VARCHAR(30)  NULL,
  last_receipt   VARCHAR(30)  NULL,
  sales_count    INT          NOT NULL DEFAULT 0,
  gross_sales    DECIMAL(15,2) NOT NULL DEFAULT 0,
  discounts      DECIMAL(15,2) NOT NULL DEFAULT 0,
  returns        DECIMAL(15,2) NOT NULL DEFAULT 0,
  voids          DECIMAL(15,2) NOT NULL DEFAULT 0,
  net_sales      DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax            DECIMAL(15,2) NOT NULL DEFAULT 0,
  tenders_json   TEXT         NULL,
  cash_drops     DECIMAL(15,2) NOT NULL DEFAULT 0,
  expected_cash  DECIMAL(15,2) NOT NULL DEFAULT 0,
  counted_cash   DECIMAL(15,2) NULL,
  over_short     DECIMAL(15,2) NULL,
  created_by     INT UNSIGNED NOT NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pr_term  FOREIGN KEY (terminal_id) REFERENCES pos_terminals(id),
  CONSTRAINT fk_pr_shift FOREIGN KEY (shift_id) REFERENCES pos_shifts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time approval codes a supervisor generates for a cashier or clerk (used once, then expired)
DROP TABLE IF EXISTS approval_codes;
CREATE TABLE approval_codes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(12)  NOT NULL,
  purpose       VARCHAR(160) NULL,
  for_user_id   INT UNSIGNED NULL,          -- the user it is meant for (NULL: any user)
  page_key      VARCHAR(60)  NULL,          -- the page it may approve on (NULL: any page the issuer may approve on)
  max_amount    DECIMAL(15,2) NULL,
  status        VARCHAR(10)  NOT NULL DEFAULT 'open',   -- open / used / expired / cancelled
  issued_by     INT UNSIGNED NOT NULL,
  issued_at     DATETIME     NOT NULL,
  expires_at    DATETIME     NOT NULL,
  used_by       INT UNSIGNED NULL,
  used_at       DATETIME     NULL,
  used_on       VARCHAR(160) NULL,
  used_txn_id   BIGINT UNSIGNED NULL,
  cancelled_by  INT UNSIGNED NULL,
  cancelled_at  DATETIME     NULL,
  UNIQUE KEY uq_approval_codes_code (code),
  KEY ix_approval_codes_status (status, expires_at),
  KEY ix_approval_codes_issued (issued_by, issued_at),
  CONSTRAINT fk_apc_issuer FOREIGN KEY (issued_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  BANKING
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS bank_reconciliations;
CREATE TABLE bank_reconciliations (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id         INT UNSIGNED NOT NULL,
  statement_date     DATE         NOT NULL,
  beginning_balance  DECIMAL(15,2) NOT NULL DEFAULT 0,
  ending_balance     DECIMAL(15,2) NOT NULL DEFAULT 0,
  cleared_balance    DECIMAL(15,2) NOT NULL DEFAULT 0,
  difference         DECIMAL(15,2) NOT NULL DEFAULT 0,
  service_charge_txn_id BIGINT UNSIGNED NULL,
  interest_txn_id    BIGINT UNSIGNED NULL,
  service_charge     DECIMAL(15,2) NOT NULL DEFAULT 0,
  service_date       DATE         NULL,
  service_account_id INT UNSIGNED NULL,
  interest_earned    DECIMAL(15,2) NOT NULL DEFAULT 0,
  interest_date      DATE         NULL,
  interest_account_id INT UNSIGNED NULL,
  class_id           INT UNSIGNED NULL,
  status             ENUM('in_progress','completed') NOT NULL DEFAULT 'in_progress',
  created_by         INT UNSIGNED NULL,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_by       INT UNSIGNED NULL,
  completed_at       DATETIME     NULL,
  KEY ix_br_account (account_id, statement_date),
  CONSTRAINT fk_br_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Snapshot of the items cleared in each reconciliation (used by the reports)
DROP TABLE IF EXISTS reconciliation_items;
CREATE TABLE reconciliation_items (
  reconciliation_id INT UNSIGNED NOT NULL,
  txn_id            BIGINT UNSIGNED NOT NULL,
  amount            DECIMAL(15,2) NOT NULL,   -- + deposit / - payment
  cleared           TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (reconciliation_id, txn_id),
  CONSTRAINT fk_ri_rec FOREIGN KEY (reconciliation_id) REFERENCES bank_reconciliations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cleared transactions changed after reconciliation
DROP TABLE IF EXISTS reconciliation_discrepancies;
CREATE TABLE reconciliation_discrepancies (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reconciliation_id INT UNSIGNED NULL,
  account_id        INT UNSIGNED NOT NULL,
  txn_id            BIGINT UNSIGNED NOT NULL,
  txn_ref           VARCHAR(80)  NULL,
  change_type       VARCHAR(20)  NOT NULL,   -- amount changed, voided, deleted, uncleared
  cleared_amount    DECIMAL(15,2) NOT NULL DEFAULT 0,
  new_amount        DECIMAL(15,2) NOT NULL DEFAULT 0,
  changed_by        INT UNSIGNED NULL,
  changed_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_rd_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  SECURITY - site and terminal assignments
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS role_sites;
CREATE TABLE role_sites (
  role_id INT UNSIGNED NOT NULL,
  site_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, site_id),
  CONSTRAINT fk_rs_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rs_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS user_sites;
CREATE TABLE user_sites (
  user_id INT UNSIGNED NOT NULL,
  site_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, site_id),
  CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_us_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS user_terminals;
CREATE TABLE user_terminals (
  user_id     INT UNSIGNED NOT NULL,
  terminal_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, terminal_id),
  CONSTRAINT fk_ut_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ut_term FOREIGN KEY (terminal_id) REFERENCES pos_terminals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  Memorized transactions, budgets and forecasts, report layouts,
--  CSV imports and database backups
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS memorized_txns;
CREATE TABLE memorized_txns (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(120) NOT NULL,
  group_name     VARCHAR(60)  NULL,
  txn_type       VARCHAR(30)  NOT NULL,
  entity_label   VARCHAR(160) NULL,               -- customer / supplier / payee, for the list
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  payload        LONGTEXT     NOT NULL,           -- the header and the lines of the saved transaction
  how            VARCHAR(20)  NOT NULL DEFAULT 'remind',   -- remind, auto, none (list only)
  frequency      VARCHAR(10)  NOT NULL DEFAULT 'monthly',  -- daily, weekly, months, quarterly, yearly
  every_n        INT          NOT NULL DEFAULT 1,
  next_date      DATE         NULL,
  days_in_advance INT         NOT NULL DEFAULT 5,
  remaining      INT          NULL,               -- how many are left to enter (NULL = no limit)
  end_date       DATE         NULL,
  last_entered   DATE         NULL,
  entered_count  INT          NOT NULL DEFAULT 0,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  notes          VARCHAR(255) NULL,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NULL,
  KEY ix_mt_due (is_active, next_date),
  KEY ix_mt_type (txn_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS budgets;
CREATE TABLE budgets (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  kind          VARCHAR(10)  NOT NULL DEFAULT 'pl',   -- pl = income statement, bs = balance sheet, forecast = sales forecast
  fiscal_year   SMALLINT     NOT NULL,
  start_month   TINYINT      NOT NULL DEFAULT 1,      -- the month the fiscal year starts in
  class_id      INT UNSIGNED NULL,
  customer_id   INT UNSIGNED NULL,                    -- forecast for one customer (NULL = all customers)
  notes         VARCHAR(255) NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NULL,
  UNIQUE KEY uq_budget (kind, fiscal_year, name),
  KEY ix_budget_class (class_id),
  KEY ix_budget_customer (customer_id),
  CONSTRAINT fk_budget_class    FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_budget_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS budget_lines;
CREATE TABLE budget_lines (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  budget_id    INT UNSIGNED NOT NULL,
  account_id   INT UNSIGNED NOT NULL,
  customer_id  INT UNSIGNED NULL,                 -- forecasts: the customer the amounts belong to
  period_month TINYINT      NOT NULL,             -- 1..12, counted from the start of the fiscal year
  amount       DECIMAL(15,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_budget_line (budget_id, account_id, customer_id, period_month),
  KEY ix_bl_account (account_id),
  CONSTRAINT fk_bl_budget   FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE,
  CONSTRAINT fk_bl_account  FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_bl_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS report_layouts;
CREATE TABLE report_layouts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  report_key   VARCHAR(60)  NOT NULL,
  name         VARCHAR(120) NOT NULL,
  title        VARCHAR(160) NULL,                 -- a heading of your own for the print-out
  columns      TEXT         NULL,                 -- key:Heading, one per line, in the order they print; a leading - means hidden
  options      TEXT         NULL,                 -- name=value, one per line: negatives, zeros, decimals, density and the rest
  is_shared    TINYINT(1)   NOT NULL DEFAULT 0,
  is_default   TINYINT(1)   NOT NULL DEFAULT 0,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NULL,
  KEY ix_rl_report (report_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS import_batches;
CREATE TABLE import_batches (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  import_type   VARCHAR(40)  NOT NULL,
  file_name     VARCHAR(190) NULL,
  rows_total    INT          NOT NULL DEFAULT 0,
  rows_ok       INT          NOT NULL DEFAULT 0,
  rows_failed   INT          NOT NULL DEFAULT 0,
  created_ids   LONGTEXT     NULL,                -- what the import created, so it can be traced
  messages      LONGTEXT     NULL,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_ib_type (import_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS backup_log;
CREATE TABLE backup_log (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  action       VARCHAR(10)  NOT NULL,             -- backup, restore
  file_name    VARCHAR(190) NOT NULL,
  file_size    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tables_count INT          NOT NULL DEFAULT 0,
  rows_count   INT          NOT NULL DEFAULT 0,
  note         VARCHAR(255) NULL,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_bl_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  COOPERATIVE MANAGEMENT (members, share capital, savings, time deposits,
--  loans, teller, year-end distribution, CDA compliance)
--  The same tables as database/migrations/2026_11_01_cooperative.sql.
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS coop_branches;
CREATE TABLE coop_branches (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(10)  NOT NULL,
  name           VARCHAR(120) NOT NULL,
  address        VARCHAR(255) NULL,
  phone          VARCHAR(60)  NULL,
  email          VARCHAR(120) NULL,
  class_id       INT UNSIGNED NULL,                 -- the class every posting of this branch carries
  is_head_office TINYINT(1)   NOT NULL DEFAULT 0,
  or_prefix      VARCHAR(10)  NOT NULL DEFAULT '',  -- official receipt series of the branch
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NULL,
  UNIQUE KEY uq_coop_branches_code (code),
  KEY ix_coop_branches_class (class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS member_types;
CREATE TABLE member_types (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(20)  NOT NULL,
  name        VARCHAR(80)  NOT NULL,
  member_kind VARCHAR(15)  NOT NULL DEFAULT 'individual',   -- individual / joint / institutional
  is_regular  TINYINT(1)   NOT NULL DEFAULT 1,              -- regular members vote and share in the surplus
  min_share_capital DECIMAL(15,2) NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order  INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_member_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS member_clusters;
CREATE TABLE member_clusters (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(80)  NOT NULL,
  branch_id INT UNSIGNED NULL,
  is_active TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_member_clusters_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS member_segments;
CREATE TABLE member_segments (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(80)  NOT NULL,
  is_active TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_member_segments_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS coop_lists;
CREATE TABLE coop_lists (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  list_key  VARCHAR(30)  NOT NULL,     -- loan_purpose, collateral_type, account_class, account_group, address_subgroup, savings_group, doc_type, id_type, occupation
  name      VARCHAR(120) NOT NULL,
  extra     VARCHAR(255) NULL,
  sort_order INT         NOT NULL DEFAULT 0,
  is_active TINYINT(1)   NOT NULL DEFAULT 1,
  KEY ix_coop_lists_key (list_key),
  UNIQUE KEY uq_coop_lists_name (list_key, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS passbook_types;
CREATE TABLE passbook_types (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(80)  NOT NULL,
  description     VARCHAR(255) NULL,
  ledger          VARCHAR(10)  NOT NULL DEFAULT 'savings',   -- savings / loan: each kind has its own design
  page_width      INT NOT NULL DEFAULT 12240,     -- twips
  page_height     INT NOT NULL DEFAULT 8640,
  cover_top       INT NOT NULL DEFAULT 900,
  cover_left      INT NOT NULL DEFAULT 2400,
  header_top      INT NOT NULL DEFAULT 700,
  header_left     INT NOT NULL DEFAULT 300,
  footer_top      INT NOT NULL DEFAULT 8000,
  footer_left     INT NOT NULL DEFAULT 100,
  body_top        INT NOT NULL DEFAULT 1230,
  body_left       INT NOT NULL DEFAULT 200,
  body_interval   INT NOT NULL DEFAULT 275,       -- distance between two lines
  start_line_skip INT NOT NULL DEFAULT 12,        -- the first line never printed on (the centre fold); 0 = none
  line_skip       INT NOT NULL DEFAULT 1,         -- how many lines are skipped from start_line_skip
  max_lines       INT NOT NULL DEFAULT 26,        -- lines per page
  columns_json    TEXT NULL,                      -- column positions (twips) and widths
  font_size_pt    DECIMAL(4,1) NOT NULL DEFAULT 9.0,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  header_json     TEXT NULL,                      -- account name and number on every page (positions)
  cover_json      TEXT NULL,                      -- the inside cover (positions)
  skip_lines      VARCHAR(60) NULL,               -- lines never printed (e.g. the centre fold): 13 or 13,14
  dash_empty      TINYINT(1) NOT NULL DEFAULT 1,  -- a dash in the empty amount column
  footer_text     VARCHAR(160) NULL,              -- printed by Print Passbook Footer
  layout_json     TEXT NULL,                      -- the design: cover, header, body, footer fields (Passbook Types)
  UNIQUE KEY uq_passbook_types_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS document_templates;
CREATE TABLE document_templates (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  doc_key        VARCHAR(40)  NOT NULL,     -- promissory_note, disclosure, comaker, release_stmt, td_certificate, share_certificate, member_notice, demand_letter
  name           VARCHAR(120) NOT NULL,
  paper          VARCHAR(10)  NOT NULL DEFAULT 'A4',
  body           LONGTEXT     NOT NULL,     -- HTML with {{placeholders}}
  effective_date DATE         NOT NULL,
  version_no     INT          NOT NULL DEFAULT 1,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_doc_templates_key (doc_key, effective_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS document_prints;
CREATE TABLE document_prints (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  doc_key    VARCHAR(40)  NOT NULL,
  ref_type   VARCHAR(30)  NOT NULL,       -- loan, td, sav_account, member, batch, receipt
  ref_id     BIGINT UNSIGNED NOT NULL,
  doc_no     VARCHAR(40)  NULL,
  copy_no    INT          NOT NULL DEFAULT 1,
  reason     VARCHAR(255) NULL,
  printed_by INT UNSIGNED NULL,
  printed_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_doc_prints_ref (ref_type, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS members;
CREATE TABLE members (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  party_id           INT UNSIGNED NULL,               -- the Party Master record
  member_no          VARCHAR(20)  NOT NULL,
  other_name_id      INT UNSIGNED NULL,               -- the member's row in other_names (subsidiary ledger name)
  branch_id          INT UNSIGNED NULL,
  member_type_id     INT UNSIGNED NULL,
  member_kind        VARCHAR(15)  NOT NULL DEFAULT 'individual',   -- individual / joint / institutional
  status             VARCHAR(12)  NOT NULL DEFAULT 'applicant',    -- applicant / active / suspended / resigned / terminated / deceased
  status_date        DATE         NULL,
  status_reason      VARCHAR(255) NULL,
  passbook_no        VARCHAR(20)  NULL,
  salutation         VARCHAR(10)  NULL,
  last_name          VARCHAR(80)  NULL,
  first_name         VARCHAR(80)  NULL,
  middle_name        VARCHAR(80)  NULL,
  suffix             VARCHAR(10)  NULL,
  full_name          VARCHAR(200) NOT NULL,           -- "Last, First M." or the institution / joint name
  birth_date         DATE         NULL,
  birth_place        VARCHAR(120) NULL,
  gender             VARCHAR(10)  NULL,
  civil_status       VARCHAR(15)  NULL,
  nationality        VARCHAR(40)  NULL DEFAULT 'Filipino',
  tin                VARCHAR(30)  NULL,
  email              VARCHAR(120) NULL,
  mobile             VARCHAR(40)  NULL,
  res_house          VARCHAR(60)  NULL,
  res_street         VARCHAR(120) NULL,
  res_barangay       VARCHAR(80)  NULL,
  res_city           VARCHAR(80)  NULL,
  res_province        VARCHAR(80)  NULL,
  res_postal         VARCHAR(10)  NULL,
  prov_house         VARCHAR(60)  NULL,
  prov_street        VARCHAR(120) NULL,
  prov_city          VARCHAR(80)  NULL,
  prov_province      VARCHAR(80)  NULL,
  prov_postal        VARCHAR(10)  NULL,
  off_house          VARCHAR(60)  NULL,
  off_street         VARCHAR(120) NULL,
  off_city           VARCHAR(80)  NULL,
  off_province       VARCHAR(80)  NULL,
  off_postal         VARCHAR(10)  NULL,
  education          VARCHAR(60)  NULL,
  year_completed     VARCHAR(10)  NULL,
  course             VARCHAR(120) NULL,
  occupation         VARCHAR(120) NULL,
  organization       VARCHAR(120) NULL,
  employment_type    VARCHAR(30)  NULL,               -- employed / self-employed / business / retired / student / other
  employer_name      VARCHAR(120) NULL,
  employer_contact   VARCHAR(40)  NULL,
  employer_address   VARCHAR(255) NULL,
  membership_date    DATE         NULL,
  pmes_date          DATE         NULL,               -- pre-membership education seminar
  cluster_id         INT UNSIGNED NULL,
  segment_id         INT UNSIGNED NULL,
  subscribed_shares  DECIMAL(15,2) NOT NULL DEFAULT 0,
  par_value          DECIMAL(15,2) NOT NULL DEFAULT 0,
  subscribed_capital DECIMAL(15,2) NOT NULL DEFAULT 0,
  account_officer_id INT UNSIGNED NULL,               -- users.id (loan / account officer)
  account_class      VARCHAR(60)  NULL,               -- e.g. Board of Director, Regular
  account_group      VARCHAR(60)  NULL,
  address_subgroup   VARCHAR(60)  NULL,
  savings_group      VARCHAR(60)  NULL,
  institution_name   VARCHAR(200) NULL,               -- joint / institutional
  hold_flag          TINYINT(1)   NOT NULL DEFAULT 0, -- no transactions until released
  hold_reason        VARCHAR(255) NULL,
  alert_note         VARCHAR(255) NULL,               -- shown to tellers and loan officers
  photo_file         VARCHAR(120) NULL,
  signature_file     VARCHAR(120) NULL,
  deceased_date      DATE         NULL,
  notes              TEXT         NULL,
  created_by         INT UNSIGNED NULL,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by         INT UNSIGNED NULL,
  updated_at         DATETIME     NULL,
  UNIQUE KEY uq_members_no (member_no),
  KEY ix_members_name (full_name(150)),
  KEY ix_members_status (status),
  KEY ix_members_branch (branch_id),
  KEY ix_members_other_name (other_name_id),
  KEY ix_members_type (member_type_id),
  KEY ix_members_cluster (cluster_id),
  KEY ix_members_mobile (mobile),
  KEY ix_members_party (party_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS member_contacts;
CREATE TABLE member_contacts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id    INT UNSIGNED NOT NULL,
  contact_type VARCHAR(20)  NOT NULL,     -- mobile / landline / email / fax / other
  country_code VARCHAR(6)   NULL,
  area_code    VARCHAR(6)   NULL,
  number       VARCHAR(60)  NOT NULL,
  is_primary   TINYINT(1)   NOT NULL DEFAULT 0,
  KEY ix_member_contacts_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS member_dependents;
CREATE TABLE member_dependents (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id    INT UNSIGNED NOT NULL,
  kind         VARCHAR(10)  NOT NULL DEFAULT 'dependent',   -- spouse / dependent
  first_name   VARCHAR(80)  NULL,
  last_name    VARCHAR(80)  NULL,
  middle_name  VARCHAR(80)  NULL,
  relationship VARCHAR(40)  NULL,
  birth_date   DATE         NULL,
  contact_no   VARCHAR(40)  NULL,
  is_dependent TINYINT(1)   NOT NULL DEFAULT 1,
  KEY ix_member_dependents_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS member_relatives;
CREATE TABLE member_relatives (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id          INT UNSIGNED NOT NULL,
  relative_member_id INT UNSIGNED NOT NULL,
  relationship       VARCHAR(40)  NULL,
  KEY ix_member_relatives_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS member_finances;
CREATE TABLE member_finances (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id     INT UNSIGNED NOT NULL,
  kind          VARCHAR(10)  NOT NULL,      -- income / expense / outlay
  name          VARCHAR(120) NOT NULL,
  member_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  spouse_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  KEY ix_member_finances_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS member_assets;
CREATE TABLE member_assets (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id   INT UNSIGNED NOT NULL,
  description VARCHAR(160) NOT NULL,
  asset_type  VARCHAR(10)  NOT NULL DEFAULT 'asset',   -- asset / liability
  amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
  KEY ix_member_assets_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS member_beneficiaries;
CREATE TABLE member_beneficiaries (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id    INT UNSIGNED NOT NULL,
  name         VARCHAR(160) NOT NULL,
  relationship VARCHAR(40)  NULL,
  birth_date   DATE         NULL,
  contact_no   VARCHAR(40)  NULL,
  address      VARCHAR(255) NULL,
  share_pct    DECIMAL(6,2) NOT NULL DEFAULT 100,
  is_primary   TINYINT(1)   NOT NULL DEFAULT 1,
  KEY ix_member_beneficiaries_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS member_representatives;
CREATE TABLE member_representatives (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id INT UNSIGNED NOT NULL,
  name      VARCHAR(160) NOT NULL,
  position  VARCHAR(80)  NULL,
  rep_member_id INT UNSIGNED NULL,
  KEY ix_member_reps_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS member_documents;
CREATE TABLE member_documents (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id     INT UNSIGNED NOT NULL,
  doc_type      VARCHAR(60)  NOT NULL,     -- ID, signature, photo, application form, proof of address ...
  doc_no        VARCHAR(60)  NULL,
  file_name     VARCHAR(120) NULL,         -- inside storage/coop/members/
  original_name VARCHAR(190) NULL,
  mime_type     VARCHAR(60)  NULL,
  issued_on     DATE         NULL,
  expires_on    DATE         NULL,
  notes         VARCHAR(255) NULL,
  uploaded_by   INT UNSIGNED NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_member_documents_member (member_id),
  KEY ix_member_documents_expiry (expires_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS member_status_history;
CREATE TABLE member_status_history (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id  INT UNSIGNED NOT NULL,
  old_status VARCHAR(12)  NULL,
  new_status VARCHAR(12)  NOT NULL,
  changed_on DATE         NOT NULL,
  reason     VARCHAR(255) NULL,
  reference  VARCHAR(80)  NULL,          -- board resolution / claim reference
  changed_by INT UNSIGNED NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_member_status_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS member_attendance;
CREATE TABLE member_attendance (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id    INT UNSIGNED NOT NULL,
  meeting_type VARCHAR(20)  NOT NULL,     -- ownership / general_assembly / training / other
  meeting_date DATE         NOT NULL,
  attended     TINYINT(1)   NOT NULL DEFAULT 1,
  remarks      VARCHAR(160) NULL,
  KEY ix_member_attendance_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS member_notes;
CREATE TABLE member_notes (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id  INT UNSIGNED NOT NULL,
  note       TEXT         NOT NULL,
  note_type  VARCHAR(20)  NOT NULL DEFAULT 'note',   -- note / alert / collection / restriction
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_member_notes_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS sav_products;
CREATE TABLE sav_products (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                  VARCHAR(10)  NOT NULL,
  name                  VARCHAR(80)  NOT NULL,
  description           VARCHAR(255) NULL,
  product_type          VARCHAR(15)  NOT NULL DEFAULT 'ordinary',   -- ordinary / special / time_deposit / share_capital
  passbook_type_id      INT UNSIGNED NULL,
  liability_account_id  INT UNSIGNED NULL,     -- deposit liability (share capital: the equity account)
  expense_account_id    INT UNSIGNED NULL,     -- interest expense
  accrued_account_id    INT UNSIGNED NULL,     -- accrued interest payable (time deposits)
  opening_amount        DECIMAL(15,2) NOT NULL DEFAULT 0,
  maintaining_balance   DECIMAL(15,2) NOT NULL DEFAULT 0,
  min_balance           DECIMAL(15,2) NOT NULL DEFAULT 0,     -- balance that must stay after a withdrawal
  max_balance           DECIMAL(15,2) NOT NULL DEFAULT 0,     -- 0 = no limit
  min_deposit           DECIMAL(15,2) NOT NULL DEFAULT 0,
  min_withdrawal        DECIMAL(15,2) NOT NULL DEFAULT 0,
  maturity_value        INT          NOT NULL DEFAULT 0,      -- time deposits: term
  maturity_unit         VARCHAR(6)   NOT NULL DEFAULT 'days', -- days / months / years
  lockin_months         INT          NOT NULL DEFAULT 0,
  preterm_rate          DECIMAL(9,4) NULL,                    -- time deposits: rate paid when pre-terminated (NULL = none)
  preterm_fee           DECIMAL(15,2) NOT NULL DEFAULT 0,
  preterm_fee_account_id INT UNSIGNED NULL,
  dormancy_months       INT          NOT NULL DEFAULT 0,      -- 0 = never dormant
  dormancy_fee          DECIMAL(15,2) NOT NULL DEFAULT 0,
  dormancy_posting      VARCHAR(12)  NOT NULL DEFAULT 'quarterly',
  dormancy_fee_account_id INT UNSIGNED NULL,
  rate_after_maturity   VARCHAR(10)  NOT NULL DEFAULT 'same',  -- same / zero / fixed
  rate_after_value      DECIMAL(9,4) NOT NULL DEFAULT 0,
  interest_basis        VARCHAR(10)  NOT NULL DEFAULT 'adb',   -- adb / lowest / ending
  interest_method       VARCHAR(10)  NOT NULL DEFAULT 'simple', -- simple / compound
  calendar_days         SMALLINT     NOT NULL DEFAULT 365,
  tax_rate              DECIMAL(9,4) NOT NULL DEFAULT 0,       -- withholding on interest, if any
  tax_account_id        INT UNSIGNED NULL,
  eligible_types        VARCHAR(100) NULL,                     -- member_types ids "1,2" (blank = all)
  allow_withdrawal      TINYINT(1)   NOT NULL DEFAULT 1,
  withdrawal_approval   TINYINT(1)   NOT NULL DEFAULT 0,       -- withdrawals need supervisor approval
  par_value             DECIMAL(15,2) NOT NULL DEFAULT 0,      -- share capital
  share_class           VARCHAR(20)  NULL,                     -- common / preferred
  isc_eligible          TINYINT(1)   NOT NULL DEFAULT 0,       -- counts for interest on share capital
  is_active             TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order            INT          NOT NULL DEFAULT 0,
  created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME     NULL,
  UNIQUE KEY uq_sav_products_code (code),
  KEY ix_sav_products_type (product_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS sav_product_rates;
CREATE TABLE sav_product_rates (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_id     INT UNSIGNED NOT NULL,
  amount_from    DECIMAL(15,2) NOT NULL DEFAULT 0,
  amount_to      DECIMAL(15,2) NOT NULL DEFAULT 999999999999.99,
  term_from      INT          NOT NULL DEFAULT 0,       -- days (time deposits), 0 = any
  term_to        INT          NOT NULL DEFAULT 0,
  effective_date DATE         NOT NULL,
  rate           DECIMAL(9,4) NOT NULL DEFAULT 0,       -- percent per annum
  posting_method VARCHAR(12)  NOT NULL DEFAULT 'quarterly',   -- monthly / quarterly / semi_annual / annual / maturity
  KEY ix_spr_product (product_id, effective_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS sav_txn_codes;
CREATE TABLE sav_txn_codes (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                VARCHAR(10)  NOT NULL,
  description         VARCHAR(120) NOT NULL,
  opposite_kind       VARCHAR(6)   NOT NULL DEFAULT 'user',   -- gl: a fixed account; user: the teller's cash account / chosen at entry
  opposite_account_id INT UNSIGNED NULL,
  entry_side          VARCHAR(6)   NOT NULL DEFAULT 'credit', -- credit = money into the account, debit = out
  is_check_deposit    TINYINT(1)   NOT NULL DEFAULT 0,
  clearing_days       INT          NOT NULL DEFAULT 0,
  is_error_correct    TINYINT(1)   NOT NULL DEFAULT 0,
  related_code        VARCHAR(10)  NULL,
  is_system           TINYINT(1)   NOT NULL DEFAULT 0,        -- used by the program itself (interest, fees, loan proceeds ...)
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  is_memo             TINYINT(1)   NOT NULL DEFAULT 0,        -- debit / credit memo: the GL account is chosen each time
  UNIQUE KEY uq_sav_txn_codes_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS sav_accounts;
CREATE TABLE sav_accounts (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_no        VARCHAR(30)  NOT NULL,
  member_id         INT UNSIGNED NOT NULL,
  product_id        INT UNSIGNED NOT NULL,
  branch_id         INT UNSIGNED NULL,
  passbook_no       VARCHAR(20)  NULL,
  passbook_type_id  INT UNSIGNED NULL,
  status            VARCHAR(12)  NOT NULL DEFAULT 'pending',   -- pending / active / dormant / closed
  opened_on         DATE         NULL,
  approved_by       INT UNSIGNED NULL,
  approved_at       DATETIME     NULL,
  closed_on         DATE         NULL,
  close_reason      VARCHAR(255) NULL,
  balance           DECIMAL(15,2) NOT NULL DEFAULT 0,      -- ledger balance
  uncleared         DECIMAL(15,2) NOT NULL DEFAULT 0,      -- check deposits still clearing
  hold_amount       DECIMAL(15,2) NOT NULL DEFAULT 0,      -- holds (loan security, garnishment)
  interest_earned   DECIMAL(15,2) NOT NULL DEFAULT 0,      -- lifetime interest credited
  last_txn_date     DATE         NULL,
  last_interest_date DATE        NULL,
  dormant_since     DATE         NULL,
  is_restricted     TINYINT(1)   NOT NULL DEFAULT 0,
  restrict_reason   VARCHAR(255) NULL,
  pb_last_line      INT          NOT NULL DEFAULT 0,      -- passbook: last printed line on the page
  pb_page           INT          NOT NULL DEFAULT 1,
  pb_last_txn_id    BIGINT UNSIGNED NULL,                 -- last sav_txns.id printed
  pb_issued_count   INT          NOT NULL DEFAULT 1,      -- passbooks issued (replacements)
  notes             VARCHAR(255) NULL,
  created_by        INT UNSIGNED NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NULL,
  UNIQUE KEY uq_sav_accounts_no (account_no),
  KEY ix_sav_accounts_member (member_id),
  KEY ix_sav_accounts_product (product_id),
  KEY ix_sav_accounts_status (status),
  KEY ix_sav_accounts_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS sav_txns;
CREATE TABLE sav_txns (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id    INT UNSIGNED NOT NULL,
  txn_date      DATE         NOT NULL,
  value_date    DATE         NULL,                 -- backdated entries: the date it is effective for interest
  txn_code      VARCHAR(10)  NOT NULL,
  description   VARCHAR(160) NULL,
  debit         DECIMAL(15,2) NOT NULL DEFAULT 0,  -- out of the account
  credit        DECIMAL(15,2) NOT NULL DEFAULT 0,  -- into the account
  balance_after DECIMAL(15,2) NOT NULL DEFAULT 0,
  txn_id        BIGINT UNSIGNED NULL,              -- transactions.id (the GL voucher / receipt)
  or_no         VARCHAR(30)  NULL,
  teller_id     INT UNSIGNED NULL,
  shift_id      INT UNSIGNED NULL,
  clearing_date DATE         NULL,                 -- check deposits
  is_cleared    TINYINT(1)   NOT NULL DEFAULT 1,
  cleared_txn_id BIGINT UNSIGNED NULL,             -- the voucher that cleared or returned the check
  is_voided     TINYINT(1)   NOT NULL DEFAULT 0,
  voided_txn_id BIGINT UNSIGNED NULL,
  pb_printed    TINYINT(1)   NOT NULL DEFAULT 0,
  pb_line       INT          NULL,
  pb_page       INT          NULL,
  ref_type      VARCHAR(20)  NULL,                 -- interest_run, td, loan, dist, batch, transfer
  ref_id        BIGINT UNSIGNED NULL,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  trans_no      VARCHAR(20)  NULL,                 -- Savings Transaction Center number (one series for savings and TD)
  opposite_account_id INT UNSIGNED NULL,           -- the account of the other side of the entry (cash, GL, memo)
  cheque_json   TEXT         NULL,                 -- cheque details (check deposits)
  is_back_entry TINYINT(1)   NOT NULL DEFAULT 0,
  corrects_id   BIGINT UNSIGNED NULL,              -- the transaction this error correction negates
  corrected_by_id BIGINT UNSIGNED NULL,            -- the error correction that negated this one
  KEY ix_sav_txns_account (account_id, txn_date, id),
  KEY ix_sav_txns_txn (txn_id),
  KEY ix_sav_txns_ref (ref_type, ref_id),
  KEY ix_sav_txns_shift (shift_id),
  KEY ix_sav_txns_clearing (is_cleared, clearing_date),
  KEY ix_sav_txns_trans (trans_no),
  KEY ix_sav_txns_corrects (corrects_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS sav_holds;
CREATE TABLE sav_holds (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id      INT UNSIGNED NOT NULL,
  amount          DECIMAL(15,2) NOT NULL,
  reason          VARCHAR(255) NULL,
  loan_account_id INT UNSIGNED NULL,
  placed_by       INT UNSIGNED NULL,
  placed_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  released_by     INT UNSIGNED NULL,
  released_at     DATETIME     NULL,
  KEY ix_sav_holds_account (account_id, released_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS sav_interest_runs;
CREATE TABLE sav_interest_runs (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_id     INT UNSIGNED NOT NULL,
  period_from    DATE         NOT NULL,
  period_to      DATE         NOT NULL,
  posting_date   DATE         NOT NULL,
  txn_id         BIGINT UNSIGNED NULL,
  accounts_count INT          NOT NULL DEFAULT 0,
  total_interest DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_tax      DECIMAL(15,2) NOT NULL DEFAULT 0,
  status         VARCHAR(10)  NOT NULL DEFAULT 'posted',   -- preview / posted / voided
  run_by         INT UNSIGNED NULL,
  run_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sav_interest_runs (product_id, period_to, status),
  KEY ix_sav_interest_runs_txn (txn_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS sav_interest_lines;
CREATE TABLE sav_interest_lines (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_id       INT UNSIGNED NOT NULL,
  account_id   INT UNSIGNED NOT NULL,
  basis_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  days         INT          NOT NULL DEFAULT 0,
  rate         DECIMAL(9,4) NOT NULL DEFAULT 0,
  interest     DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax          DECIMAL(15,2) NOT NULL DEFAULT 0,
  net          DECIMAL(15,2) NOT NULL DEFAULT 0,
  sav_txn_id   BIGINT UNSIGNED NULL,
  KEY ix_sav_interest_lines_run (run_id),
  KEY ix_sav_interest_lines_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS td_placements;
CREATE TABLE td_placements (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id       INT UNSIGNED NOT NULL,          -- the time deposit account (sav_accounts, product_type time_deposit)
  certificate_no   VARCHAR(30)  NOT NULL,
  principal        DECIMAL(15,2) NOT NULL,
  rate             DECIMAL(9,4) NOT NULL,
  term_days        INT          NOT NULL,
  term_label       VARCHAR(30)  NULL,
  placement_date   DATE         NOT NULL,
  maturity_date    DATE         NOT NULL,
  maturity_value   DECIMAL(15,2) NOT NULL,
  interest_method  VARCHAR(10)  NOT NULL DEFAULT 'simple',   -- simple / compound
  compounding      VARCHAR(12)  NOT NULL DEFAULT 'maturity', -- maturity / monthly / quarterly
  payout_mode      VARCHAR(12)  NOT NULL DEFAULT 'maturity', -- maturity / monthly / quarterly
  maturity_action  VARCHAR(20)  NOT NULL DEFAULT 'payout',   -- payout / renew_principal / renew_all / credit_savings
  credit_account_id INT UNSIGNED NULL,                      -- savings account to credit at maturity
  calendar_days    SMALLINT     NOT NULL DEFAULT 365,
  status           VARCHAR(14)  NOT NULL DEFAULT 'active',   -- active / matured / paid / renewed / preterminated
  accrued_interest DECIMAL(15,2) NOT NULL DEFAULT 0,        -- accrued so far (GL: accrued interest payable)
  interest_paid    DECIMAL(15,2) NOT NULL DEFAULT 0,
  renewed_from_id  INT UNSIGNED NULL,
  renewed_to_id    INT UNSIGNED NULL,
  placement_txn_id BIGINT UNSIGNED NULL,
  settled_txn_id   BIGINT UNSIGNED NULL,
  settled_on       DATE         NULL,
  preterm_rate     DECIMAL(9,4) NULL,
  preterm_fee      DECIMAL(15,2) NULL,
  reprint_count    INT          NOT NULL DEFAULT 0,
  notes            VARCHAR(255) NULL,
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  accrued_paid     DECIMAL(15,2) NOT NULL DEFAULT 0.00,     -- accrued interest withdrawn before maturity (CAIW)
  UNIQUE KEY uq_td_certificate (certificate_no),
  KEY ix_td_account (account_id),
  KEY ix_td_status (status, maturity_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Time deposit accrued interest withdrawn before maturity (code CAIW)
DROP TABLE IF EXISTS td_interest_payouts;
CREATE TABLE td_interest_payouts (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  placement_id  INT UNSIGNED NOT NULL,                 -- td_placements.id
  payout_date   DATE NOT NULL,
  amount        DECIMAL(15,2) NOT NULL,                -- interest withdrawn
  accrued_used  DECIMAL(15,2) NOT NULL DEFAULT 0.00,   -- the part taken from the accrued interest payable
  expensed      DECIMAL(15,2) NOT NULL DEFAULT 0.00,   -- the part not accrued yet, expensed at the withdrawal
  txn_id        BIGINT UNSIGNED NULL,                  -- the voucher (transactions.id)
  trans_no      VARCHAR(20) NULL,                      -- the savings transaction number
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_td_payouts_placement (placement_id),
  KEY ix_td_payouts_txn (txn_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS td_accruals;
CREATE TABLE td_accruals (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  placement_id INT UNSIGNED NOT NULL,
  accrual_date DATE         NOT NULL,
  period_from  DATE         NOT NULL,
  period_to    DATE         NOT NULL,
  amount       DECIMAL(15,2) NOT NULL,
  txn_id       BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_td_accruals (placement_id, period_to),
  KEY ix_td_accruals_txn (txn_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS passbook_prints;
CREATE TABLE passbook_prints (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id INT UNSIGNED NOT NULL,
  ledger     VARCHAR(10)  NOT NULL DEFAULT 'savings',   -- savings / loan
  print_type VARCHAR(10)  NOT NULL,                     -- cover / lines / footer / reprint / reset / replace
  from_txn   BIGINT UNSIGNED NULL,
  to_txn     BIGINT UNSIGNED NULL,
  line_count INT          NOT NULL DEFAULT 0,
  page_no    INT          NULL,
  reason     VARCHAR(255) NULL,
  printed_by INT UNSIGNED NULL,
  printed_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_passbook_prints_account (account_id, ledger)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS sav_batches;
CREATE TABLE sav_batches (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  batch_no     VARCHAR(30)  NOT NULL,
  batch_date   DATE         NOT NULL,
  description  VARCHAR(160) NULL,
  source       VARCHAR(20)  NOT NULL DEFAULT 'payroll',   -- payroll / cbu / import / other
  contra_account_id INT UNSIGNED NULL,                    -- the account the money comes from (payroll clearing, bank)
  branch_id    INT UNSIGNED NULL,
  total        DECIMAL(15,2) NOT NULL DEFAULT 0,
  lines_count  INT          NOT NULL DEFAULT 0,
  status       VARCHAR(10)  NOT NULL DEFAULT 'draft',      -- draft / posted / voided
  txn_id       BIGINT UNSIGNED NULL,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  posted_at    DATETIME     NULL,
  class_id     INT UNSIGNED NULL,                           -- the class of its postings (Class List)
  UNIQUE KEY uq_sav_batches_no (batch_no),
  KEY ix_sav_batches_class (class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS sav_batch_lines;
CREATE TABLE sav_batch_lines (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  batch_id    INT UNSIGNED NOT NULL,
  account_id  INT UNSIGNED NULL,                   -- savings account, or
  loan_id     INT UNSIGNED NULL,                   -- loan account (payroll amortization)
  member_no   VARCHAR(20)  NULL,
  account_no  VARCHAR(30)  NULL,
  amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
  remarks     VARCHAR(160) NULL,
  error_text  VARCHAR(255) NULL,
  KEY ix_sav_batch_lines_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_products;
CREATE TABLE loan_products (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                  VARCHAR(10)  NOT NULL,
  name                  VARCHAR(80)  NOT NULL,
  loan_type             VARCHAR(40)  NULL,             -- Provident, Productive, Emergency ... (free text group)
  description           VARCHAR(255) NULL,
  receivable_account_id INT UNSIGNED NULL,
  past_due_account_id   INT UNSIGNED NULL,             -- balance moved here when past due (optional)
  passbook_type_id      INT UNSIGNED NULL,
  mode_of_payment       VARCHAR(12)  NOT NULL DEFAULT 'monthly',   -- daily / weekly / semi_monthly / monthly / quarterly / semi_annual / annual / lump_sum
  max_loans             INT          NOT NULL DEFAULT 1,           -- open loans of this product a member may have
  term_unit             VARCHAR(6)   NOT NULL DEFAULT 'months',    -- days / months
  term_min              DECIMAL(9,2) NOT NULL DEFAULT 1,
  term_max              DECIMAL(9,2) NOT NULL DEFAULT 36,
  amount_min            DECIMAL(15,2) NOT NULL DEFAULT 0,
  amount_max            DECIMAL(15,2) NOT NULL DEFAULT 0,
  charge_method         VARCHAR(12)  NOT NULL DEFAULT 'deducted',  -- deducted / separate / capitalized
  interest_method       VARCHAR(12)  NOT NULL DEFAULT 'diminishing',   -- diminishing / straight
  amort_type            VARCHAR(20)  NOT NULL DEFAULT 'equal_amortization',   -- equal_amortization ("Equal Loan") / equal_principal ("Fixed Principal")
  diminishing_by        VARCHAR(10)  NOT NULL DEFAULT 'same',      -- same (as mode of payment) / daily
  past_due_basis        VARCHAR(20)  NOT NULL DEFAULT 'balance_due_date',   -- balance_due_date / schedule
  rate_default          DECIMAL(9,4) NOT NULL DEFAULT 0,
  rate_opt1             DECIMAL(9,4) NULL,
  rate_opt2             DECIMAL(9,4) NULL,
  rate_opt3             DECIMAL(9,4) NULL,
  calendar_days         SMALLINT     NOT NULL DEFAULT 365,
  interest_account_id   INT UNSIGNED NULL,
  interest_posting      VARCHAR(10)  NOT NULL DEFAULT 'monthly',   -- accrual posting of interest income (monthly / none)
  accrued_int_account_id INT UNSIGNED NULL,                        -- interest receivable
  penalty_grace_days    INT          NOT NULL DEFAULT 0,
  penalty_rate          DECIMAL(9,4) NOT NULL DEFAULT 0,           -- percent per annum
  penalty_base          VARCHAR(20)  NOT NULL DEFAULT 'arrears',   -- arrears / principal_arrears / balance
  penalty_account_id    INT UNSIGNED NULL,
  addl_interest         VARCHAR(10)  NOT NULL DEFAULT 'auto',      -- additional interest when past due: auto / arrears / maturity / none
  req_member_months     INT          NOT NULL DEFAULT 0,
  req_comakers          INT          NOT NULL DEFAULT 0,
  req_ndi               DECIMAL(15,2) NOT NULL DEFAULT 0,          -- minimum net disposable income
  req_segment_id        INT UNSIGNED NULL,
  req_capital_mode      VARCHAR(6)   NOT NULL DEFAULT 'min',       -- min (amount) / pct (of loan amount)
  req_capital_value     DECIMAL(15,2) NOT NULL DEFAULT 0,
  req_savings_mode      VARCHAR(6)   NOT NULL DEFAULT 'min',
  req_savings_value     DECIMAL(15,2) NOT NULL DEFAULT 0,
  req_age_min           INT          NOT NULL DEFAULT 0,
  req_age_max           INT          NOT NULL DEFAULT 0,
  requirements_json     TEXT         NULL,                         -- documentary checklist
  needs_ci              TINYINT(1)   NOT NULL DEFAULT 0,
  needs_collateral      TINYINT(1)   NOT NULL DEFAULT 0,
  patronage_eligible    TINYINT(1)   NOT NULL DEFAULT 1,
  is_active             TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order            INT          NOT NULL DEFAULT 0,
  created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME     NULL,
  UNIQUE KEY uq_loan_products_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_product_rates;
CREATE TABLE loan_product_rates (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_id     INT UNSIGNED NOT NULL,
  term_from      DECIMAL(9,2) NOT NULL DEFAULT 0,
  term_to        DECIMAL(9,2) NOT NULL DEFAULT 0,       -- 0 = any term
  amount_from    DECIMAL(15,2) NOT NULL DEFAULT 0,
  amount_to      DECIMAL(15,2) NOT NULL DEFAULT 0,      -- 0 = any amount
  effective_date DATE         NOT NULL,
  rate           DECIMAL(9,4) NOT NULL,
  KEY ix_lpr_product (product_id, effective_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_charges;
CREATE TABLE loan_charges (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(10)  NOT NULL,
  name           VARCHAR(80)  NOT NULL,
  charge_type    VARCHAR(15)  NOT NULL DEFAULT 'financial',   -- financial (income) / non_financial (payable, e.g. insurance) / savings (credited to a member savings product)
  account_id     INT UNSIGNED NULL,
  sav_product_id INT UNSIGNED NULL,
  charge_by      VARCHAR(20)  NOT NULL DEFAULT 'fixed_rate',  -- none / fixed_rate / fixed_amount / rate_by_term / rate_by_amount / amount_by_term / amount_by_amount
  value          DECIMAL(15,4) NOT NULL DEFAULT 0,
  effective_date DATE         NULL,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_loan_charges_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_charge_ranges;
CREATE TABLE loan_charge_ranges (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  charge_id  INT UNSIGNED NOT NULL,
  range_from DECIMAL(15,4) NOT NULL DEFAULT 0,
  range_to   DECIMAL(15,4) NOT NULL DEFAULT 0,
  value      DECIMAL(15,4) NOT NULL DEFAULT 0,
  KEY ix_lcr_charge (charge_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_product_charges;
CREATE TABLE loan_product_charges (
  product_id INT UNSIGNED NOT NULL,
  charge_id  INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (product_id, charge_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_approval_matrix;
CREATE TABLE loan_approval_matrix (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  level_no    INT          NOT NULL DEFAULT 1,
  amount_from DECIMAL(15,2) NOT NULL DEFAULT 0,
  amount_to   DECIMAL(15,2) NOT NULL DEFAULT 0,      -- 0 = no upper limit
  product_id  INT UNSIGNED NULL,                     -- NULL = every product
  role_id     INT UNSIGNED NULL,                     -- the role that may approve at this level (NULL = anyone with Approve on the page)
  position    VARCHAR(80)  NULL,                     -- printed on the approval
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  KEY ix_lam_level (level_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_applications;
CREATE TABLE loan_applications (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_no   VARCHAR(30)  NOT NULL,
  member_id        INT UNSIGNED NOT NULL,
  product_id       INT UNSIGNED NOT NULL,
  branch_id        INT UNSIGNED NULL,
  application_date DATE         NOT NULL,
  purpose          VARCHAR(120) NULL,
  purpose_detail   VARCHAR(255) NULL,
  term             DECIMAL(9,2) NOT NULL DEFAULT 0,
  term_unit        VARCHAR(6)   NOT NULL DEFAULT 'months',
  mode_of_payment  VARCHAR(12)  NOT NULL DEFAULT 'monthly',
  applied_amount   DECIMAL(15,2) NOT NULL DEFAULT 0,
  approved_amount  DECIMAL(15,2) NULL,
  rate             DECIMAL(9,4) NOT NULL DEFAULT 0,
  amort_type       VARCHAR(20)  NULL,
  status           VARCHAR(14)  NOT NULL DEFAULT 'new',   -- new / validated / for_ci / for_approval / approved / disapproved / returned / released / cancelled
  ci_id            INT UNSIGNED NULL,
  monthly_income   DECIMAL(15,2) NOT NULL DEFAULT 0,
  spouse_income    DECIMAL(15,2) NOT NULL DEFAULT 0,
  ndi              DECIMAL(15,2) NOT NULL DEFAULT 0,       -- net disposable income at application
  dependents       INT          NOT NULL DEFAULT 0,
  employer_name    VARCHAR(120) NULL,
  business_name    VARCHAR(120) NULL,
  spouse_name      VARCHAR(120) NULL,
  snapshot_json    TEXT         NULL,                      -- capital, savings, TD, existing loans at application
  eligibility_json TEXT         NULL,                      -- the checks and their results
  officer_id       INT UNSIGNED NULL,
  loan_id          INT UNSIGNED NULL,                      -- the released loan account
  remarks          VARCHAR(255) NULL,
  cancel_reason    VARCHAR(255) NULL,
  previous_loan_id INT UNSIGNED NULL,                      -- renewal / restructuring: the open loan paid off from the proceeds
  is_restructure   TINYINT(1)   NOT NULL DEFAULT 0,
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by       INT UNSIGNED NULL,
  updated_at       DATETIME     NULL,
  class_id         INT UNSIGNED NULL,                       -- the class of a renewal or restructure (Class List)
  UNIQUE KEY uq_loan_applications_no (application_no),
  KEY ix_loan_applications_member (member_id),
  KEY ix_loan_applications_status (status),
  KEY ix_loan_applications_branch (branch_id),
  KEY ix_loan_applications_class (class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_collaterals;
CREATE TABLE loan_collaterals (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_id  INT UNSIGNED NOT NULL,
  collateral_type VARCHAR(60)  NOT NULL,
  description     VARCHAR(255) NULL,
  ref_no          VARCHAR(80)  NULL,
  fmv             DECIMAL(15,2) NOT NULL DEFAULT 0,
  loan_value      DECIMAL(15,2) NOT NULL DEFAULT 0,
  expiry_date     DATE         NULL,
  insurance_expiry DATE        NULL,
  status          VARCHAR(12)  NOT NULL DEFAULT 'held',   -- held / released
  released_on     DATE         NULL,
  KEY ix_loan_collaterals_app (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_comakers;
CREATE TABLE loan_comakers (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_id    INT UNSIGNED NOT NULL,
  comaker_member_id INT UNSIGNED NULL,
  name              VARCHAR(160) NULL,
  address           VARCHAR(255) NULL,
  amount_guaranteed DECIMAL(15,2) NOT NULL DEFAULT 0,
  KEY ix_loan_comakers_app (application_id),
  KEY ix_loan_comakers_member (comaker_member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_approvals;
CREATE TABLE loan_approvals (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_id  INT UNSIGNED NOT NULL,
  level_no        INT          NOT NULL DEFAULT 1,
  approver_id     INT UNSIGNED NOT NULL,
  position        VARCHAR(80)  NULL,
  approved_amount DECIMAL(15,2) NULL,
  action          VARCHAR(12)  NOT NULL,       -- approved / disapproved / returned / deferred
  remarks         VARCHAR(255) NULL,
  acted_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_loan_approvals_app (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_application_docs;
CREATE TABLE loan_application_docs (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  requirement    VARCHAR(120) NOT NULL,
  is_complete    TINYINT(1)   NOT NULL DEFAULT 0,
  file_name      VARCHAR(120) NULL,
  original_name  VARCHAR(190) NULL,
  remarks        VARCHAR(160) NULL,
  KEY ix_loan_app_docs_app (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_ci;
CREATE TABLE loan_ci (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_id   INT UNSIGNED NOT NULL,
  member_id        INT UNSIGNED NOT NULL,
  date_reported    DATE         NOT NULL,
  last_updated     DATE         NULL,
  spouse_name      VARCHAR(120) NULL,
  current_address  VARCHAR(255) NULL,
  previous_address VARCHAR(255) NULL,
  residence_type   VARCHAR(40)  NULL,
  house_type       VARCHAR(40)  NULL,
  building_type    VARCHAR(40)  NULL,
  structure_type   VARCHAR(40)  NULL,
  storeys          INT          NULL,
  rooms            INT          NULL,
  lot_area         DECIMAL(10,2) NULL,
  floor_area       DECIMAL(10,2) NULL,
  estimated_value  DECIMAL(15,2) NULL,
  remarks          TEXT         NULL,
  informants       VARCHAR(255) NULL,
  conducted_by     VARCHAR(120) NULL,
  recommendation   VARCHAR(20)  NULL,       -- recommended / not_recommended / with_conditions
  score            DECIMAL(6,2) NULL,
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_loan_ci_app (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_ci_lines;
CREATE TABLE loan_ci_lines (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ci_id     INT UNSIGNED NOT NULL,
  kind      VARCHAR(12)  NOT NULL,     -- child / income / asset / reference
  name      VARCHAR(160) NULL,
  detail1   VARCHAR(160) NULL,         -- child: level | income: business/employer | asset: type | reference: occupation
  detail2   VARCHAR(160) NULL,         -- child: school | income: type | reference: contact
  detail3   VARCHAR(160) NULL,         -- income: position
  years     DECIMAL(5,1) NULL,
  amount    DECIMAL(15,2) NULL,        -- income: net income | asset: amount
  KEY ix_loan_ci_lines_ci (ci_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_accounts;
CREATE TABLE loan_accounts (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  loan_no            VARCHAR(30)  NOT NULL,
  application_id     INT UNSIGNED NULL,
  member_id          INT UNSIGNED NOT NULL,
  product_id         INT UNSIGNED NOT NULL,
  branch_id          INT UNSIGNED NULL,
  pn_no              VARCHAR(30)  NULL,
  principal          DECIMAL(15,2) NOT NULL,
  rate               DECIMAL(9,4) NOT NULL,
  interest_method    VARCHAR(12)  NOT NULL,
  amort_type         VARCHAR(20)  NOT NULL,
  term               DECIMAL(9,2) NOT NULL,
  term_unit          VARCHAR(6)   NOT NULL,
  mode_of_payment    VARCHAR(12)  NOT NULL,
  calendar_days      SMALLINT     NOT NULL DEFAULT 365,
  release_date       DATE         NOT NULL,
  start_date         DATE         NOT NULL,          -- first amortization date
  due_date           DATE         NOT NULL,          -- maturity
  release_mode       VARCHAR(10)  NOT NULL DEFAULT 'cash',   -- cash / cheque / savings
  cheque_no          VARCHAR(40)  NULL,
  cash_account_id    INT UNSIGNED NULL,
  credit_account_id  INT UNSIGNED NULL,              -- savings account credited with the proceeds
  receivable_account_id INT UNSIGNED NOT NULL,
  interest_account_id INT UNSIGNED NOT NULL,
  penalty_account_id INT UNSIGNED NULL,
  penalty_rate       DECIMAL(9,4) NOT NULL DEFAULT 0,
  penalty_grace_days INT          NOT NULL DEFAULT 0,
  penalty_base       VARCHAR(20)  NOT NULL DEFAULT 'arrears',
  total_interest     DECIMAL(15,2) NOT NULL DEFAULT 0,     -- scheduled interest
  total_charges      DECIMAL(15,2) NOT NULL DEFAULT 0,
  net_proceeds       DECIMAL(15,2) NOT NULL DEFAULT 0,
  previous_loan_payoff DECIMAL(15,2) NOT NULL DEFAULT 0,
  status             VARCHAR(14)  NOT NULL DEFAULT 'current',   -- current / past_due / paid / restructured / written_off / cancelled
  principal_balance  DECIMAL(15,2) NOT NULL DEFAULT 0,
  interest_balance   DECIMAL(15,2) NOT NULL DEFAULT 0,     -- scheduled interest not yet paid
  interest_accrued   DECIMAL(15,2) NOT NULL DEFAULT 0,     -- interest income already recognised (accrual runs)
  penalty_balance    DECIMAL(15,2) NOT NULL DEFAULT 0,     -- penalties charged and unpaid
  penalty_waived     DECIMAL(15,2) NOT NULL DEFAULT 0,
  interest_waived    DECIMAL(15,2) NOT NULL DEFAULT 0,
  last_payment_date  DATE         NULL,
  last_penalty_date  DATE         NULL,                    -- penalties computed through this date
  officer_id         INT UNSIGNED NULL,
  release_txn_id     BIGINT UNSIGNED NULL,
  voucher_no         VARCHAR(30)  NULL,                    -- the voucher ID of the release (LR- series)
  closed_on          DATE         NULL,
  restructured_to_id INT UNSIGNED NULL,
  restructured_from_id INT UNSIGNED NULL,
  written_off_on     DATE         NULL,
  written_off_txn_id BIGINT UNSIGNED NULL,
  recovered_amount   DECIMAL(15,2) NOT NULL DEFAULT 0,
  pb_last_line       INT          NOT NULL DEFAULT 0,
  pb_page            INT          NOT NULL DEFAULT 1,
  pb_last_txn_id     BIGINT UNSIGNED NULL,
  notes              VARCHAR(255) NULL,
  created_by         INT UNSIGNED NULL,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME     NULL,
  UNIQUE KEY uq_loan_accounts_no (loan_no),
  KEY ix_loan_accounts_member (member_id),
  KEY ix_loan_accounts_status (status, due_date),
  KEY ix_loan_accounts_product (product_id),
  KEY ix_loan_accounts_branch (branch_id),
  KEY ix_loan_accounts_app (application_id),
  KEY ix_loan_accounts_voucher (voucher_no),
  KEY ix_loan_accounts_release_txn (release_txn_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_schedule;
CREATE TABLE loan_schedule (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  loan_id        INT UNSIGNED NOT NULL,
  inst_no        INT          NOT NULL,
  due_date       DATE         NOT NULL,
  principal      DECIMAL(15,2) NOT NULL DEFAULT 0,
  interest       DECIMAL(15,2) NOT NULL DEFAULT 0,
  total          DECIMAL(15,2) NOT NULL DEFAULT 0,
  principal_paid DECIMAL(15,2) NOT NULL DEFAULT 0,
  interest_paid  DECIMAL(15,2) NOT NULL DEFAULT 0,
  balance_after  DECIMAL(15,2) NOT NULL DEFAULT 0,     -- principal balance after this instalment
  is_paid        TINYINT(1)   NOT NULL DEFAULT 0,
  paid_on        DATE         NULL,
  UNIQUE KEY uq_loan_schedule (loan_id, inst_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_charges_applied;
CREATE TABLE loan_charges_applied (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  loan_id        INT UNSIGNED NOT NULL,
  charge_id      INT UNSIGNED NULL,
  name           VARCHAR(80)  NOT NULL,
  charge_type    VARCHAR(15)  NOT NULL DEFAULT 'financial',
  basis_text     VARCHAR(60)  NULL,            -- "3.00 %" / "fixed 150.00"
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  account_id     INT UNSIGNED NULL,
  sav_account_id INT UNSIGNED NULL,
  how            VARCHAR(12)  NOT NULL DEFAULT 'deducted',   -- deducted / separate / capitalized
  KEY ix_loan_charges_applied_loan (loan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_txns;
CREATE TABLE loan_txns (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  loan_id       INT UNSIGNED NOT NULL,
  txn_date      DATE         NOT NULL,
  kind          VARCHAR(16)  NOT NULL,       -- release / payment / penalty / adjustment / waiver / accrual / writeoff / recovery / restructure
  memo          VARCHAR(160) NULL,
  principal_dr  DECIMAL(15,2) NOT NULL DEFAULT 0,   -- charges to the member (release, adjustments)
  interest_dr   DECIMAL(15,2) NOT NULL DEFAULT 0,
  penalty_dr    DECIMAL(15,2) NOT NULL DEFAULT 0,
  principal_cr  DECIMAL(15,2) NOT NULL DEFAULT 0,   -- payments / waivers
  interest_cr   DECIMAL(15,2) NOT NULL DEFAULT 0,
  penalty_cr    DECIMAL(15,2) NOT NULL DEFAULT 0,
  principal_bal DECIMAL(15,2) NOT NULL DEFAULT 0,
  interest_bal  DECIMAL(15,2) NOT NULL DEFAULT 0,
  penalty_bal   DECIMAL(15,2) NOT NULL DEFAULT 0,
  txn_id        BIGINT UNSIGNED NULL,
  or_no         VARCHAR(30)  NULL,
  teller_id     INT UNSIGNED NULL,
  shift_id      INT UNSIGNED NULL,
  is_voided     TINYINT(1)   NOT NULL DEFAULT 0,
  pb_printed    TINYINT(1)   NOT NULL DEFAULT 0,
  pb_line       INT          NULL,
  pb_page       INT          NULL,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  corrects_id     BIGINT UNSIGNED NULL,      -- an error correction: the loan payment it negates
  corrected_by_id BIGINT UNSIGNED NULL,      -- a payment: the error correction that negated it
  KEY ix_loan_txns_loan (loan_id, txn_date, id),
  KEY ix_loan_txns_txn (txn_id),
  KEY ix_loan_txns_shift (shift_id),
  KEY ix_loan_txns_corrects (corrects_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_payment_alloc;
CREATE TABLE loan_payment_alloc (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  loan_txn_id BIGINT UNSIGNED NOT NULL,
  schedule_id INT UNSIGNED NOT NULL,
  principal   DECIMAL(15,2) NOT NULL DEFAULT 0,
  interest    DECIMAL(15,2) NOT NULL DEFAULT 0,
  KEY ix_lpa_txn (loan_txn_id),
  KEY ix_lpa_schedule (schedule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_waivers;
CREATE TABLE loan_waivers (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  loan_id         INT UNSIGNED NOT NULL,
  waive_date      DATE         NOT NULL,
  interest_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  penalty_amount  DECIMAL(15,2) NOT NULL DEFAULT 0,
  memo            VARCHAR(255) NULL,
  status          VARCHAR(10)  NOT NULL DEFAULT 'pending',   -- pending / approved / posted / rejected / voided
  requested_by    INT UNSIGNED NULL,
  approved_by     INT UNSIGNED NULL,
  approved_at     DATETIME     NULL,
  txn_id          BIGINT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  class_id        INT UNSIGNED NULL,                        -- the class of the waiver's posting (Class List)
  KEY ix_loan_waivers_loan (loan_id),
  KEY ix_loan_waivers_class (class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_adjustments;
CREATE TABLE loan_adjustments (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  loan_id        INT UNSIGNED NOT NULL,
  adj_date       DATE         NOT NULL,
  adj_account_id INT UNSIGNED NOT NULL,
  principal_amt  DECIMAL(15,2) NOT NULL DEFAULT 0,
  principal_type VARCHAR(8)   NULL,     -- increase / decrease
  interest_amt   DECIMAL(15,2) NOT NULL DEFAULT 0,
  interest_type  VARCHAR(8)   NULL,
  penalty_amt    DECIMAL(15,2) NOT NULL DEFAULT 0,
  penalty_type   VARCHAR(8)   NULL,
  memo           VARCHAR(255) NULL,
  txn_id         BIGINT UNSIGNED NULL,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_loan_adjustments_loan (loan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_notices;
CREATE TABLE loan_notices (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  loan_id     INT UNSIGNED NOT NULL,
  notice_type VARCHAR(20)  NOT NULL,      -- reminder / demand / final_demand / followup
  notice_date DATE         NOT NULL,
  amount_due  DECIMAL(15,2) NOT NULL DEFAULT 0,
  notes       VARCHAR(255) NULL,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_loan_notices_loan (loan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS teller_shifts;
CREATE TABLE teller_shifts (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id           INT UNSIGNED NOT NULL,
  branch_id         INT UNSIGNED NULL,
  cash_account_id   INT UNSIGNED NOT NULL,
  business_date     DATE         NOT NULL,
  opened_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  opening_cash      DECIMAL(15,2) NOT NULL DEFAULT 0,
  receipts_total    DECIMAL(15,2) NOT NULL DEFAULT 0,
  disbursements_total DECIMAL(15,2) NOT NULL DEFAULT 0,
  expected_cash     DECIMAL(15,2) NULL,
  counted_cash      DECIMAL(15,2) NULL,
  over_short        DECIMAL(15,2) NULL,
  over_short_txn_id BIGINT UNSIGNED NULL,
  denominations_json TEXT        NULL,
  status            VARCHAR(8)   NOT NULL DEFAULT 'open',   -- open / closed
  closed_at         DATETIME     NULL,
  closed_by         INT UNSIGNED NULL,
  notes             VARCHAR(255) NULL,
  KEY ix_teller_shifts_user (user_id, status),
  KEY ix_teller_shifts_date (business_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS coop_receipts;
CREATE TABLE coop_receipts (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id           BIGINT UNSIGNED NOT NULL,
  receipt_kind     VARCHAR(4)   NOT NULL DEFAULT 'or',   -- or (money in) / cv (money out)
  receipt_no       VARCHAR(30)  NOT NULL,
  member_id        INT UNSIGNED NULL,
  payor_name       VARCHAR(160) NULL,
  branch_id        INT UNSIGNED NULL,
  shift_id         INT UNSIGNED NULL,
  payment_mode     VARCHAR(10)  NOT NULL DEFAULT 'cash',  -- cash / check / bank / savings (transfer)
  cash_account_id  INT UNSIGNED NULL,
  cheque_json      TEXT         NULL,                     -- [{cheque_no, bank, date, amount}]
  check_pending    DECIMAL(15,2) NOT NULL DEFAULT 0,      -- check money for loan payments / fees waiting in the clearing account
  check_cleared_txn_id BIGINT UNSIGNED NULL,              -- the voucher that cleared it to the bank
  total            DECIMAL(15,2) NOT NULL DEFAULT 0,
  is_back_entry    TINYINT(1)   NOT NULL DEFAULT 0,
  print_count      INT          NOT NULL DEFAULT 0,
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_coop_receipts_no (receipt_kind, receipt_no),
  UNIQUE KEY uq_coop_receipts_txn (txn_id),
  KEY ix_coop_receipts_member (member_id),
  KEY ix_coop_receipts_shift (shift_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS coop_receipt_lines;
CREATE TABLE coop_receipt_lines (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  receipt_id  BIGINT UNSIGNED NOT NULL,
  line_no     INT          NOT NULL DEFAULT 0,
  kind        VARCHAR(16)  NOT NULL,        -- savings / loan_principal / loan_interest / loan_penalty / charge / other / td
  ref_id      BIGINT UNSIGNED NULL,         -- sav_accounts.id / loan_accounts.id
  account_id  INT UNSIGNED NULL,            -- GL account the line posted to
  description VARCHAR(160) NULL,
  amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
  KEY ix_coop_receipt_lines_receipt (receipt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS dist_periods;
CREATE TABLE dist_periods (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  fiscal_year     SMALLINT     NOT NULL,
  period_from     DATE         NOT NULL,
  period_to       DATE         NOT NULL,
  net_surplus     DECIMAL(15,2) NOT NULL DEFAULT 0,
  reserve_pct     DECIMAL(6,2) NOT NULL DEFAULT 10,
  reserve_amt     DECIMAL(15,2) NOT NULL DEFAULT 0,
  educ_pct        DECIMAL(6,2) NOT NULL DEFAULT 10,
  educ_amt        DECIMAL(15,2) NOT NULL DEFAULT 0,
  educ_apex_pct   DECIMAL(6,2) NOT NULL DEFAULT 50,     -- share of the education fund due to the apex organisation
  cdf_pct         DECIMAL(6,2) NOT NULL DEFAULT 3,
  cdf_amt         DECIMAL(15,2) NOT NULL DEFAULT 0,
  optional_pct    DECIMAL(6,2) NOT NULL DEFAULT 7,
  optional_amt    DECIMAL(15,2) NOT NULL DEFAULT 0,
  isc_amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
  pr_amount       DECIMAL(15,2) NOT NULL DEFAULT 0,
  status          VARCHAR(10)  NOT NULL DEFAULT 'draft',    -- draft / approved / posted / closed
  resolution_no   VARCHAR(60)  NULL,
  resolution_date DATE         NULL,
  approving_officers VARCHAR(255) NULL,
  approved_by     INT UNSIGNED NULL,
  approved_at     DATETIME     NULL,
  allocation_txn_id BIGINT UNSIGNED NULL,
  notes           TEXT         NULL,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dist_periods_year (fiscal_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS dist_batches;
CREATE TABLE dist_batches (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  period_id        INT UNSIGNED NULL,
  kind             VARCHAR(4)   NOT NULL,            -- isc / pr
  batch_no         VARCHAR(30)  NOT NULL,
  description      VARCHAR(160) NULL,
  filters_json     TEXT         NULL,                -- cluster, segment, member types, product, amount range, date range, income accounts
  date_from        DATE         NOT NULL,
  date_to          DATE         NOT NULL,
  method           VARCHAR(6)   NOT NULL DEFAULT 'rate',   -- rate / pool
  rate             DECIMAL(9,4) NOT NULL DEFAULT 0,
  pool             DECIMAL(15,2) NOT NULL DEFAULT 0,
  basis            VARCHAR(10)  NOT NULL DEFAULT 'adb',    -- isc: adb / ending ; pr: income
  credit_mode      VARCHAR(14)  NOT NULL DEFAULT 'savings', -- savings / share_capital / payable
  credit_product_id INT UNSIGNED NULL,
  total_basis      DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,
  rounding_diff    DECIMAL(15,2) NOT NULL DEFAULT 0,
  members_count    INT          NOT NULL DEFAULT 0,
  status           VARCHAR(10)  NOT NULL DEFAULT 'draft',  -- draft / validated / approved / posted / cancelled
  memo             VARCHAR(255) NULL,
  resolution_ref   VARCHAR(120) NULL,
  approved_by      INT UNSIGNED NULL,
  approved_at      DATETIME     NULL,
  posted_at        DATETIME     NULL,
  posted_by        INT UNSIGNED NULL,
  txn_id           BIGINT UNSIGNED NULL,
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dist_batches_no (batch_no),
  KEY ix_dist_batches_period (period_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS dist_batch_lines;
CREATE TABLE dist_batch_lines (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  batch_id        INT UNSIGNED NOT NULL,
  member_id       INT UNSIGNED NOT NULL,
  sav_account_id  INT UNSIGNED NULL,       -- the account measured (isc) / the account credited
  basis_amount    DECIMAL(15,2) NOT NULL DEFAULT 0,   -- ADB / income earned
  current_balance DECIMAL(15,2) NOT NULL DEFAULT 0,
  computed        DECIMAL(15,2) NOT NULL DEFAULT 0,
  adjustment      DECIMAL(15,2) NOT NULL DEFAULT 0,   -- authorised offset / correction
  net_amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
  is_excluded     TINYINT(1)   NOT NULL DEFAULT 0,
  exclude_reason  VARCHAR(160) NULL,
  sav_txn_id      BIGINT UNSIGNED NULL,
  paid_txn_id     BIGINT UNSIGNED NULL,               -- cash payout voucher (payable mode)
  paid_on         DATE         NULL,
  KEY ix_dist_batch_lines_batch (batch_id),
  KEY ix_dist_batch_lines_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS coop_officers;
CREATE TABLE coop_officers (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id  INT UNSIGNED NULL,
  name       VARCHAR(160) NOT NULL,
  body       VARCHAR(30)  NOT NULL DEFAULT 'board',   -- board / audit / election / credit / education / ethics / mediation / management / other
  position   VARCHAR(80)  NOT NULL,
  term_start DATE         NULL,
  term_end   DATE         NULL,
  trainings  TEXT         NULL,      -- one per line: title, date, provider
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  KEY ix_coop_officers_body (body)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cda_compliance;
CREATE TABLE cda_compliance (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  report_year  SMALLINT     NOT NULL,
  item         VARCHAR(120) NOT NULL,     -- CAPR, Audited FS, Social Audit, Performance Report, List of Officers, COC application, Annual Tax Incentives Report ...
  due_date     DATE         NULL,
  submitted_on DATE         NULL,
  reference_no VARCHAR(80)  NULL,
  status       VARCHAR(12)  NOT NULL DEFAULT 'pending',   -- pending / submitted / accepted / late / not_applicable
  file_name    VARCHAR(120) NULL,
  original_name VARCHAR(190) NULL,
  notes        VARCHAR(255) NULL,
  updated_by   INT UNSIGNED NULL,
  updated_at   DATETIME     NULL,
  KEY ix_cda_compliance_year (report_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cda_calendar;
CREATE TABLE cda_calendar (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title       VARCHAR(160) NOT NULL,
  category    VARCHAR(30)  NOT NULL DEFAULT 'cda',   -- cda / bir / lgu / internal
  due_date    DATE         NOT NULL,
  recurrence  VARCHAR(10)  NOT NULL DEFAULT 'yearly', -- none / monthly / quarterly / yearly
  remind_days INT          NOT NULL DEFAULT 30,
  notes       VARCHAR(255) NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  KEY ix_cda_calendar_due (is_active, due_date),
  UNIQUE KEY uq_cda_calendar_title (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- opening balances brought over from a previous system (one voucher per import; corrected by adjusting entries, never voided)
DROP TABLE IF EXISTS coop_imports;
CREATE TABLE coop_imports (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kind              VARCHAR(16)  NOT NULL,        -- members / savings / time_deposits / loans
  as_of             DATE         NOT NULL,
  rows_count        INT          NOT NULL DEFAULT 0,
  total             DECIMAL(15,2) NOT NULL DEFAULT 0,
  offset_account_id INT UNSIGNED NULL,
  txn_id            BIGINT UNSIGNED NULL,
  file_name         VARCHAR(190) NULL,
  summary_json      TEXT         NULL,
  created_by        INT UNSIGNED NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_coop_imports_txn (txn_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cda_report_mappings;
CREATE TABLE cda_report_mappings (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  report     VARCHAR(30)  NOT NULL DEFAULT 'capr',
  section    VARCHAR(60)  NULL,
  field_code VARCHAR(40)  NOT NULL,
  label      VARCHAR(160) NOT NULL,
  source     VARCHAR(255) NOT NULL,     -- metric:<name> or account:<code list> or text:<value>
  sort_order INT          NOT NULL DEFAULT 0,
  KEY ix_cda_report_mappings_report (report, sort_order),
  UNIQUE KEY uq_cda_report_mappings_field (report, field_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  MULTIPURPOSE: PARTY MASTER, BUSINESS UNITS, DOCUMENT REISSUES
-- ---------------------------------------------------------------------

-- the Party Master: one row per natural or juridical person, whatever roles it holds
DROP TABLE IF EXISTS parties;
CREATE TABLE parties (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  party_no        VARCHAR(20)  NOT NULL,                     -- the stable Party ID (P-000001)
  party_type      VARCHAR(12)  NOT NULL DEFAULT 'person',    -- person / organization
  legal_name      VARCHAR(160) NOT NULL,                     -- printed on documents
  display_name    VARCHAR(160) NOT NULL,                     -- shown in lists
  last_name       VARCHAR(80)  NULL,
  first_name      VARCHAR(80)  NULL,
  middle_name     VARCHAR(80)  NULL,
  suffix          VARCHAR(20)  NULL,
  tin             VARCHAR(30)  NULL,
  birth_date      DATE         NULL,
  registration_no VARCHAR(60)  NULL,                         -- SEC / CDA / DTI registration of an organization
  email           VARCHAR(120) NULL,
  mobile          VARCHAR(40)  NULL,
  phone           VARCHAR(40)  NULL,
  address         VARCHAR(255) NULL,
  home_branch_id  INT UNSIGNED NULL,
  status          VARCHAR(10)  NOT NULL DEFAULT 'active',    -- active / inactive / merged
  merged_into_id  INT UNSIGNED NULL,
  src_table       VARCHAR(12)  NULL,                         -- where the party was first made from (backfill)
  src_id          INT UNSIGNED NULL,
  notes           VARCHAR(255) NULL,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by      INT UNSIGNED NULL,
  updated_at      DATETIME     NULL,
  UNIQUE KEY uq_parties_no (party_no),
  UNIQUE KEY uq_parties_src (src_table, src_id),
  KEY ix_parties_name (legal_name(100)),
  KEY ix_parties_display (display_name(100)),
  KEY ix_parties_tin (tin),
  KEY ix_parties_mobile (mobile),
  KEY ix_parties_email (email(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- the old IDs and the party each one was given (backfill, creation or a reviewed link)
DROP TABLE IF EXISTS party_crosswalk;
CREATE TABLE party_crosswalk (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source      VARCHAR(12)  NOT NULL,                         -- customer / vendor / member / other_name
  source_id   INT UNSIGNED NOT NULL,
  source_ref  VARCHAR(160) NULL,                             -- the name and number at that time
  party_id    INT UNSIGNED NOT NULL,
  how         VARCHAR(12)  NOT NULL DEFAULT 'backfill',      -- backfill / created
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_party_crosswalk_src (source, source_id),
  KEY ix_party_crosswalk_party (party_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- every role granted, revoked, restored, linked or moved, with the reason and who approved it
DROP TABLE IF EXISTS party_role_log;
CREATE TABLE party_role_log (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  party_id       INT UNSIGNED NOT NULL,
  role           VARCHAR(12)  NOT NULL,                      -- member / customer / supplier / employee / other / party
  ref_id         INT UNSIGNED NULL,                          -- the role record (customers.id, vendors.id ...)
  action         VARCHAR(12)  NOT NULL,                      -- granted / revoked / restored / moved_in / moved_out / merged / edited
  other_party_id INT UNSIGNED NULL,
  reason         VARCHAR(255) NULL,
  approved_by    INT UNSIGNED NULL,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_party_role_log_party (party_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- possible duplicates found by the identity review, and what was decided
DROP TABLE IF EXISTS party_match_reviews;
CREATE TABLE party_match_reviews (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  party_a     INT UNSIGNED NOT NULL,                         -- the lower party id
  party_b     INT UNSIGNED NOT NULL,
  basis       VARCHAR(120) NOT NULL,                         -- same TIN, same e-mail, same mobile, same name ...
  score       INT          NOT NULL DEFAULT 0,
  status      VARCHAR(10)  NOT NULL DEFAULT 'open',          -- open / merged / distinct
  decided_by  INT UNSIGNED NULL,
  decided_at  DATETIME     NULL,
  reason      VARCHAR(255) NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_party_match_pair (party_a, party_b),
  KEY ix_party_match_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- the cooperative's lines of business (savings and credit, consumer store, marketing, services ...)
DROP TABLE IF EXISTS business_units;
CREATE TABLE business_units (
  id                       INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                     VARCHAR(10)  NOT NULL,
  name                     VARCHAR(80)  NOT NULL,
  kind                     VARCHAR(20)  NOT NULL DEFAULT 'other',   -- savings_credit / retail / wholesale / marketing / production / service / other
  nonmember_policy         VARCHAR(10)  NOT NULL DEFAULT 'allow',   -- allow / approval / members (members only)
  patronage_eligible       TINYINT(1)   NOT NULL DEFAULT 1,         -- its member transactions can count for patronage refund
  member_price_level_id    INT UNSIGNED NULL,                       -- price level of members buying here (when the customer has none)
  nonmember_price_level_id INT UNSIGNED NULL,
  default_site_id          INT UNSIGNED NULL,
  description              VARCHAR(255) NULL,
  is_active                TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order               INT          NOT NULL DEFAULT 0,
  created_at               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_business_units_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- authorized corrections of the counterparty name / address printed on an issued document
DROP TABLE IF EXISTS document_reissues;
CREATE TABLE document_reissues (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id       BIGINT UNSIGNED NOT NULL,
  reissue_no   INT          NOT NULL,
  old_name     VARCHAR(160) NULL,
  new_name     VARCHAR(160) NULL,
  old_address  VARCHAR(255) NULL,
  new_address  VARCHAR(255) NULL,
  old_tin      VARCHAR(30)  NULL,
  new_tin      VARCHAR(30)  NULL,
  reason       VARCHAR(255) NOT NULL,
  approved_by  INT UNSIGNED NULL,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_document_reissues_txn (txn_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  2. CABLE / INTERNET TABLES (database/migrations/2027_04_01_isp_cable_system.sql)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `isp_areas`;
CREATE TABLE isp_areas (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  parent_id     INT UNSIGNED NULL,
  area_type     VARCHAR(20)  NOT NULL DEFAULT 'barangay',   
  code          VARCHAR(20)  NOT NULL,
  name          VARCHAR(120) NOT NULL,
  branch_id     INT UNSIGNED NULL,                          
  latitude      DECIMAL(10,7) NULL,
  longitude     DECIMAL(10,7) NULL,
  is_serviceable TINYINT(1)  NOT NULL DEFAULT 1,            
  notes         VARCHAR(255) NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_areas_code (code),
  KEY ix_isp_areas_parent (parent_id),
  KEY ix_isp_areas_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_plans`;
CREATE TABLE isp_plans (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                VARCHAR(20)  NOT NULL,
  name                VARCHAR(120) NOT NULL,
  description         VARCHAR(255) NULL,
  category            VARCHAR(20)  NOT NULL DEFAULT 'residential', 
  service_type        VARCHAR(20)  NOT NULL DEFAULT 'postpaid',    
  technology          VARCHAR(20)  NOT NULL DEFAULT 'fiber',       
  includes_catv       TINYINT(1)   NOT NULL DEFAULT 0,             
  monthly_fee         DECIMAL(15,2) NOT NULL DEFAULT 0,
  member_monthly_fee  DECIMAL(15,2) NULL,                          
  download_mbps       DECIMAL(10,2) NOT NULL DEFAULT 0,
  upload_mbps         DECIMAL(10,2) NOT NULL DEFAULT 0,
  data_cap_gb         DECIMAL(10,2) NULL,                          
  fair_use_policy     VARCHAR(255) NULL,
  installation_fee    DECIMAL(15,2) NOT NULL DEFAULT 0,
  router_rental_fee   DECIMAL(15,2) NOT NULL DEFAULT 0,
  static_ip_fee       DECIMAL(15,2) NOT NULL DEFAULT 0,
  equipment_deposit   DECIMAL(15,2) NOT NULL DEFAULT 0,
  contract_months     SMALLINT     NOT NULL DEFAULT 12,
  lock_in_months      SMALLINT     NOT NULL DEFAULT 0,
  early_termination_fee DECIMAL(15,2) NOT NULL DEFAULT 0,
  vat_mode            VARCHAR(12)  NOT NULL DEFAULT 'inclusive',   
  late_fee_type       VARCHAR(10)  NOT NULL DEFAULT 'fixed',       
  late_fee_value      DECIMAL(15,4) NOT NULL DEFAULT 0,
  reconnection_fee    DECIMAL(15,2) NOT NULL DEFAULT 0,
  promo_discount_pct  DECIMAL(7,4) NOT NULL DEFAULT 0,
  promo_months        SMALLINT     NOT NULL DEFAULT 0,             
  available_from      DATE         NULL,
  available_to        DATE         NULL,
  network_profile     VARCHAR(60)  NULL,                           
  income_account_id   INT UNSIGNED NULL,                           
  status              VARCHAR(12)  NOT NULL DEFAULT 'active',      
  sort_order          INT          NOT NULL DEFAULT 0,
  created_by          INT UNSIGNED NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NULL,
  UNIQUE KEY uq_isp_plans_code (code),
  KEY ix_isp_plans_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_plan_areas`;
CREATE TABLE isp_plan_areas (
  plan_id  INT UNSIGNED NOT NULL,
  area_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (plan_id, area_id),
  KEY ix_isp_plan_areas_area (area_id),
  CONSTRAINT fk_isp_plan_areas_plan FOREIGN KEY (plan_id) REFERENCES isp_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_isp_plan_areas_area FOREIGN KEY (area_id) REFERENCES isp_areas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_customers`;
CREATE TABLE isp_customers (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  customer_no     VARCHAR(20)  NOT NULL,                 
  party_id        INT UNSIGNED NULL,                     
  customer_id     INT UNSIGNED NULL,                     
  classification  VARCHAR(20)  NOT NULL DEFAULT 'residential', 
  name            VARCHAR(160) NOT NULL,
  billing_address VARCHAR(255) NULL,
  mobile          VARCHAR(40)  NULL,
  phone           VARCHAR(40)  NULL,
  email           VARCHAR(120) NULL,
  tin             VARCHAR(30)  NULL,
  id_type         VARCHAR(40)  NULL,
  id_number       VARCHAR(60)  NULL,
  id_verified_at  DATETIME     NULL,
  id_verified_by  INT UNSIGNED NULL,
  notify_sms      TINYINT(1)   NOT NULL DEFAULT 1,
  notify_email    TINYINT(1)   NOT NULL DEFAULT 1,
  privacy_consent_at DATETIME  NULL,                     
  notes           TEXT         NULL,
  status          VARCHAR(12)  NOT NULL DEFAULT 'active', 
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL,
  UNIQUE KEY uq_isp_customers_no (customer_no),
  KEY ix_isp_customers_party (party_id),
  KEY ix_isp_customers_customer (customer_id),
  KEY ix_isp_customers_name (name),
  CONSTRAINT fk_isp_customers_party FOREIGN KEY (party_id) REFERENCES parties(id),
  CONSTRAINT fk_isp_customers_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_contacts`;
CREATE TABLE isp_contacts (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  isp_customer_id INT UNSIGNED NOT NULL,
  name            VARCHAR(120) NOT NULL,
  relationship    VARCHAR(60)  NULL,
  mobile          VARCHAR(40)  NULL,
  email           VARCHAR(120) NULL,
  is_authorized   TINYINT(1)   NOT NULL DEFAULT 1,     
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_isp_contacts_customer (isp_customer_id),
  CONSTRAINT fk_isp_contacts_customer FOREIGN KEY (isp_customer_id) REFERENCES isp_customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_accounts`;
CREATE TABLE isp_accounts (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_no         VARCHAR(30)  NOT NULL,              
  isp_customer_id    INT UNSIGNED NOT NULL,
  account_name       VARCHAR(160) NOT NULL,              
  branch_id          INT UNSIGNED NULL,                  
  area_id            INT UNSIGNED NULL,
  plan_id            INT UNSIGNED NOT NULL,
  member_price       TINYINT(1)   NOT NULL DEFAULT 0,    
  install_address    VARCHAR(255) NOT NULL,
  landmark           VARCHAR(160) NULL,
  latitude           DECIMAL(10,7) NULL,
  longitude          DECIMAL(10,7) NULL,
  service_status     VARCHAR(16)  NOT NULL DEFAULT 'pending',   
  network_state      VARCHAR(16)  NOT NULL DEFAULT 'not_provisioned', 
  billing_basis      VARCHAR(12)  NOT NULL DEFAULT 'calendar',  
  bill_timing        VARCHAR(10)  NOT NULL DEFAULT 'advance',   
  cycle_day          TINYINT      NOT NULL DEFAULT 1,           
  due_days           SMALLINT     NOT NULL DEFAULT 15,          
  custom_due_day     TINYINT      NULL,                         
  prorate_first      TINYINT(1)   NOT NULL DEFAULT 1,
  activation_date    DATE         NULL,
  billed_through     DATE         NULL,                         
  next_bill_date     DATE         NULL,
  contract_start     DATE         NULL,
  contract_end       DATE         NULL,
  lock_in_until      DATE         NULL,
  router_rental      TINYINT(1)   NOT NULL DEFAULT 0,
  static_ip          VARCHAR(45)  NULL,
  ip_pool            VARCHAR(60)  NULL,
  security_deposit   DECIMAL(15,2) NOT NULL DEFAULT 0,
  deposit_status     VARCHAR(12)  NOT NULL DEFAULT 'none',      
  pppoe_username     VARCHAR(64)  NULL,
  pppoe_password_enc TEXT         NULL,                         
  mac_address        VARCHAR(30)  NULL,
  device_id          INT UNSIGNED NULL,                         
  port_id            INT UNSIGNED NULL,                         
  drop_length_m      DECIMAL(10,2) NULL,
  agent_id           INT UNSIGNED NULL,
  application_id     INT UNSIGNED NULL,
  autodebit_sav_account_id INT UNSIGNED NULL,                   
  disconnected_at    DATETIME     NULL,
  disconnect_reason  VARCHAR(255) NULL,
  reconnected_at     DATETIME     NULL,
  suspended_until    DATE         NULL,
  terminated_at      DATETIME     NULL,
  termination_reason VARCHAR(255) NULL,
  last_session_at    DATETIME     NULL,
  notes              TEXT         NULL,
  created_by         INT UNSIGNED NULL,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME     NULL,
  UNIQUE KEY uq_isp_accounts_no (account_no),
  UNIQUE KEY uq_isp_accounts_pppoe (pppoe_username),
  KEY ix_isp_accounts_customer (isp_customer_id),
  KEY ix_isp_accounts_status (service_status),
  KEY ix_isp_accounts_plan (plan_id),
  KEY ix_isp_accounts_area (area_id),
  KEY ix_isp_accounts_branch (branch_id),
  KEY ix_isp_accounts_next_bill (next_bill_date),
  KEY ix_isp_accounts_port (port_id),
  CONSTRAINT fk_isp_accounts_customer FOREIGN KEY (isp_customer_id) REFERENCES isp_customers(id),
  CONSTRAINT fk_isp_accounts_plan FOREIGN KEY (plan_id) REFERENCES isp_plans(id),
  CONSTRAINT fk_isp_accounts_area FOREIGN KEY (area_id) REFERENCES isp_areas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_account_events`;
CREATE TABLE isp_account_events (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id   INT UNSIGNED NOT NULL,
  event_date   DATETIME     NOT NULL,
  event_type   VARCHAR(30)  NOT NULL,      
  summary      VARCHAR(255) NOT NULL,
  ref_type     VARCHAR(30)  NULL,
  ref_id       BIGINT UNSIGNED NULL,
  amount       DECIMAL(15,2) NULL,
  user_id      INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_isp_account_events_account (account_id, event_date),
  KEY ix_isp_account_events_type (event_type),
  CONSTRAINT fk_isp_account_events_account FOREIGN KEY (account_id) REFERENCES isp_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_account_plans`;
CREATE TABLE isp_account_plans (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id   INT UNSIGNED NOT NULL,
  plan_id      INT UNSIGNED NOT NULL,
  monthly_fee  DECIMAL(15,2) NOT NULL,     
  effective_from DATE        NOT NULL,
  effective_to DATE          NULL,
  change_type  VARCHAR(16)   NOT NULL DEFAULT 'new',  
  proration    VARCHAR(255)  NULL,
  user_id      INT UNSIGNED  NULL,
  created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_isp_account_plans_account (account_id, effective_from),
  CONSTRAINT fk_isp_account_plans_account FOREIGN KEY (account_id) REFERENCES isp_accounts(id),
  CONSTRAINT fk_isp_account_plans_plan FOREIGN KEY (plan_id) REFERENCES isp_plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_account_addons`;
CREATE TABLE isp_account_addons (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id   INT UNSIGNED NOT NULL,
  description  VARCHAR(160) NOT NULL,
  amount       DECIMAL(15,2) NOT NULL,     
  taxable      TINYINT(1)   NOT NULL DEFAULT 1,
  start_date   DATE         NOT NULL,
  end_date     DATE         NULL,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_isp_account_addons_account (account_id),
  CONSTRAINT fk_isp_account_addons_account FOREIGN KEY (account_id) REFERENCES isp_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_agents`;
CREATE TABLE isp_agents (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code               VARCHAR(20)  NOT NULL,
  name               VARCHAR(120) NOT NULL,
  user_id            INT UNSIGNED NULL,
  vendor_id          INT UNSIGNED NULL,       
  mobile             VARCHAR(40)  NULL,
  email              VARCHAR(120) NULL,
  activation_type    VARCHAR(10)  NOT NULL DEFAULT 'fixed',  
  activation_value   DECIMAL(15,4) NOT NULL DEFAULT 0,
  first_payment_type VARCHAR(10)  NOT NULL DEFAULT 'none',   
  first_payment_value DECIMAL(15,4) NOT NULL DEFAULT 0,
  clawback_days      SMALLINT     NOT NULL DEFAULT 90,       
  is_active          TINYINT(1)   NOT NULL DEFAULT 1,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_agents_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_applications`;
CREATE TABLE isp_applications (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_no    VARCHAR(30)  NOT NULL,
  channel           VARCHAR(12)  NOT NULL DEFAULT 'walkin',   
  status            VARCHAR(20)  NOT NULL DEFAULT 'new',      
  party_id          INT UNSIGNED NULL,                        
  isp_customer_id   INT UNSIGNED NULL,
  account_id        INT UNSIGNED NULL,                        
  classification    VARCHAR(20)  NOT NULL DEFAULT 'residential',
  last_name         VARCHAR(80)  NULL,
  first_name        VARCHAR(80)  NULL,
  middle_name       VARCHAR(80)  NULL,
  business_name     VARCHAR(160) NULL,
  mobile            VARCHAR(40)  NULL,
  email             VARCHAR(120) NULL,
  billing_address   VARCHAR(255) NULL,
  install_address   VARCHAR(255) NOT NULL,
  landmark          VARCHAR(160) NULL,
  area_id           INT UNSIGNED NULL,
  branch_id         INT UNSIGNED NULL,
  latitude          DECIMAL(10,7) NULL,
  longitude         DECIMAL(10,7) NULL,
  plan_id           INT UNSIGNED NULL,
  agent_id          INT UNSIGNED NULL,
  id_type           VARCHAR(40)  NULL,
  id_number         VARCHAR(60)  NULL,
  id_verified       TINYINT(1)   NOT NULL DEFAULT 0,
  coverage_ok       TINYINT(1)   NULL,
  survey_feasible   TINYINT(1)   NULL,
  survey_notes      TEXT         NULL,
  survey_date       DATE         NULL,
  surveyed_by       INT UNSIGNED NULL,
  port_id           INT UNSIGNED NULL,                        
  est_cable_m       DECIMAL(10,2) NULL,
  quote_installation DECIMAL(15,2) NULL,
  quote_materials   DECIMAL(15,2) NULL,
  quote_deposit     DECIMAL(15,2) NULL,
  quote_total       DECIMAL(15,2) NULL,
  contract_accepted_at DATETIME  NULL,
  contract_accepted_name VARCHAR(160) NULL,
  privacy_consent   TINYINT(1)   NOT NULL DEFAULT 0,
  approved_by       INT UNSIGNED NULL,
  approved_at       DATETIME     NULL,
  rejected_reason   VARCHAR(255) NULL,
  preferred_date    DATE         NULL,
  work_order_id     INT UNSIGNED NULL,
  notes             TEXT         NULL,
  created_by        INT UNSIGNED NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NULL,
  UNIQUE KEY uq_isp_applications_no (application_no),
  KEY ix_isp_applications_status (status),
  KEY ix_isp_applications_party (party_id),
  KEY ix_isp_applications_area (area_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_application_log`;
CREATE TABLE isp_application_log (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_id  INT UNSIGNED NOT NULL,
  from_status     VARCHAR(20)  NULL,
  to_status       VARCHAR(20)  NOT NULL,
  remarks         VARCHAR(255) NULL,
  user_id         INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_isp_application_log_app (application_id),
  CONSTRAINT fk_isp_application_log_app FOREIGN KEY (application_id) REFERENCES isp_applications(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_commissions`;
CREATE TABLE isp_commissions (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  agent_id      INT UNSIGNED NOT NULL,
  account_id    INT UNSIGNED NOT NULL,
  basis         VARCHAR(30)  NOT NULL,          
  commission_key VARCHAR(60) NOT NULL,          
  amount        DECIMAL(15,2) NOT NULL,
  rule_note     VARCHAR(160) NULL,              
  status        VARCHAR(12)  NOT NULL DEFAULT 'pending', 
  approved_by   INT UNSIGNED NULL,
  approved_at   DATETIME     NULL,
  bill_txn_id   BIGINT UNSIGNED NULL,           
  reversed_reason VARCHAR(255) NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_commissions_key (commission_key),
  KEY ix_isp_commissions_agent (agent_id, status),
  CONSTRAINT fk_isp_commissions_agent FOREIGN KEY (agent_id) REFERENCES isp_agents(id),
  CONSTRAINT fk_isp_commissions_account FOREIGN KEY (account_id) REFERENCES isp_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_contract_templates`;
CREATE TABLE isp_contract_templates (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  body        MEDIUMTEXT   NOT NULL,           
  is_default  TINYINT(1)   NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_contracts`;
CREATE TABLE isp_contracts (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  contract_no        VARCHAR(30)  NOT NULL,
  account_id         INT UNSIGNED NOT NULL,
  template_id        INT UNSIGNED NULL,
  plan_id            INT UNSIGNED NULL,
  start_date         DATE         NOT NULL,
  end_date           DATE         NULL,
  lock_in_months     SMALLINT     NOT NULL DEFAULT 0,
  early_termination_fee DECIMAL(15,2) NOT NULL DEFAULT 0,
  body_snapshot      MEDIUMTEXT   NULL,          
  accepted_at        DATETIME     NULL,
  accepted_name      VARCHAR(160) NULL,
  accepted_ip        VARCHAR(45)  NULL,
  status             VARCHAR(12)  NOT NULL DEFAULT 'active',  
  amends_id          INT UNSIGNED NULL,
  terminated_at      DATETIME     NULL,
  notes              VARCHAR(255) NULL,
  created_by         INT UNSIGNED NULL,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_contracts_no (contract_no),
  KEY ix_isp_contracts_account (account_id),
  KEY ix_isp_contracts_end (end_date, status),
  CONSTRAINT fk_isp_contracts_account FOREIGN KEY (account_id) REFERENCES isp_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_technicians`;
CREATE TABLE isp_technicians (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(20)  NOT NULL,
  name        VARCHAR(120) NOT NULL,
  user_id     INT UNSIGNED NULL,
  team        VARCHAR(60)  NULL,
  role        VARCHAR(20)  NOT NULL DEFAULT 'installer',    
  skills      VARCHAR(255) NULL,
  area_ids    VARCHAR(255) NULL,                            
  mobile      VARCHAR(40)  NULL,
  site_id     INT UNSIGNED NULL,                            
  work_days   VARCHAR(20)  NOT NULL DEFAULT '1,2,3,4,5,6',  
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_technicians_code (code),
  KEY ix_isp_technicians_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_work_orders`;
CREATE TABLE isp_work_orders (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  wo_no           VARCHAR(30)  NOT NULL,
  wo_type         VARCHAR(20)  NOT NULL,       
  status          VARCHAR(16)  NOT NULL DEFAULT 'open',   
  priority        VARCHAR(10)  NOT NULL DEFAULT 'normal', 
  account_id      INT UNSIGNED NULL,
  application_id  INT UNSIGNED NULL,
  ticket_id       INT UNSIGNED NULL,
  outage_id       INT UNSIGNED NULL,
  net_op_id       BIGINT UNSIGNED NULL,
  area_id         INT UNSIGNED NULL,
  address         VARCHAR(255) NULL,
  description     VARCHAR(255) NOT NULL,
  technician_id   INT UNSIGNED NULL,
  helper_ids      VARCHAR(120) NULL,
  scheduled_date  DATE         NULL,
  scheduled_slot  VARCHAR(20)  NULL,          
  route_seq       SMALLINT     NULL,
  site_id         INT UNSIGNED NULL,          
  checklist       TEXT         NULL,          
  cable_used_m    DECIMAL(10,2) NULL,
  onu_serial      VARCHAR(60)  NULL,
  router_serial   VARCHAR(60)  NULL,
  port_id         INT UNSIGNED NULL,
  rx_power_dbm    DECIMAL(6,2) NULL,          
  tx_power_dbm    DECIMAL(6,2) NULL,
  speed_test_down DECIMAL(10,2) NULL,
  speed_test_up   DECIMAL(10,2) NULL,
  labor_hours     DECIMAL(8,2) NULL,
  labor_cost      DECIMAL(15,2) NOT NULL DEFAULT 0,
  travel_expense  DECIMAL(15,2) NOT NULL DEFAULT 0,
  expense_txn_id  BIGINT UNSIGNED NULL,       
  findings        TEXT         NULL,
  remarks         TEXT         NULL,
  customer_accepted_by VARCHAR(120) NULL,
  customer_accepted_at DATETIME NULL,
  accepted_at     DATETIME     NULL,          
  started_at      DATETIME     NULL,
  completed_at    DATETIME     NULL,
  verified_by     INT UNSIGNED NULL,
  verified_at     DATETIME     NULL,
  cancel_reason   VARCHAR(255) NULL,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL,
  UNIQUE KEY uq_isp_work_orders_no (wo_no),
  KEY ix_isp_work_orders_status (status, scheduled_date),
  KEY ix_isp_work_orders_tech (technician_id, scheduled_date),
  KEY ix_isp_work_orders_account (account_id),
  KEY ix_isp_work_orders_app (application_id),
  KEY ix_isp_work_orders_ticket (ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_wo_materials`;
CREATE TABLE isp_wo_materials (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  work_order_id INT UNSIGNED NOT NULL,
  item_id       INT UNSIGNED NOT NULL,
  qty_issued    DECIMAL(14,4) NOT NULL DEFAULT 0,
  qty_used      DECIMAL(14,4) NOT NULL DEFAULT 0,
  qty_returned  DECIMAL(14,4) NOT NULL DEFAULT 0,
  serial_no     VARCHAR(60)  NULL,
  unit_cost     DECIMAL(15,4) NULL,            
  issue_txn_id  BIGINT UNSIGNED NULL,          
  return_txn_id BIGINT UNSIGNED NULL,
  status        VARCHAR(12)  NOT NULL DEFAULT 'issued',  
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_isp_wo_materials_wo (work_order_id),
  KEY ix_isp_wo_materials_item (item_id),
  CONSTRAINT fk_isp_wo_materials_wo FOREIGN KEY (work_order_id) REFERENCES isp_work_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_files`;
CREATE TABLE isp_files (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  entity_type  VARCHAR(20)  NOT NULL,          
  entity_id    INT UNSIGNED NOT NULL,
  label        VARCHAR(120) NULL,
  stored_name  VARCHAR(120) NOT NULL,          
  original_name VARCHAR(160) NULL,
  mime         VARCHAR(80)  NOT NULL,
  size_bytes   INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by  INT UNSIGNED NULL,
  portal_user_id INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_isp_files_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_equipment`;
CREATE TABLE isp_equipment (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  item_id       INT UNSIGNED NOT NULL,          
  serial_no     VARCHAR(60)  NOT NULL,
  mac_address   VARCHAR(30)  NULL,
  ownership     VARCHAR(12)  NOT NULL DEFAULT 'company',   
  status        VARCHAR(16)  NOT NULL DEFAULT 'in_stock',  
  site_id       INT UNSIGNED NULL,
  technician_id INT UNSIGNED NULL,
  account_id    INT UNSIGNED NULL,
  work_order_id INT UNSIGNED NULL,
  deposit       DECIMAL(15,2) NOT NULL DEFAULT 0,
  rental_fee    DECIMAL(15,2) NOT NULL DEFAULT 0,
  unit_cost     DECIMAL(15,4) NULL,
  installed_at  DATETIME     NULL,
  recovered_at  DATETIME     NULL,
  disposition   VARCHAR(160) NULL,
  notes         VARCHAR(255) NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NULL,
  UNIQUE KEY uq_isp_equipment_serial (item_id, serial_no),
  KEY ix_isp_equipment_status (status),
  KEY ix_isp_equipment_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_equipment_log`;
CREATE TABLE isp_equipment_log (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  equipment_id  INT UNSIGNED NOT NULL,
  from_status   VARCHAR(16)  NULL,
  to_status     VARCHAR(16)  NOT NULL,
  account_id    INT UNSIGNED NULL,
  work_order_id INT UNSIGNED NULL,
  remarks       VARCHAR(255) NULL,
  user_id       INT UNSIGNED NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_isp_equipment_log_eq (equipment_id),
  CONSTRAINT fk_isp_equipment_log_eq FOREIGN KEY (equipment_id) REFERENCES isp_equipment(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_net_nodes`;
CREATE TABLE isp_net_nodes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  node_type     VARCHAR(20)  NOT NULL,     
  code          VARCHAR(40)  NOT NULL,
  name          VARCHAR(120) NOT NULL,
  parent_id     INT UNSIGNED NULL,         
  upstream_port_id INT UNSIGNED NULL,      
  area_id       INT UNSIGNED NULL,
  branch_id     INT UNSIGNED NULL,
  latitude      DECIMAL(10,7) NULL,
  longitude     DECIMAL(10,7) NULL,
  address       VARCHAR(255) NULL,
  vendor        VARCHAR(60)  NULL,
  model         VARCHAR(60)  NULL,
  serial_no     VARCHAR(60)  NULL,
  split_ratio   VARCHAR(10)  NULL,         
  port_count    SMALLINT     NOT NULL DEFAULT 0,
  specs         VARCHAR(255) NULL,
  device_id     INT UNSIGNED NULL,         
  installed_on  DATE         NULL,
  status        VARCHAR(12)  NOT NULL DEFAULT 'active',  
  notes         VARCHAR(255) NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NULL,
  UNIQUE KEY uq_isp_net_nodes_code (code),
  KEY ix_isp_net_nodes_type (node_type),
  KEY ix_isp_net_nodes_parent (parent_id),
  KEY ix_isp_net_nodes_upstream (upstream_port_id),
  KEY ix_isp_net_nodes_area (area_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_net_ports`;
CREATE TABLE isp_net_ports (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  node_id     INT UNSIGNED NOT NULL,
  port_no     SMALLINT     NOT NULL,
  port_type   VARCHAR(12)  NOT NULL DEFAULT 'out',  
  status      VARCHAR(12)  NOT NULL DEFAULT 'free', 
  account_id  INT UNSIGNED NULL,                    
  application_id INT UNSIGNED NULL,                 
  label       VARCHAR(60)  NULL,
  updated_at  DATETIME     NULL,
  UNIQUE KEY uq_isp_net_ports_no (node_id, port_no),
  UNIQUE KEY uq_isp_net_ports_account (account_id),
  KEY ix_isp_net_ports_status (status),
  CONSTRAINT fk_isp_net_ports_node FOREIGN KEY (node_id) REFERENCES isp_net_nodes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_cable_segments`;
CREATE TABLE isp_cable_segments (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(40)  NOT NULL,
  cable_type    VARCHAR(20)  NOT NULL DEFAULT 'fiber',   
  core_count    SMALLINT     NOT NULL DEFAULT 0,
  from_node_id  INT UNSIGNED NULL,
  to_node_id    INT UNSIGNED NULL,
  length_m      DECIMAL(10,2) NULL,
  path          TEXT         NULL,         
  installed_on  DATE         NULL,
  status        VARCHAR(12)  NOT NULL DEFAULT 'active',  
  replaced_by_id INT UNSIGNED NULL,
  notes         VARCHAR(255) NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NULL,
  UNIQUE KEY uq_isp_cable_segments_code (code),
  KEY ix_isp_cable_segments_from (from_node_id),
  KEY ix_isp_cable_segments_to (to_node_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_cable_cores`;
CREATE TABLE isp_cable_cores (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  segment_id   INT UNSIGNED NOT NULL,
  core_no      SMALLINT     NOT NULL,
  color        VARCHAR(20)  NULL,
  status       VARCHAR(12)  NOT NULL DEFAULT 'free',   
  assignment   VARCHAR(160) NULL,                      
  UNIQUE KEY uq_isp_cable_cores (segment_id, core_no),
  CONSTRAINT fk_isp_cable_cores_seg FOREIGN KEY (segment_id) REFERENCES isp_cable_segments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_splices`;
CREATE TABLE isp_splices (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  closure_id    INT UNSIGNED NOT NULL,       
  in_core_id    INT UNSIGNED NOT NULL,
  out_core_id   INT UNSIGNED NOT NULL,
  loss_db       DECIMAL(6,3) NULL,
  spliced_on    DATE         NULL,
  notes         VARCHAR(160) NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_isp_splices_closure (closure_id),
  CONSTRAINT fk_isp_splices_closure FOREIGN KEY (closure_id) REFERENCES isp_net_nodes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_node_events`;
CREATE TABLE isp_node_events (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  node_id      INT UNSIGNED NULL,
  segment_id   INT UNSIGNED NULL,
  event_type   VARCHAR(20)  NOT NULL,        
  event_date   DATE         NOT NULL,
  remarks      VARCHAR(255) NULL,
  work_order_id INT UNSIGNED NULL,
  user_id      INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_isp_node_events_node (node_id),
  KEY ix_isp_node_events_seg (segment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_devices`;
CREATE TABLE isp_devices (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(80)  NOT NULL,
  adapter         VARCHAR(20)  NOT NULL,     
  host            VARCHAR(120) NULL,
  port            INT          NULL,
  use_tls         TINYINT(1)   NOT NULL DEFAULT 1,
  username        VARCHAR(80)  NULL,
  secret_enc      TEXT         NULL,         
  options         TEXT         NULL,         
  branch_id       INT UNSIGNED NULL,
  is_default      TINYINT(1)   NOT NULL DEFAULT 0,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  last_ok_at      DATETIME     NULL,
  last_error      VARCHAR(255) NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_net_ops`;
CREATE TABLE isp_net_ops (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  op_no           VARCHAR(30)  NOT NULL,
  account_id      INT UNSIGNED NOT NULL,
  device_id       INT UNSIGNED NULL,
  op_type         VARCHAR(16)  NOT NULL,     
  reason          VARCHAR(255) NULL,
  source_type     VARCHAR(20)  NULL,         
  source_id       BIGINT UNSIGNED NULL,
  idem_key        VARCHAR(80)  NOT NULL,     
  state           VARCHAR(12)  NOT NULL DEFAULT 'requested', 
  simulated       TINYINT(1)   NOT NULL DEFAULT 0,           
  attempts        SMALLINT     NOT NULL DEFAULT 0,
  max_attempts    SMALLINT     NOT NULL DEFAULT 5,
  next_try_at     DATETIME     NULL,
  payload         TEXT         NULL,
  response        TEXT         NULL,
  error           VARCHAR(255) NULL,
  requested_by    INT UNSIGNED NULL,
  approved_by     INT UNSIGNED NULL,
  work_order_id   INT UNSIGNED NULL,         
  sent_at         DATETIME     NULL,
  confirmed_at    DATETIME     NULL,
  failed_at       DATETIME     NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL,
  UNIQUE KEY uq_isp_net_ops_no (op_no),
  UNIQUE KEY uq_isp_net_ops_idem (idem_key),
  KEY ix_isp_net_ops_state (state, next_try_at),
  KEY ix_isp_net_ops_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_net_logs`;
CREATE TABLE isp_net_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  op_id       BIGINT UNSIGNED NULL,
  device_id   INT UNSIGNED NULL,
  account_id  INT UNSIGNED NULL,
  direction   VARCHAR(8)   NOT NULL DEFAULT 'out',  
  command     VARCHAR(255) NOT NULL,                
  result      VARCHAR(255) NULL,
  ok          TINYINT(1)   NOT NULL DEFAULT 0,
  simulated   TINYINT(1)   NOT NULL DEFAULT 0,
  user_id     INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_isp_net_logs_op (op_id),
  KEY ix_isp_net_logs_account (account_id),
  KEY ix_isp_net_logs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_billing_runs`;
CREATE TABLE isp_billing_runs (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_no        VARCHAR(30)  NOT NULL,
  bill_date     DATE         NOT NULL,       
  cycle_day     TINYINT      NULL,           
  branch_id     INT UNSIGNED NULL,
  status        VARCHAR(12)  NOT NULL DEFAULT 'draft',   
  invoice_count INT          NOT NULL DEFAULT 0,
  total_amount  DECIMAL(15,2) NOT NULL DEFAULT 0,
  warnings      TEXT         NULL,           
  created_by    INT UNSIGNED NULL,
  approved_by   INT UNSIGNED NULL,
  approved_at   DATETIME     NULL,
  posted_by     INT UNSIGNED NULL,
  posted_at     DATETIME     NULL,
  source        VARCHAR(12)  NOT NULL DEFAULT 'manual',  
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_billing_runs_no (run_no),
  KEY ix_isp_billing_runs_date (bill_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_invoices`;
CREATE TABLE isp_invoices (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_no      VARCHAR(30)  NOT NULL,         
  account_id      INT UNSIGNED NOT NULL,
  run_id          INT UNSIGNED NULL,
  kind            VARCHAR(12)  NOT NULL DEFAULT 'regular',   
  period_key      VARCHAR(60)  NULL,             
  period_start    DATE         NULL,
  period_end      DATE         NULL,
  invoice_date    DATE         NOT NULL,
  due_date        DATE         NOT NULL,
  status          VARCHAR(16)  NOT NULL DEFAULT 'draft',     
  vat_registered  TINYINT(1)   NOT NULL DEFAULT 1,
  subtotal        DECIMAL(15,2) NOT NULL DEFAULT 0,          
  discount_total  DECIMAL(15,2) NOT NULL DEFAULT 0,
  vat_total       DECIMAL(15,2) NOT NULL DEFAULT 0,
  total           DECIMAL(15,2) NOT NULL DEFAULT 0,
  paid_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,          
  credit_amount   DECIMAL(15,2) NOT NULL DEFAULT 0,          
  balance         DECIMAL(15,2) NOT NULL DEFAULT 0,          
  previous_balance DECIMAL(15,2) NOT NULL DEFAULT 0,         
  txn_id          BIGINT UNSIGNED NULL,                      
  late_fee_charge_id INT UNSIGNED NULL,
  overdue_since   DATE         NULL,
  approved_by     INT UNSIGNED NULL,
  approved_at     DATETIME     NULL,
  posted_by       INT UNSIGNED NULL,
  posted_at       DATETIME     NULL,
  voided_by       INT UNSIGNED NULL,
  voided_at       DATETIME     NULL,
  void_reason     VARCHAR(255) NULL,
  notes           VARCHAR(255) NULL,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL,
  UNIQUE KEY uq_isp_invoices_no (invoice_no),
  UNIQUE KEY uq_isp_invoices_period (period_key),
  KEY ix_isp_invoices_account (account_id, invoice_date),
  KEY ix_isp_invoices_status (status, due_date),
  KEY ix_isp_invoices_run (run_id),
  KEY ix_isp_invoices_txn (txn_id),
  CONSTRAINT fk_isp_invoices_account FOREIGN KEY (account_id) REFERENCES isp_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_invoice_lines`;
CREATE TABLE isp_invoice_lines (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_id     INT UNSIGNED NOT NULL,
  line_no        SMALLINT     NOT NULL,
  line_type      VARCHAR(20)  NOT NULL,      
  description    VARCHAR(255) NOT NULL,
  qty            DECIMAL(12,4) NOT NULL DEFAULT 1,
  unit_price     DECIMAL(15,4) NOT NULL DEFAULT 0,   
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,   
  vat_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,
  vat_mode       VARCHAR(12)  NOT NULL DEFAULT 'inclusive',
  income_account_id INT UNSIGNED NULL,
  plan_id        INT UNSIGNED NULL,
  plan_price     DECIMAL(15,2) NULL,                 
  charge_id      INT UNSIGNED NULL,
  period_start   DATE         NULL,
  period_end     DATE         NULL,
  KEY ix_isp_invoice_lines_inv (invoice_id),
  CONSTRAINT fk_isp_invoice_lines_inv FOREIGN KEY (invoice_id) REFERENCES isp_invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_charges`;
CREATE TABLE isp_charges (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id    INT UNSIGNED NOT NULL,
  charge_type   VARCHAR(20)  NOT NULL,      
  description   VARCHAR(255) NOT NULL,
  qty           DECIMAL(12,4) NOT NULL DEFAULT 1,
  amount        DECIMAL(15,2) NOT NULL,     
  vat_mode      VARCHAR(12)  NOT NULL DEFAULT 'inclusive',
  income_account_id INT UNSIGNED NULL,
  charge_date   DATE         NOT NULL,
  status        VARCHAR(12)  NOT NULL DEFAULT 'pending',   
  invoice_id    INT UNSIGNED NULL,
  charge_key    VARCHAR(80)  NOT NULL,      
  source_type   VARCHAR(20)  NULL,
  source_id     BIGINT UNSIGNED NULL,
  cancel_reason VARCHAR(255) NULL,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_charges_key (charge_key),
  KEY ix_isp_charges_account (account_id, status),
  CONSTRAINT fk_isp_charges_account FOREIGN KEY (account_id) REFERENCES isp_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_adjustments`;
CREATE TABLE isp_adjustments (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  adj_no        VARCHAR(30)  NOT NULL,
  account_id    INT UNSIGNED NOT NULL,
  invoice_id    INT UNSIGNED NULL,
  adj_type      VARCHAR(20)  NOT NULL,      
  amount        DECIMAL(15,2) NOT NULL,     
  reason        VARCHAR(255) NOT NULL,
  status        VARCHAR(12)  NOT NULL DEFAULT 'pending',   
  requested_by  INT UNSIGNED NULL,
  approved_by   INT UNSIGNED NULL,
  approved_at   DATETIME     NULL,
  rejected_reason VARCHAR(255) NULL,
  txn_id        BIGINT UNSIGNED NULL,
  source_type   VARCHAR(20)  NULL,          
  source_id     INT UNSIGNED NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_adjustments_no (adj_no),
  KEY ix_isp_adjustments_account (account_id),
  KEY ix_isp_adjustments_status (status),
  CONSTRAINT fk_isp_adjustments_account FOREIGN KEY (account_id) REFERENCES isp_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_disputes`;
CREATE TABLE isp_disputes (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  dispute_no      VARCHAR(30)  NOT NULL,
  account_id      INT UNSIGNED NOT NULL,
  invoice_id      INT UNSIGNED NULL,
  amount_disputed DECIMAL(15,2) NOT NULL DEFAULT 0,
  reason          VARCHAR(255) NOT NULL,
  status          VARCHAR(20)  NOT NULL DEFAULT 'open',   
  protect         TINYINT(1)   NOT NULL DEFAULT 1,        
  investigation   TEXT         NULL,
  resolution      VARCHAR(255) NULL,
  adjustment_id   INT UNSIGNED NULL,
  opened_by       INT UNSIGNED NULL,
  resolved_by     INT UNSIGNED NULL,
  resolved_at     DATETIME     NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_disputes_no (dispute_no),
  KEY ix_isp_disputes_account (account_id, status),
  CONSTRAINT fk_isp_disputes_account FOREIGN KEY (account_id) REFERENCES isp_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_arrangements`;
CREATE TABLE isp_arrangements (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  arrangement_no  VARCHAR(30)  NOT NULL,
  account_id      INT UNSIGNED NOT NULL,
  arr_type        VARCHAR(20)  NOT NULL DEFAULT 'installment',   
  total_amount    DECIMAL(15,2) NOT NULL,
  status          VARCHAR(12)  NOT NULL DEFAULT 'pending',       
  protect         TINYINT(1)   NOT NULL DEFAULT 1,               
  waive_penalties TINYINT(1)   NOT NULL DEFAULT 0,
  consequence     VARCHAR(255) NOT NULL DEFAULT 'Missing a due date ends the arrangement and the whole unpaid balance becomes due for disconnection.',
  expires_on      DATE         NULL,
  approved_by     INT UNSIGNED NULL,
  approved_at     DATETIME     NULL,
  notes           VARCHAR(255) NULL,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_arrangements_no (arrangement_no),
  KEY ix_isp_arrangements_account (account_id, status),
  CONSTRAINT fk_isp_arrangements_account FOREIGN KEY (account_id) REFERENCES isp_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_arrangement_lines`;
CREATE TABLE isp_arrangement_lines (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  arrangement_id  INT UNSIGNED NOT NULL,
  due_date        DATE         NOT NULL,
  amount          DECIMAL(15,2) NOT NULL,
  paid_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,
  status          VARCHAR(10)  NOT NULL DEFAULT 'open',   
  KEY ix_isp_arrangement_lines_arr (arrangement_id),
  CONSTRAINT fk_isp_arrangement_lines_arr FOREIGN KEY (arrangement_id) REFERENCES isp_arrangements(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_exemptions`;
CREATE TABLE isp_exemptions (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id    INT UNSIGNED NOT NULL,
  reason        VARCHAR(255) NOT NULL,
  valid_from    DATE         NOT NULL,
  valid_until   DATE         NOT NULL,
  status        VARCHAR(10)  NOT NULL DEFAULT 'active',    
  approved_by   INT UNSIGNED NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_isp_exemptions_account (account_id, valid_until),
  CONSTRAINT fk_isp_exemptions_account FOREIGN KEY (account_id) REFERENCES isp_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_shifts`;
CREATE TABLE isp_shifts (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  shift_no       VARCHAR(30)  NOT NULL,
  user_id        INT UNSIGNED NOT NULL,
  branch_id      INT UNSIGNED NULL,
  cash_account_id INT UNSIGNED NOT NULL,     
  opened_at      DATETIME     NOT NULL,
  opening_cash   DECIMAL(15,2) NOT NULL DEFAULT 0,
  closed_at      DATETIME     NULL,
  expected_cash  DECIMAL(15,2) NULL,
  counted_cash   DECIMAL(15,2) NULL,
  denominations  TEXT         NULL,          
  over_short     DECIMAL(15,2) NULL,
  over_short_txn_id BIGINT UNSIGNED NULL,
  remitted_amount DECIMAL(15,2) NULL,
  remit_txn_id   BIGINT UNSIGNED NULL,       
  status         VARCHAR(12)  NOT NULL DEFAULT 'open',    
  certified_by   INT UNSIGNED NULL,
  certified_at   DATETIME     NULL,
  notes          VARCHAR(255) NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_shifts_no (shift_no),
  KEY ix_isp_shifts_user (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_payments`;
CREATE TABLE isp_payments (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  receipt_no       VARCHAR(30)  NOT NULL,     
  account_id       INT UNSIGNED NOT NULL,
  pay_date         DATE         NOT NULL,
  received_at      DATETIME     NOT NULL,
  amount           DECIMAL(15,2) NOT NULL,
  channel          VARCHAR(16)  NOT NULL,     
  source           VARCHAR(12)  NOT NULL DEFAULT 'counter',  
  reference_no     VARCHAR(80)  NULL,
  ref_dedupe       VARCHAR(120) NULL,         
  check_no         VARCHAR(40)  NULL,
  check_bank       VARCHAR(80)  NULL,
  status           VARCHAR(12)  NOT NULL DEFAULT 'posted',   
  shift_id         INT UNSIGNED NULL,
  collector_user_id INT UNSIGNED NULL,
  cash_account_id  INT UNSIGNED NULL,         
  gateway_event_id INT UNSIGNED NULL,
  gateway_fee      DECIMAL(15,2) NOT NULL DEFAULT 0,
  applied_amount   DECIMAL(15,2) NOT NULL DEFAULT 0,
  unapplied_amount DECIMAL(15,2) NOT NULL DEFAULT 0,          
  txn_id           BIGINT UNSIGNED NULL,      
  fee_txn_id       BIGINT UNSIGNED NULL,      
  sav_txn_id       INT UNSIGNED NULL,         
  verified_by      INT UNSIGNED NULL,
  verified_at      DATETIME     NULL,
  rejected_reason  VARCHAR(255) NULL,
  reversed_by      INT UNSIGNED NULL,
  reversed_at      DATETIME     NULL,
  reversal_reason  VARCHAR(255) NULL,
  reversal_approved_by INT UNSIGNED NULL,
  portal_user_id   INT UNSIGNED NULL,
  notes            VARCHAR(255) NULL,
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_payments_no (receipt_no),
  UNIQUE KEY uq_isp_payments_ref (ref_dedupe),
  KEY ix_isp_payments_account (account_id, pay_date),
  KEY ix_isp_payments_status (status),
  KEY ix_isp_payments_shift (shift_id),
  KEY ix_isp_payments_date (pay_date),
  CONSTRAINT fk_isp_payments_account FOREIGN KEY (account_id) REFERENCES isp_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_payment_allocs`;
CREATE TABLE isp_payment_allocs (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payment_id  INT UNSIGNED NOT NULL,
  invoice_id  INT UNSIGNED NOT NULL,
  amount      DECIMAL(15,2) NOT NULL,
  is_reversed TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_isp_payment_allocs_payment (payment_id),
  KEY ix_isp_payment_allocs_invoice (invoice_id),
  CONSTRAINT fk_isp_payment_allocs_payment FOREIGN KEY (payment_id) REFERENCES isp_payments(id),
  CONSTRAINT fk_isp_payment_allocs_invoice FOREIGN KEY (invoice_id) REFERENCES isp_invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_gateway_events`;
CREATE TABLE isp_gateway_events (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  provider      VARCHAR(20)  NOT NULL,       
  event_id      VARCHAR(120) NOT NULL,       
  event_type    VARCHAR(60)  NULL,
  signature_ok  TINYINT(1)   NOT NULL DEFAULT 0,
  account_no    VARCHAR(30)  NULL,
  amount        DECIMAL(15,2) NULL,
  fee           DECIMAL(15,2) NULL,
  reference_no  VARCHAR(120) NULL,
  status        VARCHAR(12)  NOT NULL DEFAULT 'received',   
  payment_id    INT UNSIGNED NULL,
  message       VARCHAR(255) NULL,
  payload       MEDIUMTEXT   NULL,
  remote_ip     VARCHAR(45)  NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_gateway_events (provider, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_disc_rules`;
CREATE TABLE isp_disc_rules (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code               VARCHAR(20)  NOT NULL,
  name               VARCHAR(120) NOT NULL,
  rule_type          VARCHAR(20)  NOT NULL,     
  grace_days         SMALLINT     NOT NULL DEFAULT 5,       
  min_overdue_amount DECIMAL(15,2) NOT NULL DEFAULT 0,      
  min_overdue_invoices SMALLINT   NOT NULL DEFAULT 1,
  final_notice_hours SMALLINT     NOT NULL DEFAULT 24,      
  require_approval   TINYINT(1)   NOT NULL DEFAULT 1,       
  auto_execute       TINYINT(1)   NOT NULL DEFAULT 0,       
  run_days           VARCHAR(20)  NOT NULL DEFAULT '1,2,3,4,5',  
  run_from_hour      TINYINT      NOT NULL DEFAULT 9,
  run_to_hour        TINYINT      NOT NULL DEFAULT 16,
  exclude_types      VARCHAR(160) NOT NULL DEFAULT 'deposit',     
  protect_disputes   TINYINT(1)   NOT NULL DEFAULT 1,
  protect_arrangements TINYINT(1) NOT NULL DEFAULT 1,
  reconnection_fee   DECIMAL(15,2) NULL,                    
  is_active          TINYINT(1)   NOT NULL DEFAULT 1,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_disc_rules_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_disc_queue`;
CREATE TABLE isp_disc_queue (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  queue_no        VARCHAR(30)  NOT NULL,
  account_id      INT UNSIGNED NOT NULL,
  rule_id         INT UNSIGNED NULL,
  reason_type     VARCHAR(20)  NOT NULL,       
  reason          VARCHAR(255) NOT NULL,
  eligible_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  oldest_due      DATE         NULL,
  live_key        VARCHAR(60)  NULL,           
  status          VARCHAR(16)  NOT NULL DEFAULT 'candidate',  
  skip_reason     VARCHAR(255) NULL,
  final_notice_at DATETIME     NULL,
  execute_after   DATETIME     NULL,
  approved_by     INT UNSIGNED NULL,
  approved_at     DATETIME     NULL,
  net_op_id       BIGINT UNSIGNED NULL,
  executed_at     DATETIME     NULL,
  initiated_by    VARCHAR(30)  NOT NULL DEFAULT 'scheduler',   
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL,
  UNIQUE KEY uq_isp_disc_queue_no (queue_no),
  UNIQUE KEY uq_isp_disc_queue_live (live_key),
  KEY ix_isp_disc_queue_status (status),
  KEY ix_isp_disc_queue_account (account_id),
  CONSTRAINT fk_isp_disc_queue_account FOREIGN KEY (account_id) REFERENCES isp_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_reconnections`;
CREATE TABLE isp_reconnections (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recon_no        VARCHAR(30)  NOT NULL,
  account_id      INT UNSIGNED NOT NULL,
  recon_type      VARCHAR(16)  NOT NULL DEFAULT 'automatic',   
  status          VARCHAR(16)  NOT NULL DEFAULT 'requested',   
  payment_id      INT UNSIGNED NULL,
  live_key        VARCHAR(60)  NULL,
  fee_amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
  fee_charge_id   INT UNSIGNED NULL,
  expires_at      DATETIME     NULL,           
  reason          VARCHAR(255) NULL,
  approved_by     INT UNSIGNED NULL,
  approved_at     DATETIME     NULL,
  net_op_id       BIGINT UNSIGNED NULL,
  restored_at     DATETIME     NULL,
  work_order_id   INT UNSIGNED NULL,
  requested_by    INT UNSIGNED NULL,
  portal_user_id  INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL,
  UNIQUE KEY uq_isp_reconnections_no (recon_no),
  UNIQUE KEY uq_isp_reconnections_live (live_key),
  KEY ix_isp_reconnections_status (status),
  KEY ix_isp_reconnections_account (account_id),
  CONSTRAINT fk_isp_reconnections_account FOREIGN KEY (account_id) REFERENCES isp_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_tickets`;
CREATE TABLE isp_tickets (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ticket_no       VARCHAR(30)  NOT NULL,
  account_id      INT UNSIGNED NULL,
  ticket_type     VARCHAR(24)  NOT NULL,       
  subject         VARCHAR(160) NOT NULL,
  description     TEXT         NULL,
  priority        VARCHAR(10)  NOT NULL DEFAULT 'normal',   
  status          VARCHAR(12)  NOT NULL DEFAULT 'new',      
  source          VARCHAR(12)  NOT NULL DEFAULT 'phone',    
  ntc_complaint   TINYINT(1)   NOT NULL DEFAULT 0,          
  sla_due_at      DATETIME     NULL,
  technician_id   INT UNSIGNED NULL,
  assigned_user_id INT UNSIGNED NULL,
  outage_id       INT UNSIGNED NULL,
  work_order_id   INT UNSIGNED NULL,
  escalation_level TINYINT     NOT NULL DEFAULT 0,
  root_cause      VARCHAR(160) NULL,
  resolution      TEXT         NULL,
  feedback_rating TINYINT      NULL,
  feedback_text   VARCHAR(255) NULL,
  classified_at   DATETIME     NULL,
  assigned_at     DATETIME     NULL,
  resolved_at     DATETIME     NULL,
  verified_at     DATETIME     NULL,
  closed_at       DATETIME     NULL,
  portal_user_id  INT UNSIGNED NULL,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL,
  UNIQUE KEY uq_isp_tickets_no (ticket_no),
  KEY ix_isp_tickets_status (status, sla_due_at),
  KEY ix_isp_tickets_account (account_id),
  KEY ix_isp_tickets_outage (outage_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_ticket_notes`;
CREATE TABLE isp_ticket_notes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ticket_id   INT UNSIGNED NOT NULL,
  note        TEXT         NOT NULL,
  from_status VARCHAR(12)  NULL,
  to_status   VARCHAR(12)  NULL,
  is_public   TINYINT(1)   NOT NULL DEFAULT 0,   
  user_id     INT UNSIGNED NULL,
  portal_user_id INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_isp_ticket_notes_ticket (ticket_id),
  CONSTRAINT fk_isp_ticket_notes_ticket FOREIGN KEY (ticket_id) REFERENCES isp_tickets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_outages`;
CREATE TABLE isp_outages (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  outage_no       VARCHAR(30)  NOT NULL,
  title           VARCHAR(160) NOT NULL,
  scope           VARCHAR(12)  NOT NULL DEFAULT 'node',      
  node_id         INT UNSIGNED NULL,
  area_id         INT UNSIGNED NULL,
  status          VARCHAR(12)  NOT NULL DEFAULT 'suspected', 
  is_planned      TINYINT(1)   NOT NULL DEFAULT 0,           
  started_at      DATETIME     NOT NULL,
  verified_at     DATETIME     NULL,
  restored_at     DATETIME     NULL,
  closed_at       DATETIME     NULL,
  root_cause      VARCHAR(255) NULL,
  repair_notes    TEXT         NULL,
  sla_hours       DECIMAL(8,2) NOT NULL DEFAULT 24,          
  credit_policy   VARCHAR(12)  NOT NULL DEFAULT 'prorated',  
  credit_fixed    DECIMAL(15,2) NOT NULL DEFAULT 0,
  credits_status  VARCHAR(12)  NOT NULL DEFAULT 'none',      
  affected_count  INT          NOT NULL DEFAULT 0,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL,
  UNIQUE KEY uq_isp_outages_no (outage_no),
  KEY ix_isp_outages_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_outage_accounts`;
CREATE TABLE isp_outage_accounts (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  outage_id     INT UNSIGNED NOT NULL,
  account_id    INT UNSIGNED NOT NULL,
  how_found     VARCHAR(12)  NOT NULL DEFAULT 'topology',   
  downtime_hours DECIMAL(8,2) NULL,
  credit_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  adjustment_id INT UNSIGNED NULL,
  notified_at   DATETIME     NULL,
  UNIQUE KEY uq_isp_outage_accounts (outage_id, account_id),
  CONSTRAINT fk_isp_outage_accounts_outage FOREIGN KEY (outage_id) REFERENCES isp_outages(id),
  CONSTRAINT fk_isp_outage_accounts_account FOREIGN KEY (account_id) REFERENCES isp_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_announcements`;
CREATE TABLE isp_announcements (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title       VARCHAR(160) NOT NULL,
  body        TEXT         NOT NULL,
  area_id     INT UNSIGNED NULL,              
  outage_id   INT UNSIGNED NULL,
  starts_at   DATETIME     NOT NULL,
  ends_at     DATETIME     NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_isp_announcements_dates (starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_portal_users`;
CREATE TABLE isp_portal_users (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  isp_customer_id INT UNSIGNED NOT NULL,
  username        VARCHAR(60)  NOT NULL,      
  password_hash   VARCHAR(255) NOT NULL,
  status          VARCHAR(10)  NOT NULL DEFAULT 'active',   
  must_change_password TINYINT(1) NOT NULL DEFAULT 1,
  failed_attempts INT          NOT NULL DEFAULT 0,
  last_login_at   DATETIME     NULL,
  last_login_ip   VARCHAR(45)  NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_portal_users_username (username),
  UNIQUE KEY uq_isp_portal_users_customer (isp_customer_id),
  CONSTRAINT fk_isp_portal_users_customer FOREIGN KEY (isp_customer_id) REFERENCES isp_customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_service_requests`;
CREATE TABLE isp_service_requests (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  request_no      VARCHAR(30)  NOT NULL,
  account_id      INT UNSIGNED NOT NULL,
  request_type    VARCHAR(20)  NOT NULL,     
  details         TEXT         NULL,
  requested_plan_id INT UNSIGNED NULL,
  new_address     VARCHAR(255) NULL,
  status          VARCHAR(12)  NOT NULL DEFAULT 'open',      
  response        VARCHAR(255) NULL,
  handled_by      INT UNSIGNED NULL,
  handled_at      DATETIME     NULL,
  ticket_id       INT UNSIGNED NULL,
  work_order_id   INT UNSIGNED NULL,
  portal_user_id  INT UNSIGNED NULL,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_service_requests_no (request_no),
  KEY ix_isp_service_requests_status (status),
  KEY ix_isp_service_requests_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_notif_templates`;
CREATE TABLE isp_notif_templates (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_code  VARCHAR(40)  NOT NULL,
  channel     VARCHAR(8)   NOT NULL,     
  subject     VARCHAR(160) NULL,
  body        TEXT         NOT NULL,     
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  updated_at  DATETIME     NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_notif_templates (event_code, channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_notifications`;
CREATE TABLE isp_notifications (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_code    VARCHAR(40)  NOT NULL,
  channel       VARCHAR(8)   NOT NULL,
  account_id    INT UNSIGNED NULL,
  isp_customer_id INT UNSIGNED NULL,
  recipient     VARCHAR(160) NULL,
  subject       VARCHAR(160) NULL,
  body          TEXT         NOT NULL,
  dedupe_key    VARCHAR(120) NOT NULL,   
  status        VARCHAR(12)  NOT NULL DEFAULT 'queued',  
  attempts      SMALLINT     NOT NULL DEFAULT 0,
  next_try_at   DATETIME     NULL,
  last_error    VARCHAR(255) NULL,
  provider_ref  VARCHAR(120) NULL,
  sent_at       DATETIME     NULL,
  read_at       DATETIME     NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_notifications_dedupe (dedupe_key),
  KEY ix_isp_notifications_status (status, next_try_at),
  KEY ix_isp_notifications_account (account_id),
  KEY ix_isp_notifications_customer (isp_customer_id, channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_jobs`;
CREATE TABLE isp_jobs (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  job_code      VARCHAR(40)  NOT NULL,
  name          VARCHAR(120) NOT NULL,
  description   VARCHAR(255) NULL,
  interval_min  INT          NOT NULL DEFAULT 60,      
  run_hour      TINYINT      NULL,                     
  is_enabled    TINYINT(1)   NOT NULL DEFAULT 1,
  max_retries   SMALLINT     NOT NULL DEFAULT 3,
  fail_count    SMALLINT     NOT NULL DEFAULT 0,       
  lock_token    VARCHAR(40)  NULL,
  locked_until  DATETIME     NULL,
  last_started_at DATETIME   NULL,
  last_finished_at DATETIME  NULL,
  last_status   VARCHAR(12)  NULL,                     
  last_message  VARCHAR(255) NULL,
  next_run_at   DATETIME     NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_jobs_code (job_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_job_runs`;
CREATE TABLE isp_job_runs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  job_code      VARCHAR(40)  NOT NULL,
  started_at    DATETIME     NOT NULL,
  finished_at   DATETIME     NULL,
  status        VARCHAR(12)  NOT NULL DEFAULT 'running',  
  processed     INT          NOT NULL DEFAULT 0,
  message       TEXT         NULL,
  trigger_by    VARCHAR(30)  NOT NULL DEFAULT 'cron',     
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_isp_job_runs_code (job_code, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `isp_postings`;
CREATE TABLE isp_postings (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_key      VARCHAR(64)  NOT NULL,      
  source_type    VARCHAR(24)  NOT NULL,      
  source_id      VARCHAR(40)  NOT NULL,
  doc_no         VARCHAR(40)  NULL,
  isp_account_id INT UNSIGNED NULL,
  party_id       INT UNSIGNED NULL,
  txn_id         BIGINT UNSIGNED NULL,       
  txn_type       VARCHAR(30)  NULL,
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  status         VARCHAR(10)  NOT NULL DEFAULT 'posted',  
  reversal_of_id BIGINT UNSIGNED NULL,
  reason         VARCHAR(255) NULL,
  class_id       INT UNSIGNED NULL,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  voided_by      INT UNSIGNED NULL,
  voided_at      DATETIME     NULL,
  UNIQUE KEY uq_isp_postings_event (event_key),
  KEY ix_isp_postings_txn (txn_id),
  KEY ix_isp_postings_source (source_type, source_id),
  KEY ix_isp_postings_account (isp_account_id),
  KEY ix_isp_postings_party (party_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  3. DATA
-- ---------------------------------------------------------------------

-- settings (154 rows)
INSERT INTO `settings` (`setting_key`,`setting_value`,`updated_at`) VALUES
('allow_negative_stock','1','2026-11-02 00:20:21'),
('audit_retention_years','10','2026-11-02 00:20:21'),
('bir_signatory_name','','2026-11-02 00:20:21'),
('bir_signatory_tin','','2026-11-02 00:20:21'),
('bir_signatory_title','','2026-11-02 00:20:21'),
('bir_zip_code','','2026-11-02 00:20:21'),
('bt_printer_autoprint','0','2026-11-02 00:20:21'),
('bt_printer_cut','0','2026-11-02 00:20:21'),
('bt_printer_enabled','0','2026-11-02 00:20:21'),
('bt_printer_feed','4','2026-11-02 00:20:21'),
('bt_printer_name','','2026-11-02 00:20:21'),
('bt_printer_width','32','2026-11-02 00:20:21'),
('bu_default_banking','','2026-11-02 00:20:21'),
('bu_default_coop','1','2026-11-02 00:20:21'),
('bu_default_general','','2026-11-02 00:20:21'),
('bu_default_inventory','2','2026-11-02 00:20:21'),
('bu_default_pos','2','2026-11-02 00:20:21'),
('bu_default_purchases','2','2026-11-02 00:20:21'),
('bu_default_sales','2','2026-11-02 00:20:21'),
('closing_date','','2026-11-02 00:20:21'),
('company_address','','2026-11-02 00:20:22'),
('company_email','','2026-11-02 00:20:22'),
('company_logo','','2026-11-02 00:20:21'),
('company_name','Your Cooperative Name','2026-11-02 00:20:22'),
('company_phone','','2026-11-02 00:20:22'),
('company_tin','','2026-11-02 00:20:22'),
('coop_allocation_order','penalty,interest,principal','2026-11-02 00:20:21'),
('coop_backdate_days','0','2026-11-02 00:20:21'),
('coop_category','','2026-11-02 00:20:21'),
('coop_cda_reg_no','','2026-11-02 00:20:21'),
('coop_cdf_pct','3','2026-11-02 00:20:21'),
('coop_comaker_text','','2026-11-02 00:20:21'),
('coop_default_par_value','100','2026-11-02 00:20:21'),
('coop_disclosure_text','','2026-11-02 00:20:21'),
('coop_division_name','Savings & Credit Division','2026-11-02 00:20:21'),
('coop_doc_expiry_alert_days','30','2026-11-02 00:20:21'),
('coop_dormancy_alert_days','30','2026-11-02 00:20:21'),
('coop_educ_apex_pct','50','2026-11-02 00:20:21'),
('coop_educ_pct','10','2026-11-02 00:20:21'),
('coop_fiscal_year_end','12-31','2026-11-02 00:20:21'),
('coop_interest_accrual_loans','0','2026-11-02 00:20:21'),
('coop_isc_basis','adb','2026-11-02 00:20:21'),
('coop_large_txn_amount','500000','2026-11-02 00:20:21'),
('coop_loan_account_format','{BRANCH}-{PRODUCT}-{SEQ}','2026-11-02 00:20:21'),
('coop_loan_due_alert_days','7','2026-11-02 00:20:21'),
('coop_membership_fee','0','2026-11-02 00:20:21'),
('coop_min_share_capital','0','2026-11-02 00:20:21'),
('coop_optional_pct','7','2026-11-02 00:20:21'),
('coop_or_require_shift','1','2026-11-02 00:20:21'),
('coop_over_short_tolerance','0','2026-11-02 00:20:21'),
('coop_passbook_header','1','2026-11-02 00:20:21'),
('coop_penalty_on_collection','1','2026-11-02 00:20:21'),
('coop_pn_text','','2026-11-02 00:20:21'),
('coop_pr_basis','income','2026-11-02 00:20:21'),
('coop_registered_name','','2026-11-02 00:20:21'),
('coop_reserve_pct','10','2026-11-02 00:20:21'),
('coop_sav_account_format','{BRANCH}{MEMBER}{PRODUCT}1','2026-11-02 00:20:21'),
('coop_signatory_bookkeeper','','2026-11-02 00:20:21'),
('coop_signatory_chair','','2026-11-02 00:20:21'),
('coop_signatory_gm','','2026-11-02 00:20:21'),
('coop_signatory_loans','','2026-11-02 00:20:21'),
('coop_signatory_treasurer','','2026-11-02 00:20:21'),
('coop_td_maturity_alert_days','15','2026-11-02 00:20:21'),
('coop_type','Multi-Purpose','2026-11-02 00:20:21'),
('coop_withdrawal_approval_above','0','2026-11-02 00:20:21'),
('costing_method','weighted_average','2026-11-02 00:20:21'),
('disabled_modules','','2026-11-02 00:20:21'),
('doc_show_member_no','1','2026-11-02 00:20:21'),
('finance_charge_grace_days','0','2026-11-02 00:20:21'),
('finance_charge_min','50','2026-11-02 00:20:21'),
('finance_charge_on_charges','0','2026-11-02 00:20:21'),
('finance_charge_rate','12','2026-11-02 00:20:21'),
('fiscal_year_start_month','1','2026-11-02 00:20:21'),
('isp_account_prefix','','2026-11-02 00:20:22'),
('isp_ack_title','PAYMENT ACKNOWLEDGMENT (not an invoice)','2026-11-02 00:20:22'),
('isp_auto_apply_credit','1','2026-11-02 00:20:22'),
('isp_auto_reconnect','1','2026-11-02 00:20:22'),
('isp_bill_lead_days','0','2026-11-02 00:20:22'),
('isp_bill_timing','advance','2026-11-02 00:20:22'),
('isp_billing_basis','calendar','2026-11-02 00:20:22'),
('isp_bir_atp','','2026-11-02 00:20:22'),
('isp_bir_series','','2026-11-02 00:20:22'),
('isp_business_unit_id','3','2026-11-02 00:20:22'),
('isp_default_cycle_day','1','2026-11-02 00:20:22'),
('isp_defaults_version','2027-04-01','2026-11-02 00:20:22'),
('isp_disc_retry_minutes','15','2026-11-02 00:20:22'),
('isp_due_days','15','2026-11-02 00:20:22'),
('isp_escalate_after_hours','24','2026-11-02 00:20:22'),
('isp_gateway_checkout_url','','2026-11-02 00:20:22'),
('isp_gateway_fee_fixed','0','2026-11-02 00:20:22'),
('isp_gateway_fee_pct','0','2026-11-02 00:20:22'),
('isp_gateway_mode','test','2026-11-02 00:20:22'),
('isp_gateway_provider','none','2026-11-02 00:20:22'),
('isp_gateway_secret_enc','','2026-11-02 00:20:22'),
('isp_gateway_webhook_secret_enc','','2026-11-02 00:20:22'),
('isp_invoice_footer','Please pay on or before the due date to avoid service interruption.\nThis document is system-generated.','2026-11-02 00:20:22'),
('isp_invoice_title','SERVICE INVOICE','2026-11-02 00:20:22'),
('isp_late_fee_after_days','0','2026-11-02 00:20:22'),
('isp_mail_from','','2026-11-02 00:20:22'),
('isp_mail_from_name','','2026-11-02 00:20:22');
INSERT INTO `settings` (`setting_key`,`setting_value`,`updated_at`) VALUES
('isp_map_tiles','','2026-11-02 00:20:22'),
('isp_overdue_reminder_days','2','2026-11-02 00:20:22'),
('isp_partial_recon_pct','100','2026-11-02 00:20:22'),
('isp_payment_instructions','Pay at any of our offices, by GCash / Maya, or by bank transfer. Use your account number as the reference.','2026-11-02 00:20:22'),
('isp_portal_enabled','1','2026-11-02 00:20:22'),
('isp_portal_proof_upload','1','2026-11-02 00:20:22'),
('isp_portal_self_register','1','2026-11-02 00:20:22'),
('isp_privacy_notice','Your personal data is processed by the cooperative for your subscription, billing and service support only, as allowed by the Data Privacy Act of 2012 (RA 10173).','2026-11-02 00:20:22'),
('isp_prorate_changes','1','2026-11-02 00:20:22'),
('isp_prorate_first','1','2026-11-02 00:20:22'),
('isp_receipt_title','COLLECTION RECEIPT','2026-11-02 00:20:22'),
('isp_recon_fee_policy','next_bill','2026-11-02 00:20:22'),
('isp_reminder_days','3','2026-11-02 00:20:22'),
('isp_runs_need_approval','1','2026-11-02 00:20:22'),
('isp_sla_critical_hours','4','2026-11-02 00:20:22'),
('isp_sla_high_hours','8','2026-11-02 00:20:22'),
('isp_sla_low_hours','72','2026-11-02 00:20:22'),
('isp_sla_normal_hours','24','2026-11-02 00:20:22'),
('isp_sms_api_key_enc','','2026-11-02 00:20:22'),
('isp_sms_api_url','','2026-11-02 00:20:22'),
('isp_sms_provider','none','2026-11-02 00:20:22'),
('isp_sms_sender','','2026-11-02 00:20:22'),
('isp_smtp_host','','2026-11-02 00:20:22'),
('isp_smtp_pass_enc','','2026-11-02 00:20:22'),
('isp_smtp_port','587','2026-11-02 00:20:22'),
('isp_smtp_secure','tls','2026-11-02 00:20:22'),
('isp_smtp_user','','2026-11-02 00:20:22'),
('isp_stock_site_id','1','2026-11-02 00:20:22'),
('isp_temp_recon_hours','72','2026-11-02 00:20:22'),
('isp_vat_rate','12','2026-11-02 00:20:22'),
('isp_vat_registered','1','2026-11-02 00:20:22'),
('item_search_by_page','','2026-11-02 00:20:21'),
('item_search_format','code_name','2026-11-02 00:20:21'),
('large_discount_pct','10','2026-11-02 00:20:21'),
('loan_pb_adjustments_since','2026-11-02 00:20:21','2026-11-02 00:20:21'),
('lock_posted_documents','1','2026-11-02 00:20:21'),
('logo_targets','','2026-11-02 00:20:21'),
('max_failed_logins','5','2026-11-02 00:20:21'),
('member_price_level_id','','2026-11-02 00:20:21'),
('nonmember_price_level_id','','2026-11-02 00:20:21'),
('pos_posting_mode','per_sale','2026-11-02 00:20:21'),
('pos_receipt_footer','Thank you for shopping!\nThis serves as your official receipt.','2026-11-02 00:20:21'),
('pos_receipt_show_customer','1','2026-11-02 00:20:21'),
('pos_receipt_title','OFFICIAL RECEIPT','2026-11-02 00:20:21'),
('pos_receipt_title_charge','CHARGE INVOICE','2026-11-02 00:20:21'),
('pos_receipt_title_payment','ACKNOWLEDGEMENT RECEIPT','2026-11-02 00:20:21'),
('pos_receipt_title_return','RETURN / REFUND','2026-11-02 00:20:21'),
('pw_expiry_days','90','2026-11-02 00:20:21'),
('pw_min_length','8','2026-11-02 00:20:21'),
('pw_require_number','1','2026-11-02 00:20:21'),
('pw_require_symbol','1','2026-11-02 00:20:21'),
('pw_require_upper','1','2026-11-02 00:20:21'),
('session_timeout_minutes','30','2026-11-02 00:20:21'),
('withholding_agent','1','2026-11-02 00:20:21');

-- users (27 rows)
INSERT INTO `users` (`id`,`username`,`password_hash`,`full_name`,`email`,`employee_no`,`pos_default_site_id`,`pos_default_class_id`,`coop_branch_id`,`coop_cash_account_id`,`status`,`approval_status`,`created_via`,`approved_by`,`approved_at`,`rejected_reason`,`is_platform`,`must_change_password`,`password_changed_at`,`failed_attempts`,`last_login_at`,`last_login_ip`,`created_by`,`created_at`,`updated_at`) VALUES
(1,'admin','$2y$10$yTy0cyW73zzSwdHlvkuyeORzEqnwL2GVMQMQqt7oR5AlPmK6Zd.Vi','System Administrator',NULL,'EMP-001',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-11-02 00:20:21',0,NULL,NULL,NULL,'2026-11-02 00:20:21',NULL),
(2,'accountant','$2y$10$jZAt4uIWRKAMX84NgyAgVONTKDqMiz.X8wQL5/2rRaNdXajPs9BYi','Ana Cruz',NULL,'EMP-002',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-11-02 00:20:21',0,NULL,NULL,NULL,'2026-11-02 00:20:21',NULL),
(3,'arclerk','$2y$10$Ys.W/oEiHQ1SngxkVVWJeu/0bD/CFSUoCJqH3zAEza9L.xiTTeg6K','Ben Garcia',NULL,'EMP-003',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-11-02 00:20:21',0,NULL,NULL,NULL,'2026-11-02 00:20:21',NULL),
(4,'apclerk','$2y$10$Ql23ucGBjC/ccN/Ks1qEKepNNBTfWkFtYPaTxaPfiaq5AdV5k5nqK','Carla Lim',NULL,'EMP-004',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-11-02 00:20:21',0,NULL,NULL,NULL,'2026-11-02 00:20:21',NULL),
(5,'invclerk','$2y$10$hzTte4QJxVG1HqK4VBs1t.sZ8Eo3wgvTCBHh6aSEJoYB3ArMyOqSe','Dan Villanueva',NULL,'EMP-005',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-11-02 00:20:21',0,NULL,NULL,NULL,'2026-11-02 00:20:21',NULL),
(6,'salesrep','$2y$10$23E2NsrE06nVNqzfrG.FIeNS6CZkBrLbv.pqIZsXj.j9EWhV/Fmey','Maria Santos',NULL,'EMP-006',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-11-02 00:20:21',0,NULL,NULL,NULL,'2026-11-02 00:20:21',NULL),
(7,'cashier1','$2y$10$SaNxHHozsBnbxASI7BYs8Od/cehXoCagTlflyNf4vAZ3xnlKuC.wu','Ella Ramos',NULL,'EMP-007',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-11-02 00:20:21',0,NULL,NULL,NULL,'2026-11-02 00:20:21',NULL),
(8,'supervisor','$2y$10$vnni3AwfZp474fX7AbSFpO//ZNz3uBn9VbJCNHOVEdRIBB/LAGVz6','Fred Tan',NULL,'EMP-008',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-11-02 00:20:21',0,NULL,NULL,NULL,'2026-11-02 00:20:21',NULL),
(9,'auditor','$2y$10$FZDytUIALgGTDoRZfRMNlOhY4AJ2jTKhJHg284LMu2n6nI7Erm4Nq','Grace Uy',NULL,'EMP-009',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-11-02 00:20:21',0,NULL,NULL,NULL,'2026-11-02 00:20:21',NULL),
(10,'isp.scheduler','$2y$10$3B3xv.lBSHmCMgr2C8ZZsegoyYwTNAPV4CX6ETTHDq9dGbINHglQ6','ISP Scheduler (system)',NULL,NULL,NULL,NULL,NULL,NULL,'locked','approved','system',NULL,NULL,NULL,0,0,NULL,0,NULL,NULL,NULL,'2026-11-02 00:20:22',NULL),
(11,'agent1','$2y$10$UYeQCj5PNnwPK3agxCcuGe0rtcD1Mpq6rtB82lv39yBIHRgf63ySC','Ramon Dela Cruz',NULL,'EMP-101',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-11-02 00:20:22',0,NULL,NULL,NULL,'2026-11-02 00:20:22',NULL),
(12,'installer1','$2y$10$PqhoyXlIm/REduKo91Jxn.7ZYe1SgaZqYL2Qk/GU69v8fNVwOLbi6','Jomar Pascual',NULL,'EMP-102',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-11-02 00:20:22',0,NULL,NULL,NULL,'2026-11-02 00:20:22',NULL),
(13,'maintech1','$2y$10$NnIDHnCgshrp8mWDOKdd1OQCoHXpxz/BX1.V.q1oonBFe97XmnfXa','Rodel Manalo',NULL,'EMP-103',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-11-02 00:20:22',0,NULL,NULL,NULL,'2026-11-02 00:20:22',NULL),
(14,'collector1','$2y$10$futBxATqF0SOgEDqV4xQMuVoE4UAMaDeGwKTZXpwHFQN/AtO91W7C','Alvin Soriano',NULL,'EMP-104',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-11-02 00:20:22',0,NULL,NULL,NULL,'2026-11-02 00:20:22',NULL),
(15,'teller1','$2y$10$mB09P/0SfNcneos6nVOKwe8OLLC/eO7f/fUX6FctyLOx9rvgYtQZG','Teresa Villanueva',NULL,'EMP-105',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-11-02 00:20:22',0,NULL,NULL,NULL,'2026-11-02 00:20:22',NULL),
(16,'csr1','$2y$10$AsC0o43omN65B0ryMYP1Zu17sCvUyVMnqtnWwJ6Ife1r4inKlwP4y','Kristine Lopez',NULL,'EMP-107',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-11-02 00:20:22',0,NULL,NULL,NULL,'2026-11-02 00:20:22',NULL),
(17,'custodian1','$2y$10$IOQ8UahAc0yrjg4I/9/KXeSjOhv3k9.X3tSyo0p3z8BrsniFYv9pK','Noel Bautista',NULL,'EMP-110',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-11-02 00:20:23',0,NULL,NULL,NULL,'2026-11-02 00:20:23',NULL),
(18,'membership','$2y$10$KcwGWzvhLCMVTUmGexTJK./VsOMS4ODos/xatYnzgs0VwaxxRm7uS','Nina Bautista',NULL,'EMP-113',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-11-02 00:20:23',0,NULL,NULL,NULL,'2026-11-02 00:20:23',NULL),
(19,'collections1','$2y$10$OVRoxQiSytOxT4XqU2Qj2.clO90butMSRbqRPGP2IBFBG4XcKJdbi','Liza Mendoza',NULL,'EMP-114',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-11-02 00:20:23',0,NULL,NULL,NULL,'2026-11-02 00:20:23',NULL),
(20,'billing1','$2y$10$Yyj1ohjMRtYjiCvs/IZj1eueF.d3vvkXNfaoYVjbP3wsWNs.bm9Ty','Janet Ocampo',NULL,'EMP-115',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-11-02 00:20:23',0,NULL,NULL,NULL,'2026-11-02 00:20:23',NULL),
(21,'loanofficer','$2y$10$NaHXBxLD9Glau8UhPK.VquvEep2qPIhEb1qTmlpa//FBZS8EYh1aG','Marvin Uy',NULL,'EMP-116',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-11-02 00:20:23',0,NULL,NULL,NULL,'2026-11-02 00:20:23',NULL),
(22,'creditcom','$2y$10$LvWKuW9jGhbxE1yJG8p.euQQ1xPedR852TMM3xRhqISskR3kHYXFy','Eduardo Santos',NULL,'EMP-117',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-11-02 00:20:23',0,NULL,NULL,NULL,'2026-11-02 00:20:23',NULL),
(23,'bookkeeper','$2y$10$eiR9ILzWGKH9MojdJdsFZO2ySWl7ikWEl7BswJBNHXorSvtdyujQq','Joy Fernandez',NULL,'EMP-119',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-11-02 00:20:23',0,NULL,NULL,NULL,'2026-11-02 00:20:23',NULL),
(24,'netadmin1','$2y$10$kHTgRTJBIhtB4bqpKM.Wvun5cFSy7Rk3koKrolVdO8v7lRe58ALQC','Arnold Castillo',NULL,'EMP-121',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-11-02 00:20:23',0,NULL,NULL,NULL,'2026-11-02 00:20:23',NULL),
(25,'techsup1','$2y$10$VNiO35hU3hd73vTG7pwITO5IYKlPIS/SVgEmn592fDJy1p6xldGPi','Rey Gonzales',NULL,'EMP-122',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-11-02 00:20:23',0,NULL,NULL,NULL,'2026-11-02 00:20:23',NULL),
(26,'branchmgr','$2y$10$zj3.5e7wQ6qjwuxDfdNpfOPMKPn0E44cxbWDuqt2XoZomYTKUrB32','Carlo Reyes',NULL,'EMP-124',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-11-02 00:20:23',0,NULL,NULL,NULL,'2026-11-02 00:20:23',NULL),
(27,'manager','$2y$10$8CPCsivrCnYf4KoZ/b.nHuhZzVM7msLdgYA4szpfVxpnV8nUvBvd.','Rosario Dizon',NULL,'EMP-125',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-11-02 00:20:23',0,NULL,NULL,NULL,'2026-11-02 00:20:23',NULL);

-- roles (26 rows)
INSERT INTO `roles` (`id`,`name`,`description`,`is_admin`,`is_system`,`all_sites`,`created_at`,`updated_at`,`coop_cash_account_id`) VALUES
(1,'Company Administrator','Everything in every module of the company, including user accounts, roles, page access, the closing date, the ISP set-up and the audit trail.',1,1,1,'2026-11-02 00:20:21',NULL,NULL),
(2,'Accountant','Chart of Accounts, Fixed Assets, Banking and reconciliation, the BIR Reports and Withholding Tax Table, all module reports, and the financial statements.',0,1,1,'2026-11-02 00:20:21',NULL,NULL),
(3,'A/R Clerk','Customers module transactions and the Customer and Receivables reports.',0,1,1,'2026-11-02 00:20:21',NULL,NULL),
(4,'A/P Clerk','Vendors module transactions, Pay Bills, advances to suppliers, the Vendors and Payables reports, and BIR Form 2307 for suppliers.',0,1,1,'2026-11-02 00:20:21',NULL,NULL),
(5,'Inventory Clerk','Items, sites, receiving, adjustments, assembly builds, disassembly and processing orders, stock counts, and inventory quantity reports.',0,1,1,'2026-11-02 00:20:21',NULL,NULL),
(6,'Sales Representative','Estimates, sales orders, and invoices, and the Sales reports.',0,1,1,'2026-11-02 00:20:21',NULL,NULL),
(7,'Cashier','Collects subscriber payments in an open cashier shift, prints collection receipts, counts the cash at the end of the shift; also the POS register at the assigned terminal.',0,1,0,'2026-11-02 00:20:21','2026-11-02 00:20:22',NULL),
(8,'POS Supervisor','Everything a cashier can do, plus approving voids, refunds, price overrides, and large discounts, and running X- and Z-readings.',0,1,1,'2026-11-02 00:20:21',NULL,NULL),
(9,'Auditor','View-only access to all transactions, reports, and the audit trail. Cannot create or change anything.',0,1,1,'2026-11-02 00:20:21',NULL,NULL),
(10,'General Manager','Opens every cooperative, savings, loan, teller, distribution and compliance page, approves loans and transactions, and sees every report.',0,1,1,'2026-11-02 00:20:21',NULL,NULL),
(11,'Bookkeeper','Records cooperative vouchers, posts interest and penalties, runs the subsidiary ledgers and financial reports.',0,1,1,'2026-11-02 00:20:21',NULL,NULL),
(12,'Loan Officer','Loan applications, credit investigation, loan release, collection, loan accounts and loan reports.',0,1,1,'2026-11-02 00:20:21',NULL,NULL),
(13,'Credit Committee','Reviews and approves loan applications and waivers; views loan reports.',0,1,1,'2026-11-02 00:20:21',NULL,NULL),
(14,'Teller','Teller shift, official receipts, savings deposits and withdrawals, loan collection and passbook printing.',0,1,1,'2026-11-02 00:20:21',NULL,NULL),
(15,'Membership Officer','Member records, member statements, savings account opening and the member reports.',0,1,1,'2026-11-02 00:20:21',NULL,NULL),
(16,'Collector','Loan collection and the collection, due and aging reports.',0,1,1,'2026-11-02 00:20:21',NULL,NULL),
(17,'Branch Manager','Everything of the branch: approves savings accounts, withdrawals, loans and waivers, closes teller shifts, and sees the branch reports.',0,1,1,'2026-11-02 00:20:21',NULL,NULL),
(18,'Billing Officer','Billing runs (preview and generation), invoices, adjustments to request, statements of account, disputes and payment arrangements to record, and the billing reports.',0,1,1,'2026-11-02 00:20:22',NULL,NULL),
(19,'Collection Officer','Follows up overdue subscribers: verifies bank / e-wallet payment references, records field collections, payment arrangements and collection notes; sees the aging, delinquency and disconnection lists.',0,1,1,'2026-11-02 00:20:22',NULL,NULL),
(20,'Network Administrator','Network devices and integrations (MikroTik, RADIUS, OLT, TR-069), provisioning, the network operations queue and retries, disconnections and reconnections, infrastructure and the network map, outages.',0,1,1,'2026-11-02 00:20:22',NULL,NULL),
(21,'Technical Supervisor','Dispatches and verifies work orders, assigns technicians, approves installations and equipment replacements, manages tickets, outages and the field reports.',0,1,1,'2026-11-02 00:20:22',NULL,NULL),
(22,'Installation Technician','Field installer: own task list, accepts and completes installation work orders, records materials used, serial numbers, signal readings, photos and the subscriber acceptance. No billing or financial pages.',0,1,1,'2026-11-02 00:20:22',NULL,NULL),
(23,'Maintenance Technician','Field repairs: own task list, repair, relocation, recovery and replacement work orders and the tickets assigned, materials used and returned. No billing or financial pages.',0,1,1,'2026-11-02 00:20:22',NULL,NULL),
(24,'Customer Service Representative','Front desk and hotline: subscriber look-up, applications, support tickets, service requests, statements of account, announcements; no collections, adjustments or network commands.',0,1,1,'2026-11-02 00:20:22',NULL,NULL),
(25,'Inventory Custodian','The network stockroom: equipment and material items, receiving, transfers to technicians, returns, damaged stock, counts, serial numbers and the inventory reports.',0,1,1,'2026-11-02 00:20:22',NULL,NULL),
(26,'Sales Agent','Signs up subscribers: new applications (own), checks coverage and plans, follows the status of the applications and sees own commissions.',0,1,1,'2026-11-02 00:20:22',NULL,NULL);

-- user_roles (27 rows)
INSERT INTO `user_roles` (`user_id`,`role_id`) VALUES
(1,1),
(2,2),
(3,3),
(4,4),
(5,5),
(6,6),
(7,7),
(8,8),
(9,9),
(10,1),
(11,26),
(12,22),
(13,23),
(14,16),
(15,14),
(16,24),
(17,25),
(18,15),
(19,19),
(20,18),
(21,12),
(22,13),
(23,11),
(24,20),
(25,21),
(26,17),
(27,10);

-- modules (23 rows)
INSERT INTO `modules` (`id`,`code`,`name`,`icon`,`sort_order`) VALUES
(1,'home','Home','house',1),
(2,'isp','Subscribers','router',2),
(3,'ispbill','Billing & Collection','receipt-cutoff',3),
(4,'ispnet','Network & Service Control','diagram-3',4),
(5,'ispfield','Field Service','tools',5),
(6,'ispset','ISP Setup','sliders',6),
(7,'coa','Financial','journal-bookmark',7),
(8,'coop','Members & Others','people-fill',8),
(9,'teller','Teller','safe2',9),
(10,'sav','Savings & Deposits','piggy-bank',10),
(11,'loan','Loans','cash-coin',11),
(12,'bank','Banking','bank',12),
(13,'company','Company','buildings',13),
(14,'cust','Customers','people',14),
(15,'pos','Point of Sale','cart',15),
(16,'vend','Vendors','truck',16),
(17,'inv','Inventory','box-seam',17),
(18,'fa','Fixed Assets','building',18),
(19,'party','Parties','person-vcard',19),
(20,'bir','BIR Reports','file-earmark-ruled',20),
(21,'dist','Year-end Distribution','pie-chart',21),
(22,'cda','CDA Compliance','clipboard2-check',22),
(23,'admin','System Administration','shield-lock',23);

-- pages (379 rows)
INSERT INTO `pages` (`id`,`page_key`,`module_id`,`name`,`page_type`,`menu_group`,`sort_order`,`show_in_menu`,`description`) VALUES
(1,'home.dashboard',1,'Home Dashboard','center','Home',10,1,NULL),
(2,'home.reports',1,'Report Center','center','Home',20,1,NULL),
(3,'home.memorized',1,'Memorized Reports','list','Home',30,1,NULL),
(4,'home.report_layouts',1,'Saved Report Layouts','list','Home',40,1,NULL),
(5,'home.search',1,'Search','center','Home',50,0,NULL),
(6,'home.find',1,'Find','center','Home',60,1,NULL),
(7,'rpt.find',1,'Find Results','report','Reports',70,0,NULL),
(8,'coa.accounts',7,'Chart of Accounts','list','Lists',80,1,NULL),
(9,'coa.account_types',7,'Account Types','list','Lists',90,1,NULL),
(10,'coa.classes',7,'Class List','list','Lists',100,1,NULL),
(11,'coa.names',7,'Employees & Other Names','list','Lists',110,1,NULL),
(12,'coa.memorized_txns',7,'Memorized Transactions','list','Lists',120,1,NULL),
(13,'coa.budgets',7,'Set Up Budgets','setup','Setup',130,1,NULL),
(14,'coa.journal',7,'Make Journal Entries','transaction','Transactions',140,1,NULL),
(15,'coa.je_edits',7,'Journal Entry Changes','list','Transactions',150,1,NULL),
(16,'coa.default_accounts',7,'Module Default Accounts','setup','Setup',160,1,NULL),
(17,'coa.lists',7,'Terms, Tax Codes & Other Lists','setup','Setup',170,1,NULL),
(18,'rpt.trial_balance',7,'Trial Balance','report','Reports',180,1,NULL),
(19,'rpt.balance_sheet',7,'Balance Sheet','report','Reports',190,1,NULL),
(20,'rpt.balance_sheet_summary',7,'Balance Sheet Summary','report','Reports',200,1,NULL),
(21,'rpt.balance_sheet_class',7,'Balance Sheet by Class','report','Reports',210,1,NULL),
(22,'rpt.trial_balance_range',7,'Trial Balance by Date Range','report','Reports',220,1,NULL),
(23,'rpt.trial_balance_totals',7,'Trial Balance of Totals','report','Reports',230,1,NULL),
(24,'rpt.balance_sheet_range',7,'Balance Sheet by Date Range','report','Reports',240,1,NULL),
(25,'rpt.budget_income',7,'Income Statement vs Budget','report','Reports',250,1,NULL),
(26,'rpt.budget_balance',7,'Balance Sheet vs Budget','report','Reports',260,1,NULL),
(27,'rpt.income_statement',7,'Income Statement','report','Reports',270,1,NULL),
(28,'rpt.income_statement_summary',7,'Income Statement Summary','report','Reports',280,1,NULL),
(29,'rpt.income_statement_class',7,'Income Statement by Class','report','Reports',290,1,NULL),
(30,'rpt.cash_flow',7,'Statement of Cash Flows','report','Reports',300,1,NULL),
(31,'rpt.general_ledger',7,'General Ledger','report','Reports',310,1,NULL),
(32,'rpt.subsidiary_ledger',7,'Subsidiary Ledger','report','Reports',320,1,NULL),
(33,'rpt.schedule_of_accounts',7,'Schedule of Accounts','report','Reports',330,1,NULL),
(34,'rpt.journal',7,'Journal','report','Reports',340,1,NULL),
(35,'fa.register',18,'Fixed Asset Register','center','Transactions',350,1,NULL),
(36,'fa.depreciation',18,'Run Depreciation','transaction','Transactions',360,1,NULL),
(37,'fa.disposal',18,'Dispose of Assets','transaction','Transactions',370,1,NULL),
(38,'fa.maintenance',18,'Asset Maintenance','transaction','Transactions',380,1,NULL),
(39,'fa.pm',18,'Preventive Maintenance','transaction','Transactions',390,1,NULL),
(40,'fa.categories',18,'Asset Categories','setup','Setup',400,1,NULL),
(41,'fa.locations',18,'Asset Locations','setup','Setup',410,1,NULL),
(42,'rpt.fa_register',18,'Fixed Asset Register Report','report','Reports',420,1,NULL),
(43,'rpt.fa_schedule',18,'Depreciation Schedule by Asset','report','Reports',430,1,NULL),
(44,'rpt.fa_by_category',18,'Depreciation by Category','report','Reports',440,1,NULL),
(45,'rpt.fa_additions',18,'Additions and Disposals','report','Reports',450,1,NULL),
(46,'rpt.fa_maint_history',18,'Maintenance History','report','Reports',460,1,NULL),
(47,'rpt.fa_maint_cost',18,'Maintenance Cost by Asset','report','Reports',470,1,NULL),
(48,'rpt.fa_maint_parts',18,'Spare Parts Used','report','Reports',480,1,NULL),
(49,'rpt.fa_maint_labor',18,'Maintenance Labor','report','Reports',490,1,NULL),
(50,'rpt.fa_pm_upcoming',18,'Upcoming Preventive Maintenance','report','Reports',500,1,NULL),
(51,'rpt.fa_pm_log',18,'Completed Preventive Maintenance','report','Reports',510,1,NULL),
(52,'cust.center',14,'Customer Center','center','Transactions',520,1,NULL),
(53,'cust.estimates',14,'Create Estimates','transaction','Transactions',530,1,NULL),
(54,'cust.sales_orders',14,'Create Sales Orders','transaction','Transactions',540,1,NULL),
(55,'cust.invoices',14,'Create Invoices','transaction','Transactions',550,1,NULL),
(56,'cust.batch_invoices',14,'Create Batch Invoices','transaction','Transactions',560,1,NULL),
(57,'cust.sales_receipts',14,'Enter Sales Receipts','transaction','Transactions',570,1,NULL),
(58,'cust.statement_charges',14,'Enter Statement Charges','transaction','Transactions',580,1,NULL),
(59,'cust.statements',14,'Create Statements','transaction','Transactions',590,1,NULL),
(60,'cust.finance_charges',14,'Assess Finance Charges','transaction','Transactions',600,1,NULL),
(61,'cust.forecast',14,'Set Up Forecast','setup','Transactions',610,1,NULL),
(62,'cust.payments',14,'Receive Payments','transaction','Transactions',620,1,NULL),
(63,'cust.credit_memos',14,'Create Credit Memos/Refunds','transaction','Transactions',630,1,NULL),
(64,'rpt.ar_aging_summary',14,'A/R Aging Summary','report','Customer and Receivables Reports',640,1,NULL),
(65,'rpt.ar_aging_detail',14,'A/R Aging Detail','report','Customer and Receivables Reports',650,1,NULL),
(66,'rpt.customer_balance_summary',14,'Customer Balance Summary','report','Customer and Receivables Reports',660,1,NULL),
(67,'rpt.customer_balance_detail',14,'Customer Balance Detail','report','Customer and Receivables Reports',670,1,NULL),
(68,'rpt.open_invoices',14,'Open Invoices','report','Customer and Receivables Reports',680,1,NULL),
(69,'rpt.collections',14,'Collections Report','report','Customer and Receivables Reports',690,1,NULL),
(70,'rpt.avg_days_summary',14,'Average Days to Pay Summary','report','Customer and Receivables Reports',700,1,NULL),
(71,'rpt.avg_days_detail',14,'Average Days to Pay','report','Customer and Receivables Reports',710,1,NULL),
(72,'rpt.ar_graph',14,'Accounts Receivable Graph','report','Customer and Receivables Reports',720,1,NULL),
(73,'rpt.unbilled_costs',14,'Unbilled Cost by Job','report','Customer and Receivables Reports',730,1,NULL),
(74,'rpt.txn_list_customer',14,'Transaction List by Customer','report','Customer and Receivables Reports',740,1,NULL),
(75,'rpt.item_price_list',14,'Item Price List','report','Customer and Receivables Reports',750,1,NULL),
(76,'rpt.sales_customer_summary',14,'Sales by Customer Summary','report','Sales Reports',760,1,NULL),
(77,'rpt.forecast_vs_actual',14,'Forecast vs Actual by Customer','report','Sales Reports',770,1,NULL),
(78,'rpt.sales_customer_detail',14,'Sales by Customer Detail','report','Sales Reports',780,1,NULL),
(79,'rpt.income_customer_summary',14,'Income by Customer Summary','report','Sales Reports',790,1,NULL),
(80,'rpt.income_customer_detail',14,'Income by Customer Detail','report','Sales Reports',800,1,NULL),
(81,'rpt.sales_item_summary',14,'Sales by Item Summary','report','Sales Reports',810,1,NULL),
(82,'rpt.sales_item_detail',14,'Sales by Item Detail','report','Sales Reports',820,1,NULL),
(83,'rpt.sales_rep_summary',14,'Sales by Rep Summary','report','Sales Reports',830,1,NULL),
(84,'rpt.sales_rep_detail',14,'Sales by Rep Detail','report','Sales Reports',840,1,NULL),
(85,'rpt.inventory_sales_site',14,'Inventory Sales by Site','report','Sales Reports',850,1,NULL),
(86,'rpt.sales_inv_movement',14,'Sales Report Through Inventory Movement','report','Sales Reports',860,1,NULL),
(87,'rpt.pending_sales',14,'Pending Sales','report','Sales Reports',870,1,NULL),
(88,'rpt.open_so_customer',14,'Open Sales Orders by Customer','report','Sales Reports',880,1,NULL),
(89,'rpt.open_so_item',14,'Open Sales Orders by Item','report','Sales Reports',890,1,NULL),
(90,'vend.center',16,'Vendor Center','center','Transactions',900,1,NULL),
(91,'vend.bill_tracker',16,'Bill Tracker','center','Transactions',910,1,NULL),
(92,'vend.bills',16,'Enter Bills','transaction','Transactions',920,1,NULL),
(93,'vend.debit_memos',16,'Supplier Debit Memos','transaction','Transactions',930,1,NULL),
(94,'vend.pay_bills',16,'Pay Bills','transaction','Transactions',940,1,NULL),
(95,'vend.receipts',16,'Receipt Management','transaction','Transactions',950,1,NULL),
(96,'vend.purchase_orders',16,'Create Purchase Orders','transaction','Transactions',960,1,NULL),
(97,'vend.receive_bill',16,'Receive Items and Enter Bills','transaction','Transactions',970,1,NULL),
(98,'vend.receive_items',16,'Receive Items','transaction','Transactions',980,1,NULL),
(99,'vend.bill_received',16,'Enter Bill for Received Items','transaction','Transactions',990,1,NULL),
(100,'vend.advances',16,'Advances to Suppliers','transaction','Transactions',1000,1,NULL);
INSERT INTO `pages` (`id`,`page_key`,`module_id`,`name`,`page_type`,`menu_group`,`sort_order`,`show_in_menu`,`description`) VALUES
(101,'rpt.ap_aging_summary',16,'A/P Aging Summary','report','Vendors and Payables Reports',1010,1,NULL),
(102,'rpt.ap_aging_detail',16,'A/P Aging Detail','report','Vendors and Payables Reports',1020,1,NULL),
(103,'rpt.vendor_balance_summary',16,'Vendor Balance Summary','report','Vendors and Payables Reports',1030,1,NULL),
(104,'rpt.vendor_balance_detail',16,'Vendor Balance Detail','report','Vendors and Payables Reports',1040,1,NULL),
(105,'rpt.unpaid_bills',16,'Unpaid Bills Detail','report','Vendors and Payables Reports',1050,1,NULL),
(106,'rpt.txn_list_vendor',16,'Transaction List by Vendor','report','Vendors and Payables Reports',1060,1,NULL),
(107,'rpt.vendor_contacts',16,'Vendor Contact List','report','Vendors and Payables Reports',1070,1,NULL),
(108,'rpt.expenses_vendor_summary',16,'Expenses by Vendor Summary','report','Vendors and Payables Reports',1080,1,NULL),
(109,'rpt.expenses_vendor_detail',16,'Expenses by Vendor Detail','report','Vendors and Payables Reports',1090,1,NULL),
(110,'rpt.vendor_advances',16,'Advances to Suppliers','report','Vendors and Payables Reports',1100,1,NULL),
(111,'inv.center',17,'Inventory Center','center','Transactions',1110,1,NULL),
(112,'inv.items',17,'Item List','list','Lists',1120,1,NULL),
(113,'inv.categories',17,'Category List','list','Lists',1130,1,NULL),
(114,'inv.sites',17,'Inventory Site List','list','Lists',1140,1,NULL),
(115,'inv.uom',17,'U/M Set List','list','Lists',1150,1,NULL),
(116,'inv.formulas',17,'Processing Formulas (Multi-Output BOM)','list','Lists',1160,1,NULL),
(117,'inv.transfers',17,'Transfer Inventory','transaction','Transactions',1170,1,NULL),
(118,'inv.adjust',17,'Adjust Quantity/Value on Hand','transaction','Transactions',1180,1,NULL),
(119,'inv.build',17,'Build Assemblies','transaction','Transactions',1190,1,NULL),
(120,'inv.process',17,'Disassemble / Process Items','transaction','Transactions',1200,1,NULL),
(121,'inv.physical_count',17,'Physical Inventory Count','transaction','Transactions',1210,1,NULL),
(122,'inv.change_prices',17,'Change Item Prices','transaction','Transactions',1220,1,NULL),
(123,'inv.set_prices',17,'Set Item Prices and Levels','transaction','Transactions',1230,1,NULL),
(124,'rpt.qoh_by_site',17,'Quantity on Hand by Site','report','Inventory Reports',1240,1,NULL),
(125,'rpt.inv_valuation_summary',17,'Inventory Valuation Summary','report','Inventory Reports',1250,1,NULL),
(126,'rpt.inv_valuation_site',17,'Inventory Valuation Summary by Site','report','Inventory Reports',1260,1,NULL),
(127,'rpt.inv_valuation_detail',17,'Inventory Valuation Detail','report','Inventory Reports',1270,1,NULL),
(128,'rpt.stock_status_item',17,'Inventory Stock Status by Item','report','Inventory Reports',1280,1,NULL),
(129,'rpt.stock_status_vendor',17,'Inventory Stock Status by Vendor','report','Inventory Reports',1290,1,NULL),
(130,'rpt.stock_status_site',17,'Inventory Stock Status by Site','report','Inventory Reports',1300,1,NULL),
(131,'rpt.physical_worksheet',17,'Physical Inventory Worksheet','report','Inventory Reports',1310,1,NULL),
(132,'rpt.pending_builds',17,'Pending Builds','report','Inventory Reports',1320,1,NULL),
(133,'rpt.pending_builds_site',17,'Pending Builds by Site','report','Inventory Reports',1330,1,NULL),
(134,'rpt.processing_register',17,'Processing & Disassembly Register','report','Inventory Reports',1340,1,NULL),
(135,'rpt.coproduct_costs',17,'Co-product & By-product Cost Allocation','report','Inventory Reports',1350,1,NULL),
(136,'rpt.process_yield',17,'Processing Yield Analysis','report','Inventory Reports',1360,1,NULL),
(137,'rpt.negative_inventory',17,'Negative Inventory Listing','report','Inventory Reports',1370,1,NULL),
(138,'rpt.inv_no_movement',17,'No. of Days Inventory Has No Movement','report','Inventory Reports',1380,1,NULL),
(139,'rpt.inv_custom',17,'Customized Inventory Report','report','Inventory Reports',1390,1,NULL),
(140,'rpt.inv_movement_summary',17,'Inventory Movement Summary Report','report','Inventory Reports',1400,1,NULL),
(141,'rpt.inv_movement_detail',17,'Inventory Movement Detailed Report','report','Inventory Reports',1410,1,NULL),
(142,'rpt.inv_movement_txns',17,'Inventory Movement Transactions','report','Inventory Reports',1420,0,NULL),
(143,'pos.register',15,'POS Register','transaction','Transactions',1430,1,NULL),
(144,'pos.shifts',15,'Shifts, Cash Drops & Readings','transaction','Transactions',1440,1,NULL),
(145,'pos.receipts',15,'POS Receipts, Returns & Voids','transaction','Transactions',1450,1,NULL),
(146,'pos.approval_codes',15,'Approval Codes','transaction','Transactions',1460,1,NULL),
(147,'pos.terminals',15,'POS Terminals','setup','Setup',1470,1,NULL),
(148,'rpt.pos_sales_txn',15,'POS Sales by Transaction No. (detail)','report','Reports',1480,1,NULL),
(149,'rpt.pos_sales_item',15,'POS Sales by Item','report','Reports',1490,1,NULL),
(150,'rpt.pos_sales_category',15,'POS Sales by Category','report','Reports',1500,1,NULL),
(151,'rpt.pos_sales_cashier',15,'POS Sales by Cashier & Payment Method','report','Reports',1510,1,NULL),
(152,'rpt.pos_sales_payment',15,'POS Sales by Payment Method','report','Reports',1520,0,NULL),
(153,'rpt.pos_sales_hour',15,'POS Sales by Hour','report','Reports',1530,1,NULL),
(154,'rpt.pos_daily_summary',15,'POS Daily Sales Summary','report','Reports',1540,1,NULL),
(155,'rpt.pos_readings',15,'X- and Z-Reading Summary','report','Reports',1550,1,NULL),
(156,'rpt.pos_discount_log',15,'POS Discount Log','report','Reports',1560,1,NULL),
(157,'rpt.pos_void_log',15,'POS Void and Refund Log','report','Reports',1570,1,NULL),
(158,'bank.write_checks',12,'Write Checks','transaction','Transactions',1580,1,NULL),
(159,'bank.cash_voucher',12,'Cash Voucher','transaction','Transactions',1590,1,NULL),
(160,'bank.register',12,'Use Register','center','Transactions',1600,1,NULL),
(161,'bank.deposits',12,'Make Deposits','transaction','Transactions',1610,1,NULL),
(162,'bank.transfers',12,'Transfer Funds','transaction','Transactions',1620,1,NULL),
(163,'bank.reconcile',12,'Reconcile','transaction','Transactions',1630,1,NULL),
(164,'rpt.recon_summary',12,'Reconciliation Summary','report','Reports',1640,1,NULL),
(165,'rpt.recon_detail',12,'Reconciliation Detail','report','Reports',1650,1,NULL),
(166,'rpt.recon_discrepancy',12,'Reconciliation Discrepancy','report','Reports',1660,1,NULL),
(167,'rpt.check_register',12,'Check Register','report','Reports',1670,1,NULL),
(168,'bir.form_2307',20,'Print BIR Form 2307','transaction','Transactions',1680,1,NULL),
(169,'bir.wht_table',20,'Withholding Tax Table','setup','Setup',1690,1,NULL),
(170,'rpt.bir_wht_summary',20,'Summary of Taxes Withheld from Vendors/Suppliers','report','BIR Reports',1700,1,NULL),
(171,'rpt.bir_ewt_register',20,'Expanded Withholding Tax Register (1% / 2%)','report','BIR Reports',1710,1,NULL),
(172,'rpt.bir_remittance',20,'Withholding Tax Remittances (0619-E, 1601-EQ, 1600-VT)','report','BIR Reports',1720,1,NULL),
(173,'rpt.bir_vat_withholding',20,'VAT Withholding Register (1600-VT)','report','BIR Reports',1730,1,NULL),
(174,'rpt.bir_sales_register',20,'Output VAT / Sales Register','report','BIR Reports',1740,1,NULL),
(175,'rpt.bir_purchase_register',20,'Input VAT / Purchases Register','report','BIR Reports',1750,1,NULL),
(176,'rpt.bir_cvat_withheld',20,'Creditable VAT Withheld Schedule (2550Q Schedule 3)','report','BIR Reports',1760,1,NULL),
(177,'rpt.bir_2550q',20,'Quarterly VAT Return Worksheet (2550Q)','report','BIR Reports',1770,1,NULL),
(178,'admin.users',23,'User Accounts','admin','Setup',1780,1,NULL),
(179,'admin.roles',23,'User Role Configuration','admin','Setup',1790,1,NULL),
(180,'admin.page_access',23,'Page Access Configuration','admin','Setup',1800,1,NULL),
(181,'admin.effective_access',23,'Effective Access (View as User)','admin','Setup',1810,1,NULL),
(182,'admin.settings',23,'Company, Security & Closing Date','admin','Setup',1820,1,NULL),
(183,'admin.utilities',23,'Utilities: Verify & Rebuild Data','admin','Setup',1830,1,NULL),
(184,'admin.import',23,'Import from CSV','admin','Utilities',1840,1,NULL),
(185,'admin.backup',23,'Backup & Restore','admin','Utilities',1850,1,NULL),
(186,'admin.multi_company',23,'Multi-Company Setup','admin','Setup',1860,1,NULL),
(187,'admin.audit_trail',23,'Audit Trail','report','Audit Reports',1870,1,NULL),
(188,'admin.txn_history',23,'Transaction History','report','Audit Reports',1880,1,NULL),
(189,'rpt.voided_deleted_summary',23,'Voided and Deleted Transactions Summary','report','Audit Reports',1890,1,NULL),
(190,'rpt.voided_deleted_detail',23,'Voided and Deleted Transactions Detail','report','Audit Reports',1900,1,NULL),
(191,'rpt.user_activity',23,'User Activity','report','Audit Reports',1910,1,NULL),
(192,'rpt.login_history',23,'Login History','report','Audit Reports',1920,1,NULL),
(193,'rpt.user_access',23,'User Access and Permission Changes','report','Audit Reports',1930,1,NULL),
(194,'company.profile',13,'Company Profile & Code','admin','Company',1940,1,NULL),
(195,'company.users',13,'Employees & Users','admin','Company',1950,1,NULL),
(196,'company.join_requests',13,'Join Requests','admin','Company',1960,1,NULL),
(197,'coop.members',8,'Member Center','center','Transactions',1970,1,NULL),
(198,'coop.member',8,'Member Information','transaction','Transactions',1980,0,NULL),
(199,'coop.member_statement',8,'Member Statement of Account','transaction','Transactions',1990,1,NULL),
(200,'coop.vouchers',8,'Cooperative Vouchers','transaction','Transactions',2000,0,NULL);
INSERT INTO `pages` (`id`,`page_key`,`module_id`,`name`,`page_type`,`menu_group`,`sort_order`,`show_in_menu`,`description`) VALUES
(201,'coop.import',8,'Opening Balances Import','transaction','Transactions',2010,1,NULL),
(202,'coop.profile',8,'Cooperative Profile & Policies','setup','Setup',2020,1,NULL),
(203,'coop.branches',8,'Branches','setup','Setup',2030,1,NULL),
(204,'coop.lists',8,'Member Types, Clusters & Other Lists','setup','Setup',2040,1,NULL),
(205,'coop.passbooks',8,'Passbook Types','setup','Setup',2050,1,NULL),
(206,'coop.numbering',8,'Document Numbering','setup','Setup',2060,1,NULL),
(207,'coop.templates',8,'Document Templates','setup','Setup',2070,1,NULL),
(208,'rpt.coop_members',8,'Member Master List','report','Reports',2080,1,NULL),
(209,'rpt.coop_member_movement',8,'Membership Movement','report','Reports',2090,1,NULL),
(210,'rpt.coop_share_capital',8,'Share Capital Balances','report','Reports',2100,1,NULL),
(211,'rpt.coop_cbu',8,'Capital Build-up','report','Reports',2110,1,NULL),
(212,'rpt.coop_sfc',8,'Statement of Financial Condition','report','Reports',2120,1,NULL),
(213,'rpt.coop_sop',8,'Statement of Operations','report','Reports',2130,1,NULL),
(214,'rpt.coop_sce',8,'Statement of Changes in Equity','report','Reports',2140,1,NULL),
(215,'rpt.coop_statutory_funds',8,'Statutory Funds','report','Reports',2150,1,NULL),
(216,'rpt.coop_sl_gl',8,'Subsidiary vs General Ledger Reconciliation','report','Reports',2160,1,NULL),
(217,'rpt.coop_document_prints',8,'Document Print History','report','Reports',2170,1,NULL),
(218,'coop.business_units',8,'Lines of Business','setup','Setup',2180,1,NULL),
(219,'rpt.sop_business_unit',8,'Statement of Operations by Line of Business','report','Reports',2190,1,NULL),
(220,'party.center',19,'Party Center','center','Transactions',2200,1,NULL),
(221,'party.review',19,'Identity Review','transaction','Transactions',2210,1,NULL),
(222,'party.statement',19,'Consolidated Party Statement','report','Reports',2220,1,NULL),
(223,'rpt.party_list',19,'Party List','report','Reports',2230,1,NULL),
(224,'rpt.party_role_history',19,'Party Role History','report','Reports',2240,1,NULL),
(225,'rpt.doc_reissues',19,'Reissued Documents','report','Reports',2250,1,NULL),
(226,'sav.accounts',10,'Savings Account Center','center','Transactions',2260,1,NULL),
(227,'sav.transact',10,'Savings Account Transaction','transaction','Transactions',2270,1,NULL),
(228,'sav.transfer',10,'Transfer Between Accounts','transaction','Transactions',2280,1,NULL),
(229,'sav.interest',10,'Post Savings Interest','transaction','Transactions',2290,1,NULL),
(230,'sav.passbook',10,'Passbook Printing','transaction','Transactions',2300,1,NULL),
(231,'sav.td',10,'Time Deposit Center','transaction','Transactions',2310,1,NULL),
(232,'sav.batch',10,'Batch Deductions (Payroll / CBU)','transaction','Transactions',2320,1,NULL),
(233,'sav.clearing',10,'Check Clearing','transaction','Transactions',2330,1,NULL),
(234,'sav.products',10,'Savings & Deposit Products','setup','Setup',2340,1,NULL),
(235,'sav.txn_codes',10,'Savings Transaction Codes','setup','Setup',2350,1,NULL),
(236,'rpt.sav_list',10,'Savings List','report','Reports',2360,1,NULL),
(237,'rpt.sav_journal',10,'Savings Journal','report','Reports',2370,1,NULL),
(238,'rpt.sav_balances',10,'Savings Account Balances','report','Reports',2380,1,NULL),
(239,'rpt.sav_schedule',10,'Schedule of Savings Accounts','report','Reports',2390,1,NULL),
(240,'rpt.sav_dormant',10,'Dormant Accounts','report','Reports',2400,1,NULL),
(241,'rpt.sav_interest',10,'Interest Posting Register','report','Reports',2410,1,NULL),
(242,'rpt.td_list',10,'Time Deposits by Product','report','Reports',2420,1,NULL),
(243,'rpt.td_maturity',10,'Time Deposit Maturity Schedule','report','Reports',2430,1,NULL),
(244,'rpt.td_accrued',10,'Time Deposit Accrued Interest','report','Reports',2440,1,NULL),
(245,'rpt.sav_statement',10,'Savings Account Statement','report','Reports',2450,0,NULL),
(246,'loan.applications',11,'Loan Application Center','center','Transactions',2460,1,NULL),
(247,'loan.application',11,'Loan Application Form','transaction','Transactions',2470,0,NULL),
(248,'loan.ci',11,'Credit Investigation','transaction','Transactions',2480,0,NULL),
(249,'loan.release',11,'Loan Release','transaction','Transactions',2490,1,NULL),
(250,'loan.vouchers',11,'Loan Release Vouchers','center','Transactions',2500,1,NULL),
(251,'loan.accounts',11,'Loan Accounts Center','center','Transactions',2510,1,NULL),
(252,'loan.adjustments',11,'Loan Adjustments','transaction','Transactions',2520,1,NULL),
(253,'loan.waivers',11,'Interest / Penalty Waiving','transaction','Transactions',2530,1,NULL),
(254,'loan.restructure',11,'Restructure / Renew / Write Off','transaction','Transactions',2540,1,NULL),
(255,'loan.docs',11,'Loan Documents','transaction','Transactions',2550,0,NULL),
(256,'loan.penalties',11,'Post Loan Penalties & Interest Accrual','transaction','Transactions',2560,1,NULL),
(257,'loan.products',11,'Loan Products','setup','Setup',2570,1,NULL),
(258,'loan.charges',11,'Loan Charges','setup','Setup',2580,1,NULL),
(259,'loan.approval_matrix',11,'Loan Approval Matrix','setup','Setup',2590,1,NULL),
(260,'rpt.loan_products',11,'Loan Product List','report','Reports',2600,1,NULL),
(261,'rpt.loan_schedule_recv',11,'Schedule of Loans Receivable','report','Reports',2610,1,NULL),
(262,'rpt.loan_releases',11,'Loan Releases','report','Loans Summary',2620,1,NULL),
(263,'rpt.loan_due_vs_coll',11,'Loan Due vs. Collection','report','Loans Summary',2630,1,NULL),
(264,'rpt.loan_due',11,'Loans Due for Collection','report','Loans Summary',2640,1,NULL),
(265,'rpt.loan_collections',11,'Loan Collection List','report','Loans Summary',2650,1,NULL),
(266,'rpt.loan_no_payments',11,'Loans With No Payments','report','Loans Summary',2660,1,NULL),
(267,'rpt.loan_soa',11,'Loan Statement of Account','report','Loans Summary',2670,1,NULL),
(268,'rpt.loan_aging_maturity',11,'Aging of Loans by Maturity','report','Loans Aging',2680,1,NULL),
(269,'rpt.loan_aging_par',11,'Aging of Loans at PAR','report','Loans Aging',2690,1,NULL),
(270,'rpt.loan_aging_amort',11,'Aging of Loans by Amortization','report','Loans Aging',2700,1,NULL),
(271,'rpt.loan_portfolio',11,'Loan Portfolio Analysis','report','Loans Aging',2710,1,NULL),
(272,'rpt.loan_comaker',11,'Co-maker / Guarantor Exposure','report','Reports',2720,1,NULL),
(273,'rpt.loan_collateral',11,'Collateral Register','report','Reports',2730,1,NULL),
(274,'rpt.loan_gl_compare',11,'Schedule of Loans vs GL','report','Reports',2740,1,NULL),
(275,'rpt.loan_waivers',11,'Waivers and Adjustments','report','Reports',2750,1,NULL),
(276,'rpt.loan_writeoffs',11,'Written-off Loans and Recoveries','report','Reports',2760,1,NULL),
(277,'teller.shift',9,'Teller Shift & Cash Count','transaction','Transactions',2770,1,NULL),
(278,'sav.center',9,'Savings Transaction Center','center','Transactions',2772,1,NULL),
(279,'loan.collection',9,'Loan Collection','transaction','Transactions',2774,1,NULL),
(280,'teller.or',9,'Official Receipt (Member Collection)','transaction','Transactions',2800,1,NULL),
(281,'teller.disburse',9,'Member Disbursement (Cash Voucher)','transaction','Transactions',2810,0,NULL),
(282,'teller.receipts',9,'Official Receipt List','center','Transactions',2820,1,NULL),
(283,'rpt.teller_journal',9,'Teller Transaction Journal','report','Reports',2830,1,NULL),
(284,'rpt.teller_daily',9,'Daily Collection & Disbursement Summary','report','Reports',2840,1,NULL),
(285,'rpt.teller_over_short',9,'Teller Over / Short','report','Reports',2850,1,NULL),
(286,'rpt.teller_or_list',9,'Official Receipt Register','report','Reports',2860,1,NULL),
(287,'rpt.teller_cash_position',9,'Cash Position','report','Reports',2870,1,NULL),
(288,'dist.surplus',21,'Net Surplus Allocation','transaction','Transactions',2880,1,NULL),
(289,'dist.isc',21,'Interest on Share Capital','transaction','Transactions',2890,1,NULL),
(290,'dist.pr',21,'Patronage Refund','transaction','Transactions',2900,1,NULL),
(291,'dist.batches',21,'Distribution Batches','center','Transactions',2910,1,NULL),
(292,'rpt.dist_register',21,'Distribution Register','report','Reports',2920,1,NULL),
(293,'rpt.dist_unclaimed',21,'Unclaimed Distributions','report','Reports',2930,1,NULL),
(294,'rpt.dist_summary',21,'Net Surplus Allocation Summary','report','Reports',2940,1,NULL),
(295,'cda.officers',22,'Officers, Committees & Trainings','setup','Setup',2950,1,NULL),
(296,'cda.compliance',22,'Compliance Tracking','transaction','Transactions',2960,1,NULL),
(297,'cda.calendar',22,'Regulatory Calendar','transaction','Transactions',2970,1,NULL),
(298,'cda.capr',22,'CAPR Data Preparation & Export','transaction','Transactions',2980,1,NULL),
(299,'cda.mappings',22,'Report Field Mappings','setup','Setup',2990,1,NULL),
(300,'rpt.cda_officers',22,'List of Officers and Committees','report','Reports',3000,1,NULL);
INSERT INTO `pages` (`id`,`page_key`,`module_id`,`name`,`page_type`,`menu_group`,`sort_order`,`show_in_menu`,`description`) VALUES
(301,'rpt.cda_compliance',22,'Compliance Status','report','Reports',3010,1,NULL),
(302,'isp.dashboard',2,'ISP Operations Dashboard','center','Dashboard',3020,1,NULL),
(303,'isp.subscribers',2,'Subscriber Center','center','Subscribers',3030,1,NULL),
(304,'isp.account',2,'Subscriber Account','transaction','Subscribers',3040,0,NULL),
(305,'isp.applications',2,'Applications & Activation','transaction','Subscribers',3050,1,NULL),
(306,'isp.contracts',2,'Contracts & Renewals','transaction','Subscribers',3060,1,NULL),
(307,'isp.requests',2,'Service Requests','transaction','Subscribers',3070,1,NULL),
(308,'isp.agents',2,'Sales Agents & Commissions','transaction','Subscribers',3080,1,NULL),
(309,'rpt.isp_master',2,'Subscriber Master List','report','Subscriber Reports',3090,1,NULL),
(310,'rpt.isp_active',2,'Active Accounts','report','Subscriber Reports',3100,1,NULL),
(311,'rpt.isp_new_activations',2,'Newly Activated Accounts','report','Subscriber Reports',3110,1,NULL),
(312,'rpt.isp_cancelled',2,'Cancelled Subscriptions','report','Subscriber Reports',3120,1,NULL),
(313,'rpt.isp_growth',2,'Subscriber Growth','report','Subscriber Reports',3130,1,NULL),
(314,'rpt.isp_plan_dist',2,'Plan Distribution','report','Subscriber Reports',3140,1,NULL),
(315,'rpt.isp_by_barangay',2,'Subscribers per Barangay','report','Subscriber Reports',3150,1,NULL),
(316,'rpt.isp_contracts_due',2,'Contracts Due for Renewal','report','Subscriber Reports',3160,1,NULL),
(317,'rpt.isp_agent_perf',2,'Agent Performance & Commissions','report','Subscriber Reports',3170,1,NULL),
(318,'rpt.isp_notif_log',2,'Customer Notification History','report','Subscriber Reports',3180,1,NULL),
(319,'ispbill.collect',3,'Collect Payment','transaction','Collections',3190,1,NULL),
(320,'ispbill.payments',3,'Payments & Verification','transaction','Collections',3200,1,NULL),
(321,'ispbill.shifts',3,'Cashier Shifts & Cash Count','transaction','Collections',3210,1,NULL),
(322,'ispbill.runs',3,'Billing Runs','transaction','Billing',3220,1,NULL),
(323,'ispbill.invoices',3,'Invoices','transaction','Billing',3230,1,NULL),
(324,'ispbill.adjustments',3,'Adjustments & Write-offs','transaction','Billing',3240,1,NULL),
(325,'ispbill.disputes',3,'Disputes, Arrangements & Exemptions','transaction','Billing',3250,1,NULL),
(326,'ispbill.soa',3,'Statement of Account','transaction','Billing',3260,1,NULL),
(327,'rpt.isp_billing_register',3,'Monthly Billing Register','report','Billing Reports',3270,1,NULL),
(328,'rpt.isp_invoice_register',3,'Invoice Register','report','Billing Reports',3280,1,NULL),
(329,'rpt.isp_billing_summary',3,'Billing Summary','report','Billing Reports',3290,1,NULL),
(330,'rpt.isp_unpaid',3,'Unpaid Invoices','report','Billing Reports',3300,1,NULL),
(331,'rpt.isp_adjustments',3,'Billing Adjustments','report','Billing Reports',3310,1,NULL),
(332,'rpt.isp_late_charges',3,'Late Charges','report','Billing Reports',3320,1,NULL),
(333,'rpt.isp_daily_collection',3,'Daily Collection','report','Collection Reports',3330,1,NULL),
(334,'rpt.isp_cashier_collection',3,'Collection by Cashier','report','Collection Reports',3340,1,NULL),
(335,'rpt.isp_by_method',3,'Collection by Payment Method','report','Collection Reports',3350,1,NULL),
(336,'rpt.isp_efficiency',3,'Collection Efficiency','report','Collection Reports',3360,1,NULL),
(337,'rpt.isp_aging',3,'Subscriber Receivables Aging','report','Collection Reports',3370,1,NULL),
(338,'rpt.isp_unapplied',3,'Unapplied Payments & Credits','report','Collection Reports',3380,1,NULL),
(339,'rpt.isp_delinquency',3,'Delinquency Report','report','Collection Reports',3390,1,NULL),
(340,'rpt.isp_plan_profit',3,'Plan Revenue & Profitability','report','Financial Reports',3400,1,NULL),
(341,'rpt.isp_branch_profit',3,'Branch Profitability','report','Financial Reports',3410,1,NULL),
(342,'rpt.isp_arpu',3,'Revenue per Subscriber','report','Financial Reports',3420,1,NULL),
(343,'rpt.isp_reconcile',3,'Subscriber Ledger vs General Ledger','report','Financial Reports',3430,1,NULL),
(344,'ispnet.disconnections',4,'Disconnection Queue','transaction','Service Control',3440,1,NULL),
(345,'ispnet.reconnections',4,'Reconnections','transaction','Service Control',3450,1,NULL),
(346,'ispnet.recovery',4,'Disconnected Accounts & Recovery','transaction','Service Control',3460,1,NULL),
(347,'ispnet.operations',4,'Network Operations','center','Provisioning',3470,1,NULL),
(348,'ispnet.devices',4,'Network Devices & Integrations','setup','Provisioning',3480,1,NULL),
(349,'ispnet.infra',4,'Infrastructure & Ports','list','Infrastructure',3490,1,NULL),
(350,'ispnet.map',4,'Network Map','center','Infrastructure',3500,1,NULL),
(351,'ispnet.outages',4,'Network Outages','transaction','Infrastructure',3510,1,NULL),
(352,'rpt.isp_due_disconnect',4,'Accounts Due for Disconnection','report','Disconnection Reports',3520,1,NULL),
(353,'rpt.isp_disconnections',4,'Actual Disconnections','report','Disconnection Reports',3530,1,NULL),
(354,'rpt.isp_failed_ops',4,'Failed Network Operations','report','Disconnection Reports',3540,1,NULL),
(355,'rpt.isp_disc_reasons',4,'Disconnection Reasons','report','Disconnection Reports',3550,1,NULL),
(356,'rpt.isp_recon_history',4,'Reconnection History','report','Disconnection Reports',3560,1,NULL),
(357,'rpt.isp_disc_outstanding',4,'Outstanding Disconnected Accounts','report','Disconnection Reports',3570,1,NULL),
(358,'rpt.isp_outage_history',4,'Outage History & SLA','report','Technical Reports',3580,1,NULL),
(359,'rpt.isp_capacity',4,'Network Capacity Utilization','report','Technical Reports',3590,1,NULL),
(360,'rpt.isp_net_commands',4,'Network Command Log','report','Technical Reports',3600,1,NULL),
(361,'ispfield.mytasks',5,'My Tasks Today','center','Field',3610,1,NULL),
(362,'ispfield.workorders',5,'Work Orders & Dispatch','transaction','Field',3620,1,NULL),
(363,'ispfield.tickets',5,'Support Tickets','transaction','Support',3630,1,NULL),
(364,'ispfield.technicians',5,'Technicians & Teams','list','Field',3640,1,NULL),
(365,'ispfield.equipment',5,'Equipment Register','list','Field',3650,1,NULL),
(366,'rpt.isp_install_progress',5,'Installation Progress','report','Technical Reports',3660,1,NULL),
(367,'rpt.isp_tech_productivity',5,'Technician Productivity','report','Technical Reports',3670,1,NULL),
(368,'rpt.isp_open_wo',5,'Open Work Orders','report','Technical Reports',3680,1,NULL),
(369,'rpt.isp_ticket_times',5,'Ticket Resolution Times','report','Technical Reports',3690,1,NULL),
(370,'rpt.isp_equipment_failures',5,'Equipment Failures & Replacements','report','Technical Reports',3700,1,NULL),
(371,'rpt.isp_install_profit',5,'Installation Profitability','report','Technical Reports',3710,1,NULL),
(372,'rpt.isp_materials',5,'Materials Issued & Returned','report','Technical Reports',3720,1,NULL),
(373,'ispset.plans',6,'Internet Plans & Packages','setup','Setup',3730,1,NULL),
(374,'ispset.areas',6,'Service Areas','setup','Setup',3740,1,NULL),
(375,'ispset.policies',6,'Billing & Disconnection Policies','setup','Setup',3750,1,NULL),
(376,'ispset.notifications',6,'Notifications (SMS / E-mail)','setup','Setup',3760,1,NULL),
(377,'ispset.gateways',6,'Payment Channels & Gateways','setup','Setup',3770,1,NULL),
(378,'ispset.templates',6,'Templates & Announcements','setup','Setup',3780,1,NULL),
(379,'ispset.jobs',6,'Scheduled Jobs Monitor','admin','Setup',3790,1,NULL);

-- role_page_access (1590 rows)
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(2,1,1,0,0,0,1,0),
(2,2,1,0,0,0,1,0),
(2,3,1,0,0,0,1,0),
(2,4,1,0,0,0,1,0),
(2,5,1,0,0,0,1,0),
(2,6,1,0,0,0,1,0),
(2,7,1,0,0,0,1,0),
(2,8,1,1,1,1,1,0),
(2,9,1,1,1,1,1,0),
(2,10,1,1,1,1,1,0),
(2,11,1,1,1,1,1,0),
(2,12,1,1,1,1,1,0),
(2,13,1,1,1,1,1,0),
(2,14,1,1,1,1,1,0),
(2,15,1,1,1,1,1,0),
(2,16,1,1,1,1,1,0),
(2,17,1,1,1,1,1,0),
(2,18,1,1,1,1,1,0),
(2,19,1,1,1,1,1,0),
(2,20,1,1,1,1,1,0),
(2,21,1,1,1,1,1,0),
(2,22,1,1,1,1,1,0),
(2,23,1,1,1,1,1,0),
(2,24,1,1,1,1,1,0),
(2,25,1,1,1,1,1,0),
(2,26,1,1,1,1,1,0),
(2,27,1,1,1,1,1,0),
(2,28,1,1,1,1,1,0),
(2,29,1,1,1,1,1,0),
(2,30,1,1,1,1,1,0),
(2,31,1,1,1,1,1,0),
(2,32,1,1,1,1,1,0),
(2,33,1,1,1,1,1,0),
(2,34,1,1,1,1,1,0),
(2,35,1,1,1,1,1,0),
(2,36,1,1,1,1,1,0),
(2,37,1,1,1,1,1,0),
(2,38,1,1,1,1,1,0),
(2,39,1,1,1,1,1,0),
(2,40,1,1,1,1,1,0),
(2,41,1,1,1,1,1,0),
(2,42,1,1,1,1,1,0),
(2,43,1,1,1,1,1,0),
(2,44,1,1,1,1,1,0),
(2,45,1,1,1,1,1,0),
(2,46,1,1,1,1,1,0),
(2,47,1,1,1,1,1,0),
(2,48,1,1,1,1,1,0),
(2,49,1,1,1,1,1,0),
(2,50,1,1,1,1,1,0),
(2,51,1,1,1,1,1,0),
(2,52,1,0,0,0,1,0),
(2,55,1,0,0,0,1,0),
(2,62,1,0,0,0,1,0),
(2,64,1,0,0,0,1,0),
(2,65,1,0,0,0,1,0),
(2,66,1,0,0,0,1,0),
(2,67,1,0,0,0,1,0),
(2,68,1,0,0,0,1,0),
(2,69,1,0,0,0,1,0),
(2,70,1,0,0,0,1,0),
(2,71,1,0,0,0,1,0),
(2,72,1,0,0,0,1,0),
(2,73,1,0,0,0,1,0),
(2,74,1,0,0,0,1,0),
(2,75,1,0,0,0,1,0),
(2,76,1,0,0,0,1,0),
(2,77,1,0,0,0,1,0),
(2,78,1,0,0,0,1,0),
(2,79,1,0,0,0,1,0),
(2,80,1,0,0,0,1,0),
(2,81,1,0,0,0,1,0),
(2,82,1,0,0,0,1,0),
(2,83,1,0,0,0,1,0),
(2,84,1,0,0,0,1,0),
(2,85,1,0,0,0,1,0),
(2,86,1,0,0,0,1,0),
(2,87,1,0,0,0,1,0),
(2,88,1,0,0,0,1,0),
(2,89,1,0,0,0,1,0),
(2,90,1,0,0,0,1,0),
(2,91,1,0,0,0,1,0),
(2,92,1,0,0,0,1,0),
(2,94,1,0,0,0,1,0),
(2,100,1,0,0,0,1,0),
(2,101,1,0,0,0,1,0),
(2,102,1,0,0,0,1,0),
(2,103,1,0,0,0,1,0),
(2,104,1,0,0,0,1,0),
(2,105,1,0,0,0,1,0),
(2,106,1,0,0,0,1,0),
(2,107,1,0,0,0,1,0),
(2,108,1,0,0,0,1,0),
(2,109,1,0,0,0,1,0),
(2,110,1,0,0,0,1,0),
(2,111,1,0,0,0,1,0),
(2,112,1,0,0,0,1,0),
(2,113,1,0,0,0,1,0),
(2,115,1,0,0,0,1,0),
(2,116,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(2,118,1,0,0,0,1,0),
(2,120,1,0,0,0,1,0),
(2,124,1,0,0,0,1,0),
(2,125,1,0,0,0,1,0),
(2,126,1,0,0,0,1,0),
(2,127,1,0,0,0,1,0),
(2,128,1,0,0,0,1,0),
(2,129,1,0,0,0,1,0),
(2,130,1,0,0,0,1,0),
(2,131,1,0,0,0,1,0),
(2,132,1,0,0,0,1,0),
(2,133,1,0,0,0,1,0),
(2,134,1,0,0,0,1,0),
(2,135,1,0,0,0,1,0),
(2,136,1,0,0,0,1,0),
(2,137,1,0,0,0,1,0),
(2,138,1,0,0,0,1,0),
(2,139,1,0,0,0,1,0),
(2,140,1,0,0,0,1,0),
(2,141,1,0,0,0,1,0),
(2,142,1,0,0,0,1,0),
(2,148,1,0,0,0,1,0),
(2,149,1,0,0,0,1,0),
(2,150,1,0,0,0,1,0),
(2,151,1,0,0,0,1,0),
(2,152,1,0,0,0,1,0),
(2,153,1,0,0,0,1,0),
(2,154,1,0,0,0,1,0),
(2,155,1,0,0,0,1,0),
(2,156,1,0,0,0,1,0),
(2,157,1,0,0,0,1,0),
(2,158,1,1,1,1,1,0),
(2,159,1,1,1,1,1,0),
(2,160,1,1,1,1,1,0),
(2,161,1,1,1,1,1,0),
(2,162,1,1,1,1,1,0),
(2,163,1,1,1,1,1,0),
(2,164,1,1,1,1,1,0),
(2,165,1,1,1,1,1,0),
(2,166,1,1,1,1,1,0),
(2,167,1,1,1,1,1,0),
(2,168,1,1,1,1,1,0),
(2,169,1,1,1,1,1,0),
(2,170,1,1,1,1,1,0),
(2,171,1,1,1,1,1,0),
(2,172,1,1,1,1,1,0),
(2,173,1,1,1,1,1,0),
(2,174,1,1,1,1,1,0),
(2,175,1,1,1,1,1,0),
(2,176,1,1,1,1,1,0),
(2,177,1,1,1,1,1,0),
(2,183,1,0,0,0,1,0),
(2,197,1,0,0,0,1,0),
(2,200,1,1,0,0,1,0),
(2,201,1,1,0,0,1,0),
(2,208,1,0,0,0,1,0),
(2,209,1,0,0,0,1,0),
(2,210,1,0,0,0,1,0),
(2,211,1,0,0,0,1,0),
(2,212,1,0,0,0,1,0),
(2,213,1,0,0,0,1,0),
(2,214,1,0,0,0,1,0),
(2,215,1,0,0,0,1,0),
(2,216,1,0,0,0,1,0),
(2,217,1,0,0,0,1,0),
(2,219,1,0,0,0,1,0),
(2,220,1,1,1,0,1,0),
(2,221,1,0,0,0,1,0),
(2,222,1,0,0,0,1,0),
(2,223,1,0,0,0,1,0),
(2,224,1,0,0,0,1,0),
(2,225,1,0,0,0,1,0),
(2,226,1,0,0,0,1,0),
(2,229,1,1,0,0,1,0),
(2,236,1,0,0,0,1,0),
(2,237,1,0,0,0,1,0),
(2,238,1,0,0,0,1,0),
(2,239,1,0,0,0,1,0),
(2,240,1,0,0,0,1,0),
(2,241,1,0,0,0,1,0),
(2,242,1,0,0,0,1,0),
(2,243,1,0,0,0,1,0),
(2,244,1,0,0,0,1,0),
(2,245,1,0,0,0,1,0),
(2,251,1,0,0,0,1,0),
(2,256,1,1,0,0,1,0),
(2,260,1,0,0,0,1,0),
(2,261,1,0,0,0,1,0),
(2,262,1,0,0,0,1,0),
(2,263,1,0,0,0,1,0),
(2,264,1,0,0,0,1,0),
(2,265,1,0,0,0,1,0),
(2,266,1,0,0,0,1,0),
(2,267,1,0,0,0,1,0),
(2,268,1,0,0,0,1,0),
(2,269,1,0,0,0,1,0),
(2,270,1,0,0,0,1,0),
(2,271,1,0,0,0,1,0),
(2,272,1,0,0,0,1,0),
(2,273,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(2,274,1,0,0,0,1,0),
(2,275,1,0,0,0,1,0),
(2,276,1,0,0,0,1,0),
(2,282,1,0,0,0,1,0),
(2,283,1,0,0,0,1,0),
(2,284,1,0,0,0,1,0),
(2,285,1,0,0,0,1,0),
(2,286,1,0,0,0,1,0),
(2,287,1,0,0,0,1,0),
(2,288,1,1,0,0,1,0),
(2,289,1,1,0,0,1,0),
(2,290,1,1,0,0,1,0),
(2,291,1,1,0,0,1,0),
(2,292,1,0,0,0,1,0),
(2,293,1,0,0,0,1,0),
(2,294,1,0,0,0,1,0),
(2,300,1,0,0,0,1,0),
(2,301,1,0,0,0,1,0),
(2,302,1,0,0,0,1,0),
(2,303,1,0,0,0,1,0),
(2,304,1,0,0,0,1,0),
(2,308,1,0,0,0,1,1),
(2,309,1,0,0,0,1,0),
(2,310,1,0,0,0,1,0),
(2,311,1,0,0,0,1,0),
(2,312,1,0,0,0,1,0),
(2,313,1,0,0,0,1,0),
(2,314,1,0,0,0,1,0),
(2,315,1,0,0,0,1,0),
(2,316,1,0,0,0,1,0),
(2,317,1,0,0,0,1,0),
(2,318,1,0,0,0,1,0),
(2,320,1,0,0,0,1,1),
(2,321,1,0,0,0,1,1),
(2,322,1,0,0,0,1,1),
(2,323,1,0,0,0,1,0),
(2,324,1,0,1,0,1,1),
(2,325,1,0,0,0,1,0),
(2,326,1,0,0,0,1,0),
(2,327,1,0,0,0,1,0),
(2,328,1,0,0,0,1,0),
(2,329,1,0,0,0,1,0),
(2,330,1,0,0,0,1,0),
(2,331,1,0,0,0,1,0),
(2,332,1,0,0,0,1,0),
(2,333,1,0,0,0,1,0),
(2,334,1,0,0,0,1,0),
(2,335,1,0,0,0,1,0),
(2,336,1,0,0,0,1,0),
(2,337,1,0,0,0,1,0),
(2,338,1,0,0,0,1,0),
(2,339,1,0,0,0,1,0),
(2,340,1,0,0,0,1,0),
(2,341,1,0,0,0,1,0),
(2,342,1,0,0,0,1,0),
(2,343,1,0,0,0,1,0),
(2,352,1,0,0,0,1,0),
(2,353,1,0,0,0,1,0),
(2,354,1,0,0,0,1,0),
(2,355,1,0,0,0,1,0),
(2,356,1,0,0,0,1,0),
(2,357,1,0,0,0,1,0),
(2,358,1,0,0,0,1,0),
(2,359,1,0,0,0,1,0),
(2,360,1,0,0,0,1,0),
(2,366,1,0,0,0,1,0),
(2,367,1,0,0,0,1,0),
(2,368,1,0,0,0,1,0),
(2,369,1,0,0,0,1,0),
(2,370,1,0,0,0,1,0),
(2,371,1,0,0,0,1,0),
(2,372,1,0,0,0,1,0),
(2,375,1,0,0,0,1,0),
(2,377,1,0,0,0,1,0),
(3,1,1,0,0,0,1,0),
(3,2,1,0,0,0,1,0),
(3,3,1,0,0,0,1,0),
(3,4,1,0,0,0,1,0),
(3,5,1,0,0,0,1,0),
(3,6,1,0,0,0,1,0),
(3,7,1,0,0,0,1,0),
(3,52,1,1,1,0,1,0),
(3,53,1,1,1,0,1,0),
(3,54,1,1,1,0,1,0),
(3,55,1,1,1,0,1,0),
(3,56,1,1,1,0,1,0),
(3,57,1,1,1,0,1,0),
(3,58,1,1,1,0,1,0),
(3,59,1,1,1,0,1,0),
(3,60,1,1,1,0,1,0),
(3,61,1,1,1,0,1,0),
(3,62,1,1,1,0,1,0),
(3,63,1,1,1,0,1,0),
(3,64,1,0,0,0,1,0),
(3,65,1,0,0,0,1,0),
(3,66,1,0,0,0,1,0),
(3,67,1,0,0,0,1,0),
(3,68,1,0,0,0,1,0),
(3,69,1,0,0,0,1,0),
(3,70,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(3,71,1,0,0,0,1,0),
(3,72,1,0,0,0,1,0),
(3,73,1,0,0,0,1,0),
(3,74,1,0,0,0,1,0),
(3,75,1,0,0,0,1,0),
(3,112,1,0,0,0,0,0),
(3,220,1,1,1,0,1,0),
(3,222,1,0,0,0,1,0),
(3,223,1,0,0,0,1,0),
(4,1,1,0,0,0,1,0),
(4,2,1,0,0,0,1,0),
(4,3,1,0,0,0,1,0),
(4,4,1,0,0,0,1,0),
(4,5,1,0,0,0,1,0),
(4,6,1,0,0,0,1,0),
(4,7,1,0,0,0,1,0),
(4,90,1,1,1,0,1,0),
(4,91,1,1,1,0,1,0),
(4,92,1,1,1,0,1,0),
(4,93,1,1,1,0,1,0),
(4,94,1,1,1,0,1,0),
(4,95,1,1,1,0,1,0),
(4,96,1,1,1,0,1,0),
(4,97,1,1,1,0,1,0),
(4,98,1,1,1,0,1,0),
(4,99,1,1,1,0,1,0),
(4,100,1,1,1,0,1,0),
(4,101,1,0,0,0,1,0),
(4,102,1,0,0,0,1,0),
(4,103,1,0,0,0,1,0),
(4,104,1,0,0,0,1,0),
(4,105,1,0,0,0,1,0),
(4,106,1,0,0,0,1,0),
(4,107,1,0,0,0,1,0),
(4,108,1,0,0,0,1,0),
(4,109,1,0,0,0,1,0),
(4,110,1,0,0,0,1,0),
(4,112,1,0,0,0,0,0),
(4,168,1,0,0,0,1,0),
(4,169,1,0,0,0,0,0),
(4,170,1,0,0,0,1,0),
(4,171,1,0,0,0,1,0),
(4,172,1,0,0,0,1,0),
(4,173,1,0,0,0,1,0),
(4,220,1,1,1,0,1,0),
(4,222,1,0,0,0,1,0),
(4,223,1,0,0,0,1,0),
(5,1,1,0,0,0,1,0),
(5,2,1,0,0,0,1,0),
(5,3,1,0,0,0,1,0),
(5,4,1,0,0,0,1,0),
(5,5,1,0,0,0,1,0),
(5,6,1,0,0,0,1,0),
(5,7,1,0,0,0,1,0),
(5,96,1,0,0,0,1,0),
(5,98,1,1,1,0,1,0),
(5,111,1,0,0,0,1,0),
(5,112,1,1,1,0,1,0),
(5,113,1,1,1,1,1,0),
(5,114,1,1,1,0,1,0),
(5,115,1,1,1,0,1,0),
(5,116,1,1,1,0,1,0),
(5,117,1,1,1,0,1,0),
(5,118,1,1,1,0,1,0),
(5,119,1,1,1,0,1,0),
(5,120,1,1,1,0,1,0),
(5,121,1,0,0,0,1,0),
(5,124,1,0,0,0,1,0),
(5,128,1,0,0,0,1,0),
(5,129,1,0,0,0,1,0),
(5,130,1,0,0,0,1,0),
(5,131,1,0,0,0,1,0),
(5,132,1,0,0,0,1,0),
(5,133,1,0,0,0,1,0),
(5,134,1,0,0,0,1,0),
(5,136,1,0,0,0,1,0),
(5,137,1,0,0,0,1,0),
(5,138,1,0,0,0,1,0),
(5,140,1,0,0,0,1,0),
(5,141,1,0,0,0,1,0),
(5,142,1,0,0,0,1,0),
(6,1,1,0,0,0,1,0),
(6,2,1,0,0,0,1,0),
(6,3,1,0,0,0,1,0),
(6,4,1,0,0,0,1,0),
(6,5,1,0,0,0,1,0),
(6,6,1,0,0,0,1,0),
(6,7,1,0,0,0,1,0),
(6,52,1,0,0,0,1,0),
(6,53,1,1,1,0,1,0),
(6,54,1,1,1,0,1,0),
(6,55,1,1,1,0,1,0),
(6,75,1,0,0,0,1,0),
(6,76,1,0,0,0,1,0),
(6,77,1,0,0,0,1,0),
(6,78,1,0,0,0,1,0),
(6,79,1,0,0,0,1,0),
(6,80,1,0,0,0,1,0),
(6,81,1,0,0,0,1,0),
(6,82,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(6,83,1,0,0,0,1,0),
(6,84,1,0,0,0,1,0),
(6,85,1,0,0,0,1,0),
(6,86,1,0,0,0,1,0),
(6,87,1,0,0,0,1,0),
(6,88,1,0,0,0,1,0),
(6,89,1,0,0,0,1,0),
(6,142,1,0,0,0,1,0),
(7,1,1,0,0,0,0,0),
(7,143,1,1,0,0,1,0),
(7,144,1,1,0,0,1,0),
(7,145,1,1,0,1,1,0),
(7,303,1,0,0,0,1,0),
(7,304,1,0,0,0,1,0),
(7,319,1,1,0,0,1,0),
(7,320,1,1,0,0,1,0),
(7,321,1,1,0,0,1,0),
(7,326,1,0,0,0,1,0),
(7,333,1,0,0,0,1,0),
(7,334,1,0,0,0,1,0),
(8,1,1,0,0,0,0,0),
(8,2,1,0,0,0,0,0),
(8,143,1,1,1,1,1,1),
(8,144,1,1,1,1,1,1),
(8,145,1,1,1,1,1,1),
(8,146,1,1,1,1,1,1),
(8,147,1,0,0,0,1,0),
(8,148,1,0,0,0,1,0),
(8,149,1,0,0,0,1,0),
(8,150,1,0,0,0,1,0),
(8,151,1,0,0,0,1,0),
(8,152,1,0,0,0,1,0),
(8,153,1,0,0,0,1,0),
(8,154,1,0,0,0,1,0),
(8,155,1,0,0,0,1,0),
(8,156,1,0,0,0,1,0),
(8,157,1,0,0,0,1,0),
(9,1,1,0,0,0,1,0),
(9,2,1,0,0,0,1,0),
(9,3,1,0,0,0,1,0),
(9,4,1,0,0,0,1,0),
(9,5,1,0,0,0,1,0),
(9,6,1,0,0,0,1,0),
(9,7,1,0,0,0,1,0),
(9,8,1,0,0,0,1,0),
(9,9,1,0,0,0,1,0),
(9,10,1,0,0,0,1,0),
(9,11,1,0,0,0,1,0),
(9,12,1,0,0,0,1,0),
(9,13,1,0,0,0,1,0),
(9,14,1,0,0,0,1,0),
(9,15,1,0,0,0,1,0),
(9,16,1,0,0,0,1,0),
(9,17,1,0,0,0,1,0),
(9,18,1,0,0,0,1,0),
(9,19,1,0,0,0,1,0),
(9,20,1,0,0,0,1,0),
(9,21,1,0,0,0,1,0),
(9,22,1,0,0,0,1,0),
(9,23,1,0,0,0,1,0),
(9,24,1,0,0,0,1,0),
(9,25,1,0,0,0,1,0),
(9,26,1,0,0,0,1,0),
(9,27,1,0,0,0,1,0),
(9,28,1,0,0,0,1,0),
(9,29,1,0,0,0,1,0),
(9,30,1,0,0,0,1,0),
(9,31,1,0,0,0,1,0),
(9,32,1,0,0,0,1,0),
(9,33,1,0,0,0,1,0),
(9,34,1,0,0,0,1,0),
(9,35,1,0,0,0,1,0),
(9,36,1,0,0,0,1,0),
(9,37,1,0,0,0,1,0),
(9,38,1,0,0,0,1,0),
(9,39,1,0,0,0,1,0),
(9,40,1,0,0,0,1,0),
(9,41,1,0,0,0,1,0),
(9,42,1,0,0,0,1,0),
(9,43,1,0,0,0,1,0),
(9,44,1,0,0,0,1,0),
(9,45,1,0,0,0,1,0),
(9,46,1,0,0,0,1,0),
(9,47,1,0,0,0,1,0),
(9,48,1,0,0,0,1,0),
(9,49,1,0,0,0,1,0),
(9,50,1,0,0,0,1,0),
(9,51,1,0,0,0,1,0),
(9,52,1,0,0,0,1,0),
(9,53,1,0,0,0,1,0),
(9,54,1,0,0,0,1,0),
(9,55,1,0,0,0,1,0),
(9,56,1,0,0,0,1,0),
(9,57,1,0,0,0,1,0),
(9,58,1,0,0,0,1,0),
(9,59,1,0,0,0,1,0),
(9,60,1,0,0,0,1,0),
(9,61,1,0,0,0,1,0),
(9,62,1,0,0,0,1,0),
(9,63,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(9,64,1,0,0,0,1,0),
(9,65,1,0,0,0,1,0),
(9,66,1,0,0,0,1,0),
(9,67,1,0,0,0,1,0),
(9,68,1,0,0,0,1,0),
(9,69,1,0,0,0,1,0),
(9,70,1,0,0,0,1,0),
(9,71,1,0,0,0,1,0),
(9,72,1,0,0,0,1,0),
(9,73,1,0,0,0,1,0),
(9,74,1,0,0,0,1,0),
(9,75,1,0,0,0,1,0),
(9,76,1,0,0,0,1,0),
(9,77,1,0,0,0,1,0),
(9,78,1,0,0,0,1,0),
(9,79,1,0,0,0,1,0),
(9,80,1,0,0,0,1,0),
(9,81,1,0,0,0,1,0),
(9,82,1,0,0,0,1,0),
(9,83,1,0,0,0,1,0),
(9,84,1,0,0,0,1,0),
(9,85,1,0,0,0,1,0),
(9,86,1,0,0,0,1,0),
(9,87,1,0,0,0,1,0),
(9,88,1,0,0,0,1,0),
(9,89,1,0,0,0,1,0),
(9,90,1,0,0,0,1,0),
(9,91,1,0,0,0,1,0),
(9,92,1,0,0,0,1,0),
(9,93,1,0,0,0,1,0),
(9,94,1,0,0,0,1,0),
(9,95,1,0,0,0,1,0),
(9,96,1,0,0,0,1,0),
(9,97,1,0,0,0,1,0),
(9,98,1,0,0,0,1,0),
(9,99,1,0,0,0,1,0),
(9,100,1,0,0,0,1,0),
(9,101,1,0,0,0,1,0),
(9,102,1,0,0,0,1,0),
(9,103,1,0,0,0,1,0),
(9,104,1,0,0,0,1,0),
(9,105,1,0,0,0,1,0),
(9,106,1,0,0,0,1,0),
(9,107,1,0,0,0,1,0),
(9,108,1,0,0,0,1,0),
(9,109,1,0,0,0,1,0),
(9,110,1,0,0,0,1,0),
(9,111,1,0,0,0,1,0),
(9,112,1,0,0,0,1,0),
(9,113,1,0,0,0,1,0),
(9,114,1,0,0,0,1,0),
(9,115,1,0,0,0,1,0),
(9,116,1,0,0,0,1,0),
(9,117,1,0,0,0,1,0),
(9,118,1,0,0,0,1,0),
(9,119,1,0,0,0,1,0),
(9,120,1,0,0,0,1,0),
(9,121,1,0,0,0,1,0),
(9,122,1,0,0,0,1,0),
(9,123,1,0,0,0,1,0),
(9,124,1,0,0,0,1,0),
(9,125,1,0,0,0,1,0),
(9,126,1,0,0,0,1,0),
(9,127,1,0,0,0,1,0),
(9,128,1,0,0,0,1,0),
(9,129,1,0,0,0,1,0),
(9,130,1,0,0,0,1,0),
(9,131,1,0,0,0,1,0),
(9,132,1,0,0,0,1,0),
(9,133,1,0,0,0,1,0),
(9,134,1,0,0,0,1,0),
(9,135,1,0,0,0,1,0),
(9,136,1,0,0,0,1,0),
(9,137,1,0,0,0,1,0),
(9,138,1,0,0,0,1,0),
(9,139,1,0,0,0,1,0),
(9,140,1,0,0,0,1,0),
(9,141,1,0,0,0,1,0),
(9,142,1,0,0,0,1,0),
(9,145,1,0,0,0,1,0),
(9,146,1,0,0,0,1,0),
(9,147,1,0,0,0,1,0),
(9,148,1,0,0,0,1,0),
(9,149,1,0,0,0,1,0),
(9,150,1,0,0,0,1,0),
(9,151,1,0,0,0,1,0),
(9,152,1,0,0,0,1,0),
(9,153,1,0,0,0,1,0),
(9,154,1,0,0,0,1,0),
(9,155,1,0,0,0,1,0),
(9,156,1,0,0,0,1,0),
(9,157,1,0,0,0,1,0),
(9,158,1,0,0,0,1,0),
(9,159,1,0,0,0,1,0),
(9,160,1,0,0,0,1,0),
(9,161,1,0,0,0,1,0),
(9,162,1,0,0,0,1,0),
(9,163,1,0,0,0,1,0),
(9,164,1,0,0,0,1,0),
(9,165,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(9,166,1,0,0,0,1,0),
(9,167,1,0,0,0,1,0),
(9,168,1,0,0,0,1,0),
(9,169,1,0,0,0,1,0),
(9,170,1,0,0,0,1,0),
(9,171,1,0,0,0,1,0),
(9,172,1,0,0,0,1,0),
(9,173,1,0,0,0,1,0),
(9,174,1,0,0,0,1,0),
(9,175,1,0,0,0,1,0),
(9,176,1,0,0,0,1,0),
(9,177,1,0,0,0,1,0),
(9,187,1,0,0,0,1,0),
(9,188,1,0,0,0,1,0),
(9,189,1,0,0,0,1,0),
(9,190,1,0,0,0,1,0),
(9,191,1,0,0,0,1,0),
(9,192,1,0,0,0,1,0),
(9,193,1,0,0,0,1,0),
(9,197,1,0,0,0,1,0),
(9,198,1,0,0,0,1,0),
(9,199,1,0,0,0,1,0),
(9,200,1,0,0,0,1,0),
(9,201,1,0,0,0,1,0),
(9,202,1,0,0,0,1,0),
(9,203,1,0,0,0,1,0),
(9,204,1,0,0,0,1,0),
(9,205,1,0,0,0,1,0),
(9,206,1,0,0,0,1,0),
(9,207,1,0,0,0,1,0),
(9,208,1,0,0,0,1,0),
(9,209,1,0,0,0,1,0),
(9,210,1,0,0,0,1,0),
(9,211,1,0,0,0,1,0),
(9,212,1,0,0,0,1,0),
(9,213,1,0,0,0,1,0),
(9,214,1,0,0,0,1,0),
(9,215,1,0,0,0,1,0),
(9,216,1,0,0,0,1,0),
(9,217,1,0,0,0,1,0),
(9,218,1,0,0,0,1,0),
(9,219,1,0,0,0,1,0),
(9,220,1,0,0,0,1,0),
(9,221,1,0,0,0,1,0),
(9,222,1,0,0,0,1,0),
(9,223,1,0,0,0,1,0),
(9,224,1,0,0,0,1,0),
(9,225,1,0,0,0,1,0),
(9,226,1,0,0,0,1,0),
(9,227,1,0,0,0,1,0),
(9,228,1,0,0,0,1,0),
(9,229,1,0,0,0,1,0),
(9,230,1,0,0,0,1,0),
(9,231,1,0,0,0,1,0),
(9,232,1,0,0,0,1,0),
(9,233,1,0,0,0,1,0),
(9,234,1,0,0,0,1,0),
(9,235,1,0,0,0,1,0),
(9,236,1,0,0,0,1,0),
(9,237,1,0,0,0,1,0),
(9,238,1,0,0,0,1,0),
(9,239,1,0,0,0,1,0),
(9,240,1,0,0,0,1,0),
(9,241,1,0,0,0,1,0),
(9,242,1,0,0,0,1,0),
(9,243,1,0,0,0,1,0),
(9,244,1,0,0,0,1,0),
(9,245,1,0,0,0,1,0),
(9,246,1,0,0,0,1,0),
(9,247,1,0,0,0,1,0),
(9,248,1,0,0,0,1,0),
(9,249,1,0,0,0,1,0),
(9,250,1,0,0,0,1,0),
(9,251,1,0,0,0,1,0),
(9,252,1,0,0,0,1,0),
(9,253,1,0,0,0,1,0),
(9,254,1,0,0,0,1,0),
(9,255,1,0,0,0,1,0),
(9,256,1,0,0,0,1,0),
(9,257,1,0,0,0,1,0),
(9,258,1,0,0,0,1,0),
(9,259,1,0,0,0,1,0),
(9,260,1,0,0,0,1,0),
(9,261,1,0,0,0,1,0),
(9,262,1,0,0,0,1,0),
(9,263,1,0,0,0,1,0),
(9,264,1,0,0,0,1,0),
(9,265,1,0,0,0,1,0),
(9,266,1,0,0,0,1,0),
(9,267,1,0,0,0,1,0),
(9,268,1,0,0,0,1,0),
(9,269,1,0,0,0,1,0),
(9,270,1,0,0,0,1,0),
(9,271,1,0,0,0,1,0),
(9,272,1,0,0,0,1,0),
(9,273,1,0,0,0,1,0),
(9,274,1,0,0,0,1,0),
(9,275,1,0,0,0,1,0),
(9,276,1,0,0,0,1,0),
(9,278,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(9,279,1,0,0,0,1,0),
(9,282,1,0,0,0,1,0),
(9,283,1,0,0,0,1,0),
(9,284,1,0,0,0,1,0),
(9,285,1,0,0,0,1,0),
(9,286,1,0,0,0,1,0),
(9,287,1,0,0,0,1,0),
(9,288,1,0,0,0,1,0),
(9,289,1,0,0,0,1,0),
(9,290,1,0,0,0,1,0),
(9,291,1,0,0,0,1,0),
(9,292,1,0,0,0,1,0),
(9,293,1,0,0,0,1,0),
(9,294,1,0,0,0,1,0),
(9,295,1,0,0,0,1,0),
(9,296,1,0,0,0,1,0),
(9,297,1,0,0,0,1,0),
(9,298,1,0,0,0,1,0),
(9,299,1,0,0,0,1,0),
(9,300,1,0,0,0,1,0),
(9,301,1,0,0,0,1,0),
(9,302,1,0,0,0,1,0),
(9,303,1,0,0,0,1,0),
(9,304,1,0,0,0,1,0),
(9,305,1,0,0,0,1,0),
(9,306,1,0,0,0,1,0),
(9,307,1,0,0,0,1,0),
(9,308,1,0,0,0,1,0),
(9,309,1,0,0,0,1,0),
(9,310,1,0,0,0,1,0),
(9,311,1,0,0,0,1,0),
(9,312,1,0,0,0,1,0),
(9,313,1,0,0,0,1,0),
(9,314,1,0,0,0,1,0),
(9,315,1,0,0,0,1,0),
(9,316,1,0,0,0,1,0),
(9,317,1,0,0,0,1,0),
(9,318,1,0,0,0,1,0),
(9,319,0,0,0,0,0,0),
(9,320,1,0,0,0,1,0),
(9,321,0,0,0,0,0,0),
(9,322,1,0,0,0,1,0),
(9,323,1,0,0,0,1,0),
(9,324,1,0,0,0,1,0),
(9,325,1,0,0,0,1,0),
(9,326,1,0,0,0,1,0),
(9,327,1,0,0,0,1,0),
(9,328,1,0,0,0,1,0),
(9,329,1,0,0,0,1,0),
(9,330,1,0,0,0,1,0),
(9,331,1,0,0,0,1,0),
(9,332,1,0,0,0,1,0),
(9,333,1,0,0,0,1,0),
(9,334,1,0,0,0,1,0),
(9,335,1,0,0,0,1,0),
(9,336,1,0,0,0,1,0),
(9,337,1,0,0,0,1,0),
(9,338,1,0,0,0,1,0),
(9,339,1,0,0,0,1,0),
(9,340,1,0,0,0,1,0),
(9,341,1,0,0,0,1,0),
(9,342,1,0,0,0,1,0),
(9,343,1,0,0,0,1,0),
(9,344,1,0,0,0,1,0),
(9,345,1,0,0,0,1,0),
(9,346,1,0,0,0,1,0),
(9,347,1,0,0,0,1,0),
(9,348,1,0,0,0,1,0),
(9,349,1,0,0,0,1,0),
(9,350,1,0,0,0,1,0),
(9,351,1,0,0,0,1,0),
(9,352,1,0,0,0,1,0),
(9,353,1,0,0,0,1,0),
(9,354,1,0,0,0,1,0),
(9,355,1,0,0,0,1,0),
(9,356,1,0,0,0,1,0),
(9,357,1,0,0,0,1,0),
(9,358,1,0,0,0,1,0),
(9,359,1,0,0,0,1,0),
(9,360,1,0,0,0,1,0),
(9,361,0,0,0,0,0,0),
(9,362,1,0,0,0,1,0),
(9,363,1,0,0,0,1,0),
(9,364,1,0,0,0,1,0),
(9,365,1,0,0,0,1,0),
(9,366,1,0,0,0,1,0),
(9,367,1,0,0,0,1,0),
(9,368,1,0,0,0,1,0),
(9,369,1,0,0,0,1,0),
(9,370,1,0,0,0,1,0),
(9,371,1,0,0,0,1,0),
(9,372,1,0,0,0,1,0),
(9,373,1,0,0,0,1,0),
(9,374,1,0,0,0,1,0),
(9,375,1,0,0,0,1,0),
(9,376,1,0,0,0,1,0),
(9,377,1,0,0,0,1,0),
(9,378,1,0,0,0,1,0),
(9,379,1,0,0,0,1,0),
(10,1,1,1,1,1,1,1);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(10,2,1,1,1,1,1,1),
(10,3,1,1,1,1,1,1),
(10,4,1,1,1,1,1,1),
(10,5,1,1,1,1,1,1),
(10,6,1,1,1,1,1,1),
(10,7,1,1,1,1,1,1),
(10,8,1,1,1,1,1,1),
(10,9,1,1,1,1,1,1),
(10,14,1,1,1,1,1,1),
(10,15,1,1,1,1,1,1),
(10,16,1,1,1,1,1,1),
(10,18,1,1,1,1,1,1),
(10,19,1,1,1,1,1,1),
(10,20,1,1,1,1,1,1),
(10,21,1,1,1,1,1,1),
(10,27,1,1,1,1,1,1),
(10,28,1,1,1,1,1,1),
(10,29,1,1,1,1,1,1),
(10,30,1,1,1,1,1,1),
(10,31,1,1,1,1,1,1),
(10,32,1,1,1,1,1,1),
(10,33,1,1,1,1,1,1),
(10,34,1,1,1,1,1,1),
(10,197,1,1,1,1,1,1),
(10,198,1,1,1,1,1,1),
(10,199,1,1,1,1,1,1),
(10,200,1,1,1,1,1,1),
(10,201,1,1,1,1,1,1),
(10,202,1,1,1,1,1,1),
(10,203,1,1,1,1,1,1),
(10,204,1,1,1,1,1,1),
(10,205,1,1,1,1,1,1),
(10,206,1,1,1,1,1,1),
(10,207,1,1,1,1,1,1),
(10,208,1,1,1,1,1,1),
(10,209,1,1,1,1,1,1),
(10,210,1,1,1,1,1,1),
(10,211,1,1,1,1,1,1),
(10,212,1,1,1,1,1,1),
(10,213,1,1,1,1,1,1),
(10,214,1,1,1,1,1,1),
(10,215,1,1,1,1,1,1),
(10,216,1,1,1,1,1,1),
(10,217,1,1,1,1,1,1),
(10,218,1,1,1,0,1,1),
(10,219,1,1,1,0,1,1),
(10,220,1,1,1,0,1,1),
(10,221,1,1,1,0,1,1),
(10,222,1,1,1,0,1,1),
(10,223,1,1,1,0,1,1),
(10,224,1,1,1,0,1,1),
(10,225,1,1,1,0,1,1),
(10,226,1,1,1,1,1,1),
(10,227,1,1,1,1,1,1),
(10,228,1,1,1,1,1,1),
(10,229,1,1,1,1,1,1),
(10,230,1,1,1,1,1,1),
(10,231,1,1,1,1,1,1),
(10,232,1,1,1,1,1,1),
(10,233,1,1,1,1,1,1),
(10,234,1,1,1,1,1,1),
(10,235,1,1,1,1,1,1),
(10,236,1,1,1,1,1,1),
(10,237,1,1,1,1,1,1),
(10,238,1,1,1,1,1,1),
(10,239,1,1,1,1,1,1),
(10,240,1,1,1,1,1,1),
(10,241,1,1,1,1,1,1),
(10,242,1,1,1,1,1,1),
(10,243,1,1,1,1,1,1),
(10,244,1,1,1,1,1,1),
(10,245,1,1,1,1,1,1),
(10,246,1,1,1,1,1,1),
(10,247,1,1,1,1,1,1),
(10,248,1,1,1,1,1,1),
(10,249,1,1,1,1,1,1),
(10,250,1,1,1,1,1,1),
(10,251,1,1,1,1,1,1),
(10,252,1,1,1,1,1,1),
(10,253,1,1,1,1,1,1),
(10,254,1,1,1,1,1,1),
(10,255,1,1,1,1,1,1),
(10,256,1,1,1,1,1,1),
(10,257,1,1,1,1,1,1),
(10,258,1,1,1,1,1,1),
(10,259,1,1,1,1,1,1),
(10,260,1,1,1,1,1,1),
(10,261,1,1,1,1,1,1),
(10,262,1,1,1,1,1,1),
(10,263,1,1,1,1,1,1),
(10,264,1,1,1,1,1,1),
(10,265,1,1,1,1,1,1),
(10,266,1,1,1,1,1,1),
(10,267,1,1,1,1,1,1),
(10,268,1,1,1,1,1,1),
(10,269,1,1,1,1,1,1),
(10,270,1,1,1,1,1,1),
(10,271,1,1,1,1,1,1),
(10,272,1,1,1,1,1,1),
(10,273,1,1,1,1,1,1);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(10,274,1,1,1,1,1,1),
(10,275,1,1,1,1,1,1),
(10,276,1,1,1,1,1,1),
(10,277,1,1,1,1,1,1),
(10,278,1,1,1,1,1,1),
(10,279,1,1,1,1,1,1),
(10,280,1,1,1,1,1,1),
(10,281,1,1,1,1,1,1),
(10,282,1,1,1,1,1,1),
(10,283,1,1,1,1,1,1),
(10,284,1,1,1,1,1,1),
(10,285,1,1,1,1,1,1),
(10,286,1,1,1,1,1,1),
(10,287,1,1,1,1,1,1),
(10,288,1,1,1,1,1,1),
(10,289,1,1,1,1,1,1),
(10,290,1,1,1,1,1,1),
(10,291,1,1,1,1,1,1),
(10,292,1,1,1,1,1,1),
(10,293,1,1,1,1,1,1),
(10,294,1,1,1,1,1,1),
(10,295,1,1,1,1,1,1),
(10,296,1,1,1,1,1,1),
(10,297,1,1,1,1,1,1),
(10,298,1,1,1,1,1,1),
(10,299,1,1,1,1,1,1),
(10,300,1,1,1,1,1,1),
(10,301,1,1,1,1,1,1),
(10,302,1,1,1,1,1,1),
(10,303,1,1,1,1,1,1),
(10,304,1,1,1,1,1,1),
(10,305,1,1,1,1,1,1),
(10,306,1,1,1,1,1,1),
(10,307,1,1,1,1,1,1),
(10,308,1,1,1,1,1,1),
(10,309,1,1,1,1,1,1),
(10,310,1,1,1,1,1,1),
(10,311,1,1,1,1,1,1),
(10,312,1,1,1,1,1,1),
(10,313,1,1,1,1,1,1),
(10,314,1,1,1,1,1,1),
(10,315,1,1,1,1,1,1),
(10,316,1,1,1,1,1,1),
(10,317,1,1,1,1,1,1),
(10,318,1,1,1,1,1,1),
(10,319,1,1,1,1,1,1),
(10,320,1,1,1,1,1,1),
(10,321,1,1,1,1,1,1),
(10,322,1,1,1,1,1,1),
(10,323,1,1,1,1,1,1),
(10,324,1,1,1,1,1,1),
(10,325,1,1,1,1,1,1),
(10,326,1,1,1,1,1,1),
(10,327,1,1,1,1,1,1),
(10,328,1,1,1,1,1,1),
(10,329,1,1,1,1,1,1),
(10,330,1,1,1,1,1,1),
(10,331,1,1,1,1,1,1),
(10,332,1,1,1,1,1,1),
(10,333,1,1,1,1,1,1),
(10,334,1,1,1,1,1,1),
(10,335,1,1,1,1,1,1),
(10,336,1,1,1,1,1,1),
(10,337,1,1,1,1,1,1),
(10,338,1,1,1,1,1,1),
(10,339,1,1,1,1,1,1),
(10,340,1,1,1,1,1,1),
(10,341,1,1,1,1,1,1),
(10,342,1,1,1,1,1,1),
(10,343,1,1,1,1,1,1),
(10,344,1,1,1,1,1,1),
(10,345,1,1,1,1,1,1),
(10,346,1,1,1,1,1,1),
(10,347,1,1,1,1,1,1),
(10,348,1,1,1,1,1,1),
(10,349,1,1,1,1,1,1),
(10,350,1,1,1,1,1,1),
(10,351,1,1,1,1,1,1),
(10,352,1,1,1,1,1,1),
(10,353,1,1,1,1,1,1),
(10,354,1,1,1,1,1,1),
(10,355,1,1,1,1,1,1),
(10,356,1,1,1,1,1,1),
(10,357,1,1,1,1,1,1),
(10,358,1,1,1,1,1,1),
(10,359,1,1,1,1,1,1),
(10,360,1,1,1,1,1,1),
(10,361,1,1,1,1,1,1),
(10,362,1,1,1,1,1,1),
(10,363,1,1,1,1,1,1),
(10,364,1,1,1,1,1,1),
(10,365,1,1,1,1,1,1),
(10,366,1,1,1,1,1,1),
(10,367,1,1,1,1,1,1),
(10,368,1,1,1,1,1,1),
(10,369,1,1,1,1,1,1),
(10,370,1,1,1,1,1,1),
(10,371,1,1,1,1,1,1),
(10,372,1,1,1,1,1,1),
(10,373,1,1,1,1,1,1);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(10,374,1,1,1,1,1,1),
(10,375,1,1,1,1,1,1),
(10,376,1,1,1,1,1,1),
(10,377,1,1,1,1,1,1),
(10,378,1,1,1,1,1,1),
(10,379,1,1,1,1,1,1),
(11,1,1,1,1,0,1,0),
(11,2,1,1,1,0,1,0),
(11,3,1,1,1,0,1,0),
(11,4,1,1,1,0,1,0),
(11,5,1,1,1,0,1,0),
(11,6,1,1,1,0,1,0),
(11,7,1,0,0,0,1,0),
(11,8,1,1,1,0,1,0),
(11,9,1,1,1,0,1,0),
(11,14,1,1,1,0,1,0),
(11,15,1,1,1,0,1,0),
(11,16,1,1,1,0,1,0),
(11,18,1,0,0,0,1,0),
(11,19,1,0,0,0,1,0),
(11,20,1,0,0,0,1,0),
(11,27,1,0,0,0,1,0),
(11,28,1,0,0,0,1,0),
(11,30,1,0,0,0,1,0),
(11,31,1,0,0,0,1,0),
(11,32,1,0,0,0,1,0),
(11,33,1,0,0,0,1,0),
(11,34,1,0,0,0,1,0),
(11,197,1,1,1,0,1,0),
(11,198,1,1,1,0,1,0),
(11,199,1,1,1,0,1,0),
(11,200,1,1,1,0,1,0),
(11,201,1,1,1,0,1,0),
(11,204,1,1,1,0,1,0),
(11,205,1,1,1,0,1,0),
(11,207,1,1,1,0,1,0),
(11,208,1,0,0,0,1,0),
(11,209,1,0,0,0,1,0),
(11,210,1,0,0,0,1,0),
(11,211,1,0,0,0,1,0),
(11,212,1,0,0,0,1,0),
(11,213,1,0,0,0,1,0),
(11,214,1,0,0,0,1,0),
(11,215,1,0,0,0,1,0),
(11,216,1,0,0,0,1,0),
(11,217,1,0,0,0,1,0),
(11,219,1,0,0,0,1,0),
(11,220,1,1,1,0,1,0),
(11,221,1,0,0,0,1,0),
(11,222,1,0,0,0,1,0),
(11,223,1,0,0,0,1,0),
(11,224,1,0,0,0,1,0),
(11,225,1,0,0,0,1,0),
(11,226,1,1,1,0,1,0),
(11,227,1,1,1,0,1,0),
(11,228,1,1,1,0,1,0),
(11,229,1,1,1,0,1,0),
(11,230,1,1,1,0,1,0),
(11,231,1,1,1,0,1,0),
(11,232,1,1,1,0,1,0),
(11,233,1,1,1,0,1,0),
(11,235,1,1,1,0,1,0),
(11,236,1,0,0,0,1,0),
(11,237,1,0,0,0,1,0),
(11,238,1,0,0,0,1,0),
(11,239,1,0,0,0,1,0),
(11,240,1,0,0,0,1,0),
(11,241,1,0,0,0,1,0),
(11,242,1,0,0,0,1,0),
(11,243,1,0,0,0,1,0),
(11,244,1,0,0,0,1,0),
(11,245,1,0,0,0,1,0),
(11,246,1,1,1,0,1,0),
(11,247,1,1,1,0,1,0),
(11,248,1,1,1,0,1,0),
(11,249,1,1,1,0,1,0),
(11,250,1,1,1,0,1,0),
(11,251,1,1,1,0,1,0),
(11,252,1,1,1,0,1,0),
(11,253,1,1,1,0,1,0),
(11,254,1,1,1,0,1,0),
(11,255,1,1,1,0,1,0),
(11,256,1,1,1,0,1,0),
(11,260,1,0,0,0,1,0),
(11,261,1,0,0,0,1,0),
(11,262,1,0,0,0,1,0),
(11,263,1,0,0,0,1,0),
(11,264,1,0,0,0,1,0),
(11,265,1,0,0,0,1,0),
(11,266,1,0,0,0,1,0),
(11,267,1,0,0,0,1,0),
(11,268,1,0,0,0,1,0),
(11,269,1,0,0,0,1,0),
(11,270,1,0,0,0,1,0),
(11,271,1,0,0,0,1,0),
(11,272,1,0,0,0,1,0),
(11,273,1,0,0,0,1,0),
(11,274,1,0,0,0,1,0),
(11,275,1,0,0,0,1,0),
(11,276,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(11,278,1,1,1,0,1,0),
(11,282,1,1,1,0,1,0),
(11,283,1,0,0,0,1,0),
(11,284,1,0,0,0,1,0),
(11,285,1,0,0,0,1,0),
(11,286,1,0,0,0,1,0),
(11,287,1,0,0,0,1,0),
(11,288,1,1,1,0,1,0),
(11,289,1,1,1,0,1,0),
(11,290,1,1,1,0,1,0),
(11,291,1,1,1,0,1,0),
(11,292,1,0,0,0,1,0),
(11,293,1,0,0,0,1,0),
(11,294,1,0,0,0,1,0),
(12,1,1,1,1,0,1,0),
(12,2,1,1,1,0,1,0),
(12,3,1,1,1,0,1,0),
(12,4,1,1,1,0,1,0),
(12,5,1,1,1,0,1,0),
(12,6,1,1,1,0,1,0),
(12,7,1,0,0,0,1,0),
(12,197,1,1,1,0,1,0),
(12,198,1,1,1,0,1,0),
(12,199,1,1,1,0,1,0),
(12,208,1,0,0,0,1,0),
(12,220,1,1,1,0,1,0),
(12,222,1,0,0,0,1,0),
(12,223,1,0,0,0,1,0),
(12,226,1,1,1,0,1,0),
(12,246,1,1,1,0,1,0),
(12,247,1,1,1,0,1,0),
(12,248,1,1,1,0,1,0),
(12,249,1,1,1,0,1,0),
(12,250,1,1,1,0,1,0),
(12,251,1,1,1,0,1,0),
(12,252,1,1,1,0,1,0),
(12,253,1,1,1,0,1,0),
(12,254,1,1,1,0,1,0),
(12,255,1,1,1,0,1,0),
(12,256,1,1,1,0,1,0),
(12,260,1,0,0,0,1,0),
(12,261,1,0,0,0,1,0),
(12,262,1,0,0,0,1,0),
(12,263,1,0,0,0,1,0),
(12,264,1,0,0,0,1,0),
(12,265,1,0,0,0,1,0),
(12,266,1,0,0,0,1,0),
(12,267,1,0,0,0,1,0),
(12,268,1,0,0,0,1,0),
(12,269,1,0,0,0,1,0),
(12,270,1,0,0,0,1,0),
(12,271,1,0,0,0,1,0),
(12,272,1,0,0,0,1,0),
(12,273,1,0,0,0,1,0),
(12,274,1,0,0,0,1,0),
(12,275,1,0,0,0,1,0),
(12,276,1,0,0,0,1,0),
(13,1,1,0,0,0,1,1),
(13,2,1,0,0,0,1,1),
(13,220,1,0,0,0,1,0),
(13,222,1,0,0,0,1,0),
(13,246,1,0,0,0,1,1),
(13,247,1,0,0,0,1,1),
(13,248,1,0,0,0,1,1),
(13,251,1,0,0,0,1,1),
(13,253,1,0,0,0,1,1),
(13,255,1,0,0,0,1,1),
(13,260,1,0,0,0,1,0),
(13,261,1,0,0,0,1,0),
(13,262,1,0,0,0,1,0),
(13,263,1,0,0,0,1,0),
(13,264,1,0,0,0,1,0),
(13,265,1,0,0,0,1,0),
(13,266,1,0,0,0,1,0),
(13,267,1,0,0,0,1,0),
(13,268,1,0,0,0,1,0),
(13,269,1,0,0,0,1,0),
(13,270,1,0,0,0,1,0),
(13,271,1,0,0,0,1,0),
(13,272,1,0,0,0,1,0),
(13,273,1,0,0,0,1,0),
(13,274,1,0,0,0,1,0),
(13,275,1,0,0,0,1,0),
(13,276,1,0,0,0,1,0),
(14,1,1,1,0,0,1,0),
(14,197,1,1,0,0,1,0),
(14,220,1,0,0,0,1,0),
(14,222,1,0,0,0,1,0),
(14,226,1,1,0,0,1,0),
(14,227,1,1,0,0,1,0),
(14,230,1,1,0,0,1,0),
(14,231,1,1,0,0,1,0),
(14,233,1,1,0,0,1,0),
(14,245,1,1,0,0,1,0),
(14,277,1,1,0,0,1,0),
(14,278,1,1,0,0,1,0),
(14,279,1,1,0,0,1,0),
(14,280,1,1,0,0,1,0),
(14,281,1,1,0,0,1,0),
(14,282,1,1,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(14,283,1,1,0,0,1,0),
(14,284,1,1,0,0,1,0),
(14,286,1,1,0,0,1,0),
(15,1,1,1,1,0,1,0),
(15,2,1,1,1,0,1,0),
(15,3,1,1,1,0,1,0),
(15,4,1,1,1,0,1,0),
(15,5,1,1,1,0,1,0),
(15,6,1,1,1,0,1,0),
(15,7,1,0,0,0,1,0),
(15,197,1,1,1,0,1,0),
(15,198,1,1,1,0,1,0),
(15,199,1,1,1,0,1,0),
(15,204,1,1,1,0,1,0),
(15,205,1,1,1,0,1,0),
(15,208,1,0,0,0,1,0),
(15,209,1,0,0,0,1,0),
(15,210,1,0,0,0,1,0),
(15,211,1,0,0,0,1,0),
(15,212,1,0,0,0,1,0),
(15,213,1,0,0,0,1,0),
(15,214,1,0,0,0,1,0),
(15,215,1,0,0,0,1,0),
(15,216,1,0,0,0,1,0),
(15,217,1,0,0,0,1,0),
(15,220,1,1,1,0,1,0),
(15,222,1,0,0,0,1,0),
(15,223,1,0,0,0,1,0),
(15,226,1,1,1,0,1,0),
(15,230,1,1,1,0,1,0),
(15,236,1,0,0,0,1,0),
(15,238,1,0,0,0,1,0),
(15,245,1,0,0,0,1,0),
(16,1,1,1,0,0,1,0),
(16,220,1,0,0,0,1,0),
(16,222,1,0,0,0,1,0),
(16,251,1,1,0,0,1,0),
(16,263,1,1,0,0,1,0),
(16,264,1,1,0,0,1,0),
(16,265,1,1,0,0,1,0),
(16,266,1,1,0,0,1,0),
(16,267,1,1,0,0,1,0),
(16,268,1,1,0,0,1,0),
(16,269,1,1,0,0,1,0),
(16,270,1,1,0,0,1,0),
(16,277,1,1,0,0,1,0),
(16,279,1,1,0,0,1,0),
(16,280,1,1,0,0,1,0),
(16,283,1,1,0,0,1,0),
(17,1,1,1,1,1,1,1),
(17,2,1,1,1,1,1,1),
(17,3,1,1,1,1,1,1),
(17,4,1,1,1,1,1,1),
(17,5,1,1,1,1,1,1),
(17,6,1,1,1,1,1,1),
(17,7,1,1,1,1,1,1),
(17,197,1,1,1,1,1,1),
(17,198,1,1,1,1,1,1),
(17,199,1,1,1,1,1,1),
(17,200,1,1,1,1,1,1),
(17,201,1,1,1,1,1,1),
(17,208,1,1,1,1,1,1),
(17,209,1,1,1,1,1,1),
(17,210,1,1,1,1,1,1),
(17,211,1,1,1,1,1,1),
(17,212,1,1,1,1,1,1),
(17,213,1,1,1,1,1,1),
(17,214,1,1,1,1,1,1),
(17,215,1,1,1,1,1,1),
(17,216,1,1,1,1,1,1),
(17,217,1,1,1,1,1,1),
(17,219,1,1,1,0,1,1),
(17,220,1,1,1,0,1,1),
(17,221,1,1,1,0,1,1),
(17,222,1,1,1,0,1,1),
(17,223,1,1,1,0,1,1),
(17,224,1,1,1,0,1,1),
(17,225,1,1,1,0,1,1),
(17,226,1,1,1,1,1,1),
(17,227,1,1,1,1,1,1),
(17,228,1,1,1,1,1,1),
(17,229,1,1,1,1,1,1),
(17,230,1,1,1,1,1,1),
(17,231,1,1,1,1,1,1),
(17,232,1,1,1,1,1,1),
(17,233,1,1,1,1,1,1),
(17,236,1,1,1,1,1,1),
(17,237,1,1,1,1,1,1),
(17,238,1,1,1,1,1,1),
(17,239,1,1,1,1,1,1),
(17,240,1,1,1,1,1,1),
(17,241,1,1,1,1,1,1),
(17,242,1,1,1,1,1,1),
(17,243,1,1,1,1,1,1),
(17,244,1,1,1,1,1,1),
(17,245,1,1,1,1,1,1),
(17,246,1,1,1,1,1,1),
(17,247,1,1,1,1,1,1),
(17,248,1,1,1,1,1,1),
(17,249,1,1,1,1,1,1);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(17,250,1,1,1,1,1,1),
(17,251,1,1,1,1,1,1),
(17,252,1,1,1,1,1,1),
(17,253,1,1,1,1,1,1),
(17,254,1,1,1,1,1,1),
(17,255,1,1,1,1,1,1),
(17,256,1,1,1,1,1,1),
(17,260,1,1,1,1,1,1),
(17,261,1,1,1,1,1,1),
(17,262,1,1,1,1,1,1),
(17,263,1,1,1,1,1,1),
(17,264,1,1,1,1,1,1),
(17,265,1,1,1,1,1,1),
(17,266,1,1,1,1,1,1),
(17,267,1,1,1,1,1,1),
(17,268,1,1,1,1,1,1),
(17,269,1,1,1,1,1,1),
(17,270,1,1,1,1,1,1),
(17,271,1,1,1,1,1,1),
(17,272,1,1,1,1,1,1),
(17,273,1,1,1,1,1,1),
(17,274,1,1,1,1,1,1),
(17,275,1,1,1,1,1,1),
(17,276,1,1,1,1,1,1),
(17,277,1,1,1,1,1,1),
(17,278,1,1,1,1,1,1),
(17,279,1,1,1,1,1,1),
(17,280,1,1,1,1,1,1),
(17,281,1,1,1,1,1,1),
(17,282,1,1,1,1,1,1),
(17,283,1,1,1,1,1,1),
(17,284,1,1,1,1,1,1),
(17,285,1,1,1,1,1,1),
(17,286,1,1,1,1,1,1),
(17,287,1,1,1,1,1,1),
(17,288,1,1,1,1,1,1),
(17,289,1,1,1,1,1,1),
(17,290,1,1,1,1,1,1),
(17,291,1,1,1,1,1,1),
(17,292,1,1,1,1,1,1),
(17,293,1,1,1,1,1,1),
(17,294,1,1,1,1,1,1),
(17,296,1,1,1,1,1,1),
(17,297,1,1,1,1,1,1),
(17,298,1,1,1,1,1,1),
(17,300,1,1,1,1,1,1),
(17,301,1,1,1,1,1,1),
(17,302,1,1,1,1,1,1),
(17,303,1,1,1,1,1,1),
(17,304,1,1,1,1,1,1),
(17,305,1,1,1,1,1,1),
(17,306,1,1,1,1,1,1),
(17,307,1,1,1,1,1,1),
(17,308,1,1,1,1,1,1),
(17,309,1,1,1,1,1,1),
(17,310,1,1,1,1,1,1),
(17,311,1,1,1,1,1,1),
(17,312,1,1,1,1,1,1),
(17,313,1,1,1,1,1,1),
(17,314,1,1,1,1,1,1),
(17,315,1,1,1,1,1,1),
(17,316,1,1,1,1,1,1),
(17,317,1,1,1,1,1,1),
(17,318,1,1,1,1,1,1),
(17,319,1,1,1,1,1,1),
(17,320,1,1,1,1,1,1),
(17,321,1,1,1,1,1,1),
(17,322,1,1,1,1,1,1),
(17,323,1,1,1,1,1,1),
(17,324,1,1,1,1,1,1),
(17,325,1,1,1,1,1,1),
(17,326,1,1,1,1,1,1),
(17,327,1,1,1,1,1,1),
(17,328,1,1,1,1,1,1),
(17,329,1,1,1,1,1,1),
(17,330,1,1,1,1,1,1),
(17,331,1,1,1,1,1,1),
(17,332,1,1,1,1,1,1),
(17,333,1,1,1,1,1,1),
(17,334,1,1,1,1,1,1),
(17,335,1,1,1,1,1,1),
(17,336,1,1,1,1,1,1),
(17,337,1,1,1,1,1,1),
(17,338,1,1,1,1,1,1),
(17,339,1,1,1,1,1,1),
(17,340,1,1,1,1,1,1),
(17,341,1,1,1,1,1,1),
(17,342,1,1,1,1,1,1),
(17,343,1,1,1,1,1,1),
(17,344,1,1,1,0,1,1),
(17,345,1,1,1,0,1,1),
(17,346,1,1,1,0,1,1),
(17,347,1,1,1,0,1,1),
(17,348,1,1,1,0,1,1),
(17,349,1,1,1,0,1,1),
(17,350,1,1,1,0,1,1),
(17,351,1,1,1,0,1,1),
(17,352,1,1,1,0,1,1),
(17,353,1,1,1,0,1,1),
(17,354,1,1,1,0,1,1);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(17,355,1,1,1,0,1,1),
(17,356,1,1,1,0,1,1),
(17,357,1,1,1,0,1,1),
(17,358,1,1,1,0,1,1),
(17,359,1,1,1,0,1,1),
(17,360,1,1,1,0,1,1),
(17,361,1,1,1,1,1,1),
(17,362,1,1,1,1,1,1),
(17,363,1,1,1,1,1,1),
(17,364,1,1,1,1,1,1),
(17,365,1,1,1,1,1,1),
(17,366,1,1,1,1,1,1),
(17,367,1,1,1,1,1,1),
(17,368,1,1,1,1,1,1),
(17,369,1,1,1,1,1,1),
(17,370,1,1,1,1,1,1),
(17,371,1,1,1,1,1,1),
(17,372,1,1,1,1,1,1),
(17,379,1,0,0,0,1,0),
(18,1,1,0,0,0,0,0),
(18,2,1,0,0,0,1,0),
(18,302,1,0,0,0,1,0),
(18,303,1,0,0,0,1,0),
(18,304,1,0,1,0,1,0),
(18,306,1,0,0,0,1,0),
(18,307,1,1,1,0,1,0),
(18,309,1,0,0,0,1,0),
(18,314,1,0,0,0,1,0),
(18,320,1,0,0,0,1,0),
(18,322,1,1,1,0,1,0),
(18,323,1,1,1,0,1,0),
(18,324,1,1,1,0,1,0),
(18,325,1,1,1,0,1,0),
(18,326,1,0,0,0,1,0),
(18,327,1,0,0,0,1,0),
(18,328,1,0,0,0,1,0),
(18,329,1,0,0,0,1,0),
(18,330,1,0,0,0,1,0),
(18,331,1,0,0,0,1,0),
(18,332,1,0,0,0,1,0),
(18,333,1,0,0,0,1,0),
(18,334,1,0,0,0,1,0),
(18,335,1,0,0,0,1,0),
(18,336,1,0,0,0,1,0),
(18,337,1,0,0,0,1,0),
(18,338,1,0,0,0,1,0),
(18,339,1,0,0,0,1,0),
(18,344,1,0,0,0,1,0),
(19,1,1,0,0,0,0,0),
(19,302,1,0,0,0,1,0),
(19,303,1,0,0,0,1,0),
(19,304,1,0,1,0,1,0),
(19,319,1,1,0,0,1,0),
(19,320,1,1,1,0,1,0),
(19,321,1,1,0,0,1,0),
(19,325,1,1,1,0,1,0),
(19,326,1,0,0,0,1,0),
(19,330,1,0,0,0,1,0),
(19,333,1,0,0,0,1,0),
(19,334,1,0,0,0,1,0),
(19,335,1,0,0,0,1,0),
(19,336,1,0,0,0,1,0),
(19,337,1,0,0,0,1,0),
(19,338,1,0,0,0,1,0),
(19,339,1,0,0,0,1,0),
(19,344,1,0,0,0,1,0),
(19,346,1,1,1,0,1,0),
(19,352,1,0,0,0,1,0),
(19,357,1,0,0,0,1,0),
(20,1,1,0,0,0,0,0),
(20,302,1,0,0,0,1,0),
(20,303,1,0,0,0,1,0),
(20,304,1,0,1,0,1,0),
(20,344,1,1,1,1,1,1),
(20,345,1,1,1,1,1,1),
(20,346,1,1,1,1,1,1),
(20,347,1,1,1,1,1,1),
(20,348,1,1,1,1,1,1),
(20,349,1,1,1,1,1,1),
(20,350,1,1,1,1,1,1),
(20,351,1,1,1,1,1,1),
(20,352,1,1,1,1,1,1),
(20,353,1,1,1,1,1,1),
(20,354,1,1,1,1,1,1),
(20,355,1,1,1,1,1,1),
(20,356,1,1,1,1,1,1),
(20,357,1,1,1,1,1,1),
(20,358,1,1,1,1,1,1),
(20,359,1,1,1,1,1,1),
(20,360,1,1,1,1,1,1),
(20,362,1,1,1,0,1,0),
(20,363,1,1,1,0,1,0),
(20,365,1,0,0,0,1,0),
(20,366,1,0,0,0,1,0),
(20,367,1,0,0,0,1,0),
(20,368,1,0,0,0,1,0),
(20,369,1,0,0,0,1,0),
(20,370,1,0,0,0,1,0),
(20,371,1,0,0,0,1,0),
(20,372,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(20,379,1,1,1,0,1,0),
(21,1,1,0,0,0,0,0),
(21,111,1,0,0,0,1,0),
(21,117,1,1,0,0,1,0),
(21,124,1,0,0,0,1,0),
(21,302,1,0,0,0,1,0),
(21,303,1,0,0,0,1,0),
(21,304,1,0,1,0,1,0),
(21,305,1,1,1,0,1,1),
(21,307,1,1,1,0,1,0),
(21,345,1,1,0,0,1,0),
(21,346,1,1,1,0,1,0),
(21,347,1,0,0,0,1,0),
(21,349,1,1,1,0,1,0),
(21,350,1,0,0,0,1,0),
(21,351,1,1,1,0,1,1),
(21,358,1,0,0,0,1,0),
(21,359,1,0,0,0,1,0),
(21,360,1,0,0,0,1,0),
(21,361,1,1,1,1,1,1),
(21,362,1,1,1,1,1,1),
(21,363,1,1,1,1,1,1),
(21,364,1,1,1,1,1,1),
(21,365,1,1,1,1,1,1),
(21,366,1,1,1,1,1,1),
(21,367,1,1,1,1,1,1),
(21,368,1,1,1,1,1,1),
(21,369,1,1,1,1,1,1),
(21,370,1,1,1,1,1,1),
(21,371,1,1,1,1,1,1),
(21,372,1,1,1,1,1,1),
(22,350,1,0,0,0,1,0),
(22,361,1,1,1,0,1,0),
(22,362,1,0,1,0,1,0),
(23,349,1,0,0,0,1,0),
(23,350,1,0,0,0,1,0),
(23,361,1,1,1,0,1,0),
(23,362,1,0,1,0,1,0),
(23,363,1,0,1,0,1,0),
(24,1,1,0,0,0,0,0),
(24,302,1,0,0,0,1,0),
(24,303,1,0,0,0,1,0),
(24,304,1,0,1,0,1,0),
(24,305,1,1,1,0,1,0),
(24,306,1,0,0,0,1,0),
(24,307,1,1,1,0,1,0),
(24,309,1,0,0,0,1,0),
(24,318,1,0,0,0,1,0),
(24,320,1,0,0,0,1,0),
(24,323,1,0,0,0,1,0),
(24,325,1,1,0,0,1,0),
(24,326,1,0,0,0,1,0),
(24,351,1,0,0,0,1,0),
(24,363,1,1,1,0,1,0),
(24,378,1,1,1,0,1,0),
(25,1,1,0,0,0,0,0),
(25,96,1,1,0,0,1,0),
(25,98,1,1,1,0,1,0),
(25,111,1,0,0,0,1,0),
(25,112,1,1,1,0,1,0),
(25,113,1,1,1,0,1,0),
(25,114,1,1,1,0,1,0),
(25,117,1,1,1,0,1,0),
(25,118,1,1,1,0,1,0),
(25,121,1,0,0,0,1,0),
(25,124,1,0,0,0,1,0),
(25,125,1,0,0,0,1,0),
(25,126,1,0,0,0,1,0),
(25,127,1,0,0,0,1,0),
(25,128,1,0,0,0,1,0),
(25,129,1,0,0,0,1,0),
(25,130,1,0,0,0,1,0),
(25,131,1,0,0,0,1,0),
(25,132,1,0,0,0,1,0),
(25,133,1,0,0,0,1,0),
(25,134,1,0,0,0,1,0),
(25,135,1,0,0,0,1,0),
(25,136,1,0,0,0,1,0),
(25,137,1,0,0,0,1,0),
(25,138,1,0,0,0,1,0),
(25,139,1,0,0,0,1,0),
(25,140,1,0,0,0,1,0),
(25,141,1,0,0,0,1,0),
(25,142,1,0,0,0,1,0),
(25,362,1,0,0,0,1,0),
(25,365,1,1,1,1,1,0),
(25,370,1,0,0,0,1,0),
(25,372,1,0,0,0,1,0),
(26,305,1,1,0,0,1,0),
(26,308,1,0,0,0,1,0);

-- role_special_permissions (104 rows)
INSERT INTO `role_special_permissions` (`role_id`,`perm_key`,`perm_value`) VALUES
(2,'closed_period_changes','0'),
(2,'coa_changes','1'),
(2,'credit_limit_override','0'),
(2,'design_reports','1'),
(2,'doc_reissue','1'),
(2,'edit_posted_documents','1'),
(2,'isp_max_adjustment',''),
(2,'isp_post_billing','1'),
(2,'isp_reverse_payment','1'),
(2,'isp_write_off','1'),
(2,'max_amount',''),
(2,'max_discount_pct','0'),
(2,'max_price_override_pct','0'),
(2,'view_bank_balances','1'),
(2,'view_costs','1'),
(3,'credit_limit_override','0'),
(3,'max_amount','50000'),
(3,'max_discount_pct','10'),
(3,'max_price_override_pct','5'),
(3,'view_bank_balances','0'),
(3,'view_costs','0'),
(4,'max_amount','100000'),
(4,'view_bank_balances','0'),
(4,'view_costs','1'),
(5,'max_amount','20000'),
(5,'view_costs','0'),
(6,'max_discount_pct','5'),
(6,'max_price_override_pct','0'),
(6,'view_costs','0'),
(7,'max_amount','0'),
(7,'max_discount_pct','5'),
(7,'max_price_override_pct','0'),
(7,'view_costs','0'),
(8,'max_amount','20000'),
(8,'max_discount_pct','30'),
(8,'max_price_override_pct','20'),
(8,'view_costs','0'),
(9,'isp_view_sensitive','0'),
(9,'view_bank_balances','1'),
(9,'view_costs','1'),
(10,'closed_period_changes','0'),
(10,'coop_all_branches','1'),
(10,'coop_backdate','1'),
(10,'coop_max_release',''),
(10,'coop_max_withdrawal',''),
(10,'coop_override_allocation','1'),
(10,'coop_view_confidential','1'),
(10,'coop_waive','1'),
(10,'doc_reissue','1'),
(10,'edit_posted_documents','1'),
(10,'isp_disconnect','1'),
(10,'isp_exempt','1'),
(10,'isp_max_adjustment',''),
(10,'isp_network_admin','1'),
(10,'isp_post_billing','1'),
(10,'isp_reconnect','1'),
(10,'isp_reverse_payment','1'),
(10,'isp_view_sensitive','1'),
(10,'isp_write_off','1'),
(10,'max_amount',''),
(10,'party_merge','1'),
(11,'closed_period_changes','0'),
(11,'coop_all_branches','1'),
(11,'coop_backdate','1'),
(11,'coop_max_withdrawal','50000'),
(11,'doc_reissue','1'),
(12,'closed_period_changes','0'),
(12,'coop_max_release','100000'),
(12,'coop_override_allocation','1'),
(12,'coop_view_confidential','1'),
(13,'closed_period_changes','0'),
(13,'coop_all_branches','1'),
(13,'coop_view_confidential','1'),
(13,'coop_waive','1'),
(14,'closed_period_changes','0'),
(14,'coop_max_release','100000'),
(14,'coop_max_withdrawal','50000'),
(15,'closed_period_changes','0'),
(15,'coop_view_confidential','1'),
(16,'closed_period_changes','0'),
(17,'closed_period_changes','0'),
(17,'coop_backdate','1'),
(17,'coop_max_release',''),
(17,'coop_max_withdrawal',''),
(17,'coop_override_allocation','1'),
(17,'coop_view_confidential','1'),
(17,'coop_waive','1'),
(17,'isp_disconnect','1'),
(17,'isp_exempt','1'),
(17,'isp_max_adjustment','20000'),
(17,'isp_post_billing','1'),
(17,'isp_reconnect','1'),
(17,'isp_reverse_payment','1'),
(17,'isp_view_sensitive','1'),
(17,'isp_write_off','1'),
(17,'max_amount',''),
(17,'party_merge','1'),
(18,'isp_max_adjustment','1000'),
(20,'isp_disconnect','1'),
(20,'isp_network_admin','1');
INSERT INTO `role_special_permissions` (`role_id`,`perm_key`,`perm_value`) VALUES
(20,'isp_reconnect','1'),
(20,'isp_view_sensitive','1'),
(21,'isp_reconnect','1'),
(25,'view_costs','1');

-- audit_trail (19 rows)
INSERT INTO `audit_trail` (`id`,`created_at`,`user_id`,`username`,`user_roles`,`ip_address`,`workstation`,`site_id`,`terminal_id`,`module`,`page_key`,`action`,`record_type`,`record_id`,`record_ref`,`amount`,`changes`,`snapshot`,`reason`,`approved_by`) VALUES
(1,'2026-11-02 00:20:21',NULL,'system','Database migration','127.0.0.1','SQL migration',NULL,NULL,'admin','admin.page_access','access_change','page',NULL,'Cooperative Management modules',NULL,NULL,NULL,'Migration 2026_11_01: Cooperative, Savings & Deposits, Loans, Teller, Year-end Distribution and CDA Compliance modules added with their tables, accounts, roles and settings',NULL),
(2,'2026-11-02 00:20:22',1,'admin','Company Administrator','cli','CLI',NULL,NULL,'admin',NULL,'create','user',11,'agent1',NULL,NULL,NULL,'User account of the Sales Agent role',NULL),
(3,'2026-11-02 00:20:22',1,'admin','Company Administrator','cli','CLI',NULL,NULL,'admin',NULL,'create','user',12,'installer1',NULL,NULL,NULL,'User account of the Installation Technician role',NULL),
(4,'2026-11-02 00:20:22',1,'admin','Company Administrator','cli','CLI',NULL,NULL,'admin',NULL,'create','user',13,'maintech1',NULL,NULL,NULL,'User account of the Maintenance Technician role',NULL),
(5,'2026-11-02 00:20:22',1,'admin','Company Administrator','cli','CLI',NULL,NULL,'admin',NULL,'create','user',14,'collector1',NULL,NULL,NULL,'User account of the Collector role',NULL),
(6,'2026-11-02 00:20:22',1,'admin','Company Administrator','cli','CLI',NULL,NULL,'admin',NULL,'create','user',15,'teller1',NULL,NULL,NULL,'User account of the Teller role',NULL),
(7,'2026-11-02 00:20:22',1,'admin','Company Administrator','cli','CLI',NULL,NULL,'admin',NULL,'create','user',16,'csr1',NULL,NULL,NULL,'User account of the Customer Service Representative role',NULL),
(8,'2026-11-02 00:20:23',1,'admin','Company Administrator','cli','CLI',NULL,NULL,'admin',NULL,'create','user',17,'custodian1',NULL,NULL,NULL,'User account of the Inventory Custodian role',NULL),
(9,'2026-11-02 00:20:23',1,'admin','Company Administrator','cli','CLI',NULL,NULL,'admin',NULL,'create','user',18,'membership',NULL,NULL,NULL,'User account of the Membership Officer role',NULL),
(10,'2026-11-02 00:20:23',1,'admin','Company Administrator','cli','CLI',NULL,NULL,'admin',NULL,'create','user',19,'collections1',NULL,NULL,NULL,'User account of the Collection Officer role',NULL),
(11,'2026-11-02 00:20:23',1,'admin','Company Administrator','cli','CLI',NULL,NULL,'admin',NULL,'create','user',20,'billing1',NULL,NULL,NULL,'User account of the Billing Officer role',NULL),
(12,'2026-11-02 00:20:23',1,'admin','Company Administrator','cli','CLI',NULL,NULL,'admin',NULL,'create','user',21,'loanofficer',NULL,NULL,NULL,'User account of the Loan Officer role',NULL),
(13,'2026-11-02 00:20:23',1,'admin','Company Administrator','cli','CLI',NULL,NULL,'admin',NULL,'create','user',22,'creditcom',NULL,NULL,NULL,'User account of the Credit Committee role',NULL),
(14,'2026-11-02 00:20:23',1,'admin','Company Administrator','cli','CLI',NULL,NULL,'admin',NULL,'create','user',23,'bookkeeper',NULL,NULL,NULL,'User account of the Bookkeeper role',NULL),
(15,'2026-11-02 00:20:23',1,'admin','Company Administrator','cli','CLI',NULL,NULL,'admin',NULL,'create','user',24,'netadmin1',NULL,NULL,NULL,'User account of the Network Administrator role',NULL),
(16,'2026-11-02 00:20:23',1,'admin','Company Administrator','cli','CLI',NULL,NULL,'admin',NULL,'create','user',25,'techsup1',NULL,NULL,NULL,'User account of the Technical Supervisor role',NULL),
(17,'2026-11-02 00:20:23',1,'admin','Company Administrator','cli','CLI',NULL,NULL,'admin',NULL,'create','user',26,'branchmgr',NULL,NULL,NULL,'User account of the Branch Manager role',NULL),
(18,'2026-11-02 00:20:23',1,'admin','Company Administrator','cli','CLI',NULL,NULL,'admin',NULL,'create','user',27,'manager',NULL,NULL,NULL,'User account of the General Manager role',NULL),
(19,'2026-11-02 00:20:23',1,'admin','Company Administrator','cli','CLI',NULL,NULL,'admin','admin.multi_company','multi_company_setup','company',1,'Your Cooperative Name',NULL,NULL,NULL,'Registered as Company 1 of the platform. Company Owner: admin. Super Admin: superadmin.',NULL);

-- sequences (61 rows)
INSERT INTO `sequences` (`seq_key`,`prefix`,`next_no`,`pad`) VALUES
('adjustment','ADJ-',1,5),
('advance_apply','AAP-',1,5),
('asset','FA-',1,5),
('balance_certificate','BC-',1,6),
('bill','BILL-',1,5),
('build','BLD-',1,5),
('cash_voucher','CAV-',1,5),
('check','',1001,4),
('coop_cv','MCV-',1,6),
('coop_jv','CJV-',1,6),
('coop_or','',1,6),
('credit_memo','CM-',1,5),
('debit_memo','DM-',1,5),
('deposit','DEP-',1,5),
('dist_batch','DIST-',1,5),
('estimate','EST-',1,5),
('finance_charge','FC-',1,5),
('inv_transfer','IT-',1,5),
('invoice','INV-',1,5),
('isp_account','',1000001,7),
('isp_adjustment','IAJ-',1,6),
('isp_application','APP-',1,6),
('isp_arrangement','PA-',1,5),
('isp_contract','CT-',1,6),
('isp_customer','CUS-',1,6),
('isp_disc','DQ-',1,6),
('isp_dispute','DSP-',1,5),
('isp_invoice','SI-',1,7),
('isp_netop','NOP-',1,7),
('isp_outage','OUT-',1,5),
('isp_receipt','CR-',1,7),
('isp_recon','RCN-',1,6),
('isp_request','REQ-',1,6),
('isp_run','BR-',1,5),
('isp_shift','SH-',1,6),
('isp_ticket','TKT-',1,6),
('isp_wo','WO-',1,6),
('item_receipt','IR-',1,5),
('journal','JE-',1,5),
('loan','',1,7),
('loan_application','LA-',1,6),
('loan_release','LR-',1,6),
('maintenance','MNT-',1,5),
('member','',100001,6),
('party','P-',17,6),
('passbook','',1,6),
('payment','PMT-',1,5),
('pn','',1,5),
('pos','POS-',1,6),
('processing','PRC-',1,5),
('purchase_order','PO-',1,5),
('sales_order','SO-',1,5),
('sales_receipt','SR-',1,5),
('sav_account','',1,6),
('sav_batch','BAT-',1,5),
('sav_trans','',1,7),
('share_certificate','SC-',1,6),
('statement_charge','SC-',1,5),
('td_certificate','TD-',1,6),
('transfer','TRF-',1,5),
('vendor_advance','ADV-',1,5);

-- accounts (129 rows)
INSERT INTO `accounts` (`id`,`code`,`name`,`account_type`,`type_id`,`category`,`normal_balance`,`parent_id`,`description`,`bank_account_no`,`with_sl`,`is_active`,`is_system`,`created_at`,`updated_at`,`is_interunit`) VALUES
(1,'1000','Cash','Bank',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(2,'1001','Cash on Hand - Store Registers','Bank',NULL,'Asset','Debit',1,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(3,'1005','Petty Cash Fund','Bank',NULL,'Asset','Debit',1,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(4,'1006','Revolving Fund','Bank',NULL,'Asset','Debit',1,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(5,'1010','Cash in Bank - BDO Checking','Bank',NULL,'Asset','Debit',1,NULL,'0012-3456-7890',0,1,0,'2026-11-02 00:20:21',NULL,0),
(6,'1020','Cash in Bank - BPI Savings','Bank',NULL,'Asset','Debit',1,NULL,'1234-5678-90',0,1,0,'2026-11-02 00:20:21',NULL,0),
(7,'1050','Undeposited Funds','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,1,'2026-11-02 00:20:21',NULL,0),
(8,'1060','Card Clearing','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(9,'1065','E-wallet Clearing','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(10,'1070','P.O. Card Clearing','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(11,'1100','Accounts Receivable','Accounts Receivable',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,1,'2026-11-02 00:20:21',NULL,0),
(12,'1150','Allowance for Doubtful Accounts','Other Current Asset',NULL,'Asset','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(13,'1200','Inventory','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,1,'2026-11-02 00:20:21',NULL,0),
(14,'1300','Input Tax','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,1,'2026-11-02 00:20:21',NULL,0),
(15,'1310','Creditable Withholding Tax','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(16,'1315','Creditable VAT Withheld','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(17,'1400','Prepaid Expenses','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(18,'1410','Advances to Suppliers','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(19,'1500','Land','Fixed Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(20,'1510','Building','Fixed Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(21,'1515','Accumulated Depreciation - Building','Fixed Asset',NULL,'Asset','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(22,'1520','Machinery and Equipment','Fixed Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(23,'1525','Accumulated Depreciation - Machinery','Fixed Asset',NULL,'Asset','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(24,'1530','Transportation Equipment','Fixed Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(25,'1535','Accumulated Depreciation - Transportation','Fixed Asset',NULL,'Asset','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(26,'1540','Furniture and Fixtures','Fixed Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(27,'1545','Accumulated Depreciation - Furniture','Fixed Asset',NULL,'Asset','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(28,'1550','Computer and POS Equipment','Fixed Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(29,'1555','Accumulated Depreciation - Computer and POS','Fixed Asset',NULL,'Asset','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(30,'1600','Security Deposits','Other Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(31,'2000','Accounts Payable','Accounts Payable',NULL,'Liability','Credit',NULL,NULL,NULL,0,1,1,'2026-11-02 00:20:21',NULL,0),
(32,'2050','Company Credit Card','Credit Card',NULL,'Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(33,'2100','Output Tax','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,0,1,1,'2026-11-02 00:20:21',NULL,0),
(34,'2110','Withholding Tax Payable','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(35,'2115','VAT Withheld Payable','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(36,'2120','Gift Vouchers Outstanding','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(37,'2130','Customer Deposits','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(38,'2200','Accrued Expenses','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(39,'2500','Loans Payable','Long Term Liability',NULL,'Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(40,'3000','Owner\'s Capital','Equity',NULL,'Equity','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(41,'3100','Owner\'s Drawings','Equity',NULL,'Equity','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(42,'3200','Retained Earnings','Equity',NULL,'Equity','Credit',NULL,NULL,NULL,0,1,1,'2026-11-02 00:20:21',NULL,0),
(43,'3900','Opening Balance Equity','Equity',NULL,'Equity','Credit',NULL,NULL,NULL,0,1,1,'2026-11-02 00:20:21',NULL,0),
(44,'4000','Sales','Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(45,'4010','Service Income','Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(46,'4020','Delivery Income','Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(47,'4050','Sales Returns and Allowances','Income',NULL,'Income','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(48,'4060','Sales Discounts','Income',NULL,'Income','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(49,'4110','Reimbursable Expense Income','Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(50,'4100','Finance Charge Income','Other Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(51,'4200','Interest Income','Other Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(52,'4300','Gain (Loss) on Disposal of Assets','Other Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(53,'5000','Cost of Goods Sold','Cost of Goods Sold',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(54,'5050','Purchase Discounts','Cost of Goods Sold',NULL,'Expense','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(55,'5100','Inventory Adjustments and Shrinkage','Cost of Goods Sold',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(56,'5200','Direct Labor Absorbed','Cost of Goods Sold',NULL,'Expense','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(57,'6000','Salaries and Wages','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(58,'6010','Rent Expense','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(59,'6020','Utilities Expense','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(60,'6030','Repairs and Maintenance','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(61,'6040','Office Supplies Expense','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(62,'6050','Fuel and Oil','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(63,'6060','Meals and Representation','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(64,'6070','Professional Fees','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(65,'6080','Bank and Merchant Charges','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(66,'6090','Depreciation Expense','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(67,'6100','Freight and Delivery','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(68,'6110','Purchases - Non-inventory','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(69,'6200','Cash Over and Short','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(70,'6900','Miscellaneous Expense','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(71,'7000','Interest Expense','Other Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(72,'1110','Checks and Other Cash Items','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(73,'1210','Loans Receivable - Current','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,1,1,0,'2026-11-02 00:20:21',NULL,0),
(74,'1211','Loans Receivable - Past Due','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,1,1,0,'2026-11-02 00:20:21',NULL,0),
(75,'1212','Loans Receivable - Restructured','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,1,1,0,'2026-11-02 00:20:21',NULL,0),
(76,'1213','Loans Receivable - Loans in Litigation','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(77,'1215','Allowance for Probable Losses on Loans','Other Current Asset',NULL,'Asset','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(78,'1220','Interest Receivable on Loans','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,1,1,0,'2026-11-02 00:20:21',NULL,0),
(79,'1230','Payroll Deductions Receivable','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(80,'2300','Savings Deposits','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,1,1,0,'2026-11-02 00:20:21',NULL,0),
(81,'2305','Special Savings Deposits','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(82,'2310','Time Deposits','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,1,1,0,'2026-11-02 00:20:21',NULL,0),
(83,'2320','Accrued Interest Payable on Deposits','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(84,'2330','Interest on Share Capital Payable','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,1,1,0,'2026-11-02 00:20:21',NULL,0),
(85,'2340','Patronage Refund Payable','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,1,1,0,'2026-11-02 00:20:21',NULL,0),
(86,'2350','Due to Union / Federation (CETF)','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(87,'2360','Loan Insurance Payable','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(88,'2370','Unclaimed Member Distributions','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,1,1,0,'2026-11-02 00:20:21',NULL,0),
(89,'3010','Paid-up Share Capital - Common','Equity',NULL,'Equity','Credit',NULL,NULL,NULL,1,1,0,'2026-11-02 00:20:21',NULL,0),
(90,'3020','Paid-up Share Capital - Preferred','Equity',NULL,'Equity','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(91,'3300','Reserve Fund','Equity',NULL,'Equity','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(92,'3310','Education and Training Fund','Equity',NULL,'Equity','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(93,'3320','Community Development Fund','Equity',NULL,'Equity','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(94,'3330','Optional Fund','Equity',NULL,'Equity','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(95,'3340','Undivided Net Surplus','Equity',NULL,'Equity','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(96,'4400','Interest Income on Loans','Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(97,'4410','Service Fees on Loans','Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(98,'4420','Filing Fees','Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(99,'4430','Fines, Penalties and Surcharges','Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(100,'4440','Membership Fees','Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0);
INSERT INTO `accounts` (`id`,`code`,`name`,`account_type`,`type_id`,`category`,`normal_balance`,`parent_id`,`description`,`bank_account_no`,`with_sl`,`is_active`,`is_system`,`created_at`,`updated_at`,`is_interunit`) VALUES
(101,'4450','Pre-termination Fees','Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(102,'4460','Dormancy Fees','Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(103,'4470','Other Cooperative Income','Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(104,'4480','Recovery of Loans Written Off','Other Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(105,'7100','Interest Expense on Savings Deposits','Other Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(106,'7110','Interest Expense on Time Deposits','Other Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(107,'7120','Provision for Probable Losses on Loans','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-02 00:20:21',NULL,0),
(135,'1105','Accounts Receivable - Subscribers','Accounts Receivable',NULL,'Asset','Debit',NULL,'Cable & Internet (ISP module)',NULL,0,1,1,'2026-11-02 00:20:22',NULL,0),
(136,'1003','Cash on Hand - Subscriber Collections','Bank',NULL,'Asset','Debit',NULL,'Cable & Internet (ISP module)',NULL,0,1,0,'2026-11-02 00:20:22',NULL,0),
(137,'1052','Bank Deposits and Transfers Clearing','Other Current Asset',NULL,'Asset','Debit',NULL,'Cable & Internet (ISP module)',NULL,0,1,0,'2026-11-02 00:20:22',NULL,0),
(138,'1066','GCash / Maya / Gateway Clearing','Other Current Asset',NULL,'Asset','Debit',NULL,'Cable & Internet (ISP module)',NULL,0,1,0,'2026-11-02 00:20:22',NULL,0),
(139,'1067','Savings Auto-Debit Clearing','Other Current Asset',NULL,'Asset','Debit',NULL,'Cable & Internet (ISP module)',NULL,0,1,0,'2026-11-02 00:20:22',NULL,0),
(140,'2160','Subscriber Security Deposits','Other Current Liability',NULL,'Liability','Credit',NULL,'Cable & Internet (ISP module)',NULL,0,1,1,'2026-11-02 00:20:22',NULL,0),
(141,'4600','Internet Service Revenue','Income',NULL,'Income','Credit',NULL,'Cable & Internet (ISP module)',NULL,0,1,0,'2026-11-02 00:20:22',NULL,0),
(142,'4605','Cable TV Service Revenue','Income',NULL,'Income','Credit',NULL,'Cable & Internet (ISP module)',NULL,0,1,0,'2026-11-02 00:20:22',NULL,0),
(143,'4610','Installation and Activation Revenue','Income',NULL,'Income','Credit',NULL,'Cable & Internet (ISP module)',NULL,0,1,0,'2026-11-02 00:20:22',NULL,0),
(144,'4620','Equipment Rental and Add-on Revenue','Income',NULL,'Income','Credit',NULL,'Cable & Internet (ISP module)',NULL,0,1,0,'2026-11-02 00:20:22',NULL,0),
(145,'4630','Late Payment Charges','Income',NULL,'Income','Credit',NULL,'Cable & Internet (ISP module)',NULL,0,1,0,'2026-11-02 00:20:22',NULL,0),
(146,'4640','Reconnection Fees','Income',NULL,'Income','Credit',NULL,'Cable & Internet (ISP module)',NULL,0,1,0,'2026-11-02 00:20:22',NULL,0),
(147,'4650','Other Subscriber Charges','Income',NULL,'Income','Credit',NULL,'Cable & Internet (ISP module)',NULL,0,1,0,'2026-11-02 00:20:22',NULL,0),
(148,'4660','Subscriber Discounts and Promotions','Income',NULL,'Income','Debit',NULL,'Cable & Internet (ISP module)',NULL,0,1,0,'2026-11-02 00:20:22',NULL,0),
(149,'4670','Service Credits - Outages and Adjustments','Income',NULL,'Income','Debit',NULL,'Cable & Internet (ISP module)',NULL,0,1,0,'2026-11-02 00:20:22',NULL,0),
(150,'5300','Installation Materials and Cost of Service','Cost of Goods Sold',NULL,'Expense','Debit',NULL,'Cable & Internet (ISP module)',NULL,0,1,0,'2026-11-02 00:20:22',NULL,0),
(151,'5310','Network Repairs and Maintenance Materials','Cost of Goods Sold',NULL,'Expense','Debit',NULL,'Cable & Internet (ISP module)',NULL,0,1,0,'2026-11-02 00:20:22',NULL,0),
(152,'5320','Field Labor and Travel','Cost of Goods Sold',NULL,'Expense','Debit',NULL,'Cable & Internet (ISP module)',NULL,0,1,0,'2026-11-02 00:20:22',NULL,0),
(153,'6085','Payment Gateway Fees','Expense',NULL,'Expense','Debit',NULL,'Cable & Internet (ISP module)',NULL,0,1,0,'2026-11-02 00:20:22',NULL,0),
(154,'6205','Cashier Over and Short - Subscriber Collections','Expense',NULL,'Expense','Debit',NULL,'Cable & Internet (ISP module)',NULL,0,1,0,'2026-11-02 00:20:22',NULL,0),
(155,'6300','Bad Debts - Subscriber Write-offs','Expense',NULL,'Expense','Debit',NULL,'Cable & Internet (ISP module)',NULL,0,1,0,'2026-11-02 00:20:22',NULL,0),
(156,'6310','Sales Agent Commissions','Expense',NULL,'Expense','Debit',NULL,'Cable & Internet (ISP module)',NULL,0,1,0,'2026-11-02 00:20:22',NULL,0);

-- account_types (15 rows)
INSERT INTO `account_types` (`id`,`name`,`base_type`,`cash_flow`,`sort_order`,`description`,`is_system`,`is_active`,`created_at`,`updated_at`) VALUES
(1,'Bank','Bank','cash',10,'Cash on hand and in banks',1,1,'2026-11-02 00:20:21',NULL),
(2,'Accounts Receivable','Accounts Receivable','operating',20,'What customers owe on invoices',1,1,'2026-11-02 00:20:21',NULL),
(3,'Other Current Asset','Other Current Asset','operating',30,'Loans and advances, prepaid expenses, inventory, clearing accounts',1,1,'2026-11-02 00:20:21',NULL),
(4,'Fixed Asset','Fixed Asset','investing',40,'Property and equipment and their accumulated depreciation',1,1,'2026-11-02 00:20:21',NULL),
(5,'Other Asset','Other Asset','investing',50,'Investments, deposits and other long-term assets',1,1,'2026-11-02 00:20:21',NULL),
(6,'Accounts Payable','Accounts Payable','operating',60,'What is owed to suppliers on bills',1,1,'2026-11-02 00:20:21',NULL),
(7,'Credit Card','Credit Card','operating',70,'Credit card balances',1,1,'2026-11-02 00:20:21',NULL),
(8,'Other Current Liability','Other Current Liability','operating',80,'Deposits, taxes, accruals and other debts due within a year',1,1,'2026-11-02 00:20:21',NULL),
(9,'Long Term Liability','Long Term Liability','financing',90,'Debts due after a year',1,1,'2026-11-02 00:20:21',NULL),
(10,'Equity','Equity','financing',100,'Capital, share capital, reserves, retained earnings',1,1,'2026-11-02 00:20:21',NULL),
(11,'Income','Income','',110,'Income from the main business',1,1,'2026-11-02 00:20:21',NULL),
(12,'Other Income','Other Income','',120,'Income outside the main business',1,1,'2026-11-02 00:20:21',NULL),
(13,'Cost of Goods Sold','Cost of Goods Sold','',130,'The cost of what was sold',1,1,'2026-11-02 00:20:21',NULL),
(14,'Expense','Expense','',140,'The costs of running the business',1,1,'2026-11-02 00:20:21',NULL),
(15,'Other Expense','Other Expense','',150,'Expenses outside the operations',1,1,'2026-11-02 00:20:21',NULL);

-- default_accounts (85 rows)
INSERT INTO `default_accounts` (`setting_key`,`label`,`module`,`account_id`) VALUES
('ap','Accounts Payable','vend',31),
('ar','Accounts Receivable','cust',11),
('card_clearing','Card Clearing','pos',8),
('cash_on_hand','Cash on Hand (Register)','pos',2),
('cash_over_short','Cash Over and Short','pos',69),
('cdf_fund','Community Development Fund','coop',93),
('cogs','Cost of Goods Sold','inv',53),
('coop_cash','Teller Cash on Hand (default)','coop',2),
('coop_coci','Checks and Other Cash Items (clearing)','coop',72),
('coop_insurance_payable','Loan Insurance Payable','coop',87),
('coop_other_income','Other Cooperative Income','coop',103),
('cvat_withheld','Creditable VAT Withheld','cust',16),
('cwt','Creditable Withholding Tax','cust',15),
('default_expense','Default Purchases / Expense','vend',68),
('deposit_interest_payable','Accrued Interest Payable on Deposits','coop',83),
('dormancy_fee_income','Dormancy Fees','coop',102),
('education_fund','Education and Training Fund','coop',92),
('education_fund_apex','Due to Union / Federation (CETF)','coop',86),
('ewallet_clearing','E-wallet Clearing','pos',9),
('finance_charge_income','Finance Charge Income','cust',50),
('gain_loss_disposal','Gain / Loss on Disposal of Assets','fa',52),
('input_tax','Input Tax','vend',14),
('interest_income','Interest Income','bank',51),
('inventory_adjustment','Inventory Adjustment (Shrinkage)','inv',55),
('inventory_asset','Inventory Asset','inv',13),
('isc_payable','Interest on Share Capital Payable','coop',84),
('isp_ar','Accounts Receivable - Subscribers','isp',135),
('isp_autodebit_clearing','Savings Auto-Debit Clearing','isp',139),
('isp_bad_debts','Bad Debts - Subscriber Write-offs','isp',155),
('isp_bank_clearing','Bank Deposits and Transfers Clearing (unverified collections)','isp',137),
('isp_cash','Cashier Cash on Hand - Subscriber Collections','isp',136),
('isp_cash_over_short','Cashier Over and Short','isp',154),
('isp_catv_revenue','Cable TV Service Revenue','isp',142),
('isp_commission_expense','Sales Agent Commissions','isp',156),
('isp_deposits','Subscriber Security Deposits (liability)','isp',140),
('isp_discounts','Subscriber Discounts and Promotions','isp',148),
('isp_ewallet_clearing','GCash / Maya / Gateway Clearing','isp',138),
('isp_gateway_fees','Payment Gateway Fees','isp',153),
('isp_install_expense','Installation Materials and Cost of Service','isp',150),
('isp_install_revenue','Installation and Activation Revenue','isp',143),
('isp_labor_expense','Field Labor and Travel','isp',152),
('isp_late_fee_revenue','Late Payment Charges','isp',145),
('isp_maint_expense','Network Repairs and Maintenance Materials','isp',151),
('isp_other_revenue','Other Subscriber Charges','isp',147),
('isp_reconnect_revenue','Reconnection Fees','isp',146),
('isp_rental_revenue','Equipment Rental Revenue (router, static IP, add-ons)','isp',144),
('isp_revenue','Internet Service Revenue','isp',141),
('isp_service_credits','Service Credits (outages and adjustments)','isp',149),
('loan_interest_income','Interest Income on Loans','coop',96),
('loan_interest_receivable','Interest Receivable on Loans','coop',78),
('loan_loss_allowance','Allowance for Probable Losses on Loans','coop',77),
('loan_loss_provision','Provision for Probable Losses on Loans','coop',107),
('loan_penalty_income','Fines, Penalties and Surcharges','coop',99),
('loan_recovery_income','Recovery of Loans Written Off','coop',104),
('loan_service_fee','Service Fees on Loans','coop',97),
('loans_past_due','Loans Receivable - Past Due','coop',74),
('loans_receivable','Loans Receivable - Current','coop',73),
('loans_restructured','Loans Receivable - Restructured','coop',75),
('membership_fee_income','Membership Fees','coop',100),
('merchant_fees','Bank / Merchant Charges','bank',65),
('net_surplus','Undivided Net Surplus','coop',95),
('opening_equity','Opening Balance Equity','coa',43),
('optional_fund','Optional Fund','coop',94),
('output_tax','Output Tax','cust',33),
('pr_payable','Patronage Refund Payable','coop',85),
('preterm_fee_income','Pre-termination Fees','coop',101),
('processing_absorbed','Processing Labor & Overhead Absorbed','inv',56),
('processing_variance','Processing / Disassembly Variance','inv',55),
('purchase_discounts','Purchase Discounts','vend',54),
('reimbursable_income','Reimbursable Expense Income','cust',49),
('reserve_fund','Reserve Fund','coop',91),
('retained_earnings','Retained Earnings','coa',42),
('sales_discounts','Sales Discounts','cust',48),
('sales_income','Default Sales Income','cust',44),
('sales_returns','Sales Returns and Allowances','cust',47),
('savings_deposits','Savings Deposits (default liability)','coop',80),
('savings_interest_expense','Interest Expense on Savings Deposits','coop',105),
('share_capital','Paid-up Share Capital - Common','coop',89),
('td_interest_expense','Interest Expense on Time Deposits','coop',106),
('time_deposits','Time Deposits','coop',82),
('unclaimed_dist','Unclaimed Member Distributions','coop',88),
('undeposited_funds','Undeposited Funds','cust',7),
('vat_withheld_payable','VAT Withheld Payable','vend',35),
('vendor_advances','Advances to Suppliers','vend',18),
('wht_payable','Withholding Tax Payable (Expanded)','vend',34);

-- terms (5 rows)
INSERT INTO `terms` (`id`,`name`,`net_days`,`discount_pct`,`discount_days`,`is_active`) VALUES
(1,'Due on receipt',0,'0.00',0,1),
(2,'Net 15',15,'0.00',0,1),
(3,'Net 30',30,'0.00',0,1),
(4,'2% 10 Net 30',30,'2.00',10,1),
(5,'Net 60',60,'0.00',0,1);

-- tax_codes (4 rows)
INSERT INTO `tax_codes` (`id`,`code`,`name`,`rate`,`sales_account_id`,`purchase_account_id`,`is_active`) VALUES
(1,'VAT12','VAT 12%','12.000',33,14,1),
(2,'ZR','Zero-rated','0.000',33,14,1),
(3,'EX','VAT-exempt','0.000',33,14,1),
(4,'NT','Non-taxable','0.000',33,14,1);

-- payment_methods (9 rows)
INSERT INTO `payment_methods` (`id`,`name`,`method_type`,`clearing_account_id`,`is_active`,`show_in_pos`) VALUES
(1,'Cash','cash',NULL,1,1),
(2,'Check','check',NULL,1,0),
(3,'Credit/Debit Card','card',8,1,1),
(4,'GCash','ewallet',9,1,1),
(5,'Maya','ewallet',9,1,1),
(6,'Bank Transfer','bank_transfer',NULL,1,0),
(7,'Gift Voucher','voucher',36,1,1),
(8,'P.O. Card','card',10,1,1),
(9,'Bank','bank_transfer',5,1,1);

-- price_levels (10 rows)
INSERT INTO `price_levels` (`id`,`name`,`adjust_pct`,`valid_from`,`valid_to`,`is_active`) VALUES
(1,'Wholesale','-5.00',NULL,NULL,1),
(2,'VIP','-10.00',NULL,NULL,1),
(3,'Retail Plus','5.00',NULL,NULL,1),
(4,'Price Level 4','0.00',NULL,NULL,1),
(5,'Price Level 5','0.00',NULL,NULL,1),
(6,'Price Level 6','0.00',NULL,NULL,1),
(7,'Price Level 7','0.00',NULL,NULL,1),
(8,'Price Level 8','0.00',NULL,NULL,1),
(9,'Price Level 9','0.00',NULL,NULL,1),
(10,'Price Level 10','0.00',NULL,NULL,1);

-- customer_types (4 rows)
INSERT INTO `customer_types` (`id`,`name`) VALUES
(1,'Corporate'),
(2,'Retail'),
(3,'School'),
(4,'Government');

-- billing_groups (1 rows)
INSERT INTO `billing_groups` (`id`,`name`) VALUES
(1,'Monthly Service Contracts');

-- sites (2 rows)
INSERT INTO `sites` (`id`,`code`,`name`,`site_type`,`address`,`is_active`) VALUES
(1,'WH','Main Warehouse and Network Stockroom','warehouse',NULL,1),
(5,'DMG','Damaged Goods','damaged',NULL,1);

-- item_categories (21 rows)
INSERT INTO `item_categories` (`id`,`name`) VALUES
(1,'Computers'),
(2,'Accessories'),
(3,'Services'),
(4,'Bundles'),
(5,'Supplies'),
(6,'Charges'),
(7,'Fiber Optic Cable'),
(8,'Coaxial Cable'),
(9,'Drop Wire'),
(10,'Connectors & Adapters'),
(11,'Splitters'),
(12,'Routers'),
(13,'ONU / ONT'),
(14,'Optical Transceivers'),
(15,'Network Switches'),
(16,'Power Supplies'),
(17,'Patch Cords'),
(18,'Tools'),
(19,'Spare Parts'),
(20,'Installation Consumables'),
(21,'Set-top Boxes');

-- uom_sets (5 rows)
INSERT INTO `uom_sets` (`id`,`name`,`uom_kind`,`base_unit`,`is_active`,`created_at`,`updated_at`) VALUES
(1,'Piece','count','pc',1,'2026-11-02 00:20:21',NULL),
(2,'Weight (kg)','weight','kg',1,'2026-11-02 00:20:21',NULL),
(3,'Volume (L)','volume','L',1,'2026-11-02 00:20:21',NULL),
(4,'Length (m)','length','m',1,'2026-11-02 00:20:21',NULL),
(5,'Time (hour)','time','hr',1,'2026-11-02 00:20:21',NULL);

-- uom_units (16 rows)
INSERT INTO `uom_units` (`id`,`set_id`,`code`,`name`,`factor`,`is_base`,`is_sales_default`,`is_purchase_default`,`sort_order`) VALUES
(1,1,'pc','Piece','1.000000',1,1,0,0),
(2,1,'dz','Dozen (12 pieces)','12.000000',0,0,0,1),
(3,1,'box','Box of 24','24.000000',0,0,1,2),
(4,1,'case','Case of 144','144.000000',0,0,0,3),
(5,2,'kg','Kilogram','1.000000',1,1,1,0),
(6,2,'g','Gram','0.001000',0,0,0,1),
(7,2,'sack','Sack of 25 kg','25.000000',0,0,0,2),
(8,3,'L','Liter','1.000000',1,1,1,0),
(9,3,'mL','Milliliter','0.001000',0,0,0,1),
(10,3,'drum','Drum of 200 L','200.000000',0,0,0,2),
(11,4,'m','Meter','1.000000',1,1,1,0),
(12,4,'cm','Centimeter','0.010000',0,0,0,1),
(13,4,'roll','Roll of 100 m','100.000000',0,0,0,2),
(14,5,'hr','Hour','1.000000',1,1,1,0),
(15,5,'day','Day (8 hours)','8.000000',0,0,0,1),
(16,5,'wk','Week (40 hours)','40.000000',0,0,0,2);

-- withholding_taxes (3 rows)
INSERT INTO `withholding_taxes` (`id`,`code`,`atc`,`name`,`tax_kind`,`rate`,`base_type`,`applies_to`,`payable_account_id`,`receivable_account_id`,`description`,`sort_order`,`is_active`,`created_at`,`updated_at`) VALUES
(1,'WT-GOODS','WC158','WT on Goods','income','1.0000','net_of_vat','both',NULL,NULL,'Goods bought from regular suppliers by government payors and top withholding agents (RR 11-2018)',10,1,'2026-11-02 00:20:21',NULL),
(2,'WT-SERVICES','WC160','WT on Services','income','2.0000','net_of_vat','both',NULL,NULL,'Services bought from regular suppliers by government payors and top withholding agents (RR 11-2018)',20,1,'2026-11-02 00:20:21',NULL),
(3,'WT-VAT','WV010','WT on VAT','vat','5.0000','net_of_vat','both',NULL,NULL,'Creditable VAT withheld by government buyers on VATable purchases (5% of the VAT-exclusive payment)',30,1,'2026-11-02 00:20:21',NULL);

-- asset_categories (6 rows)
INSERT INTO `asset_categories` (`id`,`name`,`asset_account_id`,`accum_dep_account_id`,`dep_expense_account_id`,`default_method`,`default_life_years`,`is_active`) VALUES
(1,'Buildings',20,21,66,'straight_line','25.00',1),
(2,'Machinery and Equipment',22,23,66,'straight_line','5.00',1),
(3,'Production Machinery (usage-based)',22,23,66,'units_of_production','5.00',1),
(4,'Transportation Equipment',24,25,66,'declining_balance','5.00',1),
(5,'Furniture and Fixtures',26,27,66,'straight_line','10.00',1),
(6,'Computer and POS Equipment',28,29,66,'declining_balance','3.00',1);

-- pos_terminals (1 rows)
INSERT INTO `pos_terminals` (`id`,`code`,`name`,`site_id`,`cash_account_id`,`receipt_prefix`,`next_receipt_no`,`next_z_no`,`is_active`,`class_id`) VALUES
(1,'POS-01','Counter 1',1,2,'C1-',1,1,1,NULL);

-- user_sites (2 rows)
INSERT INTO `user_sites` (`user_id`,`site_id`) VALUES
(7,1),
(8,1);

-- user_terminals (2 rows)
INSERT INTO `user_terminals` (`user_id`,`terminal_id`) VALUES
(7,1),
(8,1);

-- member_types (6 rows)
INSERT INTO `member_types` (`id`,`code`,`name`,`member_kind`,`is_regular`,`min_share_capital`,`is_active`,`sort_order`) VALUES
(1,'REG','Regular','individual',1,'0.00',1,1),
(2,'ASI','Associate - Individual','individual',0,'0.00',1,2),
(3,'ASJ','Associate - Joint','joint',0,'0.00',1,3),
(4,'ASN','Associate - Institutional','institutional',0,'0.00',1,4),
(5,'AFT','Aflatoun (youth)','individual',0,'0.00',1,5),
(6,'AFE','Aflateen (youth)','individual',0,'0.00',1,6);

-- coop_lists (33 rows)
INSERT INTO `coop_lists` (`id`,`list_key`,`name`,`extra`,`sort_order`,`is_active`) VALUES
(1,'loan_purpose','Business capital',NULL,1,1),
(2,'loan_purpose','Education',NULL,2,1),
(3,'loan_purpose','Medical / emergency',NULL,3,1),
(4,'loan_purpose','House repair / construction',NULL,4,1),
(5,'loan_purpose','Appliance / vehicle',NULL,5,1),
(6,'loan_purpose','Agricultural',NULL,6,1),
(7,'loan_purpose','Loan consolidation',NULL,7,1),
(8,'loan_purpose','Other',NULL,9,1),
(9,'collateral_type','Real estate (TCT / tax declaration)',NULL,1,1),
(10,'collateral_type','Vehicle (OR / CR)',NULL,2,1),
(11,'collateral_type','Deposit hold-out',NULL,3,1),
(12,'collateral_type','Share capital hold-out',NULL,4,1),
(13,'collateral_type','Appliance / chattel',NULL,5,1),
(14,'collateral_type','Other',NULL,9,1),
(15,'account_class','Regular',NULL,1,1),
(16,'account_class','Board of Director',NULL,2,1),
(17,'account_class','Officer',NULL,3,1),
(18,'account_class','Employee',NULL,4,1),
(19,'doc_type','Government ID',NULL,1,1),
(20,'doc_type','Photo',NULL,2,1),
(21,'doc_type','Signature card',NULL,3,1),
(22,'doc_type','Membership application form',NULL,4,1),
(23,'doc_type','Proof of address',NULL,5,1),
(24,'doc_type','PMES certificate',NULL,6,1),
(25,'doc_type','Board resolution (institutional)',NULL,7,1),
(26,'doc_type','Other',NULL,9,1),
(27,'id_type','PhilSys ID',NULL,1,1),
(28,'id_type','Driver\'s license',NULL,2,1),
(29,'id_type','Passport',NULL,3,1),
(30,'id_type','SSS / GSIS',NULL,4,1),
(31,'id_type','UMID',NULL,5,1),
(32,'id_type','Voter\'s ID',NULL,6,1),
(33,'id_type','Other',NULL,9,1);

-- passbook_types (2 rows)
INSERT INTO `passbook_types` (`id`,`name`,`description`,`ledger`,`page_width`,`page_height`,`cover_top`,`cover_left`,`header_top`,`header_left`,`footer_top`,`footer_left`,`body_top`,`body_left`,`body_interval`,`start_line_skip`,`line_skip`,`max_lines`,`columns_json`,`font_size_pt`,`is_active`,`header_json`,`cover_json`,`skip_lines`,`dash_empty`,`footer_text`,`layout_json`) VALUES
(1,'Regular Passbook','Regular savings passbook','savings',6480,10080,900,2400,700,300,8500,100,1230,200,275,12,1,26,'{\"date\":200,\"code\":1400,\"debit\":2600,\"credit\":4400,\"balance\":6200,\"teller\":8000}','9.0',1,NULL,NULL,NULL,1,NULL,'{\"cover\":[{\"f\":\"name\",\"x\":0,\"y\":0,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"number\",\"x\":0,\"y\":460,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"address\",\"x\":0,\"y\":920,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"contact\",\"x\":0,\"y\":1380,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"branch\",\"x\":0,\"y\":1840,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0}],\"header\":[{\"f\":\"name\",\"x\":160,\"y\":0,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"number\",\"x\":4430,\"y\":0,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0}],\"body\":[{\"f\":\"line\",\"x\":0,\"y\":0,\"w\":470,\"a\":\"r\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"date\",\"x\":530,\"y\":0,\"w\":0,\"a\":\"l\",\"fmt\":\"n\\/j\\/y\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"code\",\"x\":1210,\"y\":0,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"credit\",\"x\":1700,\"y\":0,\"w\":930,\"a\":\"r\",\"fmt\":\"comma\",\"z\":\"-\",\"t\":\"\",\"fs\":0},{\"f\":\"debit\",\"x\":2750,\"y\":0,\"w\":930,\"a\":\"r\",\"fmt\":\"comma\",\"z\":\"-\",\"t\":\"\",\"fs\":0},{\"f\":\"balance\",\"x\":3750,\"y\":0,\"w\":1130,\"a\":\"r\",\"fmt\":\"comma\",\"z\":\"0\",\"t\":\"\",\"fs\":0},{\"f\":\"teller\",\"x\":4950,\"y\":0,\"w\":290,\"a\":\"r\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0}],\"footer\":[{\"f\":\"text\",\"x\":0,\"y\":0,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"Balance as of {date}: {balance}\",\"fs\":0}],\"opt\":{\"header\":\"new_page\",\"footer_full\":0,\"font\":\"mono\",\"cpi\":20}}'),
(2,'Loan Passbook','Loan passbook','loan',6840,9720,900,2400,700,300,9300,100,1230,1,275,13,2,29,'{\"date\":200,\"code\":1400,\"debit\":2600,\"credit\":4400,\"balance\":6200,\"teller\":8000}','9.0',1,NULL,NULL,NULL,1,NULL,'{\"cover\":[{\"f\":\"name\",\"x\":0,\"y\":0,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"number\",\"x\":0,\"y\":460,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"address\",\"x\":0,\"y\":920,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"contact\",\"x\":0,\"y\":1380,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"branch\",\"x\":0,\"y\":1840,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0}],\"header\":[{\"f\":\"loan_type\",\"x\":700,\"y\":0,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"term\",\"x\":4220,\"y\":0,\"w\":0,\"a\":\"l\",\"fmt\":\"mos\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"due\",\"x\":5600,\"y\":0,\"w\":0,\"a\":\"l\",\"fmt\":\"n\\/j\\/y\",\"z\":\"\",\"t\":\"\",\"fs\":0}],\"body\":[{\"f\":\"line\",\"x\":0,\"y\":0,\"w\":570,\"a\":\"r\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"date\",\"x\":680,\"y\":0,\"w\":0,\"a\":\"l\",\"fmt\":\"n\\/j\\/y\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"ref\",\"x\":1340,\"y\":0,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"release\",\"x\":1900,\"y\":0,\"w\":850,\"a\":\"r\",\"fmt\":\"comma\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"prin\",\"x\":2900,\"y\":0,\"w\":730,\"a\":\"r\",\"fmt\":\"comma\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"bal\",\"x\":3800,\"y\":0,\"w\":900,\"a\":\"r\",\"fmt\":\"comma\",\"z\":\"0\",\"t\":\"\",\"fs\":0},{\"f\":\"int\",\"x\":4800,\"y\":0,\"w\":700,\"a\":\"r\",\"fmt\":\"comma\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"fines\",\"x\":5700,\"y\":0,\"w\":510,\"a\":\"r\",\"fmt\":\"comma\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"teller\",\"x\":6300,\"y\":0,\"w\":320,\"a\":\"r\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0}],\"footer\":[{\"f\":\"text\",\"x\":0,\"y\":0,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"Loan balance as of {date}: {balance}\",\"fs\":0}],\"opt\":{\"header\":\"new_page\",\"footer_full\":0,\"font\":\"mono\",\"cpi\":20}}');

-- sav_txn_codes (39 rows)
INSERT INTO `sav_txn_codes` (`id`,`code`,`description`,`opposite_kind`,`opposite_account_id`,`entry_side`,`is_check_deposit`,`clearing_days`,`is_error_correct`,`related_code`,`is_system`,`is_active`,`is_memo`) VALUES
(1,'CSD','Cash Savings Deposit','user',NULL,'credit',0,0,0,NULL,1,1,0),
(2,'CSW','Cash Savings Withdrawal','user',NULL,'debit',0,0,0,NULL,1,1,0),
(3,'CTD','Cash Time Deposit','user',NULL,'credit',0,0,0,NULL,1,1,0),
(4,'CTDW','Cash Time Deposit Withdrawal','user',NULL,'debit',0,0,0,NULL,1,1,0),
(5,'CK3','Local Check Deposit 3 Days Clearing','gl',72,'credit',1,3,0,NULL,0,1,0),
(6,'CK4','Local Check Deposit 4 Days Clearing','gl',72,'credit',1,4,0,NULL,0,1,0),
(7,'CK5','Local Check Deposit 5 Days Clearing','gl',72,'credit',1,5,0,NULL,0,1,0),
(8,'CK7','Regional Check Deposit 7 Days Clearing','gl',72,'credit',1,7,0,NULL,0,1,0),
(9,'CKTD','Check Time Deposit','gl',72,'credit',1,5,0,NULL,0,1,0),
(10,'INT','Interest Credited','gl',NULL,'credit',0,0,0,NULL,1,1,0),
(11,'WTX','Withholding Tax on Interest','gl',NULL,'debit',0,0,0,NULL,1,1,0),
(12,'DF','Dormancy Fee','gl',NULL,'debit',0,0,0,NULL,1,1,0),
(13,'CF','Closing Fee','gl',NULL,'debit',0,0,0,NULL,1,1,0),
(14,'PTF','Pre-termination Fee','gl',NULL,'debit',0,0,0,NULL,1,1,0),
(15,'LP','Loan Proceeds Credited','gl',NULL,'credit',0,0,0,NULL,1,1,0),
(16,'LC','Loan Charge Credited to Account','gl',NULL,'credit',0,0,0,NULL,1,1,0),
(17,'LPY','Loan Payment Debited from Account','gl',NULL,'debit',0,0,0,NULL,1,1,0),
(18,'TRI','Transfer In','gl',NULL,'credit',0,0,0,NULL,1,1,0),
(19,'TRO','Transfer Out','gl',NULL,'debit',0,0,0,NULL,1,1,0),
(20,'ISC','Interest on Share Capital','gl',NULL,'credit',0,0,0,NULL,1,1,0),
(21,'PR','Patronage Refund','gl',NULL,'credit',0,0,0,NULL,1,1,0),
(22,'PDD','Payroll Deduction Deposit','gl',NULL,'credit',0,0,0,NULL,1,1,0),
(23,'TDM','Time Deposit Maturity / Renewal','gl',NULL,'credit',0,0,0,NULL,1,1,0),
(24,'OB','Opening Balance','gl',NULL,'credit',0,0,0,NULL,1,1,0),
(25,'RTC','Returned Check','gl',72,'debit',0,0,0,NULL,0,1,0),
(26,'CM','Credit Memo (Adjustment)','gl',NULL,'credit',0,0,0,NULL,0,1,1),
(27,'DM','Debit Memo (Adjustment)','gl',NULL,'debit',0,0,0,NULL,0,1,1),
(28,'ECSD','Error Correct Cash Savings Deposit','user',NULL,'credit',0,0,1,'CSD',0,1,0),
(29,'ECSW','Error Correct Cash Savings Withdrawal','user',NULL,'debit',0,0,1,'CSW',0,1,0),
(30,'ECM','Error Correct Credit Memo','gl',NULL,'credit',0,0,1,'CM',0,1,1),
(31,'EDM','Error Correct Debit Memo','gl',NULL,'debit',0,0,1,'DM',0,1,1),
(32,'ECK3','Error Correct Local Check Deposit 3 Days Clearing','gl',72,'credit',0,0,1,'CK3',0,1,0),
(33,'ECK4','Error Correct Local Check Deposit 4 Days Clearing','gl',72,'credit',0,0,1,'CK4',0,1,0),
(34,'ECK5','Error Correct Local Check Deposit 5 Days Clearing','gl',72,'credit',0,0,1,'CK5',0,1,0),
(35,'ECK7','Error Correct Regional Check Deposit 7 Days Clearing','gl',72,'credit',0,0,1,'CK7',0,1,0),
(36,'ECKTD','Error Correct Check Time Deposit','gl',72,'credit',0,0,1,'CKTD',0,1,0),
(39,'CAIW','Cash Accrued Interest Withdrawal','user',NULL,'debit',0,0,0,NULL,1,1,0),
(40,'TDI','Time Deposit Interest Credited','gl',NULL,'credit',0,0,0,NULL,1,1,0),
(41,'INI','Initial Savings Import','gl',43,'credit',0,0,0,NULL,0,1,0);

-- cda_calendar (4 rows)
INSERT INTO `cda_calendar` (`id`,`title`,`category`,`due_date`,`recurrence`,`remind_days`,`notes`,`is_active`) VALUES
(1,'CAPR and audited financial statements (within 120 days after fiscal year end)','cda','2026-04-30','yearly',45,'Confirm the current CDA deadline for your cooperative\'s fiscal year',1),
(2,'Certificate of Compliance application','cda','2026-04-30','yearly',45,'Submitted with the annual reports',1),
(3,'Annual Tax Incentives Report / Annual Benefits Report','bir','2026-04-30','yearly',45,'Where applicable to the cooperative',1),
(4,'General Assembly','internal','2026-03-31','yearly',60,'Set the date fixed by the bylaws',1);

-- cda_report_mappings (32 rows)
INSERT INTO `cda_report_mappings` (`id`,`report`,`section`,`field_code`,`label`,`source`,`sort_order`) VALUES
(1,'capr','General','registered_name','Registered name','setting:registered_name',-3),
(2,'capr','General','cda_reg_no','CDA registration no.','setting:cda_reg_no',-2),
(3,'capr','General','officers','Officers and committee members','metric:officers_count',-1),
(4,'capr','Membership','members_total','Total members','metric:members_total',0),
(5,'capr','Membership','members_regular','Regular members','metric:members_regular',1),
(6,'capr','Membership','members_associate','Associate members','metric:members_associate',2),
(7,'capr','Membership','members_new','New members during the year','metric:members_new',3),
(8,'capr','Membership','members_left','Members who left','metric:members_separated',4),
(9,'capr','Membership','members_male','Male members','metric:members_male',5),
(10,'capr','Membership','members_female','Female members','metric:members_female',6),
(11,'capr','Capital','share_capital','Paid-up share capital','metric:paid_up_capital',10),
(12,'capr','Capital','subscribed_capital','Subscribed share capital','metric:subscribed_capital',11),
(13,'capr','Deposits','savings_deposits','Savings deposits','metric:savings_deposits',20),
(14,'capr','Deposits','time_deposits','Time deposits','metric:time_deposits',21),
(15,'capr','Loans','loans_released_n','Number of loans released during the year','metric:loans_released_count',29),
(16,'capr','Loans','loans_released','Loans released during the year','metric:loans_released_amount',30),
(17,'capr','Loans','loans_outstanding','Loans receivable outstanding','metric:loans_outstanding',31),
(18,'capr','Loans','loans_past_due','Past due loans','metric:loans_past_due',32),
(19,'capr','Loans','par_ratio','Portfolio at risk (%)','metric:par_ratio',33),
(20,'capr','Loans','borrowers','Number of borrowers','metric:loans_borrowers',34),
(21,'capr','Results','total_assets','Total assets','metric:total_assets',40),
(22,'capr','Results','total_liabilities','Total liabilities','metric:total_liabilities',41),
(23,'capr','Results','total_equity','Total members\' equity','metric:total_equity',42),
(24,'capr','Results','revenue','Total revenue','metric:gross_revenue',43),
(25,'capr','Results','expenses','Total expenses','metric:total_expenses',44),
(26,'capr','Results','net_surplus','Net surplus','metric:net_surplus',45),
(27,'capr','Statutory','reserve_fund','Reserve fund','metric:reserve_fund',50),
(28,'capr','Statutory','educ_fund','Education and training fund','metric:education_fund',51),
(29,'capr','Statutory','cdf_fund','Community development fund','metric:cdf_fund',52),
(30,'capr','Statutory','optional_fund','Optional fund','metric:optional_fund',53),
(31,'capr','Distribution','isc_declared','Interest on share capital declared','metric:isc_distributed',60),
(32,'capr','Distribution','pr_declared','Patronage refund declared','metric:pr_distributed',61);

-- business_units (3 rows)
INSERT INTO `business_units` (`id`,`code`,`name`,`kind`,`nonmember_policy`,`patronage_eligible`,`member_price_level_id`,`nonmember_price_level_id`,`default_site_id`,`description`,`is_active`,`sort_order`,`created_at`) VALUES
(1,'SC','Savings and Credit','savings_credit','members',1,NULL,NULL,NULL,'Share capital, savings, time deposits and loans of members',1,1,'2026-11-02 00:20:21'),
(2,'TR','Consumer Store','retail','allow',1,NULL,NULL,NULL,'Retail and trading of goods to members and the public',1,2,'2026-11-02 00:20:21'),
(3,'CI','Cable & Internet Services','service','allow',1,NULL,NULL,NULL,'Cable TV and internet subscriptions of members and the public',1,3,'2026-11-02 00:20:22');

-- isp_agents (1 rows)
INSERT INTO `isp_agents` (`id`,`code`,`name`,`user_id`,`vendor_id`,`mobile`,`email`,`activation_type`,`activation_value`,`first_payment_type`,`first_payment_value`,`clawback_days`,`is_active`,`created_at`) VALUES
(1,'AG-001','Ramon Dela Cruz',11,NULL,'09185550142','agent1@gsmartcable.test','fixed','500.0000','percent','10.0000',90,1,'2026-11-02 00:20:23');

-- isp_contract_templates (1 rows)
INSERT INTO `isp_contract_templates` (`id`,`name`,`body`,`is_default`,`is_active`,`created_at`) VALUES
(1,'Standard Residential / Business Subscription Agreement','SUBSCRIPTION AGREEMENT\n\nThis agreement is made on {date} between {company}, {company_address} (the Provider), and {name}, {address} (the Subscriber), for account {account_no}.\n\n1. Service. The Provider supplies the {plan} plan: up to {download} Mbps download and {upload} Mbps upload, {fair_use}. Speeds are best effort and depend on the line and the devices used.\n2. Fees. The monthly fee is {monthly_fee}{vat_note}, billed {billing}, due {due_days} days after the invoice date. Installation fee {installation_fee}; security deposit {deposit}.\n3. Term. The agreement starts on {start_date} for {months} months{lock_in}. An early termination during the lock-in period is charged {etf}.\n4. Late payment. An unpaid bill {grace_days} days after its due date may lead to disconnection after a final notice. A reconnection fee of {reconnection_fee} may apply.\n5. Equipment. Equipment lent or rented to the Subscriber remains the Provider\'s property and is returned when the service ends; lost or damaged equipment is charged at cost, less the deposit.\n6. Interruptions. Outages beyond {sla_hours} hours caused by the Provider\'s network are credited in proportion to the downtime, upon verification.\n7. Privacy. {privacy}\n8. Complaints. Complaints may be filed at any office or through the customer portal, and with the National Telecommunications Commission.\n\nAccepted by the Subscriber: ______________________   Date: __________\nFor the Provider: ______________________',1,1,'2026-11-02 00:20:22');

-- isp_technicians (3 rows)
INSERT INTO `isp_technicians` (`id`,`code`,`name`,`user_id`,`team`,`role`,`skills`,`area_ids`,`mobile`,`site_id`,`work_days`,`is_active`,`created_at`) VALUES
(1,'TEC-01','Jomar Pascual',12,'Installation Team A','installer','FTTH installation, drop cabling, ONU set-up',NULL,'09175551572',NULL,'1,2,3,4,5,6',1,'2026-11-02 00:20:23'),
(2,'TEC-02','Rodel Manalo',13,'Maintenance Team','maintenance','Troubleshooting, fiber splicing, OTDR, coaxial repair',NULL,'09175551703',NULL,'1,2,3,4,5,6',1,'2026-11-02 00:20:23'),
(3,'TEC-03','Rey Gonzales',25,'Field Supervisors','supervisor','Dispatch, quality checks, OLT and PON management',NULL,'09175553275',NULL,'1,2,3,4,5,6',1,'2026-11-02 00:20:23');

-- isp_disc_rules (7 rows)
INSERT INTO `isp_disc_rules` (`id`,`code`,`name`,`rule_type`,`grace_days`,`min_overdue_amount`,`min_overdue_invoices`,`final_notice_hours`,`require_approval`,`auto_execute`,`run_days`,`run_from_hour`,`run_to_hour`,`exclude_types`,`protect_disputes`,`protect_arrangements`,`reconnection_fee`,`is_active`,`created_at`) VALUES
(1,'NONPAY','Overdue payment after the grace period','overdue',5,'100.00',1,24,1,0,'1,2,3,4,5',9,16,'deposit',1,1,NULL,1,'2026-11-02 00:20:22'),
(2,'CONTRACT','Contract ended without renewal','contract_end',0,'0.00',0,72,1,0,'1,2,3,4,5',9,16,'deposit',1,1,NULL,0,'2026-11-02 00:20:22'),
(3,'REQUEST','Customer-requested suspension','customer_request',0,'0.00',0,0,0,0,'1,2,3,4,5',9,16,'deposit',1,1,NULL,1,'2026-11-02 00:20:22'),
(4,'FRAUD','Fraud or unauthorized connection','fraud',0,'0.00',0,0,1,0,'1,2,3,4,5',9,16,'deposit',1,1,NULL,1,'2026-11-02 00:20:22'),
(5,'TECH','Technical disconnection','technical',0,'0.00',0,0,1,0,'1,2,3,4,5',9,16,'deposit',1,1,NULL,1,'2026-11-02 00:20:22'),
(6,'ADMIN','Administrative disconnection','administrative',0,'0.00',0,0,1,0,'1,2,3,4,5',9,16,'deposit',1,1,NULL,1,'2026-11-02 00:20:22'),
(7,'CANCEL','Permanent cancellation','cancellation',0,'0.00',0,0,1,0,'1,2,3,4,5',9,16,'deposit',1,1,NULL,1,'2026-11-02 00:20:22');

-- isp_notif_templates (51 rows)
INSERT INTO `isp_notif_templates` (`id`,`event_code`,`channel`,`subject`,`body`,`is_active`,`updated_at`,`created_at`) VALUES
(1,'application_received','sms',NULL,'Hi {name}, we received your application {application_no} for {plan}. We will contact you for the site survey. - {company}',1,NULL,'2026-11-02 00:20:22'),
(2,'application_received','email','Application received','Hi {name}, we received your application {application_no} for {plan}. We will contact you for the site survey.\n\n{company}',1,NULL,'2026-11-02 00:20:22'),
(3,'application_received','inapp','Application received','Hi {name}, we received your application {application_no} for {plan}. We will contact you for the site survey. - {company}',1,NULL,'2026-11-02 00:20:22'),
(4,'application_approved','sms',NULL,'Hi {name}, your application {application_no} is approved. Installation will be scheduled soon. - {company}',1,NULL,'2026-11-02 00:20:22'),
(5,'application_approved','email','Application approved','Hi {name}, your application {application_no} is approved. Installation will be scheduled soon.\n\n{company}',1,NULL,'2026-11-02 00:20:22'),
(6,'application_approved','inapp','Application approved','Hi {name}, your application {application_no} is approved. Installation will be scheduled soon. - {company}',1,NULL,'2026-11-02 00:20:22'),
(7,'installation_schedule','sms',NULL,'Hi {name}, your installation ({wo_no}) is set on {date} {slot}. Technician: {technician}. - {company}',1,NULL,'2026-11-02 00:20:22'),
(8,'installation_schedule','email','Installation schedule','Hi {name}, your installation ({wo_no}) is set on {date} {slot}. Technician: {technician}.\n\n{company}',1,NULL,'2026-11-02 00:20:22'),
(9,'installation_schedule','inapp','Installation schedule','Hi {name}, your installation ({wo_no}) is set on {date} {slot}. Technician: {technician}. - {company}',1,NULL,'2026-11-02 00:20:22'),
(10,'service_activated','sms',NULL,'Hi {name}, account {account_no} ({plan}) is now active. Welcome to {company}!',1,NULL,'2026-11-02 00:20:22'),
(11,'service_activated','email','Service activated','Hi {name}, account {account_no} ({plan}) is now active. Welcome to {company}!',1,NULL,'2026-11-02 00:20:22'),
(12,'service_activated','inapp','Service activated','Hi {name}, account {account_no} ({plan}) is now active. Welcome to {company}!',1,NULL,'2026-11-02 00:20:22'),
(13,'invoice_generated','sms',NULL,'Hi {name}, your bill {invoice_no} for {period} is {amount}, due {due_date}. Account {account_no}. - {company}',1,NULL,'2026-11-02 00:20:22'),
(14,'invoice_generated','email','Invoice {invoice_no}','Hi {name}, your bill {invoice_no} for {period} is {amount}, due {due_date}. Account {account_no}.\n\n{company}',1,NULL,'2026-11-02 00:20:22'),
(15,'invoice_generated','inapp','Invoice {invoice_no}','Hi {name}, your bill {invoice_no} for {period} is {amount}, due {due_date}. Account {account_no}. - {company}',1,NULL,'2026-11-02 00:20:22'),
(16,'due_reminder','sms',NULL,'Reminder: {amount} for account {account_no} is due on {due_date}. Pay early to keep your service. - {company}',1,NULL,'2026-11-02 00:20:22'),
(17,'due_reminder','email','Payment reminder','Reminder: {amount} for account {account_no} is due on {due_date}. Pay early to keep your service.\n\n{company}',1,NULL,'2026-11-02 00:20:22'),
(18,'due_reminder','inapp','Payment reminder','Reminder: {amount} for account {account_no} is due on {due_date}. Pay early to keep your service. - {company}',1,NULL,'2026-11-02 00:20:22'),
(19,'payment_overdue','sms',NULL,'Hi {name}, {amount} on account {account_no} is overdue since {due_date}. Please settle to avoid disconnection. - {company}',1,NULL,'2026-11-02 00:20:22'),
(20,'payment_overdue','email','Payment overdue','Hi {name}, {amount} on account {account_no} is overdue since {due_date}. Please settle to avoid disconnection.\n\n{company}',1,NULL,'2026-11-02 00:20:22'),
(21,'payment_overdue','inapp','Payment overdue','Hi {name}, {amount} on account {account_no} is overdue since {due_date}. Please settle to avoid disconnection. - {company}',1,NULL,'2026-11-02 00:20:22'),
(22,'final_notice','sms',NULL,'FINAL NOTICE: account {account_no} will be disconnected after {date} for unpaid {amount}. Pay now to avoid interruption. - {company}',1,NULL,'2026-11-02 00:20:22'),
(23,'final_notice','email','Final disconnection notice','FINAL NOTICE: account {account_no} will be disconnected after {date} for unpaid {amount}. Pay now to avoid interruption.\n\n{company}',1,NULL,'2026-11-02 00:20:22'),
(24,'final_notice','inapp','Final disconnection notice','FINAL NOTICE: account {account_no} will be disconnected after {date} for unpaid {amount}. Pay now to avoid interruption. - {company}',1,NULL,'2026-11-02 00:20:22'),
(25,'service_disconnected','sms',NULL,'Account {account_no} was disconnected for {reason}. Settle {amount} and the service is restored automatically. - {company}',1,NULL,'2026-11-02 00:20:22'),
(26,'service_disconnected','email','Service disconnected','Account {account_no} was disconnected for {reason}. Settle {amount} and the service is restored automatically.\n\n{company}',1,NULL,'2026-11-02 00:20:22'),
(27,'service_disconnected','inapp','Service disconnected','Account {account_no} was disconnected for {reason}. Settle {amount} and the service is restored automatically. - {company}',1,NULL,'2026-11-02 00:20:22'),
(28,'payment_received','sms',NULL,'Thank you {name}! We received {amount} for account {account_no} (receipt {receipt_no}). Balance: {balance}. - {company}',1,NULL,'2026-11-02 00:20:22'),
(29,'payment_received','email','Payment received','Thank you {name}! We received {amount} for account {account_no} (receipt {receipt_no}). Balance: {balance}.\n\n{company}',1,NULL,'2026-11-02 00:20:22'),
(30,'payment_received','inapp','Payment received','Thank you {name}! We received {amount} for account {account_no} (receipt {receipt_no}). Balance: {balance}. - {company}',1,NULL,'2026-11-02 00:20:22'),
(31,'service_restored','sms',NULL,'Hi {name}, the service of account {account_no} is restored. Thank you! - {company}',1,NULL,'2026-11-02 00:20:22'),
(32,'service_restored','email','Service restored','Hi {name}, the service of account {account_no} is restored. Thank you!\n\n{company}',1,NULL,'2026-11-02 00:20:22'),
(33,'service_restored','inapp','Service restored','Hi {name}, the service of account {account_no} is restored. Thank you! - {company}',1,NULL,'2026-11-02 00:20:22'),
(34,'technician_dispatched','sms',NULL,'Hi {name}, technician {technician} is on the way for {wo_no}. - {company}',1,NULL,'2026-11-02 00:20:22'),
(35,'technician_dispatched','email','Technician dispatched','Hi {name}, technician {technician} is on the way for {wo_no}.\n\n{company}',1,NULL,'2026-11-02 00:20:22'),
(36,'technician_dispatched','inapp','Technician dispatched','Hi {name}, technician {technician} is on the way for {wo_no}. - {company}',1,NULL,'2026-11-02 00:20:22'),
(37,'ticket_resolved','sms',NULL,'Hi {name}, ticket {ticket_no} is resolved: {resolution}. Reply if the problem continues. - {company}',1,NULL,'2026-11-02 00:20:22'),
(38,'ticket_resolved','email','Ticket resolved','Hi {name}, ticket {ticket_no} is resolved: {resolution}. Reply if the problem continues.\n\n{company}',1,NULL,'2026-11-02 00:20:22'),
(39,'ticket_resolved','inapp','Ticket resolved','Hi {name}, ticket {ticket_no} is resolved: {resolution}. Reply if the problem continues. - {company}',1,NULL,'2026-11-02 00:20:22'),
(40,'network_outage','sms',NULL,'Advisory: service interruption in {area} since {date} ({title}). Our team is working on it. - {company}',1,NULL,'2026-11-02 00:20:22'),
(41,'network_outage','email','Network outage','Advisory: service interruption in {area} since {date} ({title}). Our team is working on it.\n\n{company}',1,NULL,'2026-11-02 00:20:22'),
(42,'network_outage','inapp','Network outage','Advisory: service interruption in {area} since {date} ({title}). Our team is working on it. - {company}',1,NULL,'2026-11-02 00:20:22'),
(43,'service_maintenance','sms',NULL,'Advisory: scheduled maintenance in {area} on {date}. Service may be interrupted. - {company}',1,NULL,'2026-11-02 00:20:22'),
(44,'service_maintenance','email','Scheduled maintenance','Advisory: scheduled maintenance in {area} on {date}. Service may be interrupted.\n\n{company}',1,NULL,'2026-11-02 00:20:22'),
(45,'service_maintenance','inapp','Scheduled maintenance','Advisory: scheduled maintenance in {area} on {date}. Service may be interrupted. - {company}',1,NULL,'2026-11-02 00:20:22'),
(46,'contract_expiration','sms',NULL,'Hi {name}, the contract of account {account_no} ends on {date}. Visit us or reply to renew. - {company}',1,NULL,'2026-11-02 00:20:22'),
(47,'contract_expiration','email','Contract expiring','Hi {name}, the contract of account {account_no} ends on {date}. Visit us or reply to renew.\n\n{company}',1,NULL,'2026-11-02 00:20:22'),
(48,'contract_expiration','inapp','Contract expiring','Hi {name}, the contract of account {account_no} ends on {date}. Visit us or reply to renew. - {company}',1,NULL,'2026-11-02 00:20:22'),
(49,'payment_pending','sms',NULL,'Hi {name}, we received your payment reference {reference} for {amount}. It is credited once verified. - {company}',1,NULL,'2026-11-02 00:20:22'),
(50,'payment_pending','email','Payment reference received','Hi {name}, we received your payment reference {reference} for {amount}. It is credited once verified.\n\n{company}',1,NULL,'2026-11-02 00:20:22'),
(51,'payment_pending','inapp','Payment reference received','Hi {name}, we received your payment reference {reference} for {amount}. It is credited once verified. - {company}',1,NULL,'2026-11-02 00:20:22');

-- isp_jobs (17 rows)
INSERT INTO `isp_jobs` (`id`,`job_code`,`name`,`description`,`interval_min`,`run_hour`,`is_enabled`,`max_retries`,`fail_count`,`lock_token`,`locked_until`,`last_started_at`,`last_finished_at`,`last_status`,`last_message`,`next_run_at`,`created_at`) VALUES
(1,'billing','Daily invoice generation','Generates (and, when runs need no approval, posts) the invoices of accounts due today',1440,1,1,3,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-02 00:20:22'),
(2,'due_check','Billing due-date checks','Marks unpaid invoices overdue after their due date',60,NULL,1,3,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-02 00:20:22'),
(3,'late_fees','Late-charge assessment','Charges the plan late fee once per overdue invoice',1440,2,1,3,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-02 00:20:22'),
(4,'reminders','Payment reminders','Due-date reminders before the due date and overdue reminders after it',1440,8,1,3,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-02 00:20:22'),
(5,'disc_eval','Disconnection eligibility evaluation','Builds the disconnection queue from the rules and sends the final notices',120,NULL,1,3,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-02 00:20:22'),
(6,'disc_execute','Disconnection execution','Executes approved disconnections inside the rule hours, rechecking eligibility under a lock',15,NULL,1,3,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-02 00:20:22'),
(7,'reconnect','Reconnection processing','Requests and executes reconnections of accounts that qualify after payment',5,NULL,1,3,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-02 00:20:22'),
(8,'net_ops','Network operation retries','Sends queued network operations and retries failed ones within their limit',5,NULL,1,3,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-02 00:20:22'),
(9,'net_reconcile','Network reconciliation','Compares the expected subscriber state with the device state',360,NULL,1,3,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-02 00:20:22'),
(10,'temp_recon','Expired temporary reconnections','Disconnects again the temporary reconnections that expired unpaid',60,NULL,1,3,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-02 00:20:22'),
(11,'contracts','Contract expiration notices','Notifies subscribers of contracts ending within 30 days and marks expired ones',1440,9,1,3,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-02 00:20:22'),
(12,'equipment','Equipment maintenance reminders','Lists infrastructure due for maintenance and equipment pending recovery',1440,7,1,3,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-02 00:20:22'),
(13,'ticket_alerts','Overdue support-ticket alerts','Escalates tickets past their SLA deadline',30,NULL,1,3,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-02 00:20:22'),
(14,'daily_summary','Daily financial summary','Writes the day\'s billing and collection totals to the job log',1440,23,1,3,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-02 00:20:22'),
(15,'backup','Database backup','Writes a full database backup to storage/backups',1440,0,1,3,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-02 00:20:22'),
(16,'notify_retry','Notification delivery and retries','Sends queued SMS / e-mail and retries failed ones within their limit',5,NULL,1,3,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-02 00:20:22'),
(17,'arrangements','Payment arrangement follow-up','Marks missed installments and ends defaulted arrangements',1440,3,1,3,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-02 00:20:22');

-- ---------------------------------------------------------------------
--  4. AUDIT TRAIL PROTECTION (created after the data is loaded)
-- ---------------------------------------------------------------------
-- ---------------------------------------------------------------------
--  Audit trail protection: entries can never be changed or deleted,
--  not even by administrators or directly in the database.
-- ---------------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_audit_trail_no_update;
DROP TRIGGER IF EXISTS trg_audit_trail_no_delete;

DELIMITER $$
CREATE TRIGGER trg_audit_trail_no_update BEFORE UPDATE ON audit_trail
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit trail entries are read-only and cannot be changed.';
END$$

CREATE TRIGGER trg_audit_trail_no_delete BEFORE DELETE ON audit_trail
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit trail entries are permanent and cannot be deleted.';
END$$
DELIMITER ;

-- =====================================================================
--  MASTER (PLATFORM) DATABASE: the Super Admin, the companies, their
--  databases and Company Codes, and which company each username belongs to
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `gsmart_coop_cable_master` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `gsmart_coop_cable_master`;

DROP TABLE IF EXISTS `super_admins`;
CREATE TABLE super_admins (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username             VARCHAR(50)  NOT NULL,
  password_hash        VARCHAR(255) NOT NULL,
  full_name            VARCHAR(120) NOT NULL,
  email                VARCHAR(120) NULL,
  status               ENUM('active','inactive','locked') NOT NULL DEFAULT 'active',
  must_change_password TINYINT(1)   NOT NULL DEFAULT 0,
  password_changed_at  DATETIME     NULL,
  failed_attempts      INT          NOT NULL DEFAULT 0,
  last_login_at        DATETIME     NULL,
  last_login_ip        VARCHAR(45)  NULL,
  created_by           INT UNSIGNED NULL,
  created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_super_admins_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `companies`;
CREATE TABLE companies (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name                VARCHAR(150) NOT NULL,
  address             VARCHAR(255) NULL,
  contact_person      VARCHAR(120) NULL,
  phone               VARCHAR(60)  NULL,
  email               VARCHAR(120) NULL,
  tin                 VARCHAR(40)  NULL,
  status              ENUM('provisioning','active','failed','inactive') NOT NULL DEFAULT 'provisioning',
  owner_user_id       INT UNSIGNED NULL,               
  require_approval    TINYINT(1)   NOT NULL DEFAULT 1, 
  join_role_id        INT UNSIGNED NULL,               
  template_company_id INT UNSIGNED NULL,               
  notes               VARCHAR(255) NULL,
  last_error          TEXT         NULL,               
  created_by          INT UNSIGNED NULL,               
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  activated_at        DATETIME     NULL,
  deactivated_at      DATETIME     NULL,
  updated_at          DATETIME     NULL,
  UNIQUE KEY uq_companies_name (name),
  KEY ix_companies_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `company_databases`;
CREATE TABLE company_databases (
  company_id          INT UNSIGNED NOT NULL PRIMARY KEY,
  db_name             VARCHAR(64)  NOT NULL,
  is_primary          TINYINT(1)   NOT NULL DEFAULT 0, 
  created_by_platform TINYINT(1)   NOT NULL DEFAULT 0, 
  table_count         INT          NULL,
  triggers_ok         TINYINT(1)   NOT NULL DEFAULT 0, 
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  verified_at         DATETIME     NULL,
  UNIQUE KEY uq_company_databases_name (db_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `company_codes`;
CREATE TABLE company_codes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id  INT UNSIGNED NOT NULL,
  code        VARCHAR(20)  NOT NULL,
  status      ENUM('active','disabled','retired') NOT NULL DEFAULT 'active',
  expires_at  DATETIME     NULL,
  created_by  VARCHAR(80)  NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  retired_at  DATETIME     NULL,
  UNIQUE KEY uq_company_codes_code (code),
  KEY ix_company_codes_company (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `user_company_memberships`;
CREATE TABLE user_company_memberships (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id      INT UNSIGNED NOT NULL,
  company_user_id INT UNSIGNED NOT NULL,
  username        VARCHAR(50)  NOT NULL,
  full_name       VARCHAR(120) NULL,
  email           VARCHAR(120) NULL,
  status          VARCHAR(12)  NOT NULL DEFAULT 'active',  
  created_via     VARCHAR(12)  NOT NULL DEFAULT 'admin',   
  joined_at       DATETIME     NULL,
  updated_at      DATETIME     NULL,
  UNIQUE KEY uq_memberships_username (username),
  UNIQUE KEY uq_memberships_company_user (company_id, company_user_id),
  KEY ix_memberships_company (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `user_company_links`;
CREATE TABLE user_company_links (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  home_company_id INT UNSIGNED NOT NULL,
  home_user_id    INT UNSIGNED NOT NULL,
  username        VARCHAR(50)  NOT NULL,
  company_id      INT UNSIGNED NOT NULL,              
  company_user_id INT UNSIGNED NOT NULL,              
  status          VARCHAR(12)  NOT NULL DEFAULT 'active',  
  granted_by      VARCHAR(80)  NULL,
  granted_at      DATETIME     NULL,
  removed_by      VARCHAR(80)  NULL,
  removed_at      DATETIME     NULL,
  removed_reason  VARCHAR(255) NULL,
  last_switch_at  DATETIME     NULL,
  UNIQUE KEY uq_links_home_company (home_company_id, home_user_id, company_id),
  UNIQUE KEY uq_links_company_user (company_id, company_user_id),
  KEY ix_links_company (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `company_provisioning_log`;
CREATE TABLE company_provisioning_log (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  attempt    INT          NOT NULL DEFAULT 1,
  step       VARCHAR(60)  NOT NULL,
  ok         TINYINT(1)   NOT NULL DEFAULT 1,
  message    TEXT         NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_provisioning_company (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `platform_audit`;
CREATE TABLE platform_audit (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actor_type  VARCHAR(12)  NOT NULL,              
  actor_id    INT UNSIGNED NULL,
  actor_name  VARCHAR(80)  NULL,
  company_id  INT UNSIGNED NULL,
  action      VARCHAR(40)  NOT NULL,
  details     TEXT         NULL,
  ip_address  VARCHAR(45)  NULL,
  KEY ix_platform_audit_company (company_id, id),
  KEY ix_platform_audit_action (action, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `platform_settings`;
CREATE TABLE platform_settings (
  setting_key   VARCHAR(60) NOT NULL PRIMARY KEY,
  setting_value TEXT        NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `registration_attempts`;
CREATE TABLE registration_attempts (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip_address  VARCHAR(45)  NOT NULL,
  ok          TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_registration_attempts_ip (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- super_admins (1 rows)
INSERT INTO `super_admins` (`id`,`username`,`password_hash`,`full_name`,`email`,`status`,`must_change_password`,`password_changed_at`,`failed_attempts`,`last_login_at`,`last_login_ip`,`created_by`,`created_at`) VALUES
(1,'superadmin','$2y$10$M7nByphOVTXsbVHNKU26qez01hy32CEVOg3A5zu2tbhapyFrQa1ri','Platform Super Admin',NULL,'active',1,'2026-11-02 00:20:23',0,NULL,NULL,NULL,'2026-11-02 00:20:23');

-- companies (1 rows)
INSERT INTO `companies` (`id`,`name`,`address`,`contact_person`,`phone`,`email`,`tin`,`status`,`owner_user_id`,`require_approval`,`join_role_id`,`template_company_id`,`notes`,`last_error`,`created_by`,`created_at`,`activated_at`,`deactivated_at`,`updated_at`) VALUES
(1,'Your Cooperative Name',NULL,NULL,NULL,NULL,NULL,'active',1,0,NULL,NULL,'The original database, registered as Company 1',NULL,NULL,'2026-11-02 00:20:23','2026-11-02 00:20:23',NULL,NULL);

-- company_databases (1 rows)
INSERT INTO `company_databases` (`company_id`,`db_name`,`is_primary`,`created_by_platform`,`table_count`,`triggers_ok`,`created_at`,`verified_at`) VALUES
(1,'gsmart_coop_cable',1,0,205,1,'2026-11-02 00:20:23','2026-11-02 00:20:23');

-- company_codes (1 rows)
INSERT INTO `company_codes` (`id`,`company_id`,`code`,`status`,`expires_at`,`created_by`,`created_at`,`retired_at`) VALUES
(1,1,'YCN3PPN5','active',NULL,'setup:admin','2026-11-02 00:20:23',NULL);

-- user_company_memberships (26 rows)
INSERT INTO `user_company_memberships` (`id`,`company_id`,`company_user_id`,`username`,`full_name`,`email`,`status`,`created_via`,`joined_at`,`updated_at`) VALUES
(1,1,1,'admin','System Administrator',NULL,'active','admin','2026-11-02 00:20:21','2026-11-02 00:20:23'),
(2,1,2,'accountant','Ana Cruz',NULL,'active','admin','2026-11-02 00:20:21','2026-11-02 00:20:23'),
(3,1,3,'arclerk','Ben Garcia',NULL,'active','admin','2026-11-02 00:20:21','2026-11-02 00:20:23'),
(4,1,4,'apclerk','Carla Lim',NULL,'active','admin','2026-11-02 00:20:21','2026-11-02 00:20:23'),
(5,1,5,'invclerk','Dan Villanueva',NULL,'active','admin','2026-11-02 00:20:21','2026-11-02 00:20:23'),
(6,1,6,'salesrep','Maria Santos',NULL,'active','admin','2026-11-02 00:20:21','2026-11-02 00:20:23'),
(7,1,7,'cashier1','Ella Ramos',NULL,'active','admin','2026-11-02 00:20:21','2026-11-02 00:20:23'),
(8,1,8,'supervisor','Fred Tan',NULL,'active','admin','2026-11-02 00:20:21','2026-11-02 00:20:23'),
(9,1,9,'auditor','Grace Uy',NULL,'active','admin','2026-11-02 00:20:21','2026-11-02 00:20:23'),
(10,1,11,'agent1','Ramon Dela Cruz',NULL,'active','admin','2026-11-02 00:20:22','2026-11-02 00:20:23'),
(11,1,12,'installer1','Jomar Pascual',NULL,'active','admin','2026-11-02 00:20:22','2026-11-02 00:20:23'),
(12,1,13,'maintech1','Rodel Manalo',NULL,'active','admin','2026-11-02 00:20:22','2026-11-02 00:20:23'),
(13,1,14,'collector1','Alvin Soriano',NULL,'active','admin','2026-11-02 00:20:22','2026-11-02 00:20:23'),
(14,1,15,'teller1','Teresa Villanueva',NULL,'active','admin','2026-11-02 00:20:22','2026-11-02 00:20:23'),
(15,1,16,'csr1','Kristine Lopez',NULL,'active','admin','2026-11-02 00:20:22','2026-11-02 00:20:23'),
(16,1,17,'custodian1','Noel Bautista',NULL,'active','admin','2026-11-02 00:20:23','2026-11-02 00:20:23'),
(17,1,18,'membership','Nina Bautista',NULL,'active','admin','2026-11-02 00:20:23','2026-11-02 00:20:23'),
(18,1,19,'collections1','Liza Mendoza',NULL,'active','admin','2026-11-02 00:20:23','2026-11-02 00:20:23'),
(19,1,20,'billing1','Janet Ocampo',NULL,'active','admin','2026-11-02 00:20:23','2026-11-02 00:20:23'),
(20,1,21,'loanofficer','Marvin Uy',NULL,'active','admin','2026-11-02 00:20:23','2026-11-02 00:20:23'),
(21,1,22,'creditcom','Eduardo Santos',NULL,'active','admin','2026-11-02 00:20:23','2026-11-02 00:20:23'),
(22,1,23,'bookkeeper','Joy Fernandez',NULL,'active','admin','2026-11-02 00:20:23','2026-11-02 00:20:23'),
(23,1,24,'netadmin1','Arnold Castillo',NULL,'active','admin','2026-11-02 00:20:23','2026-11-02 00:20:23'),
(24,1,25,'techsup1','Rey Gonzales',NULL,'active','admin','2026-11-02 00:20:23','2026-11-02 00:20:23'),
(25,1,26,'branchmgr','Carlo Reyes',NULL,'active','admin','2026-11-02 00:20:23','2026-11-02 00:20:23'),
(26,1,27,'manager','Rosario Dizon',NULL,'active','admin','2026-11-02 00:20:23','2026-11-02 00:20:23');

-- platform_audit (1 rows)
INSERT INTO `platform_audit` (`id`,`created_at`,`actor_type`,`actor_id`,`actor_name`,`company_id`,`action`,`details`,`ip_address`) VALUES
(1,'2026-11-02 00:20:23','user',1,'admin',1,'platform_setup','The existing database was registered as Company 1 (Your Cooperative Name); owner admin; Super Admin superadmin created','cli');

-- platform_settings (1 rows)
INSERT INTO `platform_settings` (`setting_key`,`setting_value`) VALUES
('default_require_approval','0');

DROP TRIGGER IF EXISTS trg_platform_audit_no_update;
DROP TRIGGER IF EXISTS trg_platform_audit_no_delete;
DELIMITER $$
CREATE TRIGGER trg_platform_audit_no_update BEFORE UPDATE ON platform_audit FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Platform audit entries are read-only.';
END$$
CREATE TRIGGER trg_platform_audit_no_delete BEFORE DELETE ON platform_audit FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Platform audit entries are permanent.';
END$$
DELIMITER ;

USE `gsmart_coop_cable`;
SET FOREIGN_KEY_CHECKS = 1;
