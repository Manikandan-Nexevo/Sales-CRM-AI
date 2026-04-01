-- ===============================
-- MATCHES client1_db EXACTLY
-- ===============================

SET FOREIGN_KEY_CHECKS = 0;

-- availability
CREATE TABLE `availability` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint DEFAULT NULL,
  `day_of_week` varchar(10) DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `timezone` varchar(50) DEFAULT 'Asia/Kolkata',
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- bookings
CREATE TABLE `bookings` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `start_time` datetime DEFAULT NULL,
  `end_time` datetime DEFAULT NULL,
  `meeting_link` text,
  `meeting_url` varchar(512) DEFAULT NULL,
  `meeting_type` enum('jitsi','gmeet') DEFAULT 'jitsi',
  `status` varchar(50) DEFAULT 'scheduled',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `contact_id` bigint DEFAULT NULL,
  `timezone` varchar(50) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- booking_links
CREATE TABLE `booking_links` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint DEFAULT NULL,
  `slug` varchar(100) DEFAULT NULL,
  `duration` int DEFAULT '30',
  `is_active` tinyint(1) DEFAULT '1',
  `buffer_time` int DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- calendar_events
CREATE TABLE `calendar_events` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `type` varchar(50) NOT NULL,
  `reference_id` bigint UNSIGNED DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `status` varchar(50) DEFAULT 'scheduled',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- call_logs
CREATE TABLE `call_logs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint DEFAULT NULL,
  `contact_id` bigint DEFAULT NULL,
  `direction` varchar(50) DEFAULT NULL,
  `duration` int DEFAULT '0',
  `status` varchar(50) DEFAULT NULL,
  `outcome` varchar(100) DEFAULT NULL,
  `notes` text,
  `ai_summary` text,
  `voice_transcript` text,
  `next_action` varchar(255) DEFAULT NULL,
  `next_action_date` datetime DEFAULT NULL,
  `call_recording_url` text,
  `sentiment` varchar(50) DEFAULT NULL,
  `interest_level` int DEFAULT NULL,
  `scheduled_at` datetime DEFAULT NULL,
  `answered_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- contacts
CREATE TABLE `contacts` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `company` varchar(255) DEFAULT NULL,
  `designation` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `phone_alt` varchar(20) DEFAULT NULL,
  `linkedin_url` text,
  `linkedin_connected` tinyint DEFAULT '0',
  `website` varchar(255) DEFAULT NULL,
  `industry` varchar(100) DEFAULT NULL,
  `company_size` varchar(50) DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `source` varchar(100) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `priority` varchar(50) DEFAULT NULL,
  `assigned_to` bigint DEFAULT NULL,
  `notes` text,
  `ai_score` int DEFAULT '0',
  `ai_analysis` json DEFAULT NULL,
  `tags` json DEFAULT NULL,
  `last_contacted_at` datetime DEFAULT NULL,
  `next_followup_at` datetime DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- follow_ups
CREATE TABLE `follow_ups` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint DEFAULT NULL,
  `contact_id` bigint DEFAULT NULL,
  `call_log_id` bigint DEFAULT NULL,
  `type` varchar(50) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `message` text,
  `scheduled_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `ai_generated` tinyint DEFAULT '0',
  `email_sent` tinyint DEFAULT '0',
  `whatsapp_sent` tinyint DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint DEFAULT NULL,
  `updated_by` bigint DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- whatsapp_messages
CREATE TABLE `whatsapp_messages` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `contact_id` bigint DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `message` text,
  `direction` varchar(50) DEFAULT NULL,
  `media_path` text,
  `message_type` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

SET FOREIGN_KEY_CHECKS = 1;