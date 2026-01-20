<?php

namespace App\ValueObjects\Arbitrator;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Stringable;
use Illuminate\Support\Facades\Crypt;

class EfrsbArbitratorObject implements Jsonable, Arrayable, Stringable
{
    private $login;
    private $password;

    public static function fromArray($data)
    {
        $instance = new EfrsbArbitratorObject();
        $instance->setLogin($data['login'] ?? null);
        $instance->setPassword($data['password'] ?? null);

        return $instance;
    }

    public function setLogin($login)
    {
        $this->login = $login ? Crypt::encryptString($login) : null;
    }

    public function getLogin()
    {
        return $this->login ? Crypt::decryptString($this->login) : null;
    }

    public function setPassword($password)
    {
        $this->password = $password ? Crypt::encryptString($password) : null;
    }

    public function getPassword()
    {
        return $this->password ? Crypt::decryptString($this->password) : null;
    }

    public function toJson($options = 0)
    {
        return json_encode($this->toArray());
    }

    public function __toString()
    {
        return $this->toJson();
    }

    public function toArray()
    {
        return [
            'login' => $this->getLogin(),
            'password' => $this->getPassword(),
        ];
    }

}
