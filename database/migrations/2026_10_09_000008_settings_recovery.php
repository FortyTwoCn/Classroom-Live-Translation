<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up():void {DB::unprepared(<<<'SQL'
CREATE TABLE settings_versions (id uuid PRIMARY KEY, kind varchar(20) NOT NULL CHECK(kind IN('model','oss','policies')), payload_encrypted text NOT NULL, active boolean NOT NULL DEFAULT true, actor_id bigint REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT now());
CREATE UNIQUE INDEX active_settings_kind ON settings_versions(kind) WHERE active;
ALTER TABLE lessons ADD COLUMN model_settings_version uuid REFERENCES settings_versions(id);
ALTER TABLE recording_issues ADD COLUMN details jsonb NOT NULL DEFAULT '{}';
CREATE TABLE connection_tests (id uuid PRIMARY KEY, kind varchar(10) NOT NULL CHECK(kind IN('model','oss')), owner_id bigint NOT NULL REFERENCES users(id), settings_version uuid NOT NULL REFERENCES settings_versions(id), status varchar(20) NOT NULL DEFAULT 'queued', report jsonb NOT NULL DEFAULT '{}', failure_code varchar(80), created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now());
CREATE TABLE purge_objects (id bigserial PRIMARY KEY, lesson_id bigint NOT NULL REFERENCES lessons(id), storage_version varchar(100) NOT NULL, object_key text NOT NULL, status varchar(10) NOT NULL DEFAULT 'pending', updated_at timestamptz NOT NULL DEFAULT now(), UNIQUE(lesson_id,storage_version,object_key));
CREATE INDEX retention_lessons ON lessons(ended_at,id) WHERE status='ended';
SQL);}
 public function down():void {throw new RuntimeException('Restore into an empty database instead of destructive rollback.');}
};
