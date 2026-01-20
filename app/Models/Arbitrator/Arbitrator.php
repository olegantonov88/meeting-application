<?php

namespace App\Models\Arbitrator;

use App\Casts\Arbitrator\AddressArbitratorCast;
use App\Casts\Arbitrator\BankArbitratorCast;
use App\Casts\Arbitrator\ContactsArbitratorCast;
use App\Casts\Arbitrator\EfrsbArbitratorCast;
use App\Casts\Arbitrator\MeetingSystemArbitratorCast;
use App\Casts\Arbitrator\PassportArbitratorCast;
use App\Casts\Arbitrator\ReceptionArbitratorCast;
use App\Casts\Arbitrator\RussianPostArbitratorCast;
use App\Casts\Arbitrator\SroArbitratorCast;
use App\Casts\Arbitrator\FileStorageArbitratorCast;
use App\Enums\User\UserSex;
use App\Models\ArbitratorFiles\ArbitratorFileInsurance;
use App\Models\ReadOnlyModel;

class Arbitrator extends ReadOnlyModel
{
    protected $guarded = [];

    protected $casts = [
        'sex' => UserSex::class,
        'address' => AddressArbitratorCast::class,
        'contacts' => ContactsArbitratorCast::class,
        'bank' => BankArbitratorCast::class,
        'passport' => PassportArbitratorCast::class,
        'sro' => SroArbitratorCast::class,
        'reception' => ReceptionArbitratorCast::class,
        'efrsb' => EfrsbArbitratorCast::class,
        'russian_post' => RussianPostArbitratorCast::class,
        'meeting_system' => MeetingSystemArbitratorCast::class,
        'file_storage' => FileStorageArbitratorCast::class,
        'subscription_date' => 'datetime'
    ];


    /**
     * Получить файлы страховок арбитражного управляющего
     */
    public function arbitratorFilesInsurances()
    {
        return $this->hasMany(ArbitratorFileInsurance::class);
    }

}
