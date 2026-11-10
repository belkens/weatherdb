# Weather Station Monitor

## Start a fresh installation

1. Start Apache and MySQL in the XAMPP Control Panel.
2. Import `setup.sql` with phpMyAdmin (or run it in MySQL). It creates the `weather_db` database and the required tables.
3. If your MySQL credentials differ from the XAMPP defaults, update `db_config.php`.
4. Open [http://localhost/weather/setup_admin.php](http://localhost/weather/setup_admin.php) and create the first administrator. This setup page stops creating accounts after the first user exists.
5. Register a device (see below), then open [http://localhost/weather/](http://localhost/weather/) and sign in.

## User accounts and roles

For an existing database, run `migrate_user_accounts.sql` once in phpMyAdmin, then visit `setup_admin.php` to create the first administrator. Sign in at `login.php`. Admins can change alert thresholds, create accounts, assign roles, disable accounts, and reset other users' passwords. Viewers can see the dashboard and summaries but cannot change settings or manage accounts. Passwords are stored as password hashes; accounts require passwords of at least 12 characters.

## Time zone

Dashboard times, device last-seen checks, and hourly/daily summaries use Philippine time (`Asia/Manila`, UTC+08:00).

## Weather alerts

The dashboard evaluates each device's latest reading received in the last five minutes. It shows in-dashboard alerts for freezing or high temperature, high humidity, high wind speed, and heavy rain per report. Open **Alert thresholds** on the dashboard to edit the limits. Temperature is stored and displayed in °C; default thresholds are 0°C / 37.8°C, 95% humidity, 50 km/h wind, and 5 mm rainfall per report.

For an existing database, run `migrate_alert_settings.sql` once in phpMyAdmin if the alert-settings table is missing. Then run `migrate_temperature_celsius.sql` once to convert stored Fahrenheit readings and alert thresholds. Back up `weather_db` first. Fresh installations use Celsius defaults from `setup.sql` and do not need this conversion.

After converting an existing database, update and upload the ESP32 sketch so it sends Celsius readings (`readTempC()`). The included `weather_station/weather_station.ino` template already uses Celsius.

## Hourly and daily summaries

The dashboard shows hourly summaries for the last 24 hours and daily summaries for the last 7 days, respecting the selected device filter. Temperature, humidity, pressure, and wind speed show minimum / average / maximum; rainfall shows those values plus the accumulated total. Wind direction is omitted because compass angles wrap at north. For an existing database, run `migrate_weather_summaries.sql` once to add the timestamp index used by these queries.

## Printable reports

Sign in and choose **Printable report** on the dashboard. Select a device or all devices and a date range (up to 366 days), then generate or print the report. It includes period-wide statistics and daily per-device min/average/max breakdowns.

## Upgrade an existing single-device installation

Back up `weather_db` first. If it does **not** have a `devices` table yet, open `migrate_multi_device.sql`, set the key in its `INSERT` statement to a private random key, then run it once in phpMyAdmin. It creates a `Legacy Station` and assigns the existing readings to it.

If you already imported the updated `setup.sql` into an old database, it may have created `devices` without changing the existing readings table. In that case, make sure at least one device exists, then run `migrate_existing_devices.sql` once; it assigns old readings to the first registered device. Do not run either migration a second time. Configure each station to send its corresponding API key with future readings.

## Register additional devices

Generate a unique random API key for each station. In a terminal, XAMPP's PHP can generate one with:

```sh
C:/xampp/php/php.exe -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

In phpMyAdmin, run this query for each device, replacing the sample name and key with your own values:

```sql
INSERT INTO devices (name, api_key_hash)
VALUES ('Backyard Sensor', SHA2('paste-this-devices-private-key', 256));
```

Keep the raw key on that device; only its SHA-256 hash is stored in the database. Give every station a different key. The dashboard lets you view all devices together or select an individual station, and reports each device online when it has sent data within the last five minutes.

## Send readings from a station

Send a `POST` request to `http://localhost/weather/post_weather.php`. Identify the station by sending its API key in the `X-API-Key` HTTP header. The payload must include these numeric fields:

| Field | Unit | Accepted range |
| --- | --- | --- |
| `temp` | °C | -100 to 100 (include `temp_unit=C`) |
| `humidity` | % | 0 to 100 |
| `pressure` | hPa | 300 to 1200 |
| `wind_dir` | degrees | 0 to 359 |
| `wind_speed` | km/h | 0 to 500 |
| `rain` | mm | 0 to 10000 |

Example using form-encoded data:

```sh
curl -X POST http://localhost/weather/post_weather.php \
  -H "X-API-Key: paste-this-devices-private-key" \
  -d "temp=22.5&temp_unit=C&humidity=48&pressure=1013.2&wind_dir=225&wind_speed=12.4&rain=0"
```

The endpoint also accepts a JSON request body with the same field names. A successful request responds with JSON containing `status: "ok"`, the inserted reading ID, and the device ID. Invalid keys receive HTTP 403; missing keys receive HTTP 401.
