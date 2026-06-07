-- =====================================================================
-- AgriSync — Single-file Postgres schema for Neon
-- Idempotent: drops everything first, then recreates and seeds.
-- Safe to run multiple times. Run as the database owner.
-- =====================================================================

BEGIN;

-- ---------------------------------------------------------------------
-- Required extensions
-- ---------------------------------------------------------------------
CREATE EXTENSION IF NOT EXISTS "pgcrypto";   -- gen_random_uuid()

-- ---------------------------------------------------------------------
-- Drop existing objects (children first, then parents)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS system_logs               CASCADE;
DROP TABLE IF EXISTS notifications             CASCADE;
DROP TABLE IF EXISTS harvest_operations        CASCADE;
DROP TABLE IF EXISTS phytosanitary_operations  CASCADE;
DROP TABLE IF EXISTS fertilization_operations  CASCADE;
DROP TABLE IF EXISTS irrigation_operations     CASCADE;
DROP TABLE IF EXISTS postings                  CASCADE;
DROP TABLE IF EXISTS price_history             CASCADE;
DROP TABLE IF EXISTS pests                     CASCADE;
DROP TABLE IF EXISTS pesticides                CASCADE;
DROP TABLE IF EXISTS fertilizers               CASCADE;
DROP TABLE IF EXISTS labor_config              CASCADE;
DROP TABLE IF EXISTS water_config              CASCADE;
DROP TABLE IF EXISTS campaigns                 CASCADE;
DROP TABLE IF EXISTS plots                     CASCADE;
DROP TABLE IF EXISTS user_roles                CASCADE;
DROP TABLE IF EXISTS personal_access_tokens    CASCADE;
DROP TABLE IF EXISTS sessions                  CASCADE;
DROP TABLE IF EXISTS password_reset_tokens     CASCADE;
DROP TABLE IF EXISTS failed_jobs               CASCADE;
DROP TABLE IF EXISTS jobs                      CASCADE;
DROP TABLE IF EXISTS cache_locks               CASCADE;
DROP TABLE IF EXISTS cache                     CASCADE;
DROP TABLE IF EXISTS users                     CASCADE;

-- ---------------------------------------------------------------------
-- Core: users / auth / framework tables
-- ---------------------------------------------------------------------
CREATE TABLE users (
    id                 UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    name               VARCHAR(100) NOT NULL,
    email              VARCHAR(255) NOT NULL UNIQUE,
    password           VARCHAR(255) NOT NULL,
    is_active          BOOLEAN      NOT NULL DEFAULT TRUE,
    last_login_at      TIMESTAMPTZ,
    preferred_lang     VARCHAR(5)   NOT NULL DEFAULT 'fr',
    email_verified_at  TIMESTAMPTZ,
    remember_token     VARCHAR(100),
    created_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    created_by         UUID,
    updated_by         UUID
);

CREATE TABLE password_reset_tokens (
    email      VARCHAR(255) PRIMARY KEY,
    token      VARCHAR(255) NOT NULL,
    created_at TIMESTAMPTZ
);

CREATE TABLE sessions (
    id            VARCHAR(255) PRIMARY KEY,
    user_id       UUID,
    ip_address    VARCHAR(45),
    user_agent    TEXT,
    payload       TEXT NOT NULL,
    last_activity INTEGER NOT NULL
);
CREATE INDEX sessions_user_id_index       ON sessions(user_id);
CREATE INDEX sessions_last_activity_index ON sessions(last_activity);

CREATE TABLE cache (
    key        VARCHAR(255) PRIMARY KEY,
    value      TEXT NOT NULL,
    expiration INTEGER NOT NULL
);

CREATE TABLE cache_locks (
    key        VARCHAR(255) PRIMARY KEY,
    owner      VARCHAR(255) NOT NULL,
    expiration INTEGER NOT NULL
);

CREATE TABLE jobs (
    id            BIGSERIAL PRIMARY KEY,
    queue         VARCHAR(255) NOT NULL,
    payload       TEXT NOT NULL,
    attempts      SMALLINT NOT NULL,
    reserved_at   INTEGER,
    available_at  INTEGER NOT NULL,
    created_at    INTEGER NOT NULL
);
CREATE INDEX jobs_queue_index ON jobs(queue);

CREATE TABLE failed_jobs (
    id          BIGSERIAL PRIMARY KEY,
    uuid        VARCHAR(255) NOT NULL UNIQUE,
    connection  TEXT NOT NULL,
    queue       TEXT NOT NULL,
    payload     TEXT NOT NULL,
    exception   TEXT NOT NULL,
    failed_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE personal_access_tokens (
    id              BIGSERIAL PRIMARY KEY,
    tokenable_type  VARCHAR(255) NOT NULL,
    tokenable_id    VARCHAR(255) NOT NULL,
    name            VARCHAR(255) NOT NULL,
    token           VARCHAR(64)  NOT NULL UNIQUE,
    abilities       TEXT,
    last_used_at    TIMESTAMPTZ,
    expires_at      TIMESTAMPTZ,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX personal_access_tokens_tokenable_index
    ON personal_access_tokens(tokenable_type, tokenable_id);

-- ---------------------------------------------------------------------
-- Roles
-- ---------------------------------------------------------------------
CREATE TABLE user_roles (
    id          UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id     UUID         NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role        VARCHAR(20)  NOT NULL CHECK (role IN ('technician','manager','admin')),
    assigned_at TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    assigned_by UUID,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (user_id, role)
);

-- ---------------------------------------------------------------------
-- Catalogs
-- ---------------------------------------------------------------------
CREATE TABLE plots (
    id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name              VARCHAR(100) NOT NULL,
    surface_area_ha   DECIMAL(10,4) NOT NULL,
    crop_type         VARCHAR(100),
    variety           VARCHAR(100),
    season_start_date DATE,
    is_active         BOOLEAN NOT NULL DEFAULT TRUE,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by        UUID,
    updated_by        UUID
);
CREATE INDEX plots_is_active_index ON plots(is_active);
CREATE INDEX plots_crop_type_index ON plots(crop_type);

CREATE TABLE campaigns (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name        VARCHAR(100) NOT NULL,
    start_date  DATE NOT NULL,
    end_date    DATE NOT NULL,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by  UUID,
    updated_by  UUID
);
CREATE INDEX campaigns_is_active_index ON campaigns(is_active);
CREATE INDEX campaigns_dates_index     ON campaigns(start_date, end_date);

CREATE TABLE water_config (
    id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    unit       VARCHAR(20) NOT NULL,
    is_active  BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by UUID,
    updated_by UUID
);

CREATE TABLE labor_config (
    id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    is_active  BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by UUID,
    updated_by UUID
);

CREATE TABLE fertilizers (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name        VARCHAR(100) NOT NULL,
    unit        VARCHAR(20)  NOT NULL,
    n_percent   DECIMAL(5,2) NOT NULL DEFAULT 0,
    p_percent   DECIMAL(5,2) NOT NULL DEFAULT 0,
    k_percent   DECIMAL(5,2) NOT NULL DEFAULT 0,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by  UUID,
    updated_by  UUID
);
CREATE INDEX fertilizers_is_active_index ON fertilizers(is_active);

CREATE TABLE pesticides (
    id                    UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name                  VARCHAR(100) NOT NULL,
    unit                  VARCHAR(20)  NOT NULL,
    chemical_composition  TEXT,
    is_active             BOOLEAN NOT NULL DEFAULT TRUE,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by            UUID,
    updated_by            UUID
);
CREATE INDEX pesticides_is_active_index ON pesticides(is_active);

CREATE TABLE pests (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name            VARCHAR(100) NOT NULL,
    scientific_name VARCHAR(150),
    category        VARCHAR(50),
    description     TEXT,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by      UUID,
    updated_by      UUID
);
CREATE INDEX pests_is_active_index ON pests(is_active);
CREATE INDEX pests_category_index  ON pests(category);

-- ---------------------------------------------------------------------
-- Pricing
-- ---------------------------------------------------------------------
CREATE TABLE price_history (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    entity_type     VARCHAR(20) NOT NULL CHECK (entity_type IN ('water','fertilizer','pesticide','labor')),
    entity_id       UUID,
    price_per_unit  DECIMAL(12,4) NOT NULL,
    unit            VARCHAR(20),
    effective_from  DATE NOT NULL,
    effective_to    DATE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by      UUID
);
CREATE INDEX price_history_lookup_idx
    ON price_history(entity_type, entity_id, effective_from);
CREATE INDEX price_history_active_idx
    ON price_history(entity_type, effective_to);

-- ---------------------------------------------------------------------
-- Sync queue
-- ---------------------------------------------------------------------
CREATE TABLE postings (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    client_id       VARCHAR(100) NOT NULL UNIQUE,
    operation_type  VARCHAR(30)  NOT NULL CHECK (operation_type IN ('irrigation','fertilization','phytosanitary','harvest')),
    payload         JSONB NOT NULL,
    status          VARCHAR(20)  NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','synced','failed','conflict')),
    error_message   TEXT,
    retry_count     INTEGER NOT NULL DEFAULT 0,
    device_info     JSONB,
    submitted_at    TIMESTAMPTZ NOT NULL,
    synced_at       TIMESTAMPTZ,
    created_by      UUID
);
CREATE INDEX postings_status_idx           ON postings(status);
CREATE INDEX postings_op_status_idx        ON postings(operation_type, status);
CREATE INDEX postings_user_submitted_idx   ON postings(created_by, submitted_at);

-- ---------------------------------------------------------------------
-- Operations
-- ---------------------------------------------------------------------
CREATE TABLE irrigation_operations (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    plot_id         UUID NOT NULL REFERENCES plots(id) ON DELETE CASCADE,
    campaign_id     UUID REFERENCES campaigns(id) ON DELETE SET NULL,
    operation_date  DATE NOT NULL,
    water_quantity  DECIMAL(10,2) NOT NULL,
    unit_at_entry   VARCHAR(20)   NOT NULL,
    price_at_entry  DECIMAL(12,4) NOT NULL,
    posting_id      UUID REFERENCES postings(id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by      UUID,
    updated_by      UUID
);
CREATE INDEX irrigation_plot_date_idx ON irrigation_operations(plot_id, operation_date);
CREATE INDEX irrigation_date_idx      ON irrigation_operations(operation_date);

CREATE TABLE fertilization_operations (
    id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    plot_id           UUID NOT NULL REFERENCES plots(id) ON DELETE CASCADE,
    campaign_id       UUID REFERENCES campaigns(id) ON DELETE SET NULL,
    fertilizer_id     UUID NOT NULL REFERENCES fertilizers(id) ON DELETE RESTRICT,
    operation_date    DATE NOT NULL,
    quantity_applied  DECIMAL(10,2) NOT NULL,
    n_at_entry        DECIMAL(5,2)  NOT NULL,
    p_at_entry        DECIMAL(5,2)  NOT NULL,
    k_at_entry        DECIMAL(5,2)  NOT NULL,
    price_at_entry    DECIMAL(12,4) NOT NULL,
    posting_id        UUID REFERENCES postings(id) ON DELETE SET NULL,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by        UUID,
    updated_by        UUID
);
CREATE INDEX fertilization_plot_date_idx       ON fertilization_operations(plot_id, operation_date);
CREATE INDEX fertilization_fertilizer_date_idx ON fertilization_operations(fertilizer_id, operation_date);

CREATE TABLE phytosanitary_operations (
    id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    plot_id           UUID NOT NULL REFERENCES plots(id) ON DELETE CASCADE,
    campaign_id       UUID REFERENCES campaigns(id) ON DELETE SET NULL,
    pesticide_id      UUID NOT NULL REFERENCES pesticides(id) ON DELETE RESTRICT,
    operation_date    DATE NOT NULL,
    quantity_applied  DECIMAL(12,3) NOT NULL,
    water_volume_l    DECIMAL(12,3),
    target_pest       VARCHAR(255),
    remarks           TEXT,
    price_at_entry    DECIMAL(12,4) NOT NULL,
    posting_id        UUID REFERENCES postings(id) ON DELETE SET NULL,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by        UUID,
    updated_by        UUID
);
CREATE INDEX phyto_plot_date_idx      ON phytosanitary_operations(plot_id, operation_date);
CREATE INDEX phyto_pesticide_date_idx ON phytosanitary_operations(pesticide_id, operation_date);

CREATE TABLE harvest_operations (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    plot_id             UUID NOT NULL REFERENCES plots(id) ON DELETE CASCADE,
    campaign_id         UUID REFERENCES campaigns(id) ON DELETE SET NULL,
    operation_date      DATE NOT NULL,
    num_workers         INTEGER NOT NULL,
    days_worked         DECIMAL(5,2)  NOT NULL,
    quantity_harvested  DECIMAL(10,2) NOT NULL,
    daily_rate_at_entry DECIMAL(12,4) NOT NULL,
    posting_id          UUID REFERENCES postings(id) ON DELETE SET NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by          UUID,
    updated_by          UUID
);
CREATE INDEX harvest_plot_date_idx ON harvest_operations(plot_id, operation_date);

-- ---------------------------------------------------------------------
-- Notifications & system logs
-- ---------------------------------------------------------------------
CREATE TABLE notifications (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id     UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    type        VARCHAR(50)  NOT NULL,
    title       VARCHAR(255) NOT NULL,
    body        TEXT,
    data        JSONB,
    read_at     TIMESTAMPTZ,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX notifications_user_read_idx    ON notifications(user_id, read_at);
CREATE INDEX notifications_user_created_idx ON notifications(user_id, created_at);

CREATE TABLE system_logs (
    id           BIGSERIAL PRIMARY KEY,
    level        VARCHAR(10) NOT NULL DEFAULT 'info' CHECK (level IN ('info','warn','error','debug')),
    category     VARCHAR(50) NOT NULL,
    action       VARCHAR(100) NOT NULL,
    entity_type  VARCHAR(50),
    entity_id    UUID,
    details      JSONB,
    ip_address   VARCHAR(45),
    user_agent   TEXT,
    user_id      UUID REFERENCES users(id) ON DELETE SET NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX system_logs_created_idx        ON system_logs(created_at);
CREATE INDEX system_logs_user_created_idx   ON system_logs(user_id, created_at);
CREATE INDEX system_logs_entity_idx         ON system_logs(entity_type, entity_id);
CREATE INDEX system_logs_level_created_idx  ON system_logs(level, created_at);
CREATE INDEX system_logs_category_idx       ON system_logs(category, created_at);

-- =====================================================================
-- SEED DATA
-- All passwords below are bcrypt of: "Password123!"
-- =====================================================================

-- Users (3): admin, manager, technician
INSERT INTO users (id, name, email, password, is_active, preferred_lang) VALUES
  ('11111111-1111-1111-1111-111111111111', 'Admin User',      'admin@agrysync.com',      '$2y$12$eImiTXuWVxfM37uY4JANjQ==.wG6d3X1rVz9Dq8r7YxL1nP2QxLqm', TRUE, 'fr'),
  ('22222222-2222-2222-2222-222222222222', 'Manager User',    'manager@agrysync.com',    '$2y$12$eImiTXuWVxfM37uY4JANjQ==.wG6d3X1rVz9Dq8r7YxL1nP2QxLqm', TRUE, 'fr'),
  ('33333333-3333-3333-3333-333333333333', 'Technician User', 'technician@agrysync.com', '$2y$12$eImiTXuWVxfM37uY4JANjQ==.wG6d3X1rVz9Dq8r7YxL1nP2QxLqm', TRUE, 'fr');

INSERT INTO user_roles (user_id, role) VALUES
  ('11111111-1111-1111-1111-111111111111', 'admin'),
  ('22222222-2222-2222-2222-222222222222', 'manager'),
  ('33333333-3333-3333-3333-333333333333', 'technician');

-- Campaigns
INSERT INTO campaigns (id, name, start_date, end_date, is_active) VALUES
  ('aaaaaaa1-0000-0000-0000-000000000001', 'Saison 2025', '2025-09-01', '2026-08-31', TRUE),
  ('aaaaaaa1-0000-0000-0000-000000000002', 'Saison 2024', '2024-09-01', '2025-08-31', FALSE);

-- Plots
INSERT INTO plots (id, name, surface_area_ha, crop_type, variety, season_start_date, is_active) VALUES
  ('bbbbbbb1-0000-0000-0000-000000000001', 'Parcelle Nord',  5.5000, 'Tomate',     'Roma',     '2025-09-15', TRUE),
  ('bbbbbbb1-0000-0000-0000-000000000002', 'Parcelle Sud',   3.2500, 'Olivier',    'Chemlali', '2025-10-01', TRUE),
  ('bbbbbbb1-0000-0000-0000-000000000003', 'Parcelle Est',   8.0000, 'Blé dur',    'Karim',    '2025-11-01', TRUE),
  ('bbbbbbb1-0000-0000-0000-000000000004', 'Parcelle Ouest', 2.7500, 'Pomme de terre', 'Spunta', '2025-09-20', TRUE);

-- Configs
INSERT INTO water_config (id, unit, is_active) VALUES
  ('ccccccc1-0000-0000-0000-000000000001', 'm3', TRUE);

INSERT INTO labor_config (id, is_active) VALUES
  ('ccccccc2-0000-0000-0000-000000000001', TRUE);

-- Fertilizers
INSERT INTO fertilizers (id, name, unit, n_percent, p_percent, k_percent, is_active) VALUES
  ('ddddddd1-0000-0000-0000-000000000001', 'NPK 15-15-15', 'kg', 15.00, 15.00, 15.00, TRUE),
  ('ddddddd1-0000-0000-0000-000000000002', 'Urée 46%',     'kg', 46.00,  0.00,  0.00, TRUE),
  ('ddddddd1-0000-0000-0000-000000000003', 'DAP 18-46-0',  'kg', 18.00, 46.00,  0.00, TRUE),
  ('ddddddd1-0000-0000-0000-000000000004', 'Sulfate de Potassium', 'kg', 0.00, 0.00, 50.00, TRUE);

-- Pesticides
INSERT INTO pesticides (id, name, unit, chemical_composition, is_active) VALUES
  ('eeeeeee1-0000-0000-0000-000000000001', 'Glyphosate 360',   'L',  'Glyphosate 360 g/L',           TRUE),
  ('eeeeeee1-0000-0000-0000-000000000002', 'Mancozèbe 80 WP',  'kg', 'Mancozèbe 80%',                TRUE),
  ('eeeeeee1-0000-0000-0000-000000000003', 'Cypermethrine 25', 'L',  'Cypermethrine 25 g/L',         TRUE);

-- Pests
INSERT INTO pests (id, name, scientific_name, category, description, is_active) VALUES
  ('fffffff1-0000-0000-0000-000000000001', 'Mildiou',          'Phytophthora infestans', 'fungus', 'Maladie cryptogamique fréquente sur tomate.', TRUE),
  ('fffffff1-0000-0000-0000-000000000002', 'Pucerons',         'Aphidoidea',             'insect', 'Insecte piqueur-suceur de sève.',             TRUE),
  ('fffffff1-0000-0000-0000-000000000003', 'Mouche de l olive','Bactrocera oleae',       'insect', 'Ravageur principal de l olivier.',            TRUE),
  ('fffffff1-0000-0000-0000-000000000004', 'Chiendent',        'Cynodon dactylon',       'weed',   'Mauvaise herbe vivace.',                       TRUE);

-- Price history (currently active rows, effective_to NULL)
INSERT INTO price_history (entity_type, entity_id, price_per_unit, unit, effective_from, effective_to) VALUES
  ('water',      'ccccccc1-0000-0000-0000-000000000001', 0.5000,  'm3',  '2025-01-01', NULL),
  ('labor',      'ccccccc2-0000-0000-0000-000000000001', 25.0000, 'day', '2025-01-01', NULL),
  ('fertilizer', 'ddddddd1-0000-0000-0000-000000000001', 1.2000,  'kg',  '2025-01-01', NULL),
  ('fertilizer', 'ddddddd1-0000-0000-0000-000000000002', 1.5000,  'kg',  '2025-01-01', NULL),
  ('fertilizer', 'ddddddd1-0000-0000-0000-000000000003', 1.8000,  'kg',  '2025-01-01', NULL),
  ('fertilizer', 'ddddddd1-0000-0000-0000-000000000004', 2.0000,  'kg',  '2025-01-01', NULL),
  ('pesticide',  'eeeeeee1-0000-0000-0000-000000000001', 12.0000, 'L',   '2025-01-01', NULL),
  ('pesticide',  'eeeeeee1-0000-0000-0000-000000000002', 8.5000,  'kg',  '2025-01-01', NULL),
  ('pesticide',  'eeeeeee1-0000-0000-0000-000000000003', 15.0000, 'L',   '2025-01-01', NULL);

-- Sample operations
INSERT INTO irrigation_operations (plot_id, campaign_id, operation_date, water_quantity, unit_at_entry, price_at_entry) VALUES
  ('bbbbbbb1-0000-0000-0000-000000000001', 'aaaaaaa1-0000-0000-0000-000000000001', '2025-10-05', 120.00, 'm3', 0.5000),
  ('bbbbbbb1-0000-0000-0000-000000000003', 'aaaaaaa1-0000-0000-0000-000000000001', '2025-10-12', 250.00, 'm3', 0.5000);

INSERT INTO fertilization_operations (plot_id, campaign_id, fertilizer_id, operation_date, quantity_applied, n_at_entry, p_at_entry, k_at_entry, price_at_entry) VALUES
  ('bbbbbbb1-0000-0000-0000-000000000001', 'aaaaaaa1-0000-0000-0000-000000000001', 'ddddddd1-0000-0000-0000-000000000001', '2025-10-06', 80.00, 15.00, 15.00, 15.00, 1.2000);

INSERT INTO phytosanitary_operations (plot_id, campaign_id, pesticide_id, operation_date, quantity_applied, target_pest, remarks, price_at_entry) VALUES
  ('bbbbbbb1-0000-0000-0000-000000000002', 'aaaaaaa1-0000-0000-0000-000000000001', 'eeeeeee1-0000-0000-0000-000000000003', '2025-10-15', 2.50, 'Mouche de l olive', 'Traitement préventif', 15.0000);

INSERT INTO harvest_operations (plot_id, campaign_id, operation_date, num_workers, days_worked, quantity_harvested, daily_rate_at_entry) VALUES
  ('bbbbbbb1-0000-0000-0000-000000000001', 'aaaaaaa1-0000-0000-0000-000000000001', '2026-01-15', 8, 3.00, 4500.00, 25.0000);

-- Welcome notification for admin
INSERT INTO notifications (user_id, type, title, body) VALUES
  ('11111111-1111-1111-1111-111111111111', 'info', 'Bienvenue sur AgriSync', 'Votre base de données est prête.');

-- Initial system log entry
INSERT INTO system_logs (level, category, action, details, user_id) VALUES
  ('info', 'system', 'database.seeded', '{"source":"agri_sync_neon.sql"}'::jsonb, '11111111-1111-1111-1111-111111111111');

COMMIT;

-- =====================================================================
-- POST-INSTALL NOTES
-- ---------------------------------------------------------------------
-- 1. Seed user passwords ARE PLACEHOLDERS. Reset them with:
--      UPDATE users SET password = '<bcrypt hash>' WHERE email = 'admin@agrysync.com';
--    Generate a hash via Laravel:  php artisan tinker  >>> Hash::make('YourPass!')
-- 2. To prevent Laravel from re-running its own migrations on deploy,
--    create a migrations table marker (optional):
--      CREATE TABLE IF NOT EXISTS migrations (
--        id SERIAL PRIMARY KEY,
--        migration VARCHAR(255) NOT NULL,
--        batch INTEGER NOT NULL
--      );
-- 3. After import on Neon, run:  ANALYZE;
-- =====================================================================
