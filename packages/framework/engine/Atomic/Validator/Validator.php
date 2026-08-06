<?php
declare(strict_types=1);
namespace Engine\Atomic\Validator;

if (!defined( 'ATOMIC_START' ) ) exit;

class Validator
{
    use ValidatorModelTrait;
}