<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up(): void { DB::unprepared(<<<'SQL'
ALTER TABLE users ADD COLUMN auth_version integer NOT NULL DEFAULT 1 CHECK(auth_version>0);
ALTER TABLE sessions ADD COLUMN auth_version integer, ADD COLUMN device_id uuid;
CREATE INDEX sessions_user ON sessions(user_id);
CREATE INDEX sessions_device ON sessions(device_id);
CREATE TABLE login_devices (id uuid PRIMARY KEY, user_id bigint NOT NULL REFERENCES users(id), validator_hash varchar(64) NOT NULL, previous_validator_hash varchar(64), previous_valid_until timestamptz, expires_at timestamptz NOT NULL, revoked_at timestamptz, last_used_at timestamptz NOT NULL DEFAULT now(), user_agent varchar(300) NOT NULL DEFAULT '', created_at timestamptz NOT NULL DEFAULT now());
CREATE INDEX login_devices_user ON login_devices(user_id,expires_at);
ALTER TABLE courses ADD COLUMN sort_order integer NOT NULL DEFAULT 0, ADD COLUMN archived_at timestamptz, ADD COLUMN deleted_at timestamptz;
CREATE INDEX courses_order ON courses(sort_order,id);
CREATE TABLE user_imports (id uuid PRIMARY KEY, user_id bigint NOT NULL REFERENCES users(id), status varchar(20) NOT NULL CHECK(status IN ('preview','processing','completed','failed')), duplicate_policy varchar(20) NOT NULL CHECK(duplicate_policy IN ('skip','update_name')), preview_hash varchar(64) NOT NULL, rows jsonb NOT NULL, counts jsonb NOT NULL, expires_at timestamptz NOT NULL, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now());
CREATE INDEX user_imports_user ON user_imports(user_id,created_at DESC);
SQL); }
 public function down():void {throw new RuntimeException('Destructive rollback disabled; restore to an empty database.');}
};
