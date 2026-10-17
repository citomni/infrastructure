-- Apply once to an existing database whose bruteforce_counters table predates this change.
-- Contexts then compare byte by byte, so names that differ only in letter case or accents
-- get separate counters. The table is rebuilt; existing counters are kept, and a second run
-- changes nothing. DDL implicitly commits. The order relative to deploying the code does not
-- matter. A fresh install imports sql/citomni_bruteforce.sql instead.
ALTER TABLE bruteforce_counters
	MODIFY COLUMN context VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
