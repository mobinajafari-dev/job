<?php
namespace Helpers;

class Constants {
    // وضعیت کاربران (users.status)
    const USER_STATUS = [
        'active'  => 1,
        'blocked' => 2,
        'deleted' => 3
    ];

    // وضعیت آگهی‌ها (jobs.status)
    const JOB_STATUS = [
        'pending'  => 1,
        'active'   => 2,
        'expired'  => 3,
        'rejected' => 4
    ];

    // وضعیت تیکت‌ها (tickets.status)
    const TICKET_STATUS = [
        'open'     => 1,
        'answered' => 2,
        'closed'   => 3
    ];

    // نوع تراکنش (transactions.type)
    const TRANSACTION_TYPE = [
        'deposit'        => 1,
        'withdraw'       => 2,
        'job_payment'    => 3,
        'referral_bonus' => 4,
        'welcome_bonus'  => 5,
        'discount'       => 6,
        'refund'         => 7
    ];

    // وضعیت تراکنش (transactions.status)
    const TRANSACTION_STATUS = [
        'pending'   => 1,
        'completed' => 2,
        'failed'    => 3,
        'cancelled' => 4
    ];

    // متدهای کمکی برای تبدیل ID به متن
    public static function getUserStatusText($id) {
        $map = array_flip(self::USER_STATUS);
        return $map[$id] ?? 'unknown';
    }

    public static function getJobStatusText($id) {
        $map = array_flip(self::JOB_STATUS);
        return $map[$id] ?? 'unknown';
    }

    public static function getTicketStatusText($id) {
        $map = array_flip(self::TICKET_STATUS);
        return $map[$id] ?? 'unknown';
    }

    public static function getTransactionTypeText($id) {
        $map = array_flip(self::TRANSACTION_TYPE);
        return $map[$id] ?? 'unknown';
    }

    public static function getTransactionStatusText($id) {
        $map = array_flip(self::TRANSACTION_STATUS);
        return $map[$id] ?? 'unknown';
    }
}