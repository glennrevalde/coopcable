-- =====================================================================
--  G-SMaRT Coop Cable System
--  migration: the cable / internet service provider (ISP) system
--
--  What it adds
--    Subscribers     isp_customers (one per party of the Party Master), isp_accounts (one per subscription and
--                    installation address), isp_contacts, isp_account_events (the chronological account history),
--                    isp_account_plans (the plan and price history), isp_account_addons (recurring add-ons)
--    Sales           isp_applications and isp_application_log (New Application -> ... -> Active), isp_agents and
--                    isp_commissions, isp_contract_templates and isp_contracts
--    Plans & areas   isp_plans, isp_plan_areas (eligibility), isp_areas (municipality / barangay / zone tree)
--    Billing         isp_billing_runs, isp_invoices (immutable numbers, one per account, kind and period), isp_invoice_lines
--                    (price, tax and quantity snapshots), isp_charges (one-time and pending charges, idempotent keys),
--                    isp_adjustments, isp_disputes, isp_arrangements + isp_arrangement_lines, isp_exemptions
--    Collections     isp_payments (pending / verified / posted / rejected / reversed), isp_payment_allocs,
--                    isp_shifts (cashier shifts and cash count), isp_gateway_events (authenticated, de-duplicated webhooks)
--    Service control isp_disc_rules, isp_disc_queue, isp_reconnections, isp_devices (MikroTik, RADIUS, OLT, TR-069,
--                    simulator, manual), isp_net_ops (requested -> queued -> sent -> confirmed | failed), isp_net_logs
--    Field service   isp_technicians, isp_work_orders, isp_wo_materials, isp_files, isp_equipment, isp_equipment_log
--    Infrastructure  isp_net_nodes (OLT, PON, splitter, NAP, pole, cabinet ...), isp_net_ports, isp_cable_segments,
--                    isp_cable_cores, isp_splices, isp_node_events
--    Support         isp_tickets, isp_ticket_notes, isp_outages, isp_outage_accounts, isp_announcements,
--                    isp_service_requests, isp_portal_users
--    Messages        isp_notif_templates, isp_notifications (de-duplicated, retried, logged)
--    Automation      isp_jobs and isp_job_runs (locked, re-runnable scheduled jobs)
--    Accounting      isp_postings: the one bridge between the ISP records and the general ledger (idempotent event keys)
--    Menus           Subscribers, Billing & Collection, Network & Service Control, Field Service, ISP Setup
--
--  Every money column is DECIMAL. Only one TIMESTAMP column per table defaults to CURRENT_TIMESTAMP (MySQL 5.5),
--  indexed text stays within 767 bytes (utf8mb4), no JSON columns, CTEs or window functions.
--  Safe to run more than once (CREATE TABLE IF NOT EXISTS, INSERT IGNORE). MySQL 5.5.3+ / MariaDB 5.5+.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  Service areas, plans
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS isp_areas (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  parent_id     INT UNSIGNED NULL,
  area_type     VARCHAR(20)  NOT NULL DEFAULT 'barangay',   -- province / municipality / barangay / zone
  code          VARCHAR(20)  NOT NULL,
  name          VARCHAR(120) NOT NULL,
  branch_id     INT UNSIGNED NULL,                          -- the office that serves it (coop_branches)
  latitude      DECIMAL(10,7) NULL,
  longitude     DECIMAL(10,7) NULL,
  is_serviceable TINYINT(1)  NOT NULL DEFAULT 1,            -- coverage: applications here are feasible
  notes         VARCHAR(255) NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_areas_code (code),
  KEY ix_isp_areas_parent (parent_id),
  KEY ix_isp_areas_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_plans (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                VARCHAR(20)  NOT NULL,
  name                VARCHAR(120) NOT NULL,
  description         VARCHAR(255) NULL,
  category            VARCHAR(20)  NOT NULL DEFAULT 'residential', -- residential / commercial / dedicated / bundle
  service_type        VARCHAR(20)  NOT NULL DEFAULT 'postpaid',    -- postpaid / prepaid
  technology          VARCHAR(20)  NOT NULL DEFAULT 'fiber',       -- fiber / cable / wireless
  includes_catv       TINYINT(1)   NOT NULL DEFAULT 0,             -- cable-TV + internet bundle
  monthly_fee         DECIMAL(15,2) NOT NULL DEFAULT 0,
  member_monthly_fee  DECIMAL(15,2) NULL,                          -- the price for cooperative members (NULL: same price)
  download_mbps       DECIMAL(10,2) NOT NULL DEFAULT 0,
  upload_mbps         DECIMAL(10,2) NOT NULL DEFAULT 0,
  data_cap_gb         DECIMAL(10,2) NULL,                          -- NULL = unlimited
  fair_use_policy     VARCHAR(255) NULL,
  installation_fee    DECIMAL(15,2) NOT NULL DEFAULT 0,
  router_rental_fee   DECIMAL(15,2) NOT NULL DEFAULT 0,
  static_ip_fee       DECIMAL(15,2) NOT NULL DEFAULT 0,
  equipment_deposit   DECIMAL(15,2) NOT NULL DEFAULT 0,
  contract_months     SMALLINT     NOT NULL DEFAULT 12,
  lock_in_months      SMALLINT     NOT NULL DEFAULT 0,
  early_termination_fee DECIMAL(15,2) NOT NULL DEFAULT 0,
  vat_mode            VARCHAR(12)  NOT NULL DEFAULT 'inclusive',   -- inclusive / exclusive / exempt / zero
  late_fee_type       VARCHAR(10)  NOT NULL DEFAULT 'fixed',       -- none / fixed / percent
  late_fee_value      DECIMAL(15,4) NOT NULL DEFAULT 0,
  reconnection_fee    DECIMAL(15,2) NOT NULL DEFAULT 0,
  promo_discount_pct  DECIMAL(7,4) NOT NULL DEFAULT 0,
  promo_months        SMALLINT     NOT NULL DEFAULT 0,             -- the first N billed months get the promo discount
  available_from      DATE         NULL,
  available_to        DATE         NULL,
  network_profile     VARCHAR(60)  NULL,                           -- PPPoE / RADIUS / queue profile name on the device
  income_account_id   INT UNSIGNED NULL,                           -- NULL: Module Default Accounts > Internet service revenue
  status              VARCHAR(12)  NOT NULL DEFAULT 'active',      -- active / inactive / retired
  sort_order          INT          NOT NULL DEFAULT 0,
  created_by          INT UNSIGNED NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NULL,
  UNIQUE KEY uq_isp_plans_code (code),
  KEY ix_isp_plans_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_plan_areas (
  plan_id  INT UNSIGNED NOT NULL,
  area_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (plan_id, area_id),
  KEY ix_isp_plan_areas_area (area_id),
  CONSTRAINT fk_isp_plan_areas_plan FOREIGN KEY (plan_id) REFERENCES isp_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_isp_plan_areas_area FOREIGN KEY (area_id) REFERENCES isp_areas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Customers, subscription accounts and their history
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS isp_customers (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  customer_no     VARCHAR(20)  NOT NULL,                 -- unique customer identification number
  party_id        INT UNSIGNED NULL,                     -- the Party Master record (the same person as a member, supplier ...)
  customer_id     INT UNSIGNED NULL,                     -- the A/R customer record the invoices post to
  classification  VARCHAR(20)  NOT NULL DEFAULT 'residential', -- residential / business / government / institutional
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
  privacy_consent_at DATETIME  NULL,                     -- Data Privacy Act consent recorded on the application
  notes           TEXT         NULL,
  status          VARCHAR(12)  NOT NULL DEFAULT 'active', -- active / inactive / merged
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

CREATE TABLE IF NOT EXISTS isp_contacts (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  isp_customer_id INT UNSIGNED NOT NULL,
  name            VARCHAR(120) NOT NULL,
  relationship    VARCHAR(60)  NULL,
  mobile          VARCHAR(40)  NULL,
  email           VARCHAR(120) NULL,
  is_authorized   TINYINT(1)   NOT NULL DEFAULT 1,     -- may transact for the subscriber
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_isp_contacts_customer (isp_customer_id),
  CONSTRAINT fk_isp_contacts_customer FOREIGN KEY (isp_customer_id) REFERENCES isp_customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_accounts (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_no         VARCHAR(30)  NOT NULL,              -- subscriber account number
  isp_customer_id    INT UNSIGNED NOT NULL,
  account_name       VARCHAR(160) NOT NULL,              -- the name printed on invoices
  branch_id          INT UNSIGNED NULL,                  -- coop_branches
  area_id            INT UNSIGNED NULL,
  plan_id            INT UNSIGNED NOT NULL,
  member_price       TINYINT(1)   NOT NULL DEFAULT 0,    -- billed at the plan's member price
  install_address    VARCHAR(255) NOT NULL,
  landmark           VARCHAR(160) NULL,
  latitude           DECIMAL(10,7) NULL,
  longitude          DECIMAL(10,7) NULL,
  service_status     VARCHAR(16)  NOT NULL DEFAULT 'pending',   -- pending / active / suspended / disconnected / terminated / cancelled
  network_state      VARCHAR(16)  NOT NULL DEFAULT 'not_provisioned', -- not_provisioned / provisioning / enabled / disabled / failed / unknown
  billing_basis      VARCHAR(12)  NOT NULL DEFAULT 'calendar',  -- calendar (fixed cycle day) / anniversary (activation day)
  bill_timing        VARCHAR(10)  NOT NULL DEFAULT 'advance',   -- advance (bill the coming period) / arrears (bill the period just ended)
  cycle_day          TINYINT      NOT NULL DEFAULT 1,           -- 1..28
  due_days           SMALLINT     NOT NULL DEFAULT 15,          -- days from invoice date to due date
  custom_due_day     TINYINT      NULL,                         -- a fixed day of the month the subscriber asked for
  prorate_first      TINYINT(1)   NOT NULL DEFAULT 1,
  activation_date    DATE         NULL,
  billed_through     DATE         NULL,                         -- the last day already invoiced
  next_bill_date     DATE         NULL,
  contract_start     DATE         NULL,
  contract_end       DATE         NULL,
  lock_in_until      DATE         NULL,
  router_rental      TINYINT(1)   NOT NULL DEFAULT 0,
  static_ip          VARCHAR(45)  NULL,
  ip_pool            VARCHAR(60)  NULL,
  security_deposit   DECIMAL(15,2) NOT NULL DEFAULT 0,
  deposit_status     VARCHAR(12)  NOT NULL DEFAULT 'none',      -- none / billed / held / applied / refunded
  pppoe_username     VARCHAR(64)  NULL,
  pppoe_password_enc TEXT         NULL,                         -- encrypted (IspCrypto)
  mac_address        VARCHAR(30)  NULL,
  device_id          INT UNSIGNED NULL,                         -- the NAS / BNG / RADIUS that authenticates it
  port_id            INT UNSIGNED NULL,                         -- the NAP / distribution terminal port of the drop
  drop_length_m      DECIMAL(10,2) NULL,
  agent_id           INT UNSIGNED NULL,
  application_id     INT UNSIGNED NULL,
  autodebit_sav_account_id INT UNSIGNED NULL,                   -- a member's savings account the bills may be debited from
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

CREATE TABLE IF NOT EXISTS isp_account_events (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id   INT UNSIGNED NOT NULL,
  event_date   DATETIME     NOT NULL,
  event_type   VARCHAR(30)  NOT NULL,      -- installed, activated, plan_change, invoice, payment, adjustment, complaint, suspended, disconnected, reconnected, terminated ...
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

CREATE TABLE IF NOT EXISTS isp_account_plans (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id   INT UNSIGNED NOT NULL,
  plan_id      INT UNSIGNED NOT NULL,
  monthly_fee  DECIMAL(15,2) NOT NULL,     -- the price in force for this account while the plan applied
  effective_from DATE        NOT NULL,
  effective_to DATE          NULL,
  change_type  VARCHAR(16)   NOT NULL DEFAULT 'new',  -- new / upgrade / downgrade / migration / renewal
  proration    VARCHAR(255)  NULL,
  user_id      INT UNSIGNED  NULL,
  created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_isp_account_plans_account (account_id, effective_from),
  CONSTRAINT fk_isp_account_plans_account FOREIGN KEY (account_id) REFERENCES isp_accounts(id),
  CONSTRAINT fk_isp_account_plans_plan FOREIGN KEY (plan_id) REFERENCES isp_plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_account_addons (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id   INT UNSIGNED NOT NULL,
  description  VARCHAR(160) NOT NULL,
  amount       DECIMAL(15,2) NOT NULL,     -- per month
  taxable      TINYINT(1)   NOT NULL DEFAULT 1,
  start_date   DATE         NOT NULL,
  end_date     DATE         NULL,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_isp_account_addons_account (account_id),
  CONSTRAINT fk_isp_account_addons_account FOREIGN KEY (account_id) REFERENCES isp_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Applications, agents, contracts
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS isp_agents (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code               VARCHAR(20)  NOT NULL,
  name               VARCHAR(120) NOT NULL,
  user_id            INT UNSIGNED NULL,
  vendor_id          INT UNSIGNED NULL,       -- the supplier record the commissions are paid to (A/P)
  mobile             VARCHAR(40)  NULL,
  email              VARCHAR(120) NULL,
  activation_type    VARCHAR(10)  NOT NULL DEFAULT 'fixed',  -- fixed / percent (of the first month's fee)
  activation_value   DECIMAL(15,4) NOT NULL DEFAULT 0,
  first_payment_type VARCHAR(10)  NOT NULL DEFAULT 'none',   -- none / fixed / percent (of the first payment)
  first_payment_value DECIMAL(15,4) NOT NULL DEFAULT 0,
  clawback_days      SMALLINT     NOT NULL DEFAULT 90,       -- a cancellation within these days reverses the commission
  is_active          TINYINT(1)   NOT NULL DEFAULT 1,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_agents_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_applications (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_no    VARCHAR(30)  NOT NULL,
  channel           VARCHAR(12)  NOT NULL DEFAULT 'walkin',   -- walkin / online / agent
  status            VARCHAR(20)  NOT NULL DEFAULT 'new',      -- new / for_verification / site_survey / approved / for_installation / installed / for_activation / active / rejected / cancelled
  party_id          INT UNSIGNED NULL,                        -- a party already on file (a member, a customer)
  isp_customer_id   INT UNSIGNED NULL,
  account_id        INT UNSIGNED NULL,                        -- the subscription made from it
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
  port_id           INT UNSIGNED NULL,                        -- the NAP port reserved for the drop
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

CREATE TABLE IF NOT EXISTS isp_application_log (
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

CREATE TABLE IF NOT EXISTS isp_commissions (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  agent_id      INT UNSIGNED NOT NULL,
  account_id    INT UNSIGNED NOT NULL,
  basis         VARCHAR(30)  NOT NULL,          -- activation / first_payment / clawback_activation / clawback_first_payment
  commission_key VARCHAR(60) NOT NULL,          -- one commission per agent, account and basis
  amount        DECIMAL(15,2) NOT NULL,
  rule_note     VARCHAR(160) NULL,              -- how it was worked out
  status        VARCHAR(12)  NOT NULL DEFAULT 'pending', -- pending / approved / paid / reversed / rejected
  approved_by   INT UNSIGNED NULL,
  approved_at   DATETIME     NULL,
  bill_txn_id   BIGINT UNSIGNED NULL,           -- the A/P bill that pays it
  reversed_reason VARCHAR(255) NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_commissions_key (commission_key),
  KEY ix_isp_commissions_agent (agent_id, status),
  CONSTRAINT fk_isp_commissions_agent FOREIGN KEY (agent_id) REFERENCES isp_agents(id),
  CONSTRAINT fk_isp_commissions_account FOREIGN KEY (account_id) REFERENCES isp_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_contract_templates (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  body        MEDIUMTEXT   NOT NULL,           -- with {placeholders}
  is_default  TINYINT(1)   NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_contracts (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  contract_no        VARCHAR(30)  NOT NULL,
  account_id         INT UNSIGNED NOT NULL,
  template_id        INT UNSIGNED NULL,
  plan_id            INT UNSIGNED NULL,
  start_date         DATE         NOT NULL,
  end_date           DATE         NULL,
  lock_in_months     SMALLINT     NOT NULL DEFAULT 0,
  early_termination_fee DECIMAL(15,2) NOT NULL DEFAULT 0,
  body_snapshot      MEDIUMTEXT   NULL,          -- the terms as accepted
  accepted_at        DATETIME     NULL,
  accepted_name      VARCHAR(160) NULL,
  accepted_ip        VARCHAR(45)  NULL,
  status             VARCHAR(12)  NOT NULL DEFAULT 'active',  -- draft / active / amended / renewed / terminated / expired
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

-- ---------------------------------------------------------------------
--  Field service: technicians, work orders, materials, files, equipment
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS isp_technicians (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(20)  NOT NULL,
  name        VARCHAR(120) NOT NULL,
  user_id     INT UNSIGNED NULL,
  team        VARCHAR(60)  NULL,
  role        VARCHAR(20)  NOT NULL DEFAULT 'installer',    -- installer / maintenance / supervisor / splicer
  skills      VARCHAR(255) NULL,
  area_ids    VARCHAR(255) NULL,                            -- comma-separated isp_areas ids
  mobile      VARCHAR(40)  NULL,
  site_id     INT UNSIGNED NULL,                            -- the van / stock location issued to the technician
  work_days   VARCHAR(20)  NOT NULL DEFAULT '1,2,3,4,5,6',  -- ISO days of week
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_technicians_code (code),
  KEY ix_isp_technicians_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_work_orders (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  wo_no           VARCHAR(30)  NOT NULL,
  wo_type         VARCHAR(20)  NOT NULL,       -- survey / installation / repair / disconnection / reconnection / relocation / replacement / recovery / maintenance
  status          VARCHAR(16)  NOT NULL DEFAULT 'open',   -- open / scheduled / accepted / in_progress / completed / verified / cancelled
  priority        VARCHAR(10)  NOT NULL DEFAULT 'normal', -- low / normal / high / urgent
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
  scheduled_slot  VARCHAR(20)  NULL,          -- AM / PM / a time
  route_seq       SMALLINT     NULL,
  site_id         INT UNSIGNED NULL,          -- the stock location the materials are issued from
  checklist       TEXT         NULL,          -- one ticked item per line: "1|Drop cable laid"
  cable_used_m    DECIMAL(10,2) NULL,
  onu_serial      VARCHAR(60)  NULL,
  router_serial   VARCHAR(60)  NULL,
  port_id         INT UNSIGNED NULL,
  rx_power_dbm    DECIMAL(6,2) NULL,          -- optical signal at the ONU
  tx_power_dbm    DECIMAL(6,2) NULL,
  speed_test_down DECIMAL(10,2) NULL,
  speed_test_up   DECIMAL(10,2) NULL,
  labor_hours     DECIMAL(8,2) NULL,
  labor_cost      DECIMAL(15,2) NOT NULL DEFAULT 0,
  travel_expense  DECIMAL(15,2) NOT NULL DEFAULT 0,
  expense_txn_id  BIGINT UNSIGNED NULL,       -- the cash voucher / bill of the labor and travel cost
  findings        TEXT         NULL,
  remarks         TEXT         NULL,
  customer_accepted_by VARCHAR(120) NULL,
  customer_accepted_at DATETIME NULL,
  accepted_at     DATETIME     NULL,          -- the technician accepted the job
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

CREATE TABLE IF NOT EXISTS isp_wo_materials (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  work_order_id INT UNSIGNED NOT NULL,
  item_id       INT UNSIGNED NOT NULL,
  qty_issued    DECIMAL(14,4) NOT NULL DEFAULT 0,
  qty_used      DECIMAL(14,4) NOT NULL DEFAULT 0,
  qty_returned  DECIMAL(14,4) NOT NULL DEFAULT 0,
  serial_no     VARCHAR(60)  NULL,
  unit_cost     DECIMAL(15,4) NULL,            -- the average cost at issuance (from the inventory ledger)
  issue_txn_id  BIGINT UNSIGNED NULL,          -- the stock movement that consumed it
  return_txn_id BIGINT UNSIGNED NULL,
  status        VARCHAR(12)  NOT NULL DEFAULT 'issued',  -- issued / consumed / returned
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_isp_wo_materials_wo (work_order_id),
  KEY ix_isp_wo_materials_item (item_id),
  CONSTRAINT fk_isp_wo_materials_wo FOREIGN KEY (work_order_id) REFERENCES isp_work_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_files (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  entity_type  VARCHAR(20)  NOT NULL,          -- work_order / payment / ticket / application / node
  entity_id    INT UNSIGNED NOT NULL,
  label        VARCHAR(120) NULL,
  stored_name  VARCHAR(120) NOT NULL,          -- the name on disk (storage/isp/...), never the uploaded name
  original_name VARCHAR(160) NULL,
  mime         VARCHAR(80)  NOT NULL,
  size_bytes   INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by  INT UNSIGNED NULL,
  portal_user_id INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_isp_files_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_equipment (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  item_id       INT UNSIGNED NOT NULL,          -- the inventory item (ONU, router, set-top box ...)
  serial_no     VARCHAR(60)  NOT NULL,
  mac_address   VARCHAR(30)  NULL,
  ownership     VARCHAR(12)  NOT NULL DEFAULT 'company',   -- company (rented / lent) / customer (sold)
  status        VARCHAR(16)  NOT NULL DEFAULT 'in_stock',  -- in_stock / with_technician / installed / for_recovery / recovered / damaged / missing / disposed
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

CREATE TABLE IF NOT EXISTS isp_equipment_log (
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

-- ---------------------------------------------------------------------
--  Physical network: nodes (OLT, PON, splitters, NAPs, poles, cabinets ...), ports, cables, cores, splices
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS isp_net_nodes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  node_type     VARCHAR(20)  NOT NULL,     -- olt / olt_card / splitter / nap / fdp / pole / cabinet / splice_closure / patch_panel / switch / router / coax_amp / tap
  code          VARCHAR(40)  NOT NULL,
  name          VARCHAR(120) NOT NULL,
  parent_id     INT UNSIGNED NULL,         -- the enclosing node (a card in an OLT, a splitter in a cabinet)
  upstream_port_id INT UNSIGNED NULL,      -- the port that feeds it (a PON port feeds a splitter, a splitter port feeds a NAP)
  area_id       INT UNSIGNED NULL,
  branch_id     INT UNSIGNED NULL,
  latitude      DECIMAL(10,7) NULL,
  longitude     DECIMAL(10,7) NULL,
  address       VARCHAR(255) NULL,
  vendor        VARCHAR(60)  NULL,
  model         VARCHAR(60)  NULL,
  serial_no     VARCHAR(60)  NULL,
  split_ratio   VARCHAR(10)  NULL,         -- 1:8, 1:16 ...
  port_count    SMALLINT     NOT NULL DEFAULT 0,
  specs         VARCHAR(255) NULL,
  device_id     INT UNSIGNED NULL,         -- the managed device (isp_devices) behind an OLT / router
  installed_on  DATE         NULL,
  status        VARCHAR(12)  NOT NULL DEFAULT 'active',  -- planned / active / damaged / maintenance / retired
  notes         VARCHAR(255) NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NULL,
  UNIQUE KEY uq_isp_net_nodes_code (code),
  KEY ix_isp_net_nodes_type (node_type),
  KEY ix_isp_net_nodes_parent (parent_id),
  KEY ix_isp_net_nodes_upstream (upstream_port_id),
  KEY ix_isp_net_nodes_area (area_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_net_ports (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  node_id     INT UNSIGNED NOT NULL,
  port_no     SMALLINT     NOT NULL,
  port_type   VARCHAR(12)  NOT NULL DEFAULT 'out',  -- pon / out / drop / uplink / coax
  status      VARCHAR(12)  NOT NULL DEFAULT 'free', -- free / reserved / used / faulty
  account_id  INT UNSIGNED NULL,                    -- the subscriber whose drop it feeds (exclusive)
  application_id INT UNSIGNED NULL,                 -- reserved for an application
  label       VARCHAR(60)  NULL,
  updated_at  DATETIME     NULL,
  UNIQUE KEY uq_isp_net_ports_no (node_id, port_no),
  UNIQUE KEY uq_isp_net_ports_account (account_id),
  KEY ix_isp_net_ports_status (status),
  CONSTRAINT fk_isp_net_ports_node FOREIGN KEY (node_id) REFERENCES isp_net_nodes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_cable_segments (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(40)  NOT NULL,
  cable_type    VARCHAR(20)  NOT NULL DEFAULT 'fiber',   -- fiber / drop / coax / utp
  core_count    SMALLINT     NOT NULL DEFAULT 0,
  from_node_id  INT UNSIGNED NULL,
  to_node_id    INT UNSIGNED NULL,
  length_m      DECIMAL(10,2) NULL,
  path          TEXT         NULL,         -- "lat,lng;lat,lng;..." the route drawn on the map
  installed_on  DATE         NULL,
  status        VARCHAR(12)  NOT NULL DEFAULT 'active',  -- planned / active / damaged / cut / relocated / replaced / retired
  replaced_by_id INT UNSIGNED NULL,
  notes         VARCHAR(255) NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NULL,
  UNIQUE KEY uq_isp_cable_segments_code (code),
  KEY ix_isp_cable_segments_from (from_node_id),
  KEY ix_isp_cable_segments_to (to_node_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_cable_cores (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  segment_id   INT UNSIGNED NOT NULL,
  core_no      SMALLINT     NOT NULL,
  color        VARCHAR(20)  NULL,
  status       VARCHAR(12)  NOT NULL DEFAULT 'free',   -- free / used / reserved / broken
  assignment   VARCHAR(160) NULL,                      -- what the core carries (PON 1/1/3, backbone ...)
  UNIQUE KEY uq_isp_cable_cores (segment_id, core_no),
  CONSTRAINT fk_isp_cable_cores_seg FOREIGN KEY (segment_id) REFERENCES isp_cable_segments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_splices (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  closure_id    INT UNSIGNED NOT NULL,       -- the splice closure node
  in_core_id    INT UNSIGNED NOT NULL,
  out_core_id   INT UNSIGNED NOT NULL,
  loss_db       DECIMAL(6,3) NULL,
  spliced_on    DATE         NULL,
  notes         VARCHAR(160) NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_isp_splices_closure (closure_id),
  CONSTRAINT fk_isp_splices_closure FOREIGN KEY (closure_id) REFERENCES isp_net_nodes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_node_events (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  node_id      INT UNSIGNED NULL,
  segment_id   INT UNSIGNED NULL,
  event_type   VARCHAR(20)  NOT NULL,        -- installed / maintenance / damaged / repaired / relocated / replaced / retired
  event_date   DATE         NOT NULL,
  remarks      VARCHAR(255) NULL,
  work_order_id INT UNSIGNED NULL,
  user_id      INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_isp_node_events_node (node_id),
  KEY ix_isp_node_events_seg (segment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Network integration: devices and the operation queue
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS isp_devices (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(80)  NOT NULL,
  adapter         VARCHAR(20)  NOT NULL,     -- mikrotik / radius / olt_http / tr069 / simulator / manual
  host            VARCHAR(120) NULL,
  port            INT          NULL,
  use_tls         TINYINT(1)   NOT NULL DEFAULT 1,
  username        VARCHAR(80)  NULL,
  secret_enc      TEXT         NULL,         -- encrypted password / API token (IspCrypto)
  options         TEXT         NULL,         -- key=value lines (radius database, profile names, base url ...)
  branch_id       INT UNSIGNED NULL,
  is_default      TINYINT(1)   NOT NULL DEFAULT 0,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  last_ok_at      DATETIME     NULL,
  last_error      VARCHAR(255) NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_net_ops (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  op_no           VARCHAR(30)  NOT NULL,
  account_id      INT UNSIGNED NOT NULL,
  device_id       INT UNSIGNED NULL,
  op_type         VARCHAR(16)  NOT NULL,     -- provision / activate / disconnect / reconnect / suspend / change_plan / reset_session / status / deprovision
  reason          VARCHAR(255) NULL,
  source_type     VARCHAR(20)  NULL,         -- disc_queue / reconnection / work_order / manual / plan_change
  source_id       BIGINT UNSIGNED NULL,
  idem_key        VARCHAR(80)  NOT NULL,     -- one live operation per account and intent
  state           VARCHAR(12)  NOT NULL DEFAULT 'requested', -- requested / queued / sent / confirmed / failed / cancelled / manual
  simulated       TINYINT(1)   NOT NULL DEFAULT 0,           -- 1 = the integration simulator answered, not a real device
  attempts        SMALLINT     NOT NULL DEFAULT 0,
  max_attempts    SMALLINT     NOT NULL DEFAULT 5,
  next_try_at     DATETIME     NULL,
  payload         TEXT         NULL,
  response        TEXT         NULL,
  error           VARCHAR(255) NULL,
  requested_by    INT UNSIGNED NULL,
  approved_by     INT UNSIGNED NULL,
  work_order_id   INT UNSIGNED NULL,         -- the manual work order when no device can do it
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

CREATE TABLE IF NOT EXISTS isp_net_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  op_id       BIGINT UNSIGNED NULL,
  device_id   INT UNSIGNED NULL,
  account_id  INT UNSIGNED NULL,
  direction   VARCHAR(8)   NOT NULL DEFAULT 'out',  -- out (command) / in (answer) / info
  command     VARCHAR(255) NOT NULL,                -- secrets are never written here
  result      VARCHAR(255) NULL,
  ok          TINYINT(1)   NOT NULL DEFAULT 0,
  simulated   TINYINT(1)   NOT NULL DEFAULT 0,
  user_id     INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_isp_net_logs_op (op_id),
  KEY ix_isp_net_logs_account (account_id),
  KEY ix_isp_net_logs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Billing
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS isp_billing_runs (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_no        VARCHAR(30)  NOT NULL,
  bill_date     DATE         NOT NULL,       -- the invoice date of the run
  cycle_day     TINYINT      NULL,           -- NULL: every account due on or before the bill date
  branch_id     INT UNSIGNED NULL,
  status        VARCHAR(12)  NOT NULL DEFAULT 'draft',   -- draft / approved / posted / cancelled
  invoice_count INT          NOT NULL DEFAULT 0,
  total_amount  DECIMAL(15,2) NOT NULL DEFAULT 0,
  warnings      TEXT         NULL,           -- the validation report
  created_by    INT UNSIGNED NULL,
  approved_by   INT UNSIGNED NULL,
  approved_at   DATETIME     NULL,
  posted_by     INT UNSIGNED NULL,
  posted_at     DATETIME     NULL,
  source        VARCHAR(12)  NOT NULL DEFAULT 'manual',  -- manual / scheduler
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_billing_runs_no (run_no),
  KEY ix_isp_billing_runs_date (bill_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_invoices (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_no      VARCHAR(30)  NOT NULL,         -- immutable once issued
  account_id      INT UNSIGNED NOT NULL,
  run_id          INT UNSIGNED NULL,
  kind            VARCHAR(12)  NOT NULL DEFAULT 'regular',   -- regular / initial / final / manual / plan_change
  period_key      VARCHAR(60)  NULL,             -- account + kind + period: the same charge is never billed twice
  period_start    DATE         NULL,
  period_end      DATE         NULL,
  invoice_date    DATE         NOT NULL,
  due_date        DATE         NOT NULL,
  status          VARCHAR(16)  NOT NULL DEFAULT 'draft',     -- draft / approved / posted / partially_paid / paid / overdue / disputed / adjusted / voided
  vat_registered  TINYINT(1)   NOT NULL DEFAULT 1,
  subtotal        DECIMAL(15,2) NOT NULL DEFAULT 0,          -- before VAT, after discounts
  discount_total  DECIMAL(15,2) NOT NULL DEFAULT 0,
  vat_total       DECIMAL(15,2) NOT NULL DEFAULT 0,
  total           DECIMAL(15,2) NOT NULL DEFAULT 0,
  paid_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,          -- allocated payments
  credit_amount   DECIMAL(15,2) NOT NULL DEFAULT 0,          -- credit adjustments and write-offs applied
  balance         DECIMAL(15,2) NOT NULL DEFAULT 0,          -- open amount
  previous_balance DECIMAL(15,2) NOT NULL DEFAULT 0,         -- shown on the invoice only
  txn_id          BIGINT UNSIGNED NULL,                      -- the ledger invoice (transactions)
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

CREATE TABLE IF NOT EXISTS isp_invoice_lines (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_id     INT UNSIGNED NOT NULL,
  line_no        SMALLINT     NOT NULL,
  line_type      VARCHAR(20)  NOT NULL,      -- subscription / prorate / installation / router_rental / static_ip / addon / one_time / discount / late_fee / reconnection_fee / outage_credit / deposit / termination_fee / adjustment
  description    VARCHAR(255) NOT NULL,
  qty            DECIMAL(12,4) NOT NULL DEFAULT 1,
  unit_price     DECIMAL(15,4) NOT NULL DEFAULT 0,   -- the price as charged (snapshot)
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,   -- before VAT
  vat_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,
  vat_mode       VARCHAR(12)  NOT NULL DEFAULT 'inclusive',
  income_account_id INT UNSIGNED NULL,
  plan_id        INT UNSIGNED NULL,
  plan_price     DECIMAL(15,2) NULL,                 -- the monthly fee of the plan when billed (snapshot)
  charge_id      INT UNSIGNED NULL,
  period_start   DATE         NULL,
  period_end     DATE         NULL,
  KEY ix_isp_invoice_lines_inv (invoice_id),
  CONSTRAINT fk_isp_invoice_lines_inv FOREIGN KEY (invoice_id) REFERENCES isp_invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_charges (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id    INT UNSIGNED NOT NULL,
  charge_type   VARCHAR(20)  NOT NULL,      -- installation / late_fee / reconnection_fee / one_time / equipment / deposit / termination_fee / prorate / plan_change / outage_credit / debit_adjustment
  description   VARCHAR(255) NOT NULL,
  qty           DECIMAL(12,4) NOT NULL DEFAULT 1,
  amount        DECIMAL(15,2) NOT NULL,     -- gross (VAT as the vat_mode says); negative for a credit
  vat_mode      VARCHAR(12)  NOT NULL DEFAULT 'inclusive',
  income_account_id INT UNSIGNED NULL,
  charge_date   DATE         NOT NULL,
  status        VARCHAR(12)  NOT NULL DEFAULT 'pending',   -- pending / billed / cancelled
  invoice_id    INT UNSIGNED NULL,
  charge_key    VARCHAR(80)  NOT NULL,      -- idempotency: LATE-<invoice>, RECON-<request>, INST-<account> ...
  source_type   VARCHAR(20)  NULL,
  source_id     BIGINT UNSIGNED NULL,
  cancel_reason VARCHAR(255) NULL,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_charges_key (charge_key),
  KEY ix_isp_charges_account (account_id, status),
  CONSTRAINT fk_isp_charges_account FOREIGN KEY (account_id) REFERENCES isp_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_adjustments (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  adj_no        VARCHAR(30)  NOT NULL,
  account_id    INT UNSIGNED NOT NULL,
  invoice_id    INT UNSIGNED NULL,
  adj_type      VARCHAR(20)  NOT NULL,      -- credit / debit / write_off / outage_credit / penalty_waiver / dispute_credit
  amount        DECIMAL(15,2) NOT NULL,     -- positive
  reason        VARCHAR(255) NOT NULL,
  status        VARCHAR(12)  NOT NULL DEFAULT 'pending',   -- pending / approved / posted / rejected
  requested_by  INT UNSIGNED NULL,
  approved_by   INT UNSIGNED NULL,
  approved_at   DATETIME     NULL,
  rejected_reason VARCHAR(255) NULL,
  txn_id        BIGINT UNSIGNED NULL,
  source_type   VARCHAR(20)  NULL,          -- outage / dispute / manual
  source_id     INT UNSIGNED NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_adjustments_no (adj_no),
  KEY ix_isp_adjustments_account (account_id),
  KEY ix_isp_adjustments_status (status),
  CONSTRAINT fk_isp_adjustments_account FOREIGN KEY (account_id) REFERENCES isp_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_disputes (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  dispute_no      VARCHAR(30)  NOT NULL,
  account_id      INT UNSIGNED NOT NULL,
  invoice_id      INT UNSIGNED NULL,
  amount_disputed DECIMAL(15,2) NOT NULL DEFAULT 0,
  reason          VARCHAR(255) NOT NULL,
  status          VARCHAR(20)  NOT NULL DEFAULT 'open',   -- open / investigating / resolved_credit / resolved_no_credit / withdrawn
  protect         TINYINT(1)   NOT NULL DEFAULT 1,        -- the disputed amount does not count for disconnection
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

CREATE TABLE IF NOT EXISTS isp_arrangements (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  arrangement_no  VARCHAR(30)  NOT NULL,
  account_id      INT UNSIGNED NOT NULL,
  arr_type        VARCHAR(20)  NOT NULL DEFAULT 'installment',   -- extension / installment / partial_settlement
  total_amount    DECIMAL(15,2) NOT NULL,
  status          VARCHAR(12)  NOT NULL DEFAULT 'pending',       -- pending / active / completed / defaulted / cancelled / rejected
  protect         TINYINT(1)   NOT NULL DEFAULT 1,               -- no disconnection while it is kept
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

CREATE TABLE IF NOT EXISTS isp_arrangement_lines (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  arrangement_id  INT UNSIGNED NOT NULL,
  due_date        DATE         NOT NULL,
  amount          DECIMAL(15,2) NOT NULL,
  paid_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,
  status          VARCHAR(10)  NOT NULL DEFAULT 'open',   -- open / paid / missed
  KEY ix_isp_arrangement_lines_arr (arrangement_id),
  CONSTRAINT fk_isp_arrangement_lines_arr FOREIGN KEY (arrangement_id) REFERENCES isp_arrangements(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_exemptions (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id    INT UNSIGNED NOT NULL,
  reason        VARCHAR(255) NOT NULL,
  valid_from    DATE         NOT NULL,
  valid_until   DATE         NOT NULL,
  status        VARCHAR(10)  NOT NULL DEFAULT 'active',    -- active / revoked
  approved_by   INT UNSIGNED NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_isp_exemptions_account (account_id, valid_until),
  CONSTRAINT fk_isp_exemptions_account FOREIGN KEY (account_id) REFERENCES isp_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Collections
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS isp_shifts (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  shift_no       VARCHAR(30)  NOT NULL,
  user_id        INT UNSIGNED NOT NULL,
  branch_id      INT UNSIGNED NULL,
  cash_account_id INT UNSIGNED NOT NULL,     -- the drawer (Cash on Hand) the collections go to
  opened_at      DATETIME     NOT NULL,
  opening_cash   DECIMAL(15,2) NOT NULL DEFAULT 0,
  closed_at      DATETIME     NULL,
  expected_cash  DECIMAL(15,2) NULL,
  counted_cash   DECIMAL(15,2) NULL,
  denominations  TEXT         NULL,          -- "1000=3;500=2;..."
  over_short     DECIMAL(15,2) NULL,
  over_short_txn_id BIGINT UNSIGNED NULL,
  remitted_amount DECIMAL(15,2) NULL,
  remit_txn_id   BIGINT UNSIGNED NULL,       -- the deposit / transfer of the cash
  status         VARCHAR(12)  NOT NULL DEFAULT 'open',    -- open / closed / certified
  certified_by   INT UNSIGNED NULL,
  certified_at   DATETIME     NULL,
  notes          VARCHAR(255) NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_shifts_no (shift_no),
  KEY ix_isp_shifts_user (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_payments (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  receipt_no       VARCHAR(30)  NOT NULL,     -- payment acknowledgment / collection receipt number
  account_id       INT UNSIGNED NOT NULL,
  pay_date         DATE         NOT NULL,
  received_at      DATETIME     NOT NULL,
  amount           DECIMAL(15,2) NOT NULL,
  channel          VARCHAR(16)  NOT NULL,     -- cash / bank_deposit / bank_transfer / gcash / maya / check / gateway / agent / auto_debit / other
  source           VARCHAR(12)  NOT NULL DEFAULT 'counter',  -- counter / portal / gateway / field / auto_debit / import
  reference_no     VARCHAR(80)  NULL,
  ref_dedupe       VARCHAR(120) NULL,         -- channel + reference: one payment per bank / e-wallet reference
  check_no         VARCHAR(40)  NULL,
  check_bank       VARCHAR(80)  NULL,
  status           VARCHAR(12)  NOT NULL DEFAULT 'posted',   -- pending / posted / rejected / reversed
  shift_id         INT UNSIGNED NULL,
  collector_user_id INT UNSIGNED NULL,
  cash_account_id  INT UNSIGNED NULL,         -- the account the money went to
  gateway_event_id INT UNSIGNED NULL,
  gateway_fee      DECIMAL(15,2) NOT NULL DEFAULT 0,
  applied_amount   DECIMAL(15,2) NOT NULL DEFAULT 0,
  unapplied_amount DECIMAL(15,2) NOT NULL DEFAULT 0,          -- the advance payment / credit left
  txn_id           BIGINT UNSIGNED NULL,      -- the ledger payment
  fee_txn_id       BIGINT UNSIGNED NULL,      -- the gateway fee entry
  sav_txn_id       INT UNSIGNED NULL,         -- the savings auto-debit row (sav_txns)
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

CREATE TABLE IF NOT EXISTS isp_payment_allocs (
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

CREATE TABLE IF NOT EXISTS isp_gateway_events (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  provider      VARCHAR(20)  NOT NULL,       -- paymongo / xendit / dragonpay / generic
  event_id      VARCHAR(120) NOT NULL,       -- the provider's own event id: a repeat is never posted twice
  event_type    VARCHAR(60)  NULL,
  signature_ok  TINYINT(1)   NOT NULL DEFAULT 0,
  account_no    VARCHAR(30)  NULL,
  amount        DECIMAL(15,2) NULL,
  fee           DECIMAL(15,2) NULL,
  reference_no  VARCHAR(120) NULL,
  status        VARCHAR(12)  NOT NULL DEFAULT 'received',   -- received / posted / ignored / rejected / failed
  payment_id    INT UNSIGNED NULL,
  message       VARCHAR(255) NULL,
  payload       MEDIUMTEXT   NULL,
  remote_ip     VARCHAR(45)  NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_gateway_events (provider, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Disconnection and reconnection
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS isp_disc_rules (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code               VARCHAR(20)  NOT NULL,
  name               VARCHAR(120) NOT NULL,
  rule_type          VARCHAR(20)  NOT NULL,     -- overdue / contract_end / customer_request / fraud / technical / administrative / cancellation
  grace_days         SMALLINT     NOT NULL DEFAULT 5,       -- days after the due date before the account is eligible
  min_overdue_amount DECIMAL(15,2) NOT NULL DEFAULT 0,      -- below this the account is not disconnected
  min_overdue_invoices SMALLINT   NOT NULL DEFAULT 1,
  final_notice_hours SMALLINT     NOT NULL DEFAULT 24,      -- the final warning goes out this long before execution (0: none)
  require_approval   TINYINT(1)   NOT NULL DEFAULT 1,       -- a supervisor approves the queue before it runs
  auto_execute       TINYINT(1)   NOT NULL DEFAULT 0,       -- the scheduler executes approved / non-approval items itself
  run_days           VARCHAR(20)  NOT NULL DEFAULT '1,2,3,4,5',  -- ISO weekdays the executions may run
  run_from_hour      TINYINT      NOT NULL DEFAULT 9,
  run_to_hour        TINYINT      NOT NULL DEFAULT 16,
  exclude_types      VARCHAR(160) NOT NULL DEFAULT 'deposit',     -- invoice line types not counted as arrears
  protect_disputes   TINYINT(1)   NOT NULL DEFAULT 1,
  protect_arrangements TINYINT(1) NOT NULL DEFAULT 1,
  reconnection_fee   DECIMAL(15,2) NULL,                    -- NULL: the plan's fee
  is_active          TINYINT(1)   NOT NULL DEFAULT 1,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_disc_rules_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_disc_queue (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  queue_no        VARCHAR(30)  NOT NULL,
  account_id      INT UNSIGNED NOT NULL,
  rule_id         INT UNSIGNED NULL,
  reason_type     VARCHAR(20)  NOT NULL,       -- overdue / contract_end / customer_request / fraud / technical / administrative / cancellation
  reason          VARCHAR(255) NOT NULL,
  eligible_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  oldest_due      DATE         NULL,
  live_key        VARCHAR(60)  NULL,           -- account id while the item is live: one live item per account
  status          VARCHAR(16)  NOT NULL DEFAULT 'candidate',  -- candidate / approved / notified / executing / disconnected / failed / skipped / cancelled
  skip_reason     VARCHAR(255) NULL,
  final_notice_at DATETIME     NULL,
  execute_after   DATETIME     NULL,
  approved_by     INT UNSIGNED NULL,
  approved_at     DATETIME     NULL,
  net_op_id       BIGINT UNSIGNED NULL,
  executed_at     DATETIME     NULL,
  initiated_by    VARCHAR(30)  NOT NULL DEFAULT 'scheduler',   -- scheduler / user:<id>
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL,
  UNIQUE KEY uq_isp_disc_queue_no (queue_no),
  UNIQUE KEY uq_isp_disc_queue_live (live_key),
  KEY ix_isp_disc_queue_status (status),
  KEY ix_isp_disc_queue_account (account_id),
  CONSTRAINT fk_isp_disc_queue_account FOREIGN KEY (account_id) REFERENCES isp_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_reconnections (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recon_no        VARCHAR(30)  NOT NULL,
  account_id      INT UNSIGNED NOT NULL,
  recon_type      VARCHAR(16)  NOT NULL DEFAULT 'automatic',   -- automatic / manual / temporary / arrangement / partial / emergency
  status          VARCHAR(16)  NOT NULL DEFAULT 'requested',   -- requested / pending_approval / approved / executing / restored / failed / cancelled / expired
  payment_id      INT UNSIGNED NULL,
  live_key        VARCHAR(60)  NULL,
  fee_amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
  fee_charge_id   INT UNSIGNED NULL,
  expires_at      DATETIME     NULL,           -- a temporary reconnection
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

-- ---------------------------------------------------------------------
--  Support tickets and outages
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS isp_tickets (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ticket_no       VARCHAR(30)  NOT NULL,
  account_id      INT UNSIGNED NULL,
  ticket_type     VARCHAR(24)  NOT NULL,       -- no_internet / slow / intermittent / damaged_cable / broken_device / low_signal / outage / billing / wrong_disconnection / reconnection_failure / relocation / plan_change / equipment_replacement / other
  subject         VARCHAR(160) NOT NULL,
  description     TEXT         NULL,
  priority        VARCHAR(10)  NOT NULL DEFAULT 'normal',   -- low / normal / high / critical
  status          VARCHAR(12)  NOT NULL DEFAULT 'new',      -- new / classified / assigned / in_progress / on_hold / resolved / verified / closed
  source          VARCHAR(12)  NOT NULL DEFAULT 'phone',    -- phone / walkin / portal / sms / email / field / system
  ntc_complaint   TINYINT(1)   NOT NULL DEFAULT 0,          -- an NTC consumer complaint (kept for the regulator)
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

CREATE TABLE IF NOT EXISTS isp_ticket_notes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ticket_id   INT UNSIGNED NOT NULL,
  note        TEXT         NOT NULL,
  from_status VARCHAR(12)  NULL,
  to_status   VARCHAR(12)  NULL,
  is_public   TINYINT(1)   NOT NULL DEFAULT 0,   -- shown to the subscriber on the portal
  user_id     INT UNSIGNED NULL,
  portal_user_id INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_isp_ticket_notes_ticket (ticket_id),
  CONSTRAINT fk_isp_ticket_notes_ticket FOREIGN KEY (ticket_id) REFERENCES isp_tickets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_outages (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  outage_no       VARCHAR(30)  NOT NULL,
  title           VARCHAR(160) NOT NULL,
  scope           VARCHAR(12)  NOT NULL DEFAULT 'node',      -- node / area / network
  node_id         INT UNSIGNED NULL,
  area_id         INT UNSIGNED NULL,
  status          VARCHAR(12)  NOT NULL DEFAULT 'suspected', -- suspected / verified / in_repair / restored / closed / false_alarm
  is_planned      TINYINT(1)   NOT NULL DEFAULT 0,           -- a maintenance window announced ahead
  started_at      DATETIME     NOT NULL,
  verified_at     DATETIME     NULL,
  restored_at     DATETIME     NULL,
  closed_at       DATETIME     NULL,
  root_cause      VARCHAR(255) NULL,
  repair_notes    TEXT         NULL,
  sla_hours       DECIMAL(8,2) NOT NULL DEFAULT 24,          -- downtime beyond this earns a service credit
  credit_policy   VARCHAR(12)  NOT NULL DEFAULT 'prorated',  -- none / prorated / fixed
  credit_fixed    DECIMAL(15,2) NOT NULL DEFAULT 0,
  credits_status  VARCHAR(12)  NOT NULL DEFAULT 'none',      -- none / proposed / approved / posted
  affected_count  INT          NOT NULL DEFAULT 0,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL,
  UNIQUE KEY uq_isp_outages_no (outage_no),
  KEY ix_isp_outages_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_outage_accounts (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  outage_id     INT UNSIGNED NOT NULL,
  account_id    INT UNSIGNED NOT NULL,
  how_found     VARCHAR(12)  NOT NULL DEFAULT 'topology',   -- topology / area / manual / ticket
  downtime_hours DECIMAL(8,2) NULL,
  credit_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  adjustment_id INT UNSIGNED NULL,
  notified_at   DATETIME     NULL,
  UNIQUE KEY uq_isp_outage_accounts (outage_id, account_id),
  CONSTRAINT fk_isp_outage_accounts_outage FOREIGN KEY (outage_id) REFERENCES isp_outages(id),
  CONSTRAINT fk_isp_outage_accounts_account FOREIGN KEY (account_id) REFERENCES isp_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_announcements (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title       VARCHAR(160) NOT NULL,
  body        TEXT         NOT NULL,
  area_id     INT UNSIGNED NULL,              -- NULL: everyone
  outage_id   INT UNSIGNED NULL,
  starts_at   DATETIME     NOT NULL,
  ends_at     DATETIME     NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_isp_announcements_dates (starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Customer portal
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS isp_portal_users (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  isp_customer_id INT UNSIGNED NOT NULL,
  username        VARCHAR(60)  NOT NULL,      -- the customer number or an e-mail
  password_hash   VARCHAR(255) NOT NULL,
  status          VARCHAR(10)  NOT NULL DEFAULT 'active',   -- active / locked / inactive
  must_change_password TINYINT(1) NOT NULL DEFAULT 1,
  failed_attempts INT          NOT NULL DEFAULT 0,
  last_login_at   DATETIME     NULL,
  last_login_ip   VARCHAR(45)  NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_portal_users_username (username),
  UNIQUE KEY uq_isp_portal_users_customer (isp_customer_id),
  CONSTRAINT fk_isp_portal_users_customer FOREIGN KEY (isp_customer_id) REFERENCES isp_customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_service_requests (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  request_no      VARCHAR(30)  NOT NULL,
  account_id      INT UNSIGNED NOT NULL,
  request_type    VARCHAR(20)  NOT NULL,     -- reconnection / relocation / upgrade / downgrade / suspension / cancellation / profile_update
  details         TEXT         NULL,
  requested_plan_id INT UNSIGNED NULL,
  new_address     VARCHAR(255) NULL,
  status          VARCHAR(12)  NOT NULL DEFAULT 'open',      -- open / in_progress / done / declined / cancelled
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

-- ---------------------------------------------------------------------
--  Notifications
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS isp_notif_templates (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_code  VARCHAR(40)  NOT NULL,
  channel     VARCHAR(8)   NOT NULL,     -- sms / email / inapp
  subject     VARCHAR(160) NULL,
  body        TEXT         NOT NULL,     -- {name} {account_no} {amount} {due_date} ...
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  updated_at  DATETIME     NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_notif_templates (event_code, channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_notifications (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_code    VARCHAR(40)  NOT NULL,
  channel       VARCHAR(8)   NOT NULL,
  account_id    INT UNSIGNED NULL,
  isp_customer_id INT UNSIGNED NULL,
  recipient     VARCHAR(160) NULL,
  subject       VARCHAR(160) NULL,
  body          TEXT         NOT NULL,
  dedupe_key    VARCHAR(120) NOT NULL,   -- event + reference + channel: the same message is never sent twice
  status        VARCHAR(12)  NOT NULL DEFAULT 'queued',  -- queued / sent / failed / skipped / read
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

-- ---------------------------------------------------------------------
--  Scheduled jobs
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS isp_jobs (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  job_code      VARCHAR(40)  NOT NULL,
  name          VARCHAR(120) NOT NULL,
  description   VARCHAR(255) NULL,
  interval_min  INT          NOT NULL DEFAULT 60,      -- how often it is due
  run_hour      TINYINT      NULL,                     -- a daily job: the hour it runs (NULL: every interval)
  is_enabled    TINYINT(1)   NOT NULL DEFAULT 1,
  max_retries   SMALLINT     NOT NULL DEFAULT 3,
  fail_count    SMALLINT     NOT NULL DEFAULT 0,       -- consecutive failures
  lock_token    VARCHAR(40)  NULL,
  locked_until  DATETIME     NULL,
  last_started_at DATETIME   NULL,
  last_finished_at DATETIME  NULL,
  last_status   VARCHAR(12)  NULL,                     -- ok / failed / skipped / running
  last_message  VARCHAR(255) NULL,
  next_run_at   DATETIME     NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_jobs_code (job_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isp_job_runs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  job_code      VARCHAR(40)  NOT NULL,
  started_at    DATETIME     NOT NULL,
  finished_at   DATETIME     NULL,
  status        VARCHAR(12)  NOT NULL DEFAULT 'running',  -- running / ok / failed / skipped
  processed     INT          NOT NULL DEFAULT 0,
  message       TEXT         NULL,
  trigger_by    VARCHAR(30)  NOT NULL DEFAULT 'cron',     -- cron / user:<id> / retry
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_isp_job_runs_code (job_code, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  The bridge to the general ledger
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS isp_postings (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_key      VARCHAR(64)  NOT NULL,      -- INV-<id>, PAY-<id>, ADJ-<id>, ISS-<wo>-<id>, FEE-<payment> ...
  source_type    VARCHAR(24)  NOT NULL,      -- invoice / payment / adjustment / credit_apply / issuance / return / gateway_fee / auto_debit / over_short / commission / deposit_refund / reversal
  source_id      VARCHAR(40)  NOT NULL,
  doc_no         VARCHAR(40)  NULL,
  isp_account_id INT UNSIGNED NULL,
  party_id       INT UNSIGNED NULL,
  txn_id         BIGINT UNSIGNED NULL,       -- transactions.id
  txn_type       VARCHAR(30)  NULL,
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  status         VARCHAR(10)  NOT NULL DEFAULT 'posted',  -- posted / voided / failed
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
--  Menus and pages (a new install gets them from the page registry; an upgraded database here)
-- ---------------------------------------------------------------------

INSERT IGNORE INTO modules (code, name, icon, sort_order) VALUES
  ('isp', 'Subscribers', 'router', 2),
  ('ispbill', 'Billing & Collection', 'receipt-cutoff', 3),
  ('ispnet', 'Network & Service Control', 'diagram-3', 4),
  ('ispfield', 'Field Service', 'tools', 5),
  ('ispset', 'ISP Setup', 'sliders', 6);

-- the pages themselves are written by IspSetup::installDefaults() from app/IspPages.php (tools/install.php and
-- ISP Setup > System Defaults call it), with the roles and their page access; the probe of app/Schema.php waits
-- for the dashboard page, the last one written.

-- columns widened after the first test databases were made (safe to run again: the same definition)
ALTER TABLE isp_disputes MODIFY status VARCHAR(20) NOT NULL DEFAULT 'open';
ALTER TABLE isp_arrangements MODIFY arr_type VARCHAR(20) NOT NULL DEFAULT 'installment';
ALTER TABLE isp_commissions MODIFY basis VARCHAR(30) NOT NULL;

-- ---------------------------------------------------------------------
--  Done.
-- ---------------------------------------------------------------------
