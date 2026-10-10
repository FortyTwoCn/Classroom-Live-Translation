<?php
namespace App\Domain;
final class Failure extends \RuntimeException {
 public function __construct(public readonly string $errorCode,public readonly int $httpStatus=400,public readonly array $params=[]) { parent::__construct($errorCode); }
}
