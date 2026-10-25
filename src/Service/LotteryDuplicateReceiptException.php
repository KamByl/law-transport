<?php

namespace App\Service;

class LotteryDuplicateReceiptException extends LotteryDailyLimitException
{
    public function __construct()
    {
        parent::__construct('Ten dowód zakupu został już zgłoszony.');
    }
}
