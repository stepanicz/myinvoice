-- Pravidelné faktury (custom feature, prefix 9000 aby se nemíchalo s upstream migracemi)
--
-- Šablona = "kopie faktury, která se denně cron-em vystavuje když přijde čas".
-- Každý záznam má frekvenci (monthly/quarterly/yearly), start/end, next_run_date
-- (= příští den vystavení) a flag auto_send (poslat e-mail po vystavení).
--
-- Položky šablony se kopírují do nové faktury při vystavení; v description lze
-- použít proměnné jako {MMMM}, {YYYY}, {prev:MMMM}, {next:YYYY}, {Q}, {period}
-- (viz PlaceholderRenderer).

CREATE TABLE IF NOT EXISTS recurring_invoice_templates (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id         TINYINT UNSIGNED NOT NULL,
  name                VARCHAR(190) NOT NULL,
  client_id           BIGINT UNSIGNED NOT NULL,
  project_id          BIGINT UNSIGNED NULL,
  invoice_type        ENUM('invoice','proforma') NOT NULL DEFAULT 'invoice',
  currency_id         INT UNSIGNED NOT NULL,
  language            ENUM('cs','en') NOT NULL DEFAULT 'cs',
  reverse_charge      TINYINT(1) NOT NULL DEFAULT 0,
  payment_due_days    SMALLINT UNSIGNED NOT NULL DEFAULT 14,
  note_above_items    TEXT NULL,
  note_below_items    TEXT NULL,

  frequency           ENUM('monthly','quarterly','yearly') NOT NULL,
  start_date          DATE NOT NULL,
  end_date            DATE NULL,
  next_run_date       DATE NOT NULL,
  status              ENUM('active','paused','ended') NOT NULL DEFAULT 'active',
  auto_send           TINYINT(1) NOT NULL DEFAULT 1,

  last_run_at         TIMESTAMP NULL,
  last_invoice_id     BIGINT UNSIGNED NULL,
  run_count           INT UNSIGNED NOT NULL DEFAULT 0,

  created_by          BIGINT UNSIGNED NOT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_rit_due       (status, next_run_date),
  KEY idx_rit_client    (client_id),
  KEY idx_rit_supplier  (supplier_id, status),

  CONSTRAINT fk_rit_supplier FOREIGN KEY (supplier_id)     REFERENCES supplier(id),
  CONSTRAINT fk_rit_client   FOREIGN KEY (client_id)       REFERENCES clients(id),
  CONSTRAINT fk_rit_project  FOREIGN KEY (project_id)      REFERENCES projects(id),
  CONSTRAINT fk_rit_currency FOREIGN KEY (currency_id)     REFERENCES currencies(id),
  CONSTRAINT fk_rit_user     FOREIGN KEY (created_by)      REFERENCES users(id),
  CONSTRAINT fk_rit_lastinv  FOREIGN KEY (last_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recurring_invoice_template_items (
  id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_id              BIGINT UNSIGNED NOT NULL,
  description              TEXT NOT NULL,
  quantity                 DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  unit                     VARCHAR(20) NOT NULL DEFAULT 'ks',
  unit_price_without_vat   DECIMAL(12,2) NOT NULL,
  vat_rate_id              INT UNSIGNED NOT NULL,
  order_index              INT NOT NULL DEFAULT 0,

  KEY idx_riti_template (template_id, order_index),
  CONSTRAINT fk_riti_template FOREIGN KEY (template_id) REFERENCES recurring_invoice_templates(id) ON DELETE CASCADE,
  CONSTRAINT fk_riti_vat      FOREIGN KEY (vat_rate_id) REFERENCES vat_rates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
