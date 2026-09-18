<?php

declare(strict_types=1);

namespace App\Claude\Channel;

/**
 * 送一則訊息的四種結果。
 *
 * 分成四類而不是「成功/失敗」，是因為**後續處理完全相反**：
 *
 *   Sent             完成
 *   RateLimited      等 retryAfterSeconds 再送同一則。訊息還在，不能丟
 *   PermanentFailure 使用者封鎖了 bot、超過 24 小時視窗 —— 重試永遠不會成功，
 *                    要把對方從推播名單移除。這是最常被誤當成一般失敗一直重試的一類
 *   TemporaryFailure 5xx、連線斷 —— 退避後重試
 */
enum SendOutcome
{
    case Sent;
    case RateLimited;
    case PermanentFailure;
    case TemporaryFailure;
}
