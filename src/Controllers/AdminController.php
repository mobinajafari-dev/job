<?php
namespace Controllers;

use Services\AdminService;
use Services\TelegramService;
use Helpers\Logger;

class AdminController {
    private $telegram;
    private $adminService;
    private $jobService;
    private $discountService;
    private $broadcastService;

    public function __construct() {
        $this->telegram = new TelegramService(BOT_TOKEN);
        $this->adminService = new AdminService($this->telegram);
        $this->jobService = new \Services\JobService($this->telegram, new \Services\WalletService($this->telegram));
        $this->discountService = new \Services\DiscountService($this->telegram, new \Services\WalletService($this->telegram));
        $this->broadcastService = new \Services\BroadcastService($this->telegram);
    }

    public function handle($update) {
        $chat_id = null;

        if (isset($update['message'])) {
            $chat_id = $update['message']['chat']['id'];
            $text = $update['message']['text'] ?? '';

            if (!$this->adminService->isAdmin($chat_id)) {
                $this->telegram->sendMessage($chat_id, "⛔ شما دسترسی به این بخش ندارید!");
                return;
            }

            if ($this->broadcastService->isBroadcasting($chat_id)) {
                $step = $this->broadcastService->getBroadcastStep();
                if ($step == 'message') {
                    $this->broadcastService->setBroadcastMessage($chat_id, $text);
                }
                return;
            }

            if (isset($_SESSION['admin_discount'])) {
                $this->discountService->createDiscount($chat_id, $text, 10000, 1, 30);
                unset($_SESSION['admin_discount']);
                return;
            }

            if (strpos($text, '/admin') === 0) {
                $command = trim(str_replace('/admin', '', $text));
                $this->handleAdminCommand($chat_id, $command);
            }
        } elseif (isset($update['callback_query'])) {
            $chat_id = $update['callback_query']['message']['chat']['id'];
            $data = $update['callback_query']['data'];
            $callback_id = $update['callback_query']['id'];

            if (!$this->adminService->isAdmin($chat_id)) {
                $this->telegram->answerCallbackQuery($callback_id, "⛔ شما دسترسی ندارید!", true);
                return;
            }

            $this->handleCallback($chat_id, $data, $callback_id);
        }
    }

    private function handleAdminCommand($chat_id, $command) {
        switch ($command) {
            case 'stats':
                $this->adminService->showStats($chat_id);
                break;
            case 'pending':
                $this->jobService->getPendingJobs($chat_id);
                break;
            case 'users':
                $this->adminService->showUsers($chat_id);
                break;
            case 'transactions':
                $this->adminService->showTransactions($chat_id);
                break;
            case 'financial':
                $this->adminService->showFinancialReport($chat_id);
                break;
            case 'tickets':
                $this->adminService->showTickets($chat_id);
                break;
            case 'broadcast':
                $this->broadcastService->startBroadcast($chat_id);
                break;
            default:
                $this->adminService->showAdminMenu($chat_id);
        }
    }

    private function handleCallback($chat_id, $data, $callback_id) {
        if (strpos($data, 'admin_') === 0) {
            $action = str_replace('admin_', '', $data);

            switch ($action) {
                case 'stats':
                    $this->adminService->showStats($chat_id);
                    break;
                case 'pending':
                    $this->jobService->getPendingJobs($chat_id);
                    break;
                case 'users':
                    $this->adminService->showUsers($chat_id);
                    break;
                case 'transactions':
                    $this->adminService->showTransactions($chat_id);
                    break;
                case 'financial':
                    $this->adminService->showFinancialReport($chat_id);
                    break;
                case 'tickets':
                    $this->adminService->showTickets($chat_id);
                    break;
                case 'add_discount':
                    $_SESSION['admin_discount'] = true;
                    $this->telegram->sendMessage($chat_id, "🎁 لطفاً کد تخفیف را وارد کنید:");
                    break;
                case 'broadcast':
                    $this->broadcastService->startBroadcast($chat_id);
                    break;
            }
            $this->telegram->answerCallbackQuery($callback_id);
        } elseif (strpos($data, 'approve_job_') === 0) {
            $job_id = str_replace('approve_job_', '', $data);
            $this->jobService->approveJob($chat_id, $job_id);
            $this->telegram->answerCallbackQuery($callback_id, "✅ آگهی تایید شد");
        } elseif (strpos($data, 'reject_job_') === 0) {
            $job_id = str_replace('reject_job_', '', $data);
            $this->jobService->rejectJob($chat_id, $job_id);
            $this->telegram->answerCallbackQuery($callback_id, "❌ آگهی رد شد");
        } elseif (strpos($data, 'broadcast_') === 0) {
            $action = str_replace('broadcast_', '', $data);
            if ($action == 'confirm') {
                $message = $this->broadcastService->getBroadcastMessage();
                if ($message) {
                    $this->broadcastService->sendBroadcastToAll($chat_id, $message);
                }
            } elseif ($action == 'cancel') {
                $this->broadcastService->cancelBroadcast($chat_id);
            }
            $this->telegram->answerCallbackQuery($callback_id);
        }
    }
}