<?php

require_once 'vendor/autoload.php';

use Panychek\MoEx\Exception\ExceptionInterface as MoexException;
use Panychek\MoEx\Security;

try {
    $bond = new Bond(new Security('RU000A101MT7'), 0);
//    $bond = new Bond(
//        new Security('RU000A0ZYBV5'),
//        0, [
//            '2021-09-28' => 0.3,
//            '2022-03-29' => 0.3,
//            '2022-09-27' => 0.4
//        ]);
    //$date = new \DateTime('2020-06-22');
    $date = new \DateTime();
//    var_dump($bond->getCoupons());
    //var_dump($bond->getYieldAtBackDate($date));
    var_dump($bond->getNkd($date));
    var_dump($bond->getPriceValueAtDate($date));
    var_dump($bond->getDirtyPriceWithCommission(new \DateTime()));//exit;
//    var_dump($bond->getNextCouponDate(new \DateTime('2027-04-29')));exit;
    var_dump($bond->getYieldAtBackDate($date));
    var_dump($bond->getEffectYieldAtBackDate($date));


//    $data = $bond->getHistoricalQuotes('2021-01-04', '2021-01-04');
//    var_dump($data);

} catch (MoexException $e) {
    echo $e->getMessage();
}


class Bond {
    const DAYS_IN_YEAR = 365;
    const DEFAULT_BROKER_COMMISSION = 0.00057;
    const DATE_FORMAT = 'Y-m-d';

    /**
     * @var Security
     */
    private $security;

    /**
     * @var array
     */
    private $amortizations;

    /**
     * @var float
     */
    private $brokerCommission;

    public function __construct(Security $security, $brokerCommission = null, array $amortizations = [])
    {
        $this->security = $security;
        $this->brokerCommission = $brokerCommission ?? self::DEFAULT_BROKER_COMMISSION;
        $this->amortizations = $amortizations;
    }

    public function setAmortizations(array $amortizations)
    {
        $this->amortizations = $amortizations;
    }

    public function getAmortizations(): array
    {
        return $this->amortizations;
    }

    public function setBrokerCommission(float $brokerCommission): void
    {
        $this->brokerCommission = $brokerCommission;
    }

    public function getBrokerCommission(): float
    {
        return $this->brokerCommission;
    }

    public function getBuyBackDateTime(): ?\DateTime
    {
        try {
            return new \DateTime($this->security->getBuyBackDate());
        } catch (BadMethodCallException $e) {
            return null;
        }
    }

    public function getMatDateTime(): \DateTime
    {
        return new \DateTime($this->security->getMatDate());
    }

    public function getYieldAtBackDate(\DateTime $after = null): float
    {
        if (!$this->getBuyBackDateTime()) {
            return $this->getYieldAtMatDate($after);
        }
        if (!$after) {
            $after = $this->getMidNight();
        }

        $yieldAmount = $this->getAmountAtBackDate($after) - $this->getDirtyPriceWithCommission($after);
        $days = $after->diff($this->getBuyBackDateTime())->days;

        return (self::DAYS_IN_YEAR/$days) * $yieldAmount/$this->getDirtyPriceWithCommission($after);
    }

    public function getEffectYieldAtBackDate(\DateTime $after = null): float
    {
        $simpleYield = $this->getYieldAtMatDate($after);
        $yieldAmount = $this->getAmountAtMatDate($after) - $this->getDirtyPriceWithCommission($after);
        $coupons = $this->getCoupons($after);

        $couponsYieldAmount = $this->getCouponsYieldAmount($coupons, $simpleYield);
        $yieldAmount += $couponsYieldAmount;

        //      var_dump($yieldAmount);

        $days = $after->diff($this->getMatDateTime())->days;

        return (self::DAYS_IN_YEAR/$days) * $yieldAmount/$this->getDirtyPriceWithCommission($after);
    }

    public function getEffectYieldAtMatDate(\DateTime $after = null): float
    {
        $simpleYield = $this->getYieldAtMatDate($after);
        $yieldAmount = $this->getAmountAtMatDate($after) - $this->getDirtyPriceWithCommission($after);
        $coupons = $this->getAllCoupons($after);

  var_dump($simpleYield);

        $couponsYieldAmount = $this->getCouponsYieldAmount($coupons, $simpleYield);
        $yieldAmount += $couponsYieldAmount;
        var_dump($couponsYieldAmount);

        if (count($this->getAmortizations())) {
            foreach ($this->getAmortizations() as $date => $amortizationPercent) {
                $amortDt = new \DateTime($date);
                $days = $amortDt->diff($this->getMatDateTime())->days;
                $yieldAmount += ($days/self::DAYS_IN_YEAR) * $amortizationPercent * $this->security->getFaceValue() * $simpleYield;
            }
        }

  //      var_dump($yieldAmount);

        $days = $after->diff($this->getMatDateTime())->days;

        return (self::DAYS_IN_YEAR/$days) * $yieldAmount/$this->getDirtyPriceWithCommission($after);
    }

    private function getCouponsYieldAmount(array $coupons, float $simpleYield): float
    {
        $yieldAmount = 0;
        foreach ($coupons as $date => $coupon) {
            $couponDt = new \DateTime($date);
//            var_dump($couponDt);
//            var_dump($this->getYieldAtMatDate($couponDt));
            $days = $couponDt->diff($this->getMatDateTime())->days;
            //var_dump(($days/self::DAYS_IN_YEAR) * $coupon * $simpleYield);
            $yieldAmount += ($days/self::DAYS_IN_YEAR) * $coupon * $simpleYield;
        }

        return $yieldAmount;
    }

    public function getYieldAtMatDate(\DateTime $after = null): float
    {
        if (!$after) {
            $after = $this->getMidNight();
        }

        $yieldAmount = $this->getAmountAtMatDate($after) - $this->getDirtyPriceWithCommission($after);
        $days = $after->diff($this->getMatDateTime())->days;

        if (!$days) {
            return 0;
        }

        return (self::DAYS_IN_YEAR/$days) * $yieldAmount/$this->getDirtyPriceWithCommission($after);
    }

    public function getAmountAtBackDate(\DateTime $after = null): float
    {
        return $this->security->getFaceValue() + $this->getCouponsSum($after);
    }

    public function getAmountAtMatDate(\DateTime $after = null): float
    {
        return $this->security->getFaceValue() + $this->getAllCouponsSum($after);
    }

    public function getDirtyPriceWithCommission(\DateTime $date = null): float
    {
        return round($this->getPriceValueAtDate($date) + $this->getBrokerCommissionValue($date) + $this->getNkd($date), 2);
    }

    private function getMidNight(): \DateTime
    {
        return (new DateTime())->setTime(0, 0);
    }

    public function getPriceValueAtDate(\DateTime $date = null)
    {
        if (!$date || $date >= $this->getMidNight()) {
            return $this->getLastPriceValue();
        }
        $data = $this->security->getHistoricalQuotes($date->format(self::DATE_FORMAT), $date->format(self::DATE_FORMAT));

        $result = array_shift($data);
        var_dump($date, $result['close']);

        return isset($result['close']) ? $result['close'] * $this->security->getFaceValue() / 100 : null;
    }

    public function getLastPriceValue(): float
    {
        return $this->getLastPricePercent() * $this->security->getFaceValue() / 100;
    }

    public function getLastPricePercent()
    {
        return $this->security->getLastPrice() ?? $this->security->getCurrentPrice();
    }

    public function getBrokerCommissionValue(\DateTime $date = null): float
    {
        return round($this->getPriceValueAtDate($date) * $this->getBrokerCommission(), 2);
    }

    public function getCouponDaysInterval(): int
    {
        return floor(self::DAYS_IN_YEAR/$this->security->getCouponFrequency());
    }

    public function getNkd(\DateTime $date = null): float
    {
        if (!$date) {
            $date = $this->getMidNight();
        }

        $daysToCoupon = $this->getNextCouponDate($date)->diff($date)->days;
        $daysFromCoupon = $this->getCouponDaysInterval() - $daysToCoupon;

        return round($this->getCouponValue() * $daysFromCoupon/$this->getCouponDaysInterval(), 2);
    }

    public function getNextCouponDate(\DateTime $after = null): ?\DateTime
    {
        if (!$after) {
            $after = $this->getMidNight();
        }

        foreach (array_keys($this->getAllCoupons($after)) as $couponDate) {
            $couponDateDt = new \DateTime($couponDate);
            if ($couponDateDt >= $after) {
                return $couponDateDt;
            }
        }

        return null;
    }

    public function getFirstCouponDate(): \DateTime
    {
        $nextCoupon = new \DateTime($this->security->getCouponDate());
        $firstCoupon = clone $nextCoupon;
        $issueDate = new \DateTime($this->security->getIssueDate());
        while ($nextCoupon->modify('-' . $this->getCouponDaysInterval() . ' days') > $issueDate) {
            $firstCoupon = clone $nextCoupon;
        }

        return $firstCoupon;
    }

    public function getAllCouponsSum(\DateTime $after = null): float
    {
        return array_sum(array_values($this->getAllCoupons($after)));
    }

    public function getCouponsSum(\DateTime $after = null): float
    {
        return array_sum(array_values($this->getCoupons($after)));
    }

    public function getAllCoupons(\DateTime $after = null): array
    {
        return $this->getCoupons($after, $this->getMatDateTime());
    }

    public function getCouponValueAtDate(\DateTime $date): float
    {
        $initCoupon = $this->security->getCouponValue();
        if (!$this->getAmortizations() || !count($this->getAmortizations())) {
            return $initCoupon;
        }

        $couponPercent = 1;
        $amortDates = array_keys($this->getAmortizations());
        $amortPercents = array_values($this->getAmortizations());
        $amortDate = new \DateTime(array_shift($amortDates));
        while ($date > $amortDate) {
            $couponPercent -= array_shift($amortPercents);
            $amortDate = new \DateTime(array_shift($amortDates));
        }

        return round($couponPercent * $initCoupon, 2);
    }

    public function getCoupons(\DateTime $after = null, \DateTime $end = null): array
    {
        $res = [];

        if (!$after) {
            $after = $this->getMidNight();
        }

        $nextCoupon = $this->getFirstCouponDate();
        if (!$end) {
            $end = $this->getBuyBackDateTime() ?? $this->getMatDateTime();
        }

        while ($nextCoupon <= $end) {
            if (!$after || $nextCoupon >= $after) {
                $res[$nextCoupon->format(self::DATE_FORMAT)] = $this->getCouponValueAtDate($nextCoupon);
            }
            $nextCoupon = $nextCoupon->modify('+' . $this->getCouponDaysInterval() . ' days');
        }

        return $res;
    }

    public function __call($name, $arguments)
    {
        return call_user_func_array([&$this->security, $name], $arguments);
    }
}
