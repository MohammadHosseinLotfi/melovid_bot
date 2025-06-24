CREATE TABLE IF NOT EXISTS `musics` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `file_id` VARCHAR(255) NOT NULL COMMENT 'Telegram file_id for the audio',
  `file_unique_id` VARCHAR(255) NOT NULL COMMENT 'Telegram file_unique_id for the audio',
  `title` VARCHAR(255) DEFAULT NULL COMMENT 'Music title, can be extracted from filename or entered by admin',
  `artist` VARCHAR(255) DEFAULT NULL COMMENT 'Music artist, entered by admin',
  `lyrics` TEXT DEFAULT NULL COMMENT 'Full lyrics of the music',
  `short_code` VARCHAR(10) NOT NULL UNIQUE COMMENT 'Short unique code for deep linking',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `channel_posts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `music_id` INT NOT NULL COMMENT 'Foreign key to musics table',
  `channel_id` BIGINT NOT NULL COMMENT 'Telegram Channel ID where music was posted',
  `message_id` BIGINT NOT NULL COMMENT 'Telegram Message ID in the channel',
  `posted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`music_id`) REFERENCES `musics`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_states` (
  `admin_id` BIGINT PRIMARY KEY COMMENT 'Telegram User ID of the admin',
  `state` VARCHAR(255) NOT NULL COMMENT 'Current state of the admin, e.g., waiting_for_music_file',
  `data` TEXT DEFAULT NULL COMMENT 'JSON encoded data related to the current state, e.g., music_id for editing'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional: If you want to store admins in DB instead of config.php
-- CREATE TABLE IF NOT EXISTS `admins` (
--  `user_id` BIGINT PRIMARY KEY COMMENT 'Telegram User ID of the admin',
--  `username` VARCHAR(255) DEFAULT NULL,
--  `first_name` VARCHAR(255) DEFAULT NULL,
--  `added_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Indexes for performance
CREATE INDEX idx_musics_short_code ON musics(short_code);
CREATE INDEX idx_channel_posts_music_id ON channel_posts(music_id);
CREATE INDEX idx_channel_posts_channel_message ON channel_posts(channel_id, message_id);

-- Note: Consider adding an index to admin_states.state if you query it frequently.
-- CREATE INDEX idx_admin_states_state ON admin_states(state);

-- Update public/index.php to call Database::init()
-- Update config-example.php if any new DB related constants are needed (charset is already there)
