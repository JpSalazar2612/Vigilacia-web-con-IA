CREATE TABLE "migrations"(
  "id" integer primary key autoincrement not null,
  "migration" varchar not null,
  "batch" integer not null
);
CREATE TABLE "users"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "email" varchar not null,
  "email_verified_at" datetime,
  "password" varchar not null,
  "remember_token" varchar,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "users_email_unique" on "users"("email");
CREATE TABLE "password_reset_tokens"(
  "email" varchar not null,
  "token" varchar not null,
  "created_at" datetime,
  primary key("email")
);
CREATE TABLE "sessions"(
  "id" varchar not null,
  "user_id" integer,
  "ip_address" varchar,
  "user_agent" text,
  "payload" text not null,
  "last_activity" integer not null,
  primary key("id")
);
CREATE INDEX "sessions_user_id_index" on "sessions"("user_id");
CREATE INDEX "sessions_last_activity_index" on "sessions"("last_activity");
CREATE TABLE "cache"(
  "key" varchar not null,
  "value" text not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE INDEX "cache_expiration_index" on "cache"("expiration");
CREATE TABLE "cache_locks"(
  "key" varchar not null,
  "owner" varchar not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE INDEX "cache_locks_expiration_index" on "cache_locks"("expiration");
CREATE TABLE "jobs"(
  "id" integer primary key autoincrement not null,
  "queue" varchar not null,
  "payload" text not null,
  "attempts" integer not null,
  "reserved_at" integer,
  "available_at" integer not null,
  "created_at" integer not null
);
CREATE INDEX "jobs_queue_index" on "jobs"("queue");
CREATE TABLE "job_batches"(
  "id" varchar not null,
  "name" varchar not null,
  "total_jobs" integer not null,
  "pending_jobs" integer not null,
  "failed_jobs" integer not null,
  "failed_job_ids" text not null,
  "options" text,
  "cancelled_at" integer,
  "created_at" integer not null,
  "finished_at" integer,
  primary key("id")
);
CREATE TABLE "failed_jobs"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "connection" varchar not null,
  "queue" varchar not null,
  "payload" text not null,
  "exception" text not null,
  "failed_at" datetime not null default CURRENT_TIMESTAMP
);
CREATE INDEX "failed_jobs_connection_queue_failed_at_index" on "failed_jobs"(
  "connection",
  "queue",
  "failed_at"
);
CREATE UNIQUE INDEX "failed_jobs_uuid_unique" on "failed_jobs"("uuid");
CREATE TABLE "cameras"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "location" varchar,
  "zone" varchar not null default 'East Fence',
  "stream_url" varchar not null,
  "snapshot_url" varchar,
  "fence_line" text,
  "status" varchar not null default 'online',
  "is_active" tinyint(1) not null default '1',
  "ai_enabled" tinyint(1) not null default '1',
  "last_seen_at" datetime,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "cameras_zone_status_index" on "cameras"("zone", "status");
CREATE UNIQUE INDEX "cameras_name_unique" on "cameras"("name");
CREATE TABLE "detections"(
  "id" integer primary key autoincrement not null,
  "camera_id" integer not null,
  "track_id" varchar not null,
  "event_type" varchar not null,
  "confidence" numeric not null default '0',
  "bbox" text,
  "snapshot_path" varchar,
  "meta" text,
  "detected_at" datetime not null default CURRENT_TIMESTAMP,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("camera_id") references "cameras"("id") on delete cascade
);
CREATE INDEX "detections_track_id_event_type_detected_at_index" on "detections"(
  "track_id",
  "event_type",
  "detected_at"
);
CREATE TABLE "alerts"(
  "id" integer primary key autoincrement not null,
  "camera_id" integer not null,
  "detection_id" integer not null,
  "level" varchar not null default 'warning',
  "message" varchar not null,
  "status" varchar not null default 'pending',
  "sent_at" datetime,
  "acknowledged_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("camera_id") references "cameras"("id") on delete cascade,
  foreign key("detection_id") references "detections"("id") on delete cascade
);
CREATE INDEX "alerts_level_status_index" on "alerts"("level", "status");
CREATE TABLE "person_faces"(
  "id" integer primary key autoincrement not null,
  "detection_id" integer,
  "camera_id" integer,
  "track_id" varchar,
  "face_image_path" varchar,
  "face_encoding_hash" varchar,
  "confidence" numeric not null default '0',
  "label" varchar,
  "notes" text,
  "first_seen_at" datetime,
  "last_seen_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("detection_id") references "detections"("id") on delete set null,
  foreign key("camera_id") references "cameras"("id") on delete set null
);
CREATE INDEX "person_faces_track_id_face_encoding_hash_index" on "person_faces"(
  "track_id",
  "face_encoding_hash"
);
CREATE TABLE "personal_access_tokens"(
  "id" integer primary key autoincrement not null,
  "tokenable_type" varchar not null,
  "tokenable_id" integer not null,
  "name" text not null,
  "token" varchar not null,
  "abilities" text,
  "last_used_at" datetime,
  "expires_at" datetime,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "personal_access_tokens_tokenable_type_tokenable_id_index" on "personal_access_tokens"(
  "tokenable_type",
  "tokenable_id"
);
CREATE UNIQUE INDEX "personal_access_tokens_token_unique" on "personal_access_tokens"(
  "token"
);
CREATE INDEX "personal_access_tokens_expires_at_index" on "personal_access_tokens"(
  "expires_at"
);

INSERT INTO migrations VALUES(1,'0001_01_01_000000_create_users_table',1);
INSERT INTO migrations VALUES(2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO migrations VALUES(3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO migrations VALUES(4,'2026_09_24_120000_create_cameras_table',1);
INSERT INTO migrations VALUES(5,'2026_09_24_120001_create_detections_table',1);
INSERT INTO migrations VALUES(6,'2026_09_24_120002_create_alerts_table',1);
INSERT INTO migrations VALUES(7,'2026_09_24_120003_create_person_faces_table',1);
INSERT INTO migrations VALUES(8,'2026_09_24_234301_create_personal_access_tokens_table',1);
