-- Peptide Research Tracker
-- MySQL 8.0+
-- Clean-install schema only. No historical/backfill data is included.

CREATE DATABASE IF NOT EXISTS writes_peptides
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_0900_ai_ci;

USE writes_peptides;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS reminders;
DROP TABLE IF EXISTS scheduled_doses;
DROP TABLE IF EXISTS schedule_overrides;
DROP TABLE IF EXISTS administrations;
DROP TABLE IF EXISTS protocol_item_weekdays;
DROP TABLE IF EXISTS protocol_items;
DROP TABLE IF EXISTS protocols;
DROP TABLE IF EXISTS prepared_vials;
DROP TABLE IF EXISTS inventory_vial_events;
DROP TABLE IF EXISTS inventory_lots;
DROP TABLE IF EXISTS vial_presentations;
DROP TABLE IF EXISTS formulation_components;
DROP TABLE IF EXISTS formulations;
DROP TABLE IF EXISTS peptides;
DROP TABLE IF EXISTS weight_log;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE peptides (
    peptide_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    short_name VARCHAR(60) NULL,
    description TEXT NULL,
    half_life_hours DECIMAL(12,4) NULL,
    half_life_notes TEXT NULL,
    half_life_source VARCHAR(500) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (peptide_id),
    UNIQUE KEY uq_peptides_name (name),
    KEY idx_peptides_active_name (active, name)
) ENGINE=InnoDB;

CREATE TABLE formulations (
    formulation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (formulation_id),
    UNIQUE KEY uq_formulations_name (name),
    KEY idx_formulations_active_name (active, name)
) ENGINE=InnoDB;

CREATE TABLE formulation_components (
    formulation_component_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    formulation_id BIGINT UNSIGNED NOT NULL,
    peptide_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(14,4) NULL,
    amount_unit VARCHAR(20) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (formulation_component_id),
    UNIQUE KEY uq_formulation_component (formulation_id, peptide_id),
    KEY idx_fc_peptide (peptide_id),
    CONSTRAINT fk_fc_formulation
        FOREIGN KEY (formulation_id)
        REFERENCES formulations (formulation_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_fc_peptide
        FOREIGN KEY (peptide_id)
        REFERENCES peptides (peptide_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE vial_presentations (
    vial_presentation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    formulation_id BIGINT UNSIGNED NOT NULL,
    vial_amount DECIMAL(14,4) NOT NULL,
    vial_amount_unit VARCHAR(20) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (vial_presentation_id),
    UNIQUE KEY uq_vial_presentation (formulation_id, vial_amount, vial_amount_unit),
    KEY idx_vial_formulation (formulation_id),
    CONSTRAINT fk_vial_formulation
        FOREIGN KEY (formulation_id)
        REFERENCES formulations (formulation_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE inventory_lots (
    inventory_lot_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vial_presentation_id BIGINT UNSIGNED NOT NULL,
    quantity_received INT UNSIGNED NOT NULL,
    lot_number VARCHAR(100) NULL,
    supplier VARCHAR(150) NULL,
    received_at DATE NULL,
    expires_at DATE NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (inventory_lot_id),
    KEY idx_inventory_lot_presentation (vial_presentation_id),
    CONSTRAINT fk_inventory_lot_presentation
        FOREIGN KEY (vial_presentation_id)
        REFERENCES vial_presentations (vial_presentation_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE inventory_vial_events (
    inventory_vial_event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    inventory_lot_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM('opened','discarded') NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    notes VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (inventory_vial_event_id),
    KEY idx_inventory_events_lot_type (inventory_lot_id, event_type),
    CONSTRAINT fk_inventory_event_lot
        FOREIGN KEY (inventory_lot_id)
        REFERENCES inventory_lots (inventory_lot_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE prepared_vials (
    prepared_vial_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    inventory_lot_id BIGINT UNSIGNED NULL,
    vial_presentation_id BIGINT UNSIGNED NOT NULL,
    mixing_recipe_id BIGINT UNSIGNED NULL COMMENT 'Reserved for optional/future mixing recipe support.',
    prepared_at DATETIME NOT NULL,
    status ENUM('active','finished','discarded') NOT NULL DEFAULT 'active',
    completed_at DATETIME NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (prepared_vial_id),
    KEY idx_prepared_vial_status (vial_presentation_id, status),
    KEY idx_prepared_inventory_lot (inventory_lot_id),
    CONSTRAINT fk_prepared_inventory_lot
        FOREIGN KEY (inventory_lot_id)
        REFERENCES inventory_lots (inventory_lot_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_prepared_presentation
        FOREIGN KEY (vial_presentation_id)
        REFERENCES vial_presentations (vial_presentation_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE protocols (
    protocol_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (protocol_id),
    KEY idx_protocols_active (active)
) ENGINE=InnoDB;

CREATE TABLE protocol_items (
    protocol_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    protocol_id BIGINT UNSIGNED NOT NULL,
    formulation_id BIGINT UNSIGNED NOT NULL,
    schedule_type ENUM('scheduled','as_needed') NOT NULL,
    dose_amount DECIMAL(14,4) NOT NULL,
    dose_unit VARCHAR(20) NOT NULL,
    time_of_day ENUM('morning','afternoon','evening','night') NOT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    cycle_anchor_date DATE NULL,
    run_days INT UNSIGNED NULL,
    off_days INT UNSIGNED NULL DEFAULT 0,
    repeat_cycle TINYINT(1) NOT NULL DEFAULT 0,
    mix_reminder_days INT UNSIGNED NOT NULL DEFAULT 2,
    active TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (protocol_item_id),
    KEY idx_protocol_item_protocol_active (protocol_id, active),
    KEY idx_protocol_item_formulation_active (formulation_id, active),
    KEY idx_protocol_item_effective (effective_from, effective_to),
    CONSTRAINT fk_protocol_item_protocol
        FOREIGN KEY (protocol_id)
        REFERENCES protocols (protocol_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_protocol_item_formulation
        FOREIGN KEY (formulation_id)
        REFERENCES formulations (formulation_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE protocol_item_weekdays (
    protocol_item_id BIGINT UNSIGNED NOT NULL,
    weekday TINYINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (protocol_item_id, weekday),
    CONSTRAINT chk_protocol_weekday CHECK (weekday BETWEEN 1 AND 7),
    CONSTRAINT fk_protocol_weekday_item
        FOREIGN KEY (protocol_item_id)
        REFERENCES protocol_items (protocol_item_id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE schedule_overrides (
    schedule_override_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    protocol_item_id BIGINT UNSIGNED NOT NULL,
    scheduled_date DATE NOT NULL,
    action ENUM('add','skip') NOT NULL,
    dose_amount DECIMAL(14,4) NULL,
    dose_unit VARCHAR(20) NULL,
    time_of_day ENUM('morning','afternoon','evening','night') NULL,
    notes VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (schedule_override_id),
    UNIQUE KEY uq_schedule_override_item_date (protocol_item_id, scheduled_date),
    KEY idx_schedule_override_date (scheduled_date),
    CONSTRAINT fk_schedule_override_item
        FOREIGN KEY (protocol_item_id)
        REFERENCES protocol_items (protocol_item_id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE administrations (
    administration_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    protocol_item_id BIGINT UNSIGNED NULL,
    formulation_id BIGINT UNSIGNED NOT NULL,
    prepared_vial_id BIGINT UNSIGNED NULL,
    administered_date DATE NOT NULL,
    administered_time TIME NULL,
    time_of_day ENUM('morning','afternoon','evening','night') NOT NULL,
    dose_amount DECIMAL(14,4) NOT NULL,
    dose_unit VARCHAR(20) NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (administration_id),
    KEY idx_administration_date (administered_date),
    KEY idx_administration_formulation_date (formulation_id, administered_date),
    KEY idx_administration_protocol_item (protocol_item_id),
    KEY idx_administration_prepared_vial (prepared_vial_id),
    CONSTRAINT fk_administration_protocol_item
        FOREIGN KEY (protocol_item_id)
        REFERENCES protocol_items (protocol_item_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_administration_formulation
        FOREIGN KEY (formulation_id)
        REFERENCES formulations (formulation_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_administration_prepared_vial
        FOREIGN KEY (prepared_vial_id)
        REFERENCES prepared_vials (prepared_vial_id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE scheduled_doses (
    scheduled_dose_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    protocol_item_id BIGINT UNSIGNED NOT NULL,
    formulation_id BIGINT UNSIGNED NOT NULL,
    scheduled_date DATE NOT NULL,
    time_of_day ENUM('morning','afternoon','evening','night') NOT NULL,
    dose_amount DECIMAL(14,4) NOT NULL,
    dose_unit VARCHAR(20) NOT NULL,
    status ENUM('pending','taken','skipped','cancelled') NOT NULL DEFAULT 'pending',
    administration_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (scheduled_dose_id),
    UNIQUE KEY uq_scheduled_dose_item_date (protocol_item_id, scheduled_date),
    KEY idx_scheduled_date_status (scheduled_date, status),
    KEY idx_scheduled_formulation_date (formulation_id, scheduled_date),
    KEY idx_scheduled_administration (administration_id),
    CONSTRAINT fk_scheduled_protocol_item
        FOREIGN KEY (protocol_item_id)
        REFERENCES protocol_items (protocol_item_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_scheduled_formulation
        FOREIGN KEY (formulation_id)
        REFERENCES formulations (formulation_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_scheduled_administration
        FOREIGN KEY (administration_id)
        REFERENCES administrations (administration_id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE reminders (
    reminder_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    protocol_item_id BIGINT UNSIGNED NULL,
    formulation_id BIGINT UNSIGNED NOT NULL,
    reminder_type VARCHAR(30) NOT NULL,
    reminder_date DATE NOT NULL,
    target_date DATE NOT NULL,
    message VARCHAR(500) NOT NULL,
    status ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (reminder_id),
    UNIQUE KEY uq_reminder_item_type_target (protocol_item_id, reminder_type, target_date),
    KEY idx_reminder_status_date (status, reminder_date),
    KEY idx_reminder_formulation_status (formulation_id, status),
    CONSTRAINT fk_reminder_protocol_item
        FOREIGN KEY (protocol_item_id)
        REFERENCES protocol_items (protocol_item_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_reminder_formulation
        FOREIGN KEY (formulation_id)
        REFERENCES formulations (formulation_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE weight_log (
    weight_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    weight DECIMAL(8,2) NOT NULL,
    measured_at DATETIME NOT NULL,
    notes VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (weight_log_id),
    KEY idx_weight_measured_at (measured_at)
) ENGINE=InnoDB;

-- The current UI expects an active master protocol so new peptide protocol
-- items can be created immediately.
INSERT INTO protocols (
    name,
    start_date,
    end_date,
    active,
    notes
)
VALUES (
    'Current Research Protocol',
    NULL,
    NULL,
    1,
    'Default master protocol created during installation.'
);
