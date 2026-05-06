-- Párování plateb z bankovních notifikačních mailů (custom feature, prefix 9000+).
--
-- Každý supplier dostane vlastní e-mail alias `payment-<10hex>@uctostepanovi.cz`,
-- který se nastaví v hostingu jako alias do schránky `parovaniplateb@uctostepanovi.cz`.
-- Cron pak fetchuje IMAP, podle Delivered-To rozliší dodavatele a per-bank parser
-- vytáhne částku/VS/účty. Mail = jeden synthetic řádek v `bank_statements` (source='email')
-- s 1 transakcí v `bank_transactions` → reuse existujícího StatementMatcheru a UI.

-- 1. supplier.payment_email_alias (per-supplier doručovací adresa)
ALTER TABLE supplier
  ADD COLUMN payment_email_alias VARCHAR(190) NULL AFTER tagline,
  ADD UNIQUE KEY uq_supplier_payment_alias (payment_email_alias);

-- 2. bank_statements: rozlišit zdroj (gpc / email) + napojit na supplier (multi-tenant)
ALTER TABLE bank_statements
  ADD COLUMN source ENUM('gpc','email') NOT NULL DEFAULT 'gpc' AFTER file_hash,
  ADD COLUMN supplier_id TINYINT UNSIGNED NULL AFTER source,
  ADD COLUMN source_meta JSON NULL AFTER supplier_id,
  ADD KEY idx_bs_supplier (supplier_id),
  ADD KEY idx_bs_source (source),
  ADD CONSTRAINT fk_bs_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id);

-- 3. Backfill: vygenerovat alias pro existující dodavatele (UUID() per-row → unique).
UPDATE supplier
SET payment_email_alias = CONCAT(
  'payment-',
  SUBSTRING(SHA2(CONCAT(id, '-', UUID(), '-', RAND()), 224), 1, 10),
  '@uctostepanovi.cz'
)
WHERE payment_email_alias IS NULL;
