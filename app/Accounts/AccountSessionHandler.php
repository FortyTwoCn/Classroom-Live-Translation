<?php
namespace App\Accounts;
use Illuminate\Session\DatabaseSessionHandler;
final class AccountSessionHandler extends DatabaseSessionHandler {
 public function read($sessionId):string{if(DeviceAuth::isRevoked($sessionId)){$this->exists=false;return '';}return parent::read($sessionId);}
 public function write($sessionId,$data):bool{return $this->connection->transaction(function()use($sessionId,$data){$s=$this->container->make('session.store');if($uid=$s->get('uid'))$this->connection->table('users')->where('id',$uid)->lockForUpdate()->first();if($device=$s->get('device_id'))$this->connection->table('login_devices')->where('id',$device)->lockForUpdate()->first();DeviceAuth::lockSession($sessionId);if(DeviceAuth::isRevoked($sessionId))return true;return parent::write($sessionId,$data);},3);}
 protected function getDefaultPayload($data) {
  $p=parent::getDefaultPayload($data);$s=$this->container->make('session.store');
  $uid=$s->get('uid');$version=$s->get('auth_version');
  $u=$uid?$this->connection->table('users')->where('id',$uid)->where('disabled',false)->lockForUpdate()->first():null;
  $valid=$u&&(int)$u->auth_version===(int)$version;
  if($valid&&$device=$s->get('device_id'))$valid=$this->connection->table('login_devices')->where('id',$device)->where('user_id',$uid)->whereNull('revoked_at')->where('expires_at','>',now())->lockForUpdate()->exists();
  $p['user_id']=$valid?(int)$uid:null;$p['auth_version']=$valid?(int)$version:null;$p['device_id']=$valid?$s->get('device_id'):null;
  return $p;
 }
}
