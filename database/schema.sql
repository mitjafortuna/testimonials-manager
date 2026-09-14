-- Testimonials Manager — schema
-- MySQL 8 / MariaDB 10.4+. All timestamps are UTC.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(64)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name  VARCHAR(128) NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per parent SKU; title/description/image are copied from the EN master landing on sync.
CREATE TABLE IF NOT EXISTS products (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_sku  VARCHAR(64)  NOT NULL,
  title       VARCHAR(255) NOT NULL DEFAULT '',
  description TEXT         NULL,
  image       VARCHAR(512) NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_sku (parent_sku),
  KEY ix_products_title (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- id is the UPSTREAM landing id (stable sync key), not auto-increment.
-- removed_at marks landings that disappeared from the upstream feed; their testimonials are kept.
-- (product_id, country) is deliberately NOT unique: upstream may recreate a landing under a new id;
-- a unique key would make ON DUPLICATE KEY UPDATE silently rewrite the old row. Sync is keyed by id
-- only; the old row is soft-deleted.
CREATE TABLE IF NOT EXISTS landings (
  id             INT UNSIGNED NOT NULL,
  product_id     INT UNSIGNED NOT NULL,
  country        CHAR(2)      NOT NULL,
  is_master      TINYINT(1)   NOT NULL DEFAULT 0,
  url            VARCHAR(512) NOT NULL,
  title          VARCHAR(255) NOT NULL DEFAULT '',
  description    TEXT         NULL,
  image          VARCHAR(512) NULL,
  status         VARCHAR(64)  NULL,
  removed_at     DATETIME     NULL,
  last_synced_at DATETIME     NOT NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_landings_product_country (product_id, country),
  KEY ix_landings_country (country),
  KEY ix_landings_removed (removed_at),
  CONSTRAINT fk_landings_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- rating NULL means "random": resolved to 4.0–5.0 at display time.
CREATE TABLE IF NOT EXISTS testimonials (
  id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  landing_id  INT UNSIGNED     NOT NULL,
  author_name VARCHAR(128)     NOT NULL,
  text        VARCHAR(2000)    NOT NULL,
  rating      TINYINT UNSIGNED NULL,
  gender      ENUM('male','female','unisex') NOT NULL DEFAULT 'unisex',
  url         VARCHAR(512)     NULL,
  is_active   TINYINT(1)       NOT NULL DEFAULT 1,
  sort_order  INT UNSIGNED     NOT NULL DEFAULT 0,
  created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by  INT UNSIGNED     NULL,
  updated_by  INT UNSIGNED     NULL,
  PRIMARY KEY (id),
  KEY ix_testimonials_landing_sort (landing_id, sort_order),
  KEY ix_testimonials_landing_active (landing_id, is_active),
  CONSTRAINT chk_testimonials_rating CHECK (rating IS NULL OR rating BETWEEN 1 AND 5),
  CONSTRAINT fk_testimonials_landing FOREIGN KEY (landing_id) REFERENCES landings (id) ON DELETE RESTRICT,
  CONSTRAINT fk_testimonials_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_testimonials_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Files live in storage/uploads/{filename}; filename is a generated UUID + extension.
CREATE TABLE IF NOT EXISTS testimonial_images (
  id             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  testimonial_id INT UNSIGNED      NOT NULL,
  filename       VARCHAR(64)       NOT NULL,
  thumb_filename VARCHAR(64)       NOT NULL,
  mime           VARCHAR(32)       NOT NULL,
  size_bytes     INT UNSIGNED      NOT NULL,
  width          SMALLINT UNSIGNED NOT NULL,
  height         SMALLINT UNSIGNED NOT NULL,
  sort_order     INT UNSIGNED      NOT NULL DEFAULT 0,
  created_at     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by     INT UNSIGNED      NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_images_filename (filename),
  KEY ix_images_testimonial_sort (testimonial_id, sort_order),
  CONSTRAINT fk_images_testimonial FOREIGN KEY (testimonial_id) REFERENCES testimonials (id) ON DELETE CASCADE,
  CONSTRAINT fk_images_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS change_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity_type VARCHAR(32)     NOT NULL,
  entity_id   INT UNSIGNED    NOT NULL,
  action      VARCHAR(32)     NOT NULL,
  changes     JSON            NULL,
  user_id     INT UNSIGNED    NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_change_log_entity (entity_type, entity_id, created_at),
  CONSTRAINT fk_change_log_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_runs (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  started_at    DATETIME     NOT NULL,
  finished_at   DATETIME     NULL,
  status        ENUM('running','ok','failed') NOT NULL DEFAULT 'running',
  added         INT UNSIGNED NOT NULL DEFAULT 0,
  updated       INT UNSIGNED NOT NULL DEFAULT 0,
  removed       INT UNSIGNED NOT NULL DEFAULT 0,
  error_message TEXT         NULL,
  PRIMARY KEY (id),
  KEY ix_sync_runs_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
