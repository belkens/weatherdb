USE weather_db;

-- Run once when upgrading data previously stored in Fahrenheit.
-- Backs up weather_db before running this conversion.
UPDATE weather_readings
SET temp = ROUND((temp - 32) * 5 / 9, 1);

UPDATE weather_alert_settings
SET low_temp = ROUND((low_temp - 32) * 5 / 9, 1),
    high_temp = ROUND((high_temp - 32) * 5 / 9, 1)
WHERE id = 1;
