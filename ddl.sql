CREATE DATABASE /*!32312 IF NOT EXISTS*/ `slack_reminder` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */;

USE `slack_reminder`;

CREATE TABLE `prefs` (
  `uid` varchar(50) NOT NULL,
  `cid` varchar(255) NOT NULL,
  `default_hour` varchar(50) DEFAULT NULL,
  `snooze_times` varchar(1024) DEFAULT NULL,
  PRIMARY KEY (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `reminders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_user` int(11) DEFAULT NULL,
  `uid` varchar(255) NOT NULL,
  `cid` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `alarm_time` datetime NOT NULL,
  `done` tinyint(1) DEFAULT '0',
  `alarm_sent` tinyint(1) DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_alarmtime` (`alarm_time`,`done`),
  KEY `idx_uid` (`uid`(191)),
  KEY `idx_done` (`done`),
  KEY `idx_alarm_time` (`alarm_time`),
  KEY `idx_alarm_sent` (`alarm_sent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;