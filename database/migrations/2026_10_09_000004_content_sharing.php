<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up():void {DB::unprepared(<<<'SQL'
ALTER TABLE lessons ADD COLUMN editing_frozen boolean NOT NULL DEFAULT false;
ALTER TABLE subtitle_units ADD COLUMN deleted_at timestamptz;
CREATE TABLE subtitle_revision_batches (id uuid PRIMARY KEY, lesson_id bigint NOT NULL REFERENCES lessons(id), actor_id bigint NOT NULL REFERENCES users(id), request_hash varchar(64) NOT NULL, result jsonb NOT NULL, created_at timestamptz NOT NULL DEFAULT now());
CREATE TABLE subtitle_revisions (id bigserial PRIMARY KEY, unit_id bigint NOT NULL REFERENCES subtitle_units(id), lesson_id bigint NOT NULL REFERENCES lessons(id), batch_id uuid NOT NULL REFERENCES subtitle_revision_batches(id), actor_id bigint NOT NULL REFERENCES users(id), version integer NOT NULL CHECK(version>1), before_text text NOT NULL, after_text text NOT NULL, restored_revision_id bigint REFERENCES subtitle_revisions(id), created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(unit_id,version));
CREATE INDEX revisions_unit_history ON subtitle_revisions(unit_id,id DESC);
CREATE TABLE lesson_shares (id uuid PRIMARY KEY, lesson_id bigint NOT NULL UNIQUE REFERENCES lessons(id), token_hash varchar(64) NOT NULL UNIQUE, token_encrypted text NOT NULL, version integer NOT NULL DEFAULT 1, expires_at timestamptz, revoked_at timestamptz, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now());
CREATE TABLE share_grants (session_id varchar(255) NOT NULL, share_id uuid NOT NULL REFERENCES lesson_shares(id) ON DELETE CASCADE, share_version integer NOT NULL, created_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(session_id,share_id));
CREATE TABLE subtitle_exports (id uuid PRIMARY KEY, lesson_id bigint NOT NULL REFERENCES lessons(id), owner_id bigint NOT NULL REFERENCES users(id), idempotency_key uuid NOT NULL, request_hash varchar(64) NOT NULL, status varchar(20) NOT NULL CHECK(status IN('queued','processing','ready','failed','expired')), format varchar(10) NOT NULL CHECK(format IN('txt','md','srt','vtt')), layout varchar(20) NOT NULL CHECK(layout IN('source','translation','bilingual')), text_version varchar(20) NOT NULL CHECK(text_version IN('current','original')), cutoff_seq bigint NOT NULL, metadata jsonb NOT NULL, snapshot_count integer NOT NULL DEFAULT 0, report jsonb NOT NULL DEFAULT '{}', file_path text, filename text, mime varchar(100), failure_code varchar(80), expires_at timestamptz NOT NULL, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(), UNIQUE(owner_id,idempotency_key));
CREATE INDEX export_owner_list ON subtitle_exports(owner_id,created_at DESC);
CREATE INDEX export_expiry ON subtitle_exports(expires_at);
CREATE TABLE subtitle_export_rows (export_id uuid NOT NULL REFERENCES subtitle_exports(id) ON DELETE CASCADE, ordinal integer NOT NULL, payload jsonb NOT NULL, PRIMARY KEY(export_id,ordinal));
SQL);}
 public function down():void {throw new RuntimeException('Destructive rollback disabled; restore into an empty database.');}
};
