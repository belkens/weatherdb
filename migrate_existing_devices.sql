USE weather_db;

-- Use this only if the devices table already exists but weather_readings
-- does not have a device_id column. Existing readings go to the first device.
-- Back up the database before running; run this script once.
ALTER TABLE weather_readings ADD COLUMN device_id INT NULL AFTER id;

UPDATE weather_readings
SET device_id = (SELECT id FROM devices ORDER BY id LIMIT 1)
WHERE device_id IS NULL;

ALTER TABLE weather_readings
    MODIFY device_id INT NOT NULL,
    ADD INDEX idx_weather_device_id (device_id, id),
    ADD CONSTRAINT fk_weather_readings_device FOREIGN KEY (device_id) REFERENCES devices(id);
