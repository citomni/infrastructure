CREATE TABLE bruteforce_counters (
	id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	context       VARCHAR(64) NOT NULL,
	subject_type  ENUM('identifier','ip') NOT NULL,
	subject_hash  CHAR(64) NOT NULL,
	window_start  INT UNSIGNED NOT NULL,
	attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
	blocked_until INT UNSIGNED NOT NULL DEFAULT 0,
	created_at    INT UNSIGNED NOT NULL,
	updated_at    INT UNSIGNED NOT NULL,
	PRIMARY KEY (id),
	UNIQUE KEY uniq_ctx_subject (context, subject_type, subject_hash),
	KEY idx_blocked_until (blocked_until),
	KEY idx_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;