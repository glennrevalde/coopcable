-- =====================================================================
--  G-SMaRT Coop Cable System
--  migration: class on cooperative transactions, journal entry changes with approval
--
--  What it changes
--    1. The class (Chart of Accounts > Class List, the same classes as the accounting books) kept with the
--       cooperative transactions that are saved first and posted later, or reported by class:
--         sav_batches.class_id              Batch Deduction
--         loan_waivers.class_id             Interest / Penalty Waiving
--         loan_applications.class_id        Loan Restructure / Renew
--       The journal entries themselves already carry the class (transactions.class_id, journal_lines.class_id).
--    2. journal_edit_requests: a change to a saved journal entry, with the explanation of the change, the entry as
--       it was and as proposed, waiting for (or decided by) an authorized approver. Its page "Journal Entry
--       Changes" is added to the Chart of Accounts menu, with the access of "Make Journal Entries".
--
--  Nothing is deleted and no posted amount changes. Safe to run more than once. MySQL 5.5.3+ / MariaDB 5.5+.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1 and 2. The address sub-group and the class columns, each with a key.
--     A table of a module that is not installed is skipped.
--     No foreign key is added: a class that is later deleted or deactivated is
--     shown as "(not on the list)" by the program.
-- ---------------------------------------------------------------------



SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sav_batches') = 0,
  'SELECT ''sav_batches: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sav_batches' AND column_name = 'class_id') = 0,
     'ALTER TABLE `sav_batches` ADD COLUMN `class_id` INT UNSIGNED NULL',
     'SELECT ''sav_batches: class_id already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sav_batches' AND column_name = 'class_id') = 0,
  'SELECT ''sav_batches: no class_id column to index''',
  IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'sav_batches' AND index_name = 'ix_sav_batches_class') = 0,
     'ALTER TABLE `sav_batches` ADD KEY `ix_sav_batches_class` (`class_id`)',
     'SELECT ''sav_batches: ix_sav_batches_class already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'loan_waivers') = 0,
  'SELECT ''loan_waivers: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'loan_waivers' AND column_name = 'class_id') = 0,
     'ALTER TABLE `loan_waivers` ADD COLUMN `class_id` INT UNSIGNED NULL',
     'SELECT ''loan_waivers: class_id already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'loan_waivers' AND column_name = 'class_id') = 0,
  'SELECT ''loan_waivers: no class_id column to index''',
  IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'loan_waivers' AND index_name = 'ix_loan_waivers_class') = 0,
     'ALTER TABLE `loan_waivers` ADD KEY `ix_loan_waivers_class` (`class_id`)',
     'SELECT ''loan_waivers: ix_loan_waivers_class already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'loan_applications') = 0,
  'SELECT ''loan_applications: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'loan_applications' AND column_name = 'class_id') = 0,
     'ALTER TABLE `loan_applications` ADD COLUMN `class_id` INT UNSIGNED NULL',
     'SELECT ''loan_applications: class_id already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'loan_applications' AND column_name = 'class_id') = 0,
  'SELECT ''loan_applications: no class_id column to index''',
  IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'loan_applications' AND index_name = 'ix_loan_applications_class') = 0,
     'ALTER TABLE `loan_applications` ADD KEY `ix_loan_applications_class` (`class_id`)',
     'SELECT ''loan_applications: ix_loan_applications_class already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;













-- ---------------------------------------------------------------------
--  3. Changes to saved journal entries, approved by a second user
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS journal_edit_requests (
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

SET @je_module = (SELECT module_id FROM pages WHERE page_key = 'coa.journal' LIMIT 1);
SET @je_sort   = (SELECT COALESCE(MAX(sort_order), 0) FROM pages WHERE page_key = 'coa.journal');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu, description)
SELECT 'coa.je_edits', @je_module, 'Journal Entry Changes', 'list', 'Transactions', @je_sort + 1, 1,
       'Changes to saved journal entries, with their explanation, waiting for approval.'
  FROM DUAL WHERE @je_module IS NOT NULL;

-- the roles that open Make Journal Entries open the changes page too (the Approve right decides who approves)
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, src.can_create, src.can_edit, src.can_delete, src.can_print, src.can_approve
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'coa.journal'
  JOIN pages dst ON dst.page_key = 'coa.je_edits';

-- ---------------------------------------------------------------------
--  Done. Sign in again (or open any page) and the program sees the new columns.
-- ---------------------------------------------------------------------
