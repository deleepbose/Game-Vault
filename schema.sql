-- game_vault schema
-- Import this via phpMyAdmin or: mysql -u root game_vault < schema.sql

CREATE DATABASE IF NOT EXISTS game_vault CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE game_vault;

DROP TABLE IF EXISTS idempotency_log;
DROP TABLE IF EXISTS inventory;
DROP TABLE IF EXISTS items;
DROP TABLE IF EXISTS players;

CREATE TABLE players (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    gold INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    type VARCHAR(32) NOT NULL,
    rarity VARCHAR(16) NOT NULL,
    price_gold INT NOT NULL,
    INDEX idx_type (type)
) ENGINE=InnoDB;

CREATE TABLE inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    player_id INT NOT NULL,
    item_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    acquired_at DATETIME NOT NULL,
    UNIQUE KEY uniq_player_item (player_id, item_id),
    CONSTRAINT fk_inv_player FOREIGN KEY (player_id) REFERENCES players(id),
    CONSTRAINT fk_inv_item FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB;

-- idempotency log so a retried purchase never double-charges
CREATE TABLE idempotency_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    player_id INT NOT NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    response_status INT NOT NULL,
    response_body MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_player_key (player_id, idempotency_key)
) ENGINE=InnoDB;

-- seed players
INSERT INTO players (username, gold) VALUES
    ('alice', 500),
    ('bran', 1200),
    ('cael', 75);

-- seed catalogue
INSERT INTO items (name, type, rarity, price_gold) VALUES
    ('Iron Sword', 'weapon', 'common', 100),
    ('Steel Bow', 'weapon', 'uncommon', 250),
    ('Fire Rune', 'consumable', 'rare', 200),
    ('Healing Potion', 'consumable', 'common', 50),
    ('Cloak of Shadows', 'armour', 'rare', 400),
    ('Leather Boots', 'armour', 'common', 75),
    ('Stormcaller Staff', 'weapon', 'legendary', 1500),
    ('Minor Mana Crystal', 'consumable', 'common', 30);
