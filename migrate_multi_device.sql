USE weather_db;

-- Run this once on an existing single-device installation.
CREATE TABLE devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    api_key_hash CHAR(64) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Replace the placeholder below with a private key before running this migration.
-- Keep the raw key for the existing station; only its SHA-256 hash is stored.
INSERT INTO devices (name, api_key_hash)
VALUES ('Legacy Station', SHA2('3d45e2a5a24285c0ff09daa3e3837c67da8778dd8a2bb4c3d9c4d7696b5e9557', 256));

ALTER TABLE weather_readings ADD COLUMN device_id INT NULL AFTER id;

UPDATE weather_readings
SET device_id = (SELECT id FROM devices WHERE name = 'Legacy Station' LIMIT 1)
WHERE device_id IS NULL;

ALTER TABLE weather_readings
    MODIFY device_id INT NOT NULL,
    ADD INDEX idx_weather_device_id (device_id, id),
    ADD CONSTRAINT fk_weather_readings_device FOREIGN KEY (device_id) REFERENCES devices(id);
