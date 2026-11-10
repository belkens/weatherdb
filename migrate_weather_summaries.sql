USE weather_db;

-- Run once on existing databases to speed up time-range summary queries.
ALTER TABLE weather_readings
    ADD INDEX idx_weather_created_at (created_at);
