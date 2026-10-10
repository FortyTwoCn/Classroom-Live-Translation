<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up():void{DB::unprepared('CREATE TABLE session_revocations (id_hash varchar(64) PRIMARY KEY, expires_at timestamptz NOT NULL); CREATE INDEX session_revocations_expiry ON session_revocations(expires_at);');}
 public function down():void{throw new RuntimeException('Destructive rollback disabled; restore into an empty database.');}
};
