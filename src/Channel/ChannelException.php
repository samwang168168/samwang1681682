<?php

declare(strict_types=1);

namespace App\Claude\Channel;

use RuntimeException;

/** 驗簽失敗、設定缺漏、平台回了無法解析的東西。 */
final class ChannelException extends RuntimeException
{
}
